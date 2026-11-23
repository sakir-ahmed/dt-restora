<?php

declare(strict_types=1);

use App\Actions\Sales\CloseShiftAction;
use App\Actions\Sales\CreateOrderAction;
use App\Actions\Sales\OpenShiftAction;
use App\Actions\Sales\PayOrderAction;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01', 'vat_percent' => '0.00', 'service_charge_percent' => '0.00']);
    $this->cashier = User::factory()->role('cashier', $this->branch->id)->create();
    $this->other = User::factory()->role('cashier', $this->branch->id)->create();

    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $this->item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => '100.00']);
});

function openShiftFor(object $test, User $user, float $cash = 1000): Shift
{
    return app(OpenShiftAction::class)->execute($test->branch, $user, $cash);
}

function sale(object $test, ?Shift $shift, User $cashier): Order
{
    return app(CreateOrderAction::class)->execute(
        branch: $test->branch,
        type: OrderType::Takeaway,
        lines: [['menu_item_id' => $test->item->id, 'quantity' => 1]],
        cashier: $cashier,
        shift: $shift,
    );
}

it('counts cash taken by the cashier in the shift, with the opening cash', function (): void {
    $shift = openShiftFor($this, $this->cashier);
    app(PayOrderAction::class)->executeCash(sale($this, $shift, $this->cashier), 100, $this->cashier);

    $closed = app(CloseShiftAction::class)->execute($shift, 1100);

    expect((float) $closed->expected_cash)->toBe(1100.0)
        ->and((float) $closed->difference)->toBe(0.0);
});

it('counts cash collected on an older bill in the shift that took it, not the one that rang it up', function (): void {
    $first = openShiftFor($this, $this->cashier);
    $older = sale($this, $first, $this->cashier);
    $closedFirst = app(CloseShiftAction::class)->execute($first, 1000);

    $second = openShiftFor($this, $this->cashier, 500);
    app(PayOrderAction::class)->executeCash($older, 100, $this->cashier);
    $closedSecond = app(CloseShiftAction::class)->execute($second, 600);

    expect((float) $closedFirst->expected_cash)->toBe(1000.0)
        ->and((float) $closedSecond->expected_cash)->toBe(600.0)
        ->and((float) $closedSecond->difference)->toBe(0.0);
});

it('does not count another cashier\'s cash', function (): void {
    $shift = openShiftFor($this, $this->cashier);
    $other = openShiftFor($this, $this->other, 200);
    app(PayOrderAction::class)->executeCash(sale($this, $other, $this->other), 100, $this->other);

    expect((float) app(CloseShiftAction::class)->execute($shift, 1000)->expected_cash)->toBe(1000.0)
        ->and((float) app(CloseShiftAction::class)->execute($other, 300)->expected_cash)->toBe(300.0);
});

it('leaves out the cash of a sale that was deleted afterwards', function (): void {
    $shift = openShiftFor($this, $this->cashier);
    $kept = sale($this, $shift, $this->cashier);
    $deleted = sale($this, $shift, $this->cashier);
    app(PayOrderAction::class)->executeCash($kept, 100, $this->cashier);
    app(PayOrderAction::class)->executeCash($deleted, 100, $this->cashier);
    $deleted->update(['status' => OrderStatus::Void->value]);

    expect((float) app(CloseShiftAction::class)->execute($shift, 1100)->expected_cash)->toBe(1100.0);
});

it('counts a cash payment with no cashier in the shift its order was rung up in', function (): void {
    $shift = openShiftFor($this, $this->cashier);
    $order = sale($this, $shift, $this->cashier);
    $order->payments()->create(['method' => 'cash', 'amount' => '100.00', 'status' => 'completed', 'user_id' => null]);

    expect((float) app(CloseShiftAction::class)->execute($shift, 1100)->expected_cash)->toBe(1100.0);
});

it('tells the cashier about unpaid bills of the shift before closing, without showing expected cash', function (): void {
    $shift = openShiftFor($this, $this->cashier);
    app(PayOrderAction::class)->executeCash(sale($this, $shift, $this->cashier), 100, $this->cashier);
    sale($this, $shift, $this->cashier);

    $this->actingAs($this->cashier, 'staff')
        ->getJson(route('admin.shifts.summary', $shift))
        ->assertOk()
        ->assertJson(['orders' => 2, 'open_orders' => 1])
        ->assertJsonMissing(['expected_cash']);

    $this->actingAs($this->other, 'staff')->getJson(route('admin.shifts.summary', $shift))->assertForbidden();
});

it('hands back the figures of the shift with the other payment methods once it is closed', function (): void {
    $shift = openShiftFor($this, $this->cashier);
    app(PayOrderAction::class)->executeCash(sale($this, $shift, $this->cashier), 100, $this->cashier);
    sale($this, $shift, $this->cashier)->payments()->create(['method' => 'bkash', 'amount' => '100.00', 'status' => 'completed', 'user_id' => $this->cashier->id]);

    $summary = app(CloseShiftAction::class)->summary(app(CloseShiftAction::class)->execute($shift, 1100));

    expect($summary['orders'])->toBe(2)
        ->and($summary['cash_sales'])->toBe('100.00')
        ->and($summary['methods'])->toBe(['bkash' => '100.00', 'cash' => '100.00']);
});
