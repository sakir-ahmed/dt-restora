<?php

declare(strict_types=1);

use App\Actions\Inventory\ApproveStockTransferAction;
use App\Actions\Inventory\ReceiveStockTransferAction;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\Journal;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Wastage;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->kitchen = User::factory()->role('kitchen-manager', $this->branch->id)->create();

    $this->gram = Unit::query()->where('symbol', 'g')->firstOrFail();
    $this->kg = Unit::query()->where('symbol', 'kg')->firstOrFail();

    $this->ingredient = Ingredient::factory()->create(['unit_id' => $this->gram->id]);

    IngredientStock::query()->create([
        'branch_id' => $this->branch->id,
        'ingredient_id' => $this->ingredient->id,
        'stock_qty' => 1000,
        'avg_cost' => 0.5,
        'last_cost' => 0.5,
    ]);
});

it('logs wastage with the cost calculated server-side', function (): void {
    $response = $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 0.2, // 200 g
        'reason_code' => 'spoiled',
        'note' => 'Fridge was off',
    ]);

    $response->assertRedirect();

    $wastage = Wastage::query()->firstOrFail();

    expect((float) $wastage->cost)->toBe(100.0) // 200 g x 0.5
        ->and($wastage->reason_code)->toBe('spoiled')
        ->and((float) $wastage->fresh()->ingredient->stocks->firstWhere('branch_id', $this->branch->id)->stock_qty)->toBe(800.0);
});

it('requires a known reason code', function (): void {
    $response = $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 0.1,
        'reason_code' => 'no-reason',
    ]);

    $response->assertSessionHasErrors('reason_code');
});

it('never lets stock go negative when the branch forbids it', function (): void {
    $response = $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 5, // 5000 g, only 1000 g available
        'reason_code' => 'spoiled',
    ]);

    $response->assertStatus(500);

    $stock = IngredientStock::query()
        ->where('branch_id', $this->branch->id)
        ->where('ingredient_id', $this->ingredient->id)
        ->first();

    expect((float) $stock->stock_qty)->toBe(1000.0)
        ->and(Wastage::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0);
});

it('allows negative stock when the branch opts in', function (): void {
    $this->branch->update(['allow_negative_stock' => true]);

    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 5,
        'reason_code' => 'spoiled',
    ]);

    $stock = IngredientStock::query()
        ->where('branch_id', $this->branch->id)
        ->where('ingredient_id', $this->ingredient->id)
        ->first();

    expect((float) $stock->stock_qty)->toBe(-4000.0);
});

/**
 * A dish whose recipe uses the shared 1000 g @ 0.50 ingredient and a fresh
 * 1000 g @ 1.00 one: one portion costs 200 g x 0.5 + 100 g x 1.0 = 200.
 */
function seedDishWithRecipe($test): MenuItem
{
    $category = MenuCategory::factory()->create(['branch_id' => $test->branch->id]);
    $chicken = Ingredient::factory()->create(['unit_id' => $test->gram->id]);

    IngredientStock::query()->create([
        'branch_id' => $test->branch->id,
        'ingredient_id' => $chicken->id,
        'stock_qty' => 1000,
        'avg_cost' => 1.0,
        'last_cost' => 1.0,
    ]);

    $item = MenuItem::factory()->create(['branch_id' => $test->branch->id, 'menu_category_id' => $category->id]);

    $recipe = Recipe::query()->create(['menu_item_id' => $item->id]);
    $recipe->items()->createMany([
        ['ingredient_id' => $test->ingredient->id, 'qty' => 200],
        ['ingredient_id' => $chicken->id, 'qty' => 100],
    ]);

    return $item->loadMissing('recipe.items');
}

it('logs menu item wastage and deducts every recipe line from stock', function (): void {
    $item = seedDishWithRecipe($this);
    $chicken = $item->recipe->items->last()->ingredient;

    $response = $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 3,
        'reason_code' => 'kitchen_mistake',
        'note' => 'Burned batch',
    ]);

    $response->assertRedirect();

    $wastage = Wastage::query()->where('menu_item_id', $item->id)->firstOrFail();

    expect((float) $wastage->cost)->toBe(600.0) // 3 x (200 g x 0.5 + 100 g x 1.0)
        ->and((float) $wastage->qty)->toBe(3.0)
        ->and($wastage->lines)->toHaveCount(2)
        ->and((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(400.0)
        ->and((float) $chicken->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(700.0)
        ->and(StockMovement::query()->where('type', 'wastage')->count())->toBe(2);

    $journal = Journal::query()->where('source_type', Wastage::class)->where('source_id', $wastage->id)->firstOrFail();

    expect($journal->totalDebit())->toBe(600.0);
});

it('refuses to waste a menu item that has no recipe', function (): void {
    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $item = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $category->id]);

    $response = $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 2,
        'reason_code' => 'kitchen_mistake',
    ]);

    $response->assertSessionHasErrors('menu_item_id');
    expect(Wastage::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('rolls the whole menu item wastage back when one line runs out of stock', function (): void {
    $item = seedDishWithRecipe($this);

    $response = $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 6, // 6 x 200 g = 1200 g, only 1000 g available
        'reason_code' => 'kitchen_mistake',
    ]);

    $response->assertStatus(500);

    expect(Wastage::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(1000.0);
});

it('restores every recipe line when a menu item wastage is deleted', function (): void {
    $item = seedDishWithRecipe($this);
    $chicken = $item->recipe->items->last()->ingredient;

    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 3,
        'reason_code' => 'kitchen_mistake',
    ]);

    $wastage = Wastage::query()->where('menu_item_id', $item->id)->firstOrFail();

    $this->actingAs($this->kitchen, 'staff')->delete(route('admin.wastage.destroy', $wastage), [
        'reason_code' => 'entry_mistake',
    ])->assertRedirect();

    expect((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(1000.0)
        ->and((float) $chicken->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(1000.0)
        ->and($wastage->fresh()->deleted_at)->not->toBeNull();

    $journal = Journal::query()->where('source_type', Wastage::class)->where('source_id', $wastage->id)->firstOrFail();

    expect($journal->status)->toBe(Journal::STATUS_REVERSED)
        ->and(Journal::query()->where('reverses_journal_id', $journal->id)->exists())->toBeTrue();
});

it('shows the dish and portions on the wastage report', function (): void {
    $item = seedDishWithRecipe($this);
    $owner = User::factory()->role('owner')->create();

    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 3,
        'reason_code' => 'over_production',
    ]);

    $response = $this->actingAs($owner, 'staff')->get('/admin/reports?type=wastage&from='.now()->toDateString().'&to='.now()->toDateString());

    $response->assertOk();

    $rows = $response->viewData('page')['props']['rows'];

    expect($rows[0]['ingredient'])->toBe($item->name)
        ->and($rows[0]['qty'])->toBe('3.000 '.__('inventory.portions'))
        ->and($rows[0]['cost'])->toBe('600.00');
});

it('revises an ingredient wastage and moves stock by the difference', function (): void {
    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 0.2, // 200 g, cost 100
        'reason_code' => 'spoiled',
    ]);

    $wastage = Wastage::query()->firstOrFail();

    $response = $this->actingAs($this->kitchen, 'staff')->put(route('admin.wastage.update', $wastage), [
        'qty' => 0.5, // 500 g, cost 250
        'reason_code' => 'dropped',
        'note' => 'Actually the whole tray',
    ]);

    $response->assertRedirect();

    $wastage->refresh();

    expect((float) $wastage->cost)->toBe(250.0)
        ->and($wastage->reason_code)->toBe('dropped')
        ->and((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(500.0)
        // Old journal reversed, a fresh one posted for the new cost.
        ->and(Journal::query()->where('source_type', Wastage::class)->where('source_id', $wastage->id)->where('status', 'posted')->count())->toBe(1)
        ->and(Journal::query()->where('source_type', Wastage::class)->where('source_id', $wastage->id)->where('status', Journal::STATUS_REVERSED)->count())->toBe(1);
});

it('revises a menu item wastage to new portions and re-freezes the lines', function (): void {
    $item = seedDishWithRecipe($this);
    $chicken = $item->recipe->items->last()->ingredient;

    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 3,
        'reason_code' => 'kitchen_mistake',
    ]);

    $wastage = Wastage::query()->where('menu_item_id', $item->id)->firstOrFail();

    $this->actingAs($this->kitchen, 'staff')->put(route('admin.wastage.update', $wastage), [
        'qty' => 2,
        'reason_code' => 'over_production',
        'note' => null,
    ])->assertRedirect();

    $wastage->refresh();

    expect((float) $wastage->cost)->toBe(400.0)
        ->and((float) $wastage->qty)->toBe(2.0)
        ->and($wastage->lines)->toHaveCount(2)
        // Stock follows the difference: 400 -> 600 and 700 -> 800.
        ->and((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(600.0)
        ->and((float) $chicken->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(800.0)
        ->and(Journal::query()->where('source_type', Wastage::class)->where('source_id', $wastage->id)->where('status', 'posted')->count())->toBe(1);
});

it('refuses a revision that would take stock below zero', function (): void {
    $item = seedDishWithRecipe($this);

    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'menu_item_id' => $item->id,
        'qty' => 3,
        'reason_code' => 'kitchen_mistake',
    ]);

    $wastage = Wastage::query()->where('menu_item_id', $item->id)->firstOrFail();
    $linesBefore = $wastage->lines()->pluck('base_qty', 'ingredient_id')->all();
    $stockBefore = (float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty;
    $journalsBefore = Journal::query()->where('source_type', Wastage::class)->count();

    $response = $this->actingAs($this->kitchen, 'staff')->put(route('admin.wastage.update', $wastage), [
        'qty' => 6, // 6 x 200 g = 1200 g, only 1000 g in stock
        'reason_code' => 'kitchen_mistake',
    ]);

    $response->assertSessionHasErrors('qty');

    expect($wastage->lines()->pluck('base_qty', 'ingredient_id')->all())->toBe($linesBefore)
        ->and((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe($stockBefore)
        ->and(Journal::query()->where('source_type', Wastage::class)->count())->toBe($journalsBefore);
});

it('revises only the reason and note without touching the journal', function (): void {
    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 0.2,
        'reason_code' => 'spoiled',
    ]);

    $wastage = Wastage::query()->firstOrFail();
    $journalsBefore = Journal::query()->where('source_type', Wastage::class)->count();

    $this->actingAs($this->kitchen, 'staff')->put(route('admin.wastage.update', $wastage), [
        'qty' => 0.2,
        'reason_code' => 'other',
        'note' => 'Refrigerator door left open',
    ])->assertRedirect();

    $wastage->refresh();

    expect($wastage->reason_code)->toBe('other')
        ->and($wastage->note)->toBe('Refrigerator door left open')
        ->and((float) $wastage->cost)->toBe(100.0)
        ->and((float) $this->ingredient->stocks()->where('branch_id', $this->branch->id)->firstOrFail()->stock_qty)->toBe(800.0)
        ->and(Journal::query()->where('source_type', Wastage::class)->count())->toBe($journalsBefore);
});

it('cannot revise a deleted wastage entry', function (): void {
    $this->actingAs($this->kitchen, 'staff')->post('/admin/wastage', [
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 0.2,
        'reason_code' => 'spoiled',
    ]);

    $wastage = Wastage::query()->firstOrFail();
    $wastage->delete();

    $this->actingAs($this->kitchen, 'staff')->put(route('admin.wastage.update', $wastage), [
        'qty' => 0.5,
        'reason_code' => 'spoiled',
    ])->assertNotFound();
});

it('applies stock count variances on close', function (): void {
    $count = StockCount::query()->create([
        'branch_id' => $this->branch->id,
        'status' => 'open',
        'user_id' => $this->kitchen->id,
    ]);

    $item = $count->items()->create([
        'ingredient_id' => $this->ingredient->id,
        'system_qty' => 1000,
    ]);

    $this->actingAs($this->kitchen, 'staff')->put("/admin/stock-counts/{$count->id}/items", [
        'counted' => [$item->id => '940'],
    ]);

    $response = $this->actingAs($this->kitchen, 'staff')->post("/admin/stock-counts/{$count->id}/close");

    $response->assertRedirect();

    $stock = IngredientStock::query()
        ->where('branch_id', $this->branch->id)
        ->where('ingredient_id', $this->ingredient->id)
        ->first();

    expect($count->fresh()->status)->toBe('closed')
        ->and((float) $stock->stock_qty)->toBe(940.0)
        ->and((float) $count->fresh()->items->first()->variance_qty)->toBe(-60.0);
});

it('moves transfer stock exactly once through request, approve and receive', function (): void {
    $branchB = Branch::factory()->create(['code' => 'BR02']);

    $transfer = StockTransfer::query()->create([
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $branchB->id,
        'status' => StockTransfer::STATUS_REQUESTED,
        'requested_by' => $this->kitchen->id,
    ]);

    $transfer->items()->create([
        'ingredient_id' => $this->ingredient->id,
        'unit_id' => $this->kg->id,
        'qty' => 0.5, // 500 g
    ]);

    // Receive before approve must fail.
    try {
        app(ReceiveStockTransferAction::class)->execute($transfer->fresh(), $this->kitchen);
        $this->fail('Receiving an unapproved transfer should throw.');
    } catch (LogicException) {
        // expected
    }

    app(ApproveStockTransferAction::class)->execute($transfer->fresh(), $this->kitchen);
    app(ReceiveStockTransferAction::class)->execute($transfer->fresh(), $this->kitchen);

    // Receiving again must be a no-op.
    try {
        app(ReceiveStockTransferAction::class)->execute($transfer->fresh(), $this->kitchen);
        $this->fail('Receiving twice should throw.');
    } catch (LogicException) {
        // expected
    }

    $source = IngredientStock::query()->where('branch_id', $this->branch->id)->where('ingredient_id', $this->ingredient->id)->first();
    $destination = IngredientStock::query()->where('branch_id', $branchB->id)->where('ingredient_id', $this->ingredient->id)->first();

    expect((float) $source->stock_qty)->toBe(500.0)
        ->and((float) $destination->stock_qty)->toBe(500.0)
        ->and((float) $destination->avg_cost)->toBe(0.5)
        ->and(StockMovement::query()->where('type', 'transfer_out')->count())->toBe(1)
        ->and(StockMovement::query()->where('type', 'transfer_in')->count())->toBe(1);
});
