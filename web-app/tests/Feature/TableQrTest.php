<?php

declare(strict_types=1);

use App\Actions\Sales\CreateOrderAction;
use App\Actions\Sales\PayOrderAction;
use App\Enums\OrderChannel;
use App\Enums\OrderKind;
use App\Enums\OrderType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->table = RestaurantTable::factory()->create(['branch_id' => $this->branch->id, 'name' => 'T3', 'area' => 'Garden']);
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();
    $this->cashier = User::factory()->role('cashier', $this->branch->id)->create();

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '200.00']);

    RateLimiter::clear('table-order:'.$this->table->id);
});

function qrUrl(RestaurantTable $table): string
{
    return route('web.table', ['token' => $table->fresh()->qr_token]);
}

it('gives every table its own long secret and never sends it to the POS', function (): void {
    $other = RestaurantTable::factory()->create(['branch_id' => $this->branch->id]);

    expect($this->table->qr_token)->toHaveLength(32)
        ->and($this->table->qr_token)->not->toBe($other->qr_token)
        ->and($this->table->qr_generated_at)->not->toBeNull()
        ->and($this->table->toArray())->not->toHaveKey('qr_token');

    $this->actingAs($this->cashier, 'staff')->get('/admin/pos')->assertInertia(fn ($page) => $page
        ->missing('tables.0.qr_token'));
});

it('opens the menu book for the table behind a valid code', function (): void {
    $this->get(qrUrl($this->table))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertInertia(fn ($page) => $page
            ->component('web/TableMenu')
            ->where('table.name', 'T3')
            ->where('table.area', 'Garden')
            ->has('categories.0.items', 1));
});

it('turns away codes that are wrong, old or made up', function (string $path): void {
    $this->get($path)->assertStatus(410)->assertInertia(fn ($page) => $page->component('web/TableInvalid'));
})->with([
    'wrong secret' => fn () => '/t/'.str_repeat('a', 32),
    'table name' => fn () => '/q/T3',
    'branch and table name' => fn () => '/q/br01/T3',
]);

it('stops an old printed code the moment a new one is issued', function (): void {
    $oldUrl = qrUrl($this->table);

    $this->actingAs($this->manager, 'staff')
        ->post(route('admin.tables.qr.regenerate', $this->table))
        ->assertRedirect();

    expect(qrUrl($this->table))->not->toBe($oldUrl);

    $this->get($oldUrl)->assertStatus(410);
    $this->get(qrUrl($this->table))->assertOk();
});

it('refuses an order sent with an old code', function (): void {
    $oldToken = $this->table->qr_token;

    $this->table->rotateQrToken();

    $this->post(route('web.table.order', ['token' => $oldToken]), [
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertStatus(410);

    expect(Order::query()->count())->toBe(0);
});

it('lets only users who manage tables issue codes', function (): void {
    $this->actingAs($this->cashier, 'staff')
        ->post(route('admin.tables.qr.regenerate', $this->table))
        ->assertForbidden();

    $otherBranch = Branch::factory()->create(['code' => 'BR02']);
    $foreign = RestaurantTable::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($this->manager, 'staff')
        ->post(route('admin.tables.qr.regenerate', $foreign))
        ->assertNotFound();
});

it('renews every code of the branch at once', function (): void {
    $second = RestaurantTable::factory()->create(['branch_id' => $this->branch->id]);
    $before = [$this->table->qr_token, $second->qr_token];

    $this->actingAs($this->manager, 'staff')->post(route('admin.tables.qr.regenerate-all'))->assertRedirect();

    expect($this->table->fresh()->qr_token)->not->toBe($before[0])
        ->and($second->fresh()->qr_token)->not->toBe($before[1]);
});

it('places a guest order for a manager to approve, with the table already chosen', function (): void {
    Event::fake();

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), [
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
        'guest_name' => 'Rafi',
        'note' => 'less spicy',
    ])->assertRedirect(qrUrl($this->table))->assertSessionHas('table_order');

    $order = Order::query()->firstOrFail();

    expect($order->channel)->toBe(OrderChannel::Qr)
        ->and($order->order_type)->toBe(OrderType::DineIn)
        ->and($order->restaurant_table_id)->toBe($this->table->id)
        ->and($order->status->value)->toBe('pending')
        ->and($order->approval_status)->toBe('awaiting')
        ->and($order->invoice_no)->toContain('-QR-')
        ->and($order->note)->toContain('Rafi')->toContain('less spicy')
        ->and($this->table->fresh()->status)->toBe('occupied');

    // The confirmation shows once on the menu page.
    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('placed.invoice_no', $order->invoice_no));
    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('placed', null));
});

it('shows the guest order on the POS with its table', function (): void {
    $payload = ['items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]]];

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), $payload)->assertRedirect();
    $first = Order::query()->firstOrFail();
    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), $payload)->assertRedirect();

    $this->actingAs($this->cashier, 'staff')->get('/admin/pos')->assertInertia(fn ($page) => $page
        ->where('openOrders.0.restaurant_table_id', $this->table->id)
        ->where('tables.0.status', 'occupied'));

    $this->actingAs($this->cashier, 'staff')->getJson('/admin/orders/incoming?after='.$first->id)
        ->assertOk()
        ->assertJsonPath('orders.0.channel', 'qr');
});

it('limits how fast one table can send orders', function (): void {
    $payload = ['items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]]];

    foreach (range(1, 6) as $ignored) {
        $this->post(route('web.table.order', ['token' => $this->table->qr_token]), $payload)->assertRedirect();
    }

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), $payload)->assertSessionHasErrors('order');

    expect(Order::query()->count())->toBe(6);
});

it('rejects dishes from another branch or ones that are sold out', function (): void {
    $otherBranch = Branch::factory()->create(['code' => 'BR02']);
    $foreignItem = MenuItem::factory()->create([
        'branch_id' => $otherBranch->id,
        'menu_category_id' => MenuCategory::factory()->create(['branch_id' => $otherBranch->id])->id,
    ]);
    $soldOut = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->item->menu_category_id,
        'is_available' => false,
    ]);

    foreach ([$foreignItem, $soldOut] as $item) {
        $this->post(route('web.table.order', ['token' => $this->table->qr_token]), [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items.0.menu_item_id');
    }

    expect(Order::query()->count())->toBe(0);
});

it('does not let the website checkout pick a table', function (): void {
    $this->post('/checkout', [
        'order_type' => 'dine_in',
        'payment_method' => 'cod',
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('order_type');
});

it('also answers the in-page requests the menu book makes', function (): void {
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'];

    $this->withHeaders($headers)->get(qrUrl($this->table))->assertOk()->assertJsonPath('component', 'web/TableMenu');

    $this->withHeaders($headers)
        ->post(route('web.table.order', ['token' => $this->table->qr_token]), ['items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]]])
        ->assertRedirect(qrUrl($this->table));

    $this->withHeaders($headers)->get('/t/'.str_repeat('b', 32))->assertStatus(410);
});

function qrPayload(MenuItem $item): array
{
    return ['items' => [['menu_item_id' => $item->id, 'quantity' => 1]]];
}

it('keeps a table open to more QR orders but closes it once someone else is seated', function (): void {
    // First guest orders by QR: the table becomes occupied, and more phones at the table can still order.
    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), qrPayload($this->item))->assertRedirect();

    expect($this->table->fresh()->status)->toBe('occupied');

    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.allowed', true));
    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), qrPayload($this->item))->assertRedirect();

    // A waiter then rings up a normal order on the same table: the QR code stops taking orders.
    $shift = Shift::query()->create(['branch_id' => $this->branch->id, 'user_id' => $this->cashier->id, 'terminal_code' => 'T01', 'opening_cash' => 0, 'opened_at' => now()]);

    app(CreateOrderAction::class)->execute(
        branch: $this->branch,
        type: OrderType::DineIn,
        lines: [['menu_item_id' => $this->item->id, 'quantity' => 1]],
        cashier: $this->cashier,
        shift: $shift,
        tableId: $this->table->id,
        terminalCode: 'T01',
    );

    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page
        ->where('ordering.allowed', false)
        ->where('ordering.reason', 'occupied'));

    $count = Order::query()->count();

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), qrPayload($this->item))->assertSessionHasErrors('order');

    expect(Order::query()->count())->toBe($count);
});

it('treats a table marked occupied with nothing open as taken, and opens it once freed', function (): void {
    $this->table->update(['status' => 'occupied']);

    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.reason', 'occupied'));

    $this->table->update(['status' => 'free']);

    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.allowed', true));
});

function booking(RestaurantTable $table, string $status, int $startsInMinutes, int $lastsMinutes, array $extra = []): Reservation
{
    $reservation = Reservation::query()->create($extra + [
        'branch_id' => $table->branch_id,
        'guest_name' => 'Karim',
        'party_size' => 4,
        'starts_at' => now()->addMinutes($startsInMinutes),
        'ends_at' => now()->addMinutes($startsInMinutes + $lastsMinutes),
        'status' => $status,
    ]);

    $reservation->tables()->attach($table->id, ['position' => 0]);

    return $reservation;
}

it('closes the table for QR orders while a booking is waiting for its guests', function (): void {
    booking($this->table, Reservation::BOOKED, -10, 90);

    $this->get(qrUrl($this->table))->assertOk()->assertInertia(fn ($page) => $page
        ->where('ordering.allowed', false)
        ->where('ordering.reason', 'reserved')
        ->has('ordering.until')
        ->has('categories'));

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), qrPayload($this->item))->assertSessionHasErrors('order');

    expect(Order::query()->count())->toBe(0);
});

it('only blocks while a booking is running and its guests have not been seated', function (): void {
    $booked = booking($this->table, Reservation::BOOKED, 120, 60);

    // Later today: not yet.
    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.allowed', true));

    // Over, cancelled or no show: free.
    $booked->update(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.allowed', true));

    foreach ([Reservation::CANCELLED, Reservation::NO_SHOW, Reservation::COMPLETED] as $status) {
        $booked->update(['starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(), 'status' => $status]);
        $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.allowed', true));
    }

    // Seated guests are the ones sitting there, so the table opens for them.
    $booked->update(['status' => Reservation::SEATED]);
    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), qrPayload($this->item))->assertRedirect();
});

it('does not take the booking from another table into account', function (): void {
    $other = RestaurantTable::factory()->create(['branch_id' => $this->branch->id]);
    booking($other, Reservation::BOOKED, -10, 90);

    $this->get(qrUrl($this->table))->assertInertia(fn ($page) => $page->where('ordering.allowed', true));
});

it('keeps a guest order off the kitchen board until a manager sends it', function (): void {
    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), [
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertRedirect();

    $order = Order::query()->firstOrFail();

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 0));

    // Nobody can move it along the kitchen steps meanwhile, and a cashier cannot approve it.
    $this->actingAs($this->cashier, 'staff')->patch(route('admin.orders.status', $order), ['status' => 'preparing'])->assertSessionHas('error');
    $this->actingAs($this->cashier, 'staff')->post(route('admin.orders.approve', $order))->assertForbidden();

    $this->actingAs($this->manager, 'staff')->post(route('admin.orders.approve', $order))->assertSessionHas('success');

    expect($order->fresh()->approval_status)->toBe('approved');

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 1)->where('orders.0.table.name', 'T3'));
});

it('lets a manager cancel an awaiting guest order with a reason', function (): void {
    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), [
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertRedirect();

    $order = Order::query()->firstOrFail();

    $this->actingAs($this->manager, 'staff')
        ->post(route('admin.orders.delete', $order), ['reason_code' => 'duplicate_order'])
        ->assertSessionHas('success');

    expect($order->fresh()->status->value)->toBe('void');
});

it('serves a ready order from the kitchen board and shows who prepared it', function (): void {
    $order = app(CreateOrderAction::class)->execute($this->branch, OrderType::DineIn, [['menu_item_id' => $this->item->id, 'quantity' => 1]], tableId: $this->table->id);

    $this->actingAs($this->manager, 'staff')->patch(route('admin.orders.status', $order), ['status' => 'preparing'])->assertSessionHas('success');
    expect($order->fresh()->prepared_by)->toBe($this->manager->id);

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))
        ->assertInertia(fn ($page) => $page->where('orders.0.preparer.name', $this->manager->name));

    $this->actingAs($this->manager, 'staff')->patch(route('admin.orders.status', $order), ['status' => 'ready'])->assertSessionHas('success');
    $this->actingAs($this->manager, 'staff')->patch(route('admin.orders.status', $order), ['status' => 'served'])->assertSessionHas('success');

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))
        ->assertInertia(fn ($page) => $page->has('orders', 0));
});

it('blocks a pre-order only dish on a table order and on the POS for a cashier, but lets a manager sell it', function (): void {
    $this->item->category()->update(['pre_order_required' => true, 'pre_order_lead_hours' => 6]);

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), [
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertSessionHasErrors('items');

    expect(Order::query()->count())->toBe(0);

    $payload = ['order_type' => 'takeaway', 'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]], 'terminal' => 'T01'];

    $this->actingAs($this->cashier, 'staff')->post('/admin/orders', $payload)->assertSessionHasErrors('items');
    $this->actingAs($this->manager, 'staff')->post('/admin/orders', $payload)->assertSessionHasNoErrors();

    expect(Order::query()->count())->toBe(1);
});

it('lets a dish opt out of its category pre-order rule', function (): void {
    $this->item->category()->update(['pre_order_required' => true]);
    $this->item->update(['pre_order_required' => false]);
    MenuItem::forgetCategoryRules();

    $this->post(route('web.table.order', ['token' => $this->table->qr_token]), [
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
    ])->assertSessionHasNoErrors();
});

it('takes a website pre-order for a required dish only far enough ahead, and keeps it off the kitchen board until released', function (): void {
    $this->item->update(['pre_order_required' => true, 'pre_order_lead_hours' => 12]);
    $order = fn (string $when) => app(CreateOrderAction::class)->execute(
        $this->branch,
        OrderType::Takeaway,
        [['menu_item_id' => $this->item->id, 'quantity' => 1]],
        scheduledFor: new DateTimeImmutable($when),
        kind: OrderKind::Preorder,
    );

    expect(fn () => $order('+3 hours'))->toThrow(ValidationException::class);

    $placed = $order('+2 days');

    expect($placed->kind)->toBe(OrderKind::Preorder);

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))->assertInertia(fn ($page) => $page->has('orders', 0));

    // Not yet due, so the kitchen steps are locked; the manager hands it over early.
    $this->actingAs($this->manager, 'staff')->patch(route('admin.orders.status', $placed), ['status' => 'preparing'])->assertSessionHas('error');
    $this->actingAs($this->cashier, 'staff')->post(route('admin.orders.release', $placed))->assertForbidden();
    $this->actingAs($this->manager, 'staff')->post(route('admin.orders.release', $placed))->assertSessionHas('success');

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))->assertInertia(fn ($page) => $page->has('orders', 1));

    // The release is by the clock too: a pre-order due within the lead time shows without a click.
    $soon = app(CreateOrderAction::class)->execute($this->branch, OrderType::Takeaway, [['menu_item_id' => $this->item->id, 'quantity' => 1]], scheduledFor: new DateTimeImmutable('+30 minutes'), kind: OrderKind::Preorder, overridePreorder: true);

    $this->actingAs($this->manager, 'staff')->get(route('admin.kds.index'))->assertInertia(fn ($page) => $page->has('orders', 2));
    expect($soon->fresh()->released_at)->toBeNull();
});

it('gives the approve permission to managers and not to cashiers', function (): void {
    expect($this->manager->can('orders.approve'))->toBeTrue()
        ->and($this->cashier->can('orders.approve'))->toBeFalse();
});

it('filters the orders list by kind, including regular orders', function (): void {
    $regular = app(CreateOrderAction::class)->execute($this->branch, OrderType::Takeaway, [['menu_item_id' => $this->item->id, 'quantity' => 1]]);
    $preorder = app(CreateOrderAction::class)->execute($this->branch, OrderType::Takeaway, [['menu_item_id' => $this->item->id, 'quantity' => 1]], scheduledFor: new DateTimeImmutable('+2 days'), kind: OrderKind::Preorder);

    $ids = fn (string $kind) => collect($this->actingAs($this->manager, 'staff')->get(route('admin.orders.index', ['kind' => $kind]))->viewData('page')['props']['orders'])->pluck('id')->all();

    expect($ids('regular'))->toBe([$regular->id])
        ->and($ids('preorder'))->toBe([$preorder->id]);
});

it('filters the orders list by cashier, cook, waiter, table, channel, type, payment and date', function (): void {
    $make = fn (array $args = []) => app(CreateOrderAction::class)->execute($this->branch, $args['type'] ?? OrderType::Takeaway, [['menu_item_id' => $this->item->id, 'quantity' => 1]], ...($args['with'] ?? []));
    $cook = User::factory()->role('kitchen-manager', $this->branch->id)->create();

    $a = $make(['type' => OrderType::DineIn, 'with' => ['cashier' => $this->cashier, 'waiter' => $this->manager, 'tableId' => $this->table->id]]);
    $a->update(['prepared_by' => $cook->id]);
    $b = $make();
    app(PayOrderAction::class)->executeCash($b, 5000, $this->cashier);
    $c = $make(['with' => ['channel' => OrderChannel::Website]]);
    $c->forceFill(['created_at' => now()->subDays(10)])->save();

    $ids = fn (array $query) => collect($this->actingAs($this->manager, 'staff')->get(route('admin.orders.index', $query))->viewData('page')['props']['orders'])->pluck('id')->sort()->values()->all();

    expect($ids(['cashier' => $this->cashier->id]))->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($ids(['cook' => $cook->id]))->toBe([$a->id])
        ->and($ids(['waiter' => $this->manager->id]))->toBe([$a->id])
        ->and($ids(['table' => $this->table->id]))->toBe([$a->id])
        ->and($ids(['channel' => 'website']))->toBe([$c->id])
        ->and($ids(['type' => 'dine_in']))->toBe([$a->id])
        ->and($ids(['payment' => 'paid']))->toBe([$b->id])
        ->and($ids(['payment' => 'unpaid']))->toBe(collect([$a->id, $c->id])->sort()->values()->all())
        ->and($ids(['from' => now()->subDay()->toDateString()]))->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($ids(['to' => now()->subDays(5)->toDateString()]))->toBe([$c->id]);

    $props = $this->actingAs($this->manager, 'staff')->get(route('admin.orders.index'))->viewData('page')['props'];
    expect(collect($props['staffOptions'])->pluck('id')->all())->toContain($this->cashier->id, $cook->id)
        ->and(collect($props['tableOptions'])->pluck('id')->all())->toContain($this->table->id);
});
