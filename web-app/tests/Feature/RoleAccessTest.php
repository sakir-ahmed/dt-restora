<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('gives every product role the admin access permission', function (string $role): void {
    $user = User::factory()->role($role)->create();

    expect($user->can('admin.access'))->toBeTrue();
})->with([
    'owner',
    'branch-manager',
    'cashier',
    'waiter',
    'kitchen-manager',
    'accountant',
    'rider',
]);

it('gives the super-admin role every permission through the gate', function (): void {
    $user = User::factory()->role('super-admin')->create();

    expect($user->can('settings.manage'))->toBeTrue()
        ->and($user->can('journals.post'))->toBeTrue()
        ->and($user->can('some-future-permission'))->toBeTrue();
});

it('scopes role permissions to the seeded matrix', function (string $role, string $permission, bool $allowed): void {
    $user = User::factory()->role($role)->create();

    expect($user->can($permission))->toBe($allowed);
})->with([
    'cashier can open POS' => ['cashier', 'pos.access', true],
    'waiter cannot open POS' => ['waiter', 'pos.access', false],
    'cashier cannot void orders' => ['cashier', 'orders.void', false],
    'branch-manager can void orders' => ['branch-manager', 'orders.void', true],
    'kitchen-manager can log wastage' => ['kitchen-manager', 'wastage.log', true],
    'cashier cannot post journals' => ['cashier', 'journals.post', false],
    'accountant can post journals' => ['accountant', 'journals.post', true],
    'rider cannot manage menu' => ['rider', 'menu.manage', false],
    'owner can manage branches' => ['owner', 'branches.manage', true],
    'branch-manager can manage tables' => ['branch-manager', 'tables.manage', true],
    'cashier cannot manage tables' => ['cashier', 'tables.manage', false],
]);

it('returns 403 for a user with no role on the admin panel', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'staff')->get('/admin');

    $response->assertForbidden();
});

it('opens the dashboard to the kitchen manager without the sales figures', function (): void {
    $kitchen = User::factory()->role('kitchen-manager')->create();

    $response = $this->actingAs($kitchen, 'staff')->get('/admin');

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['canViewMetrics'])->toBeFalse()
        ->and($props['summary']['gross_sales'])->toBe('0.00')
        ->and($props['summary']['net_profit'])->toBe('0.00')
        ->and($props['salesByDay'])->toBe([])
        ->and($props['branchComparison'])->toBe([]);
});

it('opens the dashboard to a rider', function (): void {
    $rider = User::factory()->role('rider')->create();

    $response = $this->actingAs($rider, 'staff')->get('/admin');

    $response->assertOk();
});

it('opens the dashboard to a custom role holding only the required permission', function (): void {
    $role = Role::findOrCreate('front-desk', 'staff');
    $role->syncPermissions(['admin.access']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this->actingAs($user, 'staff')->get('/admin');

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['canViewMetrics'])->toBeFalse()
        ->and($props['alerts']['lowStock'])->toBe(0)
        ->and($props['preorders'])->toBe(0);
});

it('keeps low stock on the dashboard for a role with inventory view', function (): void {
    $branch = Branch::factory()->create();
    $unit = Unit::query()->create(['name' => 'Gram', 'symbol' => 'g-'.uniqid(), 'type' => 'weight', 'factor' => 1]);
    $ingredient = Ingredient::query()->create(['name' => 'Milk', 'unit_id' => $unit->id, 'reorder_level' => 100, 'is_active' => true]);

    IngredientStock::query()->create([
        'branch_id' => $branch->id,
        'ingredient_id' => $ingredient->id,
        'stock_qty' => 40,
        'avg_cost' => 1,
    ]);

    $kitchen = User::factory()->role('kitchen-manager', $branch->id)->create();

    $response = $this->actingAs($kitchen, 'staff')->get('/admin');

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['alerts']['lowStock'])->toBe(1)
        ->and($props['lowStockItems'])->toHaveCount(1);
});

it('hides low stock on the dashboard from a role without inventory view', function (): void {
    $branch = Branch::factory()->create();
    $unit = Unit::query()->create(['name' => 'Gram', 'symbol' => 'g-'.uniqid(), 'type' => 'weight', 'factor' => 1]);
    $ingredient = Ingredient::query()->create(['name' => 'Milk', 'unit_id' => $unit->id, 'reorder_level' => 100, 'is_active' => true]);

    IngredientStock::query()->create([
        'branch_id' => $branch->id,
        'ingredient_id' => $ingredient->id,
        'stock_qty' => 40,
        'avg_cost' => 1,
    ]);

    $role = Role::findOrCreate('greeter', 'staff');
    $role->syncPermissions(['admin.access']);

    $user = User::factory()->create();
    $user->assignRole($role);

    $response = $this->actingAs($user, 'staff')->get('/admin');

    $response->assertOk();

    expect($response->viewData('page')['props']['alerts']['lowStock'])->toBe(0);
});

it('lets a cashier open the ui-kit showcase', function (): void {
    $cashier = User::factory()->role('cashier')->create();

    $response = $this->actingAs($cashier, 'staff')->get('/admin/ui-kit');

    $response->assertOk();
});
