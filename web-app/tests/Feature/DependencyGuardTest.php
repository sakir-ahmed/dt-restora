<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DependencyInspector;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();
    $this->item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => MenuCategory::factory()->create(['branch_id' => $this->branch->id])->id,
        'name' => 'Morog Polao',
    ]);

    $order = Order::query()->create([
        'branch_id' => $this->branch->id, 'invoice_no' => 'BR01-DEP-0001', 'order_type' => 'takeaway',
        'channel' => 'pos', 'status' => 'paid', 'subtotal' => '100.00', 'total' => '100.00',
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id, 'menu_item_id' => $this->item->id, 'name' => 'Morog Polao',
        'unit_price' => '100.00', 'quantity' => 1, 'line_total' => '100.00',
    ]);
});

it('lists what still uses a record, from the database foreign keys', function (): void {
    $steps = DependencyInspector::steps($this->item);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['label'])->toBe('1. Orders')
        ->and($steps[0]['value'])->toStartWith('1 ')
        ->and($steps[0]['url'])->toContain('/admin/orders?search=');
});

it('explains any blocked delete the same way in every module instead of a server error', function (): void {
    // A bare delete that no controller guards, so the database itself refuses it.
    Route::middleware(['web', 'auth:staff'])->delete('/_test/menu-items/{item}', fn (MenuItem $item) => $item->delete() ? back() : back());

    $this->actingAs($this->manager, 'staff')->delete("/_test/menu-items/{$this->item->id}")
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Morog Polao'))
        ->assertSessionHas('error_steps.0.label', '1. Orders');

    expect(MenuItem::query()->find($this->item->id))->not->toBeNull();
});
