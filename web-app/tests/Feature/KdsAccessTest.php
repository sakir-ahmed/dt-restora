<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);

    // Exactly what an owner ticks on the Roles screen: Kitchen -> Use the Kitchen Display.
    // The screen always adds the required admin.access on top.
    $chefRole = Role::findOrCreate('chef', 'staff');
    $chefRole->syncPermissions(['admin.access', 'kds.access']);

    $this->chef = User::factory()->role('chef', $this->branch->id)->create();

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $category->id,
        'base_price' => '200.00',
    ]);
});

function kitchenOrderId(object $test): int
{
    $orderId = DB::table('orders')->insertGetId([
        'branch_id' => $test->branch->id,
        'invoice_no' => 'KDS-'.uniqid(),
        'order_type' => 'takeaway',
        'channel' => 'pos',
        'status' => 'pending',
        'subtotal' => '200.00',
        'total' => '200.00',
        'vat_amount' => '0.00',
        'food_cost' => '0.0000',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'menu_item_id' => $test->item->id,
        'name' => 'Test Item',
        'unit_price' => '200.00',
        'quantity' => 1,
        'line_total' => '200.00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $orderId;
}

it('shows the kitchen board to a chef role holding only kds access', function (): void {
    $orderId = kitchenOrderId($this);

    $this->actingAs($this->chef, 'staff')
        ->get(route('admin.kds.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('orders.0.id', $orderId)->where('orders.0.status', 'pending'));
});

it('lets the chef run every kitchen step from the display', function (): void {
    $orderId = kitchenOrderId($this);

    // Start preparing stamps the chef on the ticket.
    $this->actingAs($this->chef, 'staff')
        ->patch(route('admin.orders.status', $orderId), ['status' => 'preparing'])
        ->assertSessionHas('success');

    $order = DB::table('orders')->where('id', $orderId)->first();

    expect($order->kitchen_status)->toBe('preparing')
        ->and($order->status)->toBe('preparing')
        ->and($order->prepared_by)->toBe($this->chef->id);

    $this->actingAs($this->chef, 'staff')
        ->patch(route('admin.orders.status', $orderId), ['status' => 'ready'])
        ->assertSessionHas('success');

    $this->actingAs($this->chef, 'staff')
        ->patch(route('admin.orders.status', $orderId), ['status' => 'served'])
        ->assertSessionHas('success');

    $order = DB::table('orders')->where('id', $orderId)->first();

    expect($order->kitchen_status)->toBe('served');

    // Served tickets leave the board.
    $this->actingAs($this->chef, 'staff')
        ->get(route('admin.kds.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('keeps the payment step away from the kitchen role', function (): void {
    $orderId = kitchenOrderId($this);

    $this->actingAs($this->chef, 'staff')
        ->patch(route('admin.orders.status', $orderId), ['status' => 'paid'])
        ->assertForbidden();

    expect(DB::table('orders')->where('id', $orderId)->value('kitchen_status'))->toBe('pending');
});

it('keeps the display closed to a role without kds access', function (): void {
    $role = Role::findOrCreate('cleaner', 'staff');
    $role->syncPermissions(['admin.access']);

    $user = User::factory()->role('cleaner', $this->branch->id)->create();

    $this->actingAs($user, 'staff')->get(route('admin.kds.index'))->assertForbidden();
});

it('lets the standard kitchen-manager role run the display', function (): void {
    // kitchen-manager carries kds.access but not orders.create, like the chef role.
    $orderId = kitchenOrderId($this);
    $manager = User::factory()->role('kitchen-manager', $this->branch->id)->create();

    $this->actingAs($manager, 'staff')
        ->patch(route('admin.orders.status', $orderId), ['status' => 'preparing'])
        ->assertSessionHas('success');
});

it('still lets order creators move the kitchen steps', function (): void {
    // A cashier holds orders.create without kds.access; the status endpoint stays open to them.
    $orderId = kitchenOrderId($this);
    $cashier = User::factory()->role('cashier', $this->branch->id)->create();

    $this->actingAs($cashier, 'staff')
        ->patch(route('admin.orders.status', $orderId), ['status' => 'preparing'])
        ->assertSessionHas('success');
});
