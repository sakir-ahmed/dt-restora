<?php

declare(strict_types=1);

use App\Actions\Sales\CreateOrderAction;
use App\Actions\Sales\PayOrderAction;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\AggregatorChannel;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create([
        'code' => 'BR01',
        'vat_percent' => '5.00',
        'vat_inclusive' => false,
        'service_charge_percent' => '10.00',
    ]);

    $this->cashier = User::factory()->role('cashier', $this->branch->id)->create();
    $this->chef = User::factory()->role('kitchen-manager', $this->branch->id)->create();

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '200.00']);
    $this->drink = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '50.00']);

    $this->ingredient = Ingredient::factory()->create(['unit_id' => Unit::where('symbol', 'g')->firstOrFail()->id]);
    IngredientStock::query()->create(['branch_id' => $this->branch->id, 'ingredient_id' => $this->ingredient->id, 'stock_qty' => 10000, 'avg_cost' => 0.1]);
    Recipe::query()->create(['menu_item_id' => $this->drink->id])->items()->create(['ingredient_id' => $this->ingredient->id, 'qty' => 100]);

    Shift::query()->create(['branch_id' => $this->branch->id, 'user_id' => $this->cashier->id, 'terminal_code' => 'T01', 'opening_cash' => 1000, 'opened_at' => now()]);
});

function sentOrder(object $test): Order
{
    return app(CreateOrderAction::class)->execute(
        branch: $test->branch,
        type: OrderType::Takeaway,
        lines: [['menu_item_id' => $test->item->id, 'quantity' => 2]],
        cashier: $test->cashier,
    );
}

it('gives every new order one kitchen ticket holding its items', function (): void {
    $order = sentOrder($this);

    expect($order->tickets)->toHaveCount(1)
        ->and($order->tickets->first()->number)->toBe(1)
        ->and($order->items->every(fn ($item) => $item->kitchen_ticket_id === $order->tickets->first()->id))->toBeTrue();
});

it('adds items to a preparing order as a second ticket on the same invoice', function (): void {
    $order = sentOrder($this);
    $order->moveTickets(OrderStatus::Preparing);
    $firstTicket = $order->tickets()->first();

    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 3]]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('order.ticket_number', 2);

    $order->refresh();

    // One invoice; the old line is untouched and the bill covers both rounds: 400 + 150 = 550, 10% service, 5% VAT.
    expect(Order::query()->count())->toBe(1)
        ->and($order->items)->toHaveCount(2)
        ->and((float) $order->subtotal)->toBe(550.0)
        ->and((float) $order->total)->toBe(635.0) // 550 + 55 service + 30.25 VAT = 635.25, rounded
        ->and($order->tickets)->toHaveCount(2);

    // The first ticket is exactly as the kitchen left it; the new one waits as pending.
    $first = $order->tickets->first();
    $second = $order->tickets->last();

    expect($first->id)->toBe($firstTicket->id)
        ->and($first->status)->toBe(OrderStatus::Preparing)
        ->and($first->items)->toHaveCount(1)
        ->and($first->items->first()->menu_item_id)->toBe($this->item->id)
        ->and($second->status)->toBe(OrderStatus::Pending)
        ->and($second->items)->toHaveCount(1)
        ->and($second->items->first()->menu_item_id)->toBe($this->drink->id)
        // The order follows its least advanced ticket.
        ->and($order->kitchen_status)->toBe(OrderStatus::Pending);

    // Stock is taken once, for the new items only: 3 x 100 g.
    expect((float) IngredientStock::query()->first()->stock_qty)->toBe(9700.0);
});

it('shows each ticket as its own card on the kitchen display and moves them separately', function (): void {
    $order = sentOrder($this);
    $order->moveTickets(OrderStatus::Served);

    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]])
        ->assertSessionHasNoErrors();

    // The served first ticket has left the board; the added items are a new card, round 2 of 2.
    $this->actingAs($this->chef, 'staff')
        ->get(route('admin.kds.index'))
        ->assertInertia(fn ($page) => $page
            ->has('orders', 1)
            ->where('orders.0.order_id', $order->id)
            ->where('orders.0.ticket_no', 2)
            ->where('orders.0.rounds', 2)
            ->where('orders.0.status', 'pending')
            ->has('orders.0.items', 1));

    $ticket = $order->tickets()->where('number', 2)->first();

    foreach (['preparing', 'ready', 'served'] as $step) {
        $this->actingAs($this->chef, 'staff')
            ->patch(route('admin.kitchen-tickets.status', $ticket), ['status' => $step])
            ->assertSessionHas('success');
    }

    expect($ticket->fresh()->status)->toBe(OrderStatus::Served)
        ->and($order->fresh()->kitchen_status)->toBe(OrderStatus::Served)
        ->and($order->fresh()->tickets()->where('number', 1)->first()->status)->toBe(OrderStatus::Served);
});

it('moving one ticket leaves the other ticket of the invoice where it is', function (): void {
    $order = sentOrder($this);
    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]]);

    $first = $order->tickets()->where('number', 1)->first();

    $this->actingAs($this->chef, 'staff')
        ->patch(route('admin.kitchen-tickets.status', $first), ['status' => 'preparing'])
        ->assertSessionHas('success');

    expect($order->tickets()->where('number', 2)->first()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->kitchen_status)->toBe(OrderStatus::Pending);
});

it('moves every ticket when the whole order is moved from the orders list', function (): void {
    $order = sentOrder($this);
    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]]);

    $this->actingAs($this->cashier, 'staff')
        ->patch(route('admin.orders.status', $order), ['status' => 'served'])
        ->assertSessionHas('success');

    expect($order->tickets()->pluck('status')->map->value->unique()->all())->toBe(['served'])
        ->and($order->fresh()->kitchen_status)->toBe(OrderStatus::Served);
});

it('keeps a paid order closed to added items', function (): void {
    $order = sentOrder($this);
    app(PayOrderAction::class)->executeCash($order, 5000, $this->cashier);

    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]])
        ->assertSessionHasErrors('order');

    expect($order->fresh()->items)->toHaveCount(1);
});

it('keeps a delivery platform order closed to added items', function (): void {
    $order = sentOrder($this);
    $channel = AggregatorChannel::query()->create(['key' => 'foodpanda', 'name' => 'Foodpanda', 'commission_percent' => '25.00', 'webhook_secret' => 'secret-token', 'is_active' => true]);
    $order->update(['aggregator_channel_id' => $channel->id]);

    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]])
        ->assertSessionHasErrors('order');
});

it('keeps the kitchen role from adding items', function (): void {
    $order = sentOrder($this);

    $this->actingAs($this->chef, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]])
        ->assertForbidden();
});

it('prints a kitchen slip with only the items of one ticket', function (): void {
    $order = sentOrder($this);
    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 1]]]);

    $second = $order->tickets()->where('number', 2)->first();

    $slip = $this->actingAs($this->cashier, 'staff')
        ->get(route('admin.orders.kitchen-token', ['order' => $order, 'ticket' => $second->id]))
        ->assertOk()
        ->getContent();

    expect($slip)->toContain($this->drink->name)->and($slip)->not->toContain($this->item->name);
});

it('adds items through the staff API and lists the kitchen tickets', function (): void {
    $order = sentOrder($this);
    $token = User::factory()->role('cashier', $this->branch->id)->create()->createToken('staff', ['staff'])->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/branches/{$this->branch->id}/orders/{$order->id}/items", ['items' => [['menu_item_id' => $this->drink->id, 'quantity' => 2]]])
        ->assertOk()
        ->assertJsonPath('data.kitchen_tickets.1.number', 2)
        ->assertJsonCount(2, 'data.kitchen_tickets')
        ->assertJsonCount(2, 'data.items');
});

it('backfills a ticket for an order that has none', function (): void {
    $order = sentOrder($this);
    $order->tickets()->delete();

    expect($order->fresh()->ensureTickets())->toHaveCount(1)
        ->and($order->fresh()->items()->whereNull('kitchen_ticket_id')->count())->toBe(0);
});
