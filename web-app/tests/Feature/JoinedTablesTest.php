<?php

declare(strict_types=1);

use App\Actions\Sales\ApproveOrderVoidAction;
use App\Actions\Sales\CreateOrderAction;
use App\Actions\Sales\PayOrderAction;
use App\Actions\Sales\RequestOrderVoidAction;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->cashier = User::factory()->role('cashier', $this->branch->id)->create();
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();
    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '100.00']);

    $this->t10 = RestaurantTable::factory()->create(['branch_id' => $this->branch->id, 'name' => 'T-10', 'seats' => 4, 'status' => 'free']);
    $this->t11 = RestaurantTable::factory()->create(['branch_id' => $this->branch->id, 'name' => 'T-11', 'seats' => 6, 'status' => 'free']);
    $this->t12 = RestaurantTable::factory()->create(['branch_id' => $this->branch->id, 'name' => 'T-12', 'seats' => 2, 'status' => 'free']);
});

function dineIn(object $test, ?array $tables, ?int $tableId = null, ?Order $revising = null): Order
{
    return app(CreateOrderAction::class)->execute(
        branch: $test->branch,
        type: OrderType::DineIn,
        lines: [['menu_item_id' => $test->item->id, 'quantity' => 1]],
        cashier: $test->cashier,
        tableId: $tableId,
        tables: $tables,
        revising: $revising,
    );
}

it('seats one order at several joined tables and keeps the seat numbers', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id, 'seats' => [2, 1]], ['id' => $this->t11->id, 'seats' => []]]);

    expect($order->restaurant_table_id)->toBe($this->t10->id)
        ->and($order->tableIds())->toBe([$this->t10->id, $this->t11->id])
        ->and($this->t10->fresh()->status)->toBe('occupied')
        ->and($this->t11->fresh()->status)->toBe('occupied')
        ->and($this->t12->fresh()->status)->toBe('free')
        ->and($order->tableLinks()->where('restaurant_table_id', $this->t10->id)->first()->seats)->toBe([1, 2]);

    expect(Order::query()->with('tableLinks.restaurantTable')->find($order->id)->tableLabel())->toBe('T-10, T-11 - Seats 1, 2');
});

it('still seats an order at one table the way it always did', function (): void {
    $order = dineIn($this, null, $this->t12->id);

    expect($order->tableIds())->toBe([$this->t12->id])
        ->and($this->t12->fresh()->status)->toBe('occupied')
        ->and(Order::query()->with('tableLinks.restaurantTable')->find($order->id)->tableLabel())->toBe('T-12');
});

it('keeps every joined table when the order is edited without naming tables', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id], ['id' => $this->t11->id]]);

    $edited = dineIn($this, null, $this->t10->id, $order);

    expect($edited->tableIds())->toBe([$this->t10->id, $this->t11->id])
        ->and($this->t11->fresh()->status)->toBe('occupied');
});

it('frees a table the order moved away from and occupies the new one', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id], ['id' => $this->t11->id]]);

    dineIn($this, [['id' => $this->t11->id], ['id' => $this->t12->id]], null, $order);

    expect($this->t10->fresh()->status)->toBe('free')
        ->and($this->t11->fresh()->status)->toBe('occupied')
        ->and($this->t12->fresh()->status)->toBe('occupied')
        ->and($order->fresh()->tableIds())->toBe([$this->t11->id, $this->t12->id]);
});

it('frees every joined table when the order is deleted', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id], ['id' => $this->t11->id]]);

    $pending = app(RequestOrderVoidAction::class)->execute($order, $this->manager, 'customer_changed_mind');
    app(ApproveOrderVoidAction::class)->execute($pending, $this->manager);

    expect($this->t10->fresh()->status)->toBe('free')->and($this->t11->fresh()->status)->toBe('free');
});

it('frees all joined tables together when the paid table is released from the POS', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id], ['id' => $this->t11->id]]);
    app(PayOrderAction::class)->executeCash($order, 500, $this->cashier);

    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.pos.tables.release', $this->t11))
        ->assertSessionHasNoErrors();

    expect($this->t10->fresh()->status)->toBe('free')->and($this->t11->fresh()->status)->toBe('free');
});

it('takes joined tables and seats from the POS order form', function (): void {
    $this->actingAs($this->cashier, 'staff')->post(route('admin.orders.store'), [
        'order_type' => 'dine_in',
        'restaurant_table_id' => $this->t10->id,
        'tables' => [['id' => $this->t10->id, 'seats' => [1, 2]], ['id' => $this->t11->id, 'seats' => [3]]],
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertSessionHasNoErrors();

    $order = Order::query()->latest('id')->first();

    expect($order->tableIds())->toBe([$this->t10->id, $this->t11->id])
        ->and($order->tableLinks()->where('restaurant_table_id', $this->t11->id)->first()->seats)->toBe([3]);
});

it('names the table of an older order that has no table links', function (): void {
    $order = dineIn($this, null, $this->t12->id);
    $order->tableLinks()->delete();

    expect(Order::query()->with('tableLinks.restaurantTable')->find($order->id)->tableLabel())->toBe('T-12');

    $this->actingAs($this->cashier, 'staff')->get(route('admin.orders.receipt', $order))->assertOk()->assertSee('T-12');
});

it('prints the joined tables and seats on the invoice and the kitchen slip', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id, 'seats' => [1, 2]], ['id' => $this->t11->id]]);

    $this->actingAs($this->cashier, 'staff')->get(route('admin.orders.receipt', $order))->assertOk()->assertSee('Table T-10, T-11 - Seats 1, 2');
    $this->actingAs($this->cashier, 'staff')->get(route('admin.orders.kitchen-token', $order))->assertOk()->assertSee('Table T-10, T-11 - Seats 1, 2');
});

it('shows a running table, a billed table and a booked table on the Table view', function (): void {
    $running = dineIn($this, [['id' => $this->t10->id]]);
    $billed = dineIn($this, [['id' => $this->t11->id]]);
    app(PayOrderAction::class)->executeCash($billed, 500, $this->cashier);

    $this->actingAs($this->cashier, 'staff')
        ->get(route('admin.pos.floor'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/pos/Floor')
            ->where('tables', fn ($tables) => collect($tables)->pluck('state', 'name')->all() === ['T-10' => 'running', 'T-11' => 'billed', 'T-12' => 'available']));

    expect($running->id)->not->toBe($billed->id);
});

it('reads a table as running while any bill on it is unpaid, even if a newer bill is paid', function (): void {
    $open = dineIn($this, [['id' => $this->t10->id]]);
    $paid = dineIn($this, [['id' => $this->t10->id]]);
    app(PayOrderAction::class)->executeCash($paid, 500, $this->cashier);

    $this->actingAs($this->cashier, 'staff')
        ->get(route('admin.pos.floor'))
        ->assertInertia(fn ($page) => $page
            ->where('tables', fn ($tables) => collect($tables)->firstWhere('name', 'T-10')['state'] === 'running'
                && collect($tables)->firstWhere('name', 'T-10')['last_order']['id'] === $open->id));
});

it('gives the Table view the full order of a busy table and what the cashier may do with it', function (): void {
    $order = dineIn($this, [['id' => $this->t10->id, 'seats' => [1]]]);

    $this->actingAs($this->cashier, 'staff')
        ->get(route('admin.pos.floor'))
        ->assertInertia(fn ($page) => $page
            ->has("orders.{$order->id}.items", 1)
            ->where("orders.{$order->id}.table_label", 'T-10 - Seats 1')
            ->where('canPay', true)
            ->has('payMethods')
            ->has('serverNow'));
});

it('tells the Table view which table carries the bill of joined tables', function (): void {
    $order = dineIn($this, [['id' => $this->t11->id], ['id' => $this->t10->id]]);

    $this->actingAs($this->cashier, 'staff')
        ->get(route('admin.pos.floor'))
        ->assertInertia(fn ($page) => $page
            ->where('tables', fn ($tables) => collect($tables)->firstWhere('name', 'T-10')['last_order']['primary_table_id'] === $this->t11->id
                && count(collect($tables)->firstWhere('name', 'T-10')['last_order']['table_ids']) === 2));

    expect($order->restaurant_table_id)->toBe($this->t11->id);
});
