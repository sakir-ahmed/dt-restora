<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoleDefaults;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->owner = User::factory()->role('owner')->create();
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '100.00']);
});

function rolePayload(array $over = []): array
{
    return array_merge(['permissions' => ['orders.view'], 'all_branches' => false], $over);
}

function roleNamed(string $name): Role
{
    return Role::findByName($name, 'staff');
}

it('keeps the permission catalog and the database in step, with wording in both languages', function (): void {
    $catalog = PermissionCatalog::all();

    expect(Permission::query()->pluck('name')->sort()->values()->all())->toBe(collect($catalog)->sort()->values()->all())
        ->and($catalog)->toHaveCount(count(array_unique($catalog)));

    foreach (['en', 'bn'] as $locale) {
        foreach ($catalog as $name) {
            expect(__('permissions.items.'.$name, [], $locale))->not->toBe('permissions.items.'.$name);
        }

        foreach (array_keys(PermissionCatalog::groups()) as $group) {
            expect(__('permissions.groups.'.$group, [], $locale))->not->toBe('permissions.groups.'.$group);
        }

        foreach (RoleDefaults::names() as $role) {
            expect(__('permissions.roles.'.$role.'.name', [], $locale))->not->toBe('permissions.roles.'.$role.'.name')
                ->and(__('permissions.roles.'.$role.'.description', [], $locale))->not->toBe('permissions.roles.'.$role.'.description');
        }
    }

    // The two languages say different things (this is a translation, not a copy).
    expect(__('permissions.items.orders.view', [], 'bn'))->not->toBe(__('permissions.items.orders.view', [], 'en'));
});

it('seeds the standard restaurant roles with sensible permissions', function (): void {
    $expected = ['super-admin', 'owner', 'branch-manager', 'supervisor', 'cashier', 'waiter', 'host', 'kitchen-manager', 'store-keeper', 'accountant', 'hr-manager', 'marketing-manager', 'delivery-manager', 'rider'];

    expect(RoleDefaults::names())->toBe($expected)
        ->and(Role::query()->whereIn('name', $expected)->count())->toBe(count($expected));

    foreach (RoleDefaults::definitions() as $name => $definition) {
        $granted = roleNamed($name)->permissions->pluck('name')->sort()->values()->all();

        expect($granted)->toBe(collect(RoleDefaults::permissionsFor($name))->sort()->values()->all())
            ->and(collect($granted)->every(fn (string $permission): bool => PermissionCatalog::isKnown($permission)))->toBeTrue()
            ->and($granted)->toContain('admin.access');
    }

    // Spot checks of the standard split of duties.
    expect(roleNamed('cashier')->hasPermissionTo('orders.void'))->toBeFalse()
        ->and(roleNamed('supervisor')->hasPermissionTo('orders.void'))->toBeTrue()
        ->and(roleNamed('waiter')->hasPermissionTo('pos.access'))->toBeFalse()
        ->and(roleNamed('store-keeper')->hasPermissionTo('purchases.manage'))->toBeTrue()
        ->and(roleNamed('store-keeper')->hasPermissionTo('journals.post'))->toBeFalse()
        ->and(roleNamed('accountant')->hasPermissionTo('orders.create'))->toBeFalse()
        ->and(roleNamed('owner')->hasPermissionTo('roles.manage'))->toBeTrue()
        ->and(roleNamed('branch-manager')->hasPermissionTo('roles.manage'))->toBeFalse();
});

it('shows the roles screen to the owner only', function (): void {
    $this->actingAs($this->owner, 'staff')->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/roles/Index')
            ->has('roles', count(RoleDefaults::names()))
            ->has('groups')
            ->where('required', 'admin.access'));

    $this->actingAs($this->manager, 'staff')->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($this->manager, 'staff')->put(route('admin.roles.update', roleNamed('cashier')), rolePayload())->assertForbidden();
});

it('edits a standard role, flags it as changed, and resets it to the defaults', function (): void {
    $cashier = roleNamed('cashier');

    $this->actingAs($this->owner, 'staff')
        ->put(route('admin.roles.update', $cashier), rolePayload(['permissions' => ['orders.view', 'reports.view']]))
        ->assertSessionHasNoErrors();

    // Panel access stays on whatever is ticked.
    expect($cashier->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'orders.view', 'reports.view']);

    $shown = fn () => collect($this->actingAs($this->owner, 'staff')->get(route('admin.roles.index'))->viewData('page')['props']['roles'])->firstWhere('name', 'cashier');

    expect($shown()['modified'])->toBeTrue();

    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.reset', $cashier))->assertSessionHas('success');

    expect($cashier->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(collect(RoleDefaults::permissionsFor('cashier'))->sort()->values()->all())
        ->and($shown()['modified'])->toBeFalse();
});

it('never lets the owner or super-admin role be changed, reset or deleted', function (): void {
    foreach (['owner', 'super-admin'] as $name) {
        $role = roleNamed($name);
        $before = $role->permissions->count();

        $this->actingAs($this->owner, 'staff')->put(route('admin.roles.update', $role), rolePayload())->assertSessionHasErrors('role');
        $this->actingAs($this->owner, 'staff')->post(route('admin.roles.reset', $role))->assertSessionHasErrors('role');
        $this->actingAs($this->owner, 'staff')->delete(route('admin.roles.destroy', $role))->assertSessionHasErrors('role');

        expect($role->fresh()->permissions->count())->toBe($before);
    }
});

it('rejects permissions that are not in the catalog', function (): void {
    $this->actingAs($this->owner, 'staff')
        ->put(route('admin.roles.update', roleNamed('waiter')), rolePayload(['permissions' => ['orders.view', 'nuke.everything']]))
        ->assertSessionHasErrors('permissions.1');

    expect(roleNamed('waiter')->hasPermissionTo('orders.create'))->toBeTrue();
});

it('creates a custom role, starting empty or from another role', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), [
        'label' => 'Night Supervisor',
        'label_bn' => 'নাইট সুপারভাইজার',
        'description' => 'Closes the restaurant',
        'copy_from' => 'cashier',
        'all_branches' => false,
    ])->assertSessionHasNoErrors();

    $role = roleNamed('night-supervisor');

    expect($role->label)->toBe('Night Supervisor')
        ->and($role->label_bn)->toBe('নাইট সুপারভাইজার')
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(collect(RoleDefaults::permissionsFor('cashier'))->sort()->values()->all());

    // A second role with the same name gets its own key, and an empty one still signs in.
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Night Supervisor'])->assertSessionHasNoErrors();
    expect(roleNamed('night-supervisor-2')->permissions->pluck('name')->all())->toBe(['admin.access']);

    // A standard role name is never reused.
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Cashier'])->assertSessionHasNoErrors();
    expect(Role::query()->where('name', 'cashier-2')->exists())->toBeTrue();

    // Only a manager-level account with the permission may do it.
    $this->actingAs($this->manager, 'staff')->post(route('admin.roles.store'), ['label' => 'Sneaky'])->assertForbidden();
});

it('changes what people can do straight away when their role changes', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Floor Helper', 'permissions' => ['orders.view', 'menu.view']])->assertSessionHasNoErrors();

    $helper = User::factory()->create(['branch_id' => $this->branch->id]);
    $helper->assignRole('floor-helper');

    $order = ['order_type' => 'takeaway', 'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]], 'terminal' => 'T01'];

    $this->actingAs($helper, 'staff')->post('/admin/orders', $order)->assertForbidden();

    $this->actingAs($this->owner, 'staff')
        ->put(route('admin.roles.update', roleNamed('floor-helper')), rolePayload(['permissions' => ['orders.view', 'orders.create', 'menu.view']]))
        ->assertSessionHasNoErrors();

    $this->actingAs(User::query()->find($helper->id), 'staff')->post('/admin/orders', $order)->assertRedirect();

    // Taking it away again closes the door again.
    $this->actingAs($this->owner, 'staff')
        ->put(route('admin.roles.update', roleNamed('floor-helper')), rolePayload(['permissions' => ['orders.view']]))
        ->assertSessionHasNoErrors();

    $this->actingAs(User::query()->find($helper->id), 'staff')->post('/admin/orders', $order)->assertForbidden();
});

it('lets staff be given a custom role and keeps roles in use from being deleted', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Bar Lead', 'permissions' => ['orders.view']])->assertSessionHasNoErrors();
    $role = roleNamed('bar-lead');

    $this->actingAs($this->owner, 'staff')->post(route('admin.staff.users.store'), [
        'name' => 'Rina Barman',
        'username' => 'rina.bar',
        'password' => 'Password123',
        'branch_id' => $this->branch->id,
        'roles' => ['bar-lead'],
    ])->assertSessionHasNoErrors();

    $rina = User::query()->where('username', 'rina.bar')->firstOrFail();
    expect($rina->hasRole('bar-lead'))->toBeTrue();

    // An unknown role is refused.
    $this->actingAs($this->owner, 'staff')->post(route('admin.staff.users.store'), [
        'name' => 'Nobody', 'username' => 'nobody.x', 'password' => 'Password123', 'roles' => ['made-up'],
    ])->assertSessionHasErrors('roles.0');

    // In use: cannot go. Standard roles cannot go at all.
    $this->actingAs($this->owner, 'staff')->delete(route('admin.roles.destroy', $role))->assertSessionHas('error');
    expect(Role::query()->where('name', 'bar-lead')->exists())->toBeTrue();

    $this->actingAs($this->owner, 'staff')->delete(route('admin.roles.destroy', roleNamed('cashier')))->assertSessionHas('error');
    expect(Role::query()->where('name', 'cashier')->exists())->toBeTrue();

    $rina->syncRoles(['waiter']);

    $this->actingAs($this->owner, 'staff')->delete(route('admin.roles.destroy', $role))->assertSessionHas('success');
    expect(Role::query()->where('name', 'bar-lead')->exists())->toBeFalse();
});

it('keeps a role that works across every branch from being tied to one', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Area Auditor', 'all_branches' => true])->assertSessionHasNoErrors();

    $this->actingAs($this->owner, 'staff')->post(route('admin.staff.users.store'), [
        'name' => 'Audit One', 'username' => 'audit.one', 'password' => 'Password123', 'roles' => ['area-auditor'],
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('username', 'audit.one')->value('branch_id'))->toBeNull();
});

it('stops people removing the Roles screen from their own role', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Role Admin', 'permissions' => ['roles.manage', 'orders.view']])->assertSessionHasNoErrors();

    $admin = User::factory()->create();
    $admin->assignRole('role-admin');

    $this->actingAs($admin, 'staff')
        ->put(route('admin.roles.update', roleNamed('role-admin')), rolePayload(['permissions' => ['orders.view']]))
        ->assertSessionHasErrors('permissions');

    expect(roleNamed('role-admin')->hasPermissionTo('roles.manage'))->toBeTrue();
});

it('resets every standard role at once and leaves custom roles alone', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Pastry Lead', 'permissions' => ['menu.view']])->assertSessionHasNoErrors();

    roleNamed('waiter')->syncPermissions(['admin.access']);
    roleNamed('host')->delete();

    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.reset-all'))->assertSessionHas('success');

    expect(roleNamed('waiter')->hasPermissionTo('orders.create'))->toBeTrue()
        ->and(Role::query()->where('name', 'host')->exists())->toBeTrue()
        ->and(roleNamed('pastry-lead')->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'menu.view']);
});

it('installs missing roles without overwriting roles that were customised', function (): void {
    roleNamed('cashier')->syncPermissions(['admin.access', 'orders.view']);
    roleNamed('store-keeper')->delete();

    RoleDefaults::install(false);

    expect(roleNamed('cashier')->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'orders.view'])
        ->and(Role::query()->where('name', 'store-keeper')->exists())->toBeTrue();
});

it('offers the roles, readable, on the staff screen and over the API', function (): void {
    $this->actingAs($this->owner, 'staff')->post(route('admin.roles.store'), ['label' => 'Bar Lead'])->assertSessionHasNoErrors();

    $roles = collect($this->actingAs($this->owner, 'staff')->get(route('admin.staff.index'))->viewData('page')['props']['roles']);

    expect($roles->pluck('name')->all())->toContain('bar-lead', 'supervisor', 'owner')
        ->not->toContain('super-admin')
        ->and($roles->firstWhere('name', 'cashier')['label'])->toBe('Cashier')
        ->and($roles->firstWhere('name', 'bar-lead')['label'])->toBe('Bar Lead');

    $token = $this->owner->createToken('staff', ['staff'])->plainTextToken;
    $api = $this->withToken($token)->getJson('/api/v1/branches/'.$this->branch->id.'/staff')->assertOk()->json('data.roles');

    expect($api)->toContain('bar-lead', 'hr-manager')->not->toContain('super-admin');
});
