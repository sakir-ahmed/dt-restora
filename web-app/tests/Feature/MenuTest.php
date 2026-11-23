<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();
    $this->category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
});

it('creates a menu item with variants and add-ons', function (): void {
    $response = $this->actingAs($this->manager, 'staff')->post('/admin/menu/items', [
        'menu_category_id' => $this->category->id,
        'name' => 'Chicken Burger',
        'name_bn' => 'চিকেন বার্গার',
        'base_price' => '180.00',
        'is_available' => true,
        'is_vegetarian' => false,
        'sort_order' => 1,
        'variants' => [
            ['name' => 'Single', 'price' => '180.00', 'is_default' => true, 'sort_order' => 1],
            ['name' => 'Double Patty', 'price' => '280.00', 'is_default' => false, 'sort_order' => 2],
        ],
        'addons' => [
            ['name' => 'Cheese Slice', 'price' => '40.00', 'is_active' => true, 'sort_order' => 1],
        ],
    ]);

    $response->assertRedirect();

    $item = MenuItem::query()->where('name', 'Chicken Burger')->firstOrFail();

    expect($item->variants)->toHaveCount(2)
        ->and($item->addOns)->toHaveCount(1)
        ->and($item->base_price)->toBe('180.00')
        ->and($item->variants->firstWhere('name', 'Double Patty')->price)->toBe('280.00');
});

it('prices variants absolutely and keeps exactly one default', function (): void {
    $item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->category->id,
        'base_price' => '180.00',
    ]);

    $large = $item->variants()->create(['name' => 'Large', 'price' => '360.00', 'is_default' => true]);
    $regular = $item->variants()->create(['name' => 'Regular', 'price' => '280.00', 'is_default' => false]);

    expect($item->priceFor($large))->toBe('360.00')
        ->and($item->priceFor($regular))->toBe('280.00')
        ->and($item->priceFor())->toBe('180.00')
        ->and($item->variants->where('is_default', true)->count())->toBe(1);
});

it('updates an item and syncs its variants and add-ons', function (): void {
    $item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->category->id,
    ]);

    $keepVariant = $item->variants()->create(['name' => 'Regular', 'price' => '250.00', 'is_default' => true]);
    $item->variants()->create(['name' => 'Old', 'price' => '100.00']);

    $response = $this->actingAs($this->manager, 'staff')->put("/admin/menu/items/{$item->id}", [
        'menu_category_id' => $this->category->id,
        'name' => 'Beef Tehari',
        'base_price' => '250.00',
        'is_available' => true,
        'is_vegetarian' => false,
        'sort_order' => 1,
        'variants' => [
            ['id' => $keepVariant->id, 'name' => 'Regular', 'price' => '250.00', 'is_default' => true],
            ['name' => 'Large', 'price' => '330.00', 'is_default' => false],
        ],
        'addons' => [
            ['name' => 'Extra Roast', 'price' => '90.00', 'is_active' => true],
        ],
    ]);

    $response->assertRedirect();

    $item->refresh()->load(['variants', 'addOns']);

    expect($item->name)->toBe('Beef Tehari')
        ->and($item->variants->pluck('name')->all())->toBe(['Regular', 'Large'])
        ->and($item->variants->count())->toBe(2)
        ->and($item->addOns->count())->toBe(1);
});

it('searches items by english name, bangla name and barcode', function (): void {
    $item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->category->id,
        'name' => 'Chicken Biryani',
        'name_bn' => 'চিকেন বিরিয়ানি',
        'barcode' => '8941100102030',
    ]);

    MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->category->id,
        'name' => 'Masala Tea',
    ]);

    expect(MenuItem::search('biryani')->count())->toBe(1)
        ->and(MenuItem::search('চিকেন')->count())->toBe(1)
        ->and(MenuItem::search('8941100102030')->count())->toBe(1)
        ->and(MenuItem::search(null)->count())->toBe(2);
});

it('rejects item writes from roles without menu permission', function (): void {
    $waiter = User::factory()->role('waiter', $this->branch->id)->create();

    $response = $this->actingAs($waiter, 'staff')->post('/admin/menu/items', [
        'menu_category_id' => $this->category->id,
        'name' => 'Not Allowed',
        'base_price' => '10.00',
    ]);

    $response->assertForbidden();
});

it('rejects the menu page for roles without menu view permission', function (): void {
    $rider = User::factory()->role('rider', $this->branch->id)->create();

    $response = $this->actingAs($rider, 'staff')->get('/admin/menu');

    $response->assertForbidden();
});

it('does not let a manager create an item in another branch category', function (): void {
    $otherBranch = Branch::factory()->create(['code' => 'BR02']);
    $otherCategory = MenuCategory::factory()->create(['branch_id' => $otherBranch->id]);

    $response = $this->actingAs($this->manager, 'staff')->post('/admin/menu/items', [
        'menu_category_id' => $otherCategory->id,
        'name' => 'Cross Branch',
        'base_price' => '10.00',
    ]);

    $response->assertNotFound();
});

it('sends each dish with its variants and add-ons so the edit form can load them', function (): void {
    $item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => $this->category->id,
    ]);
    $item->variants()->create(['name' => 'Large', 'price' => '360.00', 'is_default' => true]);
    $item->addOns()->create(['name' => 'Extra cheese', 'price' => '40.00', 'is_active' => true]);

    $this->actingAs($this->manager, 'staff')->get('/admin/menu')
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.items.0.variants.0.name', 'Large')
            ->where('categories.0.items.0.add_ons.0.name', 'Extra cheese')
            ->has('categories.0.items.0.media_gallery'));
});

it('deletes a menu item that was never ordered', function (): void {
    $item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $this->category->id]);

    $this->actingAs($this->manager, 'staff')->delete("/admin/menu/items/{$item->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(MenuItem::query()->find($item->id))->toBeNull();
});

it('refuses to delete a menu item that already sits on an order instead of failing with a server error', function (): void {
    $item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $this->category->id]);

    $order = \App\Models\Order::query()->create([
        'branch_id' => $this->branch->id,
        'invoice_no' => 'BR01-DEL-0001',
        'order_type' => 'takeaway',
        'channel' => 'pos',
        'status' => 'completed',
        'subtotal' => '100.00',
        'total' => '100.00',
    ]);
    \App\Models\OrderItem::query()->create([
        'order_id' => $order->id,
        'menu_item_id' => $item->id,
        'name' => $item->name,
        'unit_price' => '100.00',
        'quantity' => 1,
        'line_total' => '100.00',
    ]);

    $this->actingAs($this->manager, 'staff')->delete("/admin/menu/items/{$item->id}")
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'is on 1 orders'))
        ->assertSessionHas('error_link.url', fn (string $url): bool => str_contains($url, '/admin/orders?search='));

    expect(MenuItem::query()->find($item->id))->not->toBeNull();

    $this->actingAs($this->manager, 'staff')->get('/admin/orders?search='.urlencode($item->name))
        ->assertInertia(fn ($page) => $page->has('orders', 1));
});

it('lists every order the refusal message counts when the View these orders link is opened', function (): void {
    $item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $this->category->id, 'name' => 'Morog Polao']);
    $other = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $this->category->id, 'name' => 'Plain Rice']);

    foreach (range(1, 130) as $number) {
        $order = \App\Models\Order::query()->create([
            'branch_id' => $this->branch->id,
            'invoice_no' => sprintf('BR01-CNT-%04d', $number),
            'order_type' => 'takeaway',
            'channel' => 'pos',
            // Some are void: the list hides those by default, but a search must still show them.
            'status' => $number % 10 === 0 ? 'void' : 'paid',
            'subtotal' => '100.00',
            'total' => '100.00',
        ]);

        // Two lines of the same dish in one order still count as one order.
        foreach ([$item, $item, $other] as $line) {
            \App\Models\OrderItem::query()->create([
                'order_id' => $order->id, 'menu_item_id' => $line->id, 'name' => $line->name,
                'unit_price' => '100.00', 'quantity' => 1, 'line_total' => '100.00',
            ]);
        }
    }

    $response = $this->actingAs($this->manager, 'staff')->delete("/admin/menu/items/{$item->id}");

    $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'is on 130 orders'));

    $url = session('error_link.url');

    $this->actingAs($this->manager, 'staff')->get($url)
        ->assertInertia(fn ($page) => $page->has('orders', 130));
});
