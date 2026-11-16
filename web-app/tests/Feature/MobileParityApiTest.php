<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Preorder;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class, PaymentMethodSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01', 'vat_percent' => '0.00', 'service_charge_percent' => '0.00']);
    $this->owner = User::factory()->role('owner')->create();
    $this->token = $this->owner->createToken('staff', ['staff'])->plainTextToken;

    // Exactly what an owner ticks on the Roles screen: Kitchen -> Use the Kitchen Display.
    $chefRole = Role::findOrCreate('chef', 'staff');
    $chefRole->syncPermissions(['admin.access', 'kds.access']);
    $this->chef = User::factory()->role('chef', $this->branch->id)->create();
    $this->chefToken = $this->chef->createToken('chef', ['staff'])->plainTextToken;

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $category->id,
        'base_price' => '200.00',
        'is_available' => true,
    ]);
    $this->base = '/api/v1/branches/'.$this->branch->id;
});

/** Laravel's auth guard caches the first token's user, so switching staff mid-test needs a flush. */
function asStaff(TestCase $test, string $token): object
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

function apiOrderId(TestCase $test, string $key = 'o1'): int
{
    return asStaff($test, $test->token)
        ->withHeader('Idempotency-Key', $key)
        ->postJson($test->base.'/orders', [
            'order_type' => 'takeaway',
            'items' => [['menu_item_id' => $test->item->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
}

// ------------------------------------------------------------ kitchen display

it('lets a kitchen-only role run the kitchen steps through the api', function (): void {
    $id = apiOrderId($this, 'k1');

    asStaff($this, $this->chefToken)
        ->patchJson($this->base.'/orders/'.$id.'/status', ['status' => 'preparing'])
        ->assertOk()
        ->assertJsonPath('data.kitchen_status', 'preparing');

    expect(Order::query()->find($id)->prepared_by)->toBe($this->chef->id);
});

it('keeps marking paid with the order permission alone', function (): void {
    $id = apiOrderId($this, 'k2');

    asStaff($this, $this->chefToken)
        ->patchJson($this->base.'/orders/'.$id.'/status', ['status' => 'paid'])
        ->assertForbidden();

    asStaff($this, $this->token)
        ->patchJson($this->base.'/orders/'.$id.'/status', ['status' => 'paid'])
        ->assertOk();

    expect(Order::query()->find($id)->status->value)->toBe('paid');
});

it('exposes the kitchen journey on the order payload', function (): void {
    $id = apiOrderId($this, 'k3');

    asStaff($this, $this->token)
        ->getJson($this->base.'/orders/'.$id)
        ->assertOk()
        ->assertJsonPath('data.kitchen_status', 'pending')
        ->assertJsonPath('data.next_statuses', ['preparing', 'ready', 'served']);
});

// ------------------------------------------------------------ order edit

it('edits an open order through the api', function (): void {
    $id = apiOrderId($this, 'e1');

    asStaff($this, $this->token)
        ->putJson($this->base.'/orders/'.$id, [
            'order_type' => 'takeaway',
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
        ])->assertOk()
        ->assertJsonPath('data.total', '400.00');

    expect(Order::query()->find($id)->items)->toHaveCount(1)
        ->and((int) Order::query()->find($id)->items->first()->quantity)->toBe(2);
});

it('revises a paid order through the api and keeps it paid', function (): void {
    $id = asStaff($this, $this->token)
        ->withHeader('Idempotency-Key', 'e2')
        ->postJson($this->base.'/orders', [
            'order_type' => 'takeaway',
            'customer_mobile' => '01712345678',
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->withHeader('Idempotency-Key', 'e2p')
        ->postJson($this->base.'/orders/'.$id.'/payments', ['tendered' => 200])
        ->assertOk();

    // A bigger bill on a paid sale: the rest goes on the customer's due.
    asStaff($this, $this->token)
        ->putJson($this->base.'/orders/'.$id, [
            'order_type' => 'takeaway',
            'customer_mobile' => '01712345678',
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
        ])->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.total', '400.00')
        ->assertJsonPath('data.is_due', true);
});

// ------------------------------------------------------------ dashboard

it('opens the dashboard to every staff role and gates the figures', function (): void {
    $hostRole = Role::findOrCreate('greeter', 'staff');
    $hostRole->syncPermissions(['admin.access', 'reservations.view']);
    $host = User::factory()->role('greeter', $this->branch->id)->create();
    $hostToken = $host->createToken('host', ['staff'])->plainTextToken;

    apiOrderId($this, 'd1');

    $limited = asStaff($this, $hostToken)->getJson('/api/v1/dashboard')->assertOk();
    expect($limited->json('data.can_view_metrics'))->toBeFalse()
        ->and($limited->json('data.summary.net_sales'))->toBe('0.00')
        ->and($limited->json('data.can_view_reservations'))->toBeTrue();

    $full = asStaff($this, $this->token)->getJson('/api/v1/dashboard')->assertOk();
    expect($full->json('data.can_view_metrics'))->toBeTrue()
        ->and($full->json('data.summary.orders'))->toBe(1)
        ->and($full->json('data.waiting_preorders'))->toBeArray();
});

// ------------------------------------------------------------ pre-orders

it('finds pre-orders by search and source through the api', function (): void {
    foreach ([['Rahim', 'staff'], ['Karim', 'public']] as [$guest, $source]) {
        Preorder::query()->create([
            'branch_id' => $this->branch->id,
            'order_no' => 'PRE-'.uniqid(),
            'source' => $source,
            'customer_name' => $guest,
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200]],
            'total' => 200,
            'expected_at' => now()->addDay(),
        ]);
    }

    expect(asStaff($this, $this->token)->getJson($this->base.'/preorders?q=rahi')->json('data.preorders'))->toHaveCount(1)
        ->and(asStaff($this, $this->token)->getJson($this->base.'/preorders?source=public')->json('data.preorders'))->toHaveCount(1)
        ->and(asStaff($this, $this->token)->getJson($this->base.'/preorders')->json('data.preorders.0.order_id'))->toBeNull();
});

it('sends a waiting pre-order to the kitchen as a real order through the api', function (): void {
    $preorder = Preorder::query()->create([
        'branch_id' => $this->branch->id,
        'order_no' => 'PRE-'.uniqid(),
        'source' => Preorder::SOURCE_STAFF,
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 200, 'line_total' => 400]],
        'total' => 400,
    ]);

    asStaff($this, $this->token)
        ->postJson($this->base.'/preorders/'.$preorder->id.'/send')
        ->assertOk()
        ->assertJsonPath('data.preorder.status', Preorder::COMPLETED);

    $order = Order::query()->where('preorder_id', $preorder->id)->firstOrFail();
    expect($order->total)->toBe('400.00')
        ->and(asStaff($this, $this->token)
            ->postJson($this->base.'/preorders/'.$preorder->id.'/send')
            ->assertUnprocessable()->status())->toBe(422);
});

it('deletes a mistaken pre-order with a reason through the api', function (): void {
    $preorder = Preorder::query()->create([
        'branch_id' => $this->branch->id,
        'order_no' => 'PRE-'.uniqid(),
        'source' => Preorder::SOURCE_STAFF,
        'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200]],
        'total' => 200,
    ]);

    asStaff($this, $this->token)
        ->deleteJson($this->base.'/preorders/'.$preorder->id, ['reason_code' => 'entry_mistake'])
        ->assertOk();

    expect($preorder->fresh()->trashed())->toBeTrue();
});

// ------------------------------------------------------------ reservations

function apiReservation(TestCase $test, array $overrides = []): array
{
    return $overrides + [
        'guest_name' => 'Salam',
        'party_size' => 4,
        'starts_at' => now()->addHours(3)->toIso8601String(),
        'table_ids' => [$test->table->id],
    ];
}

beforeEach(function (): void {
    // A second beforeEach runs after the first: the tables the booking tests pick from.
    $this->table = RestaurantTable::factory()->create(['branch_id' => $this->branch->id, 'name' => 'T1', 'is_active' => true]);
});

it('books with a pre-ordered menu and booking money through the api', function (): void {
    Queue::fake();

    $response = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this, [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
            'deposit_amount' => 100,
            'deposit_method' => 'cash',
        ]))->assertCreated()->json('data');

    expect($response['preorder_total'])->toBe('400.00')
        ->and($response['deposit']['status'])->toBe(Reservation::DEPOSIT_HELD)
        ->and($response['deposit']['balance'])->toBe('100.00');
});

it('shows the day sheet and searches across days through the api', function (): void {
    asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this, [
            'guest_name' => 'Rahim',
            'starts_at' => now()->addDay()->setHour(19)->toIso8601String(),
        ]))->assertCreated();

    asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this, ['guest_name' => 'Karim']))
        ->assertCreated();

    expect(asStaff($this, $this->token)->getJson($this->base.'/reservations')->json('data'))->toHaveCount(1)
        ->and(asStaff($this, $this->token)->getJson($this->base.'/reservations?q=rahi')->json('data'))->toHaveCount(1)
        ->and(asStaff($this, $this->token)->getJson($this->base.'/reservations?date='.now()->addDay()->toDateString())->json('data'))->toHaveCount(1)
        ->and(collect(asStaff($this, $this->token)->getJson($this->base.'/reservations?date='.now()->addDay()->toDateString())->json('data'))->pluck('guest_name'))->toContain('Rahim');
});

it('edits a holding booking through the api', function (): void {
    $id = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this))
        ->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->putJson($this->base.'/reservations/'.$id, apiReservation($this, ['guest_name' => 'Salam Uddin', 'party_size' => 6]))
        ->assertOk()
        ->assertJsonPath('data.guest_name', 'Salam Uddin');

    expect(Reservation::query()->find($id)->party_size)->toBe(6);
});

it('seats a booking and sends its pre-order to the kitchen through the api', function (): void {
    Queue::fake();

    $id = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this, [
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2]],
        ]))->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->patchJson($this->base.'/reservations/'.$id.'/status', ['status' => Reservation::SEATED])
        ->assertOk()
        ->assertJsonPath('data.status', Reservation::SEATED);

    $reservation = Reservation::query()->with('mealOrder')->find($id);
    expect($reservation->mealOrder)->not->toBeNull()
        ->and($reservation->mealOrder->total)->toBe('400.00');
});

it('refuses to delete a seated booking or one holding money', function (): void {
    $plain = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this))->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->patchJson($this->base.'/reservations/'.$plain.'/status', ['status' => Reservation::SEATED])->assertOk();

    asStaff($this, $this->token)
        ->deleteJson($this->base.'/reservations/'.$plain, ['reason_code' => 'entry_mistake'])
        ->assertUnprocessable();

    Queue::fake();

    $withMoney = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this, [
            'deposit_amount' => 50,
            'deposit_method' => 'cash',
            'starts_at' => now()->addHours(6)->toIso8601String(),
        ]))->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->deleteJson($this->base.'/reservations/'.$withMoney, ['reason_code' => 'entry_mistake'])
        ->assertUnprocessable();

    // Settle the money, then the mistaken booking can go.
    asStaff($this, $this->token)
        ->postJson($this->base.'/reservations/'.$withMoney.'/deposit/refund')->assertOk();

    // The money trail keeps the booking on record: even refunded, it stays.
    asStaff($this, $this->token)
        ->deleteJson($this->base.'/reservations/'.$withMoney, ['reason_code' => 'entry_mistake'])
        ->assertUnprocessable();

    $mistake = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this, [
            'starts_at' => now()->addHours(8)->toIso8601String(),
        ]))->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->deleteJson($this->base.'/reservations/'.$mistake, ['reason_code' => 'entry_mistake'])
        ->assertOk();

    expect(Reservation::withTrashed()->find($mistake)->trashed())->toBeTrue()
        ->and(Reservation::query()->find($mistake))->toBeNull();
});

it('collects and closes booking money through the api', function (): void {
    Queue::fake();

    $id = asStaff($this, $this->token)
        ->postJson($this->base.'/reservations', apiReservation($this))->assertCreated()->json('data.id');

    asStaff($this, $this->token)
        ->postJson($this->base.'/reservations/'.$id.'/deposit', ['deposit_amount' => 200, 'deposit_method' => 'bkash', 'deposit_reference' => 'TRX1'])
        ->assertOk()
        ->assertJsonPath('data.deposit.status', Reservation::DEPOSIT_HELD);

    // A second take is refused while the money is held.
    asStaff($this, $this->token)
        ->postJson($this->base.'/reservations/'.$id.'/deposit', ['deposit_amount' => 100, 'deposit_method' => 'cash'])
        ->assertUnprocessable();

    asStaff($this, $this->token)
        ->postJson($this->base.'/reservations/'.$id.'/deposit/forfeit')
        ->assertOk()
        ->assertJsonPath('data.deposit.status', Reservation::DEPOSIT_FORFEITED);

    expect(PaymentMethod::query()->where('code', 'bkash')->where('is_active', true)->exists())->toBeTrue();
});

// ------------------------------------------------------------ notifications

it('lists the bell alerts and marks them read through the api', function (): void {
    $gram = Unit::query()->create(['name' => 'Gram', 'symbol' => 'g-'.uniqid(), 'type' => 'weight', 'factor' => 1]);
    $milk = Ingredient::query()->create(['name' => 'Milk', 'unit_id' => $gram->id, 'reorder_level' => 100]);

    IngredientStock::query()->create([
        'branch_id' => $this->branch->id,
        'ingredient_id' => $milk->id,
        'stock_qty' => 40,
        'avg_cost' => 1,
    ]);

    $first = asStaff($this, $this->token)->getJson('/api/v1/notifications')->assertOk();
    expect($first->json('data.count'))->toBe(1)
        ->and($first->json('data.notifications.0.id'))->toBe('low-stock')
        ->and($first->json('data.notifications.0.body'))->toBe('Milk');

    asStaff($this, $this->token)->postJson('/api/v1/notifications/read')->assertOk();

    expect(asStaff($this, $this->token)->getJson('/api/v1/notifications')->json('data.count'))->toBe(0);
});

// ------------------------------------------------------------ roles

it('manages roles through the api like the web screen', function (): void {
    $index = asStaff($this, $this->token)->getJson('/api/v1/roles')->assertOk();
    expect($index->json('data.roles'))->not->toBeEmpty()
        ->and($index->json('data.groups'))->not->toBeEmpty()
        ->and($index->json('data.required'))->toBe('admin.access');

    $role = asStaff($this, $this->token)
        ->postJson('/api/v1/roles', [
            'label' => 'Night crew',
            'permissions' => ['orders.view', 'kds.access'],
        ])->assertCreated()->json('data');

    expect($role['label'])->toBe('Night crew')
        ->and($role['permissions'])->toContain('admin.access', 'kds.access');

    asStaff($this, $this->token)
        ->putJson('/api/v1/roles/'.$role['id'], ['permissions' => ['orders.view']])
        ->assertOk();

    asStaff($this, $this->token)
        ->deleteJson('/api/v1/roles/'.$role['id'])
        ->assertOk();

    // A chef cannot reach the screen at all.
    asStaff($this, $this->chefToken)->getJson('/api/v1/roles')->assertForbidden();
});

it('resets a standard role to its defaults through the api', function (): void {
    $cashier = Role::findByName('cashier', 'staff');
    $cashier->syncPermissions(['admin.access', 'orders.view']);

    asStaff($this, $this->token)
        ->postJson('/api/v1/roles/'.$cashier->id.'/reset')
        ->assertOk();

    expect($cashier->fresh()->permissions->pluck('name')->all())->toContain('pos.access');
});
