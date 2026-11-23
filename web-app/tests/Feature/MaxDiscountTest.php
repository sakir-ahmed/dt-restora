<?php

declare(strict_types=1);

use App\Actions\Sales\CreateOrderAction;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Services\DiscountAllocation;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01', 'vat_percent' => '0.00', 'service_charge_percent' => '0.00']);
    $this->owner = User::factory()->role('owner')->create();
    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);

    $make = fn (string $price, array $extra = []) => MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id, 'base_price' => $price, ...$extra]);

    // 100 never discounted, 200 and 300 free to discount.
    $this->noDiscount = $make('100.00', ['max_discount_type' => 'percent', 'max_discount_value' => '0']);
    $this->plain = $make('200.00');
    $this->other = $make('300.00');
});

function orderWith(object $test, array $items, ?array $discount = null, array $lineExtra = []): Order
{
    return app(CreateOrderAction::class)->execute(
        branch: $test->branch,
        type: OrderType::Takeaway,
        lines: collect($items)->map(fn (MenuItem $item, int $index): array => ['menu_item_id' => $item->id, 'quantity' => 1, ...($lineExtra[$index] ?? [])])->all(),
        cashier: $test->owner,
        discount: $discount,
    );
}

it('skips a dish whose maximum discount is 0 when a percent discount is given on the bill', function (): void {
    $order = orderWith($this, [$this->noDiscount, $this->plain, $this->other], ['type' => 'percent', 'value' => 10]);

    // 10% of the 500 that can be discounted, not of the whole 600.
    expect((float) $order->subtotal)->toBe(600.0)
        ->and((float) $order->discount_amount)->toBe(50.0)
        ->and((float) $order->total)->toBe(550.0);
});

it('spreads a fixed bill discount over the dishes that may be discounted only', function (): void {
    $order = orderWith($this, [$this->noDiscount, $this->plain, $this->other], ['type' => 'fixed', 'value' => 100]);

    expect((float) $order->discount_amount)->toBe(100.0)
        ->and((float) $order->total)->toBe(500.0);
});

it('stops a fixed discount at what the dishes allow', function (): void {
    $only = orderWith($this, [$this->noDiscount], ['type' => 'fixed', 'value' => 50]);

    expect((float) $only->discount_amount)->toBe(0.0)
        ->and((float) $only->total)->toBe(100.0);
});

it('limits a dish to its own maximum percent and lets the others take the rest', function (): void {
    $limited = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $this->plain->menu_category_id, 'base_price' => '200.00', 'max_discount_type' => 'percent', 'max_discount_value' => '5']);

    $percent = orderWith($this, [$limited, $this->other], ['type' => 'percent', 'value' => 10]);
    // 5% of 200 = 10, 10% of 300 = 30.
    expect((float) $percent->discount_amount)->toBe(40.0);

    // A fixed 60: shared 40:60 would put 24 on the limited dish (over its 10), so it keeps 10 and the other takes 50.
    $fixed = orderWith($this, [$limited, $this->other], ['type' => 'fixed', 'value' => 60]);
    expect((float) $fixed->discount_amount)->toBe(60.0)
        ->and((float) $fixed->total)->toBe(440.0);
});

it('uses a fixed maximum per unit and so allows more for more units', function (): void {
    $fixedCap = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $this->plain->menu_category_id, 'base_price' => '200.00', 'max_discount_type' => 'fixed', 'max_discount_value' => '15']);

    $order = app(CreateOrderAction::class)->execute(
        branch: $this->branch,
        type: OrderType::Takeaway,
        lines: [['menu_item_id' => $fixedCap->id, 'quantity' => 2]],
        cashier: $this->owner,
        discount: ['type' => 'fixed', 'value' => 100],
    );

    expect((float) $order->discount_amount)->toBe(30.0);
});

it('cuts a discount typed on a line down to the dish maximum', function (): void {
    $order = orderWith($this, [$this->noDiscount, $this->plain], null, [0 => ['discount' => ['type' => 'percent', 'value' => 10]], 1 => ['discount' => ['type' => 'percent', 'value' => 10]]]);

    expect((float) $order->discount_amount)->toBe(20.0);
});

it('leaves bills with no dish maximum exactly as they were', function (): void {
    $order = orderWith($this, [$this->plain, $this->other], ['type' => 'percent', 'value' => 10]);

    expect((float) $order->discount_amount)->toBe(50.0)
        ->and((float) $order->total)->toBe(450.0);
});

it('keeps the maximum when items are added to a running order', function (): void {
    $order = orderWith($this, [$this->plain], ['type' => 'percent', 'value' => 10]);

    $this->actingAs($this->owner, 'staff')
        ->post(route('admin.orders.items.store', $order), ['items' => [['menu_item_id' => $this->noDiscount->id, 'quantity' => 1]]])
        ->assertSessionHasNoErrors();

    // 10% of the 200 dish only; the 100 dish is added with no discount.
    expect((float) $order->fresh()->discount_amount)->toBe(20.0)
        ->and((float) $order->fresh()->total)->toBe(280.0);
});

it('shares a fixed amount by line amount and respects each limit', function (): void {
    expect(DiscountAllocation::fixed(60, [0 => 200.0, 1 => 300.0], [0 => 10.0, 1 => 300.0]))->toBe([0 => 10.0, 1 => 50.0])
        ->and(DiscountAllocation::fixed(100, [0 => 100.0, 1 => 200.0, 2 => 300.0], [0 => 0.0, 1 => 200.0, 2 => 300.0]))->toBe([0 => 0.0, 1 => 40.0, 2 => 60.0])
        ->and(DiscountAllocation::fixed(900, [0 => 100.0, 1 => 200.0], [0 => 0.0, 1 => 50.0]))->toBe([0 => 0.0, 1 => 50.0])
        ->and(DiscountAllocation::percent(10, [0 => 100.0, 1 => 200.0], [0 => 0.0, 1 => 200.0]))->toBe([0 => 0.0, 1 => 20.0]);
});

it('saves a dish maximum discount from the menu form and refuses a value without a type', function (): void {
    $manager = User::factory()->role('owner')->create();
    $payload = fn (array $extra): array => [
        'menu_category_id' => $this->plain->menu_category_id, 'name' => 'Biryani', 'base_price' => '250.00',
        'is_available' => true, 'is_vegetarian' => false, 'sort_order' => 0, ...$extra,
    ];

    $this->actingAs($manager, 'staff')->put("/admin/menu/items/{$this->plain->id}", $payload(['max_discount_type' => 'percent', 'max_discount_value' => 0]))
        ->assertSessionHasNoErrors();

    expect($this->plain->fresh()->max_discount_type)->toBe('percent')
        ->and((float) $this->plain->fresh()->max_discount_value)->toBe(0.0);

    $this->actingAs($manager, 'staff')->put("/admin/menu/items/{$this->plain->id}", $payload(['max_discount_type' => 'percent']))
        ->assertSessionHasErrors('max_discount_value');
});
