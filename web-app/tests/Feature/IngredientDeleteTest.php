<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Purchase;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed([ChartOfAccountsSeeder::class, RoleSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->owner = User::factory()->role('owner')->create(['branch_id' => $this->branch->id]);
    $this->unit = Unit::query()->where('symbol', 'kg')->firstOrFail();
});

function ingredientDeletePayload(string $reason = 'duplicate_entry', ?string $note = null): array
{
    return ['reason_code' => $reason, 'note' => $note];
}

it('deletes an unused ingredient softly and keeps the audit trail', function (): void {
    $ingredient = Ingredient::factory()->create(['name' => 'Duplicate Oil', 'unit_id' => $this->unit->id]);

    $this->actingAs($this->owner, 'staff')
        ->delete("/admin/ingredients/{$ingredient->id}", ingredientDeletePayload())
        ->assertSessionHas('success');

    $kept = Ingredient::withTrashed()->find($ingredient->id);

    expect($kept->trashed())->toBeTrue()
        ->and($kept->delete_reason)->toBe('duplicate_entry')
        ->and($kept->deleted_by)->toBe($this->owner->id);

    // Hidden from the list with the setting off...
    Setting::current()->update(['show_deleted_items' => false]);

    $hidden = $this->actingAs($this->owner, 'staff')->get('/admin/ingredients')->inertiaProps();

    expect(collect($hidden['ingredients'])->pluck('id'))->not->toContain($ingredient->id);

    // ...kept in it, marked deleted, with the setting on.
    Setting::current()->update(['show_deleted_items' => true]);

    $shown = $this->actingAs($this->owner, 'staff')->get('/admin/ingredients')->inertiaProps();
    $row = collect($shown['ingredients'])->firstWhere('id', $ingredient->id);

    expect($row)->not->toBeNull()
        ->and($row['deleted_at'])->not->toBeNull()
        ->and($row['recipes_count'])->toBe(0);
});

it('requires a known reason code to delete an ingredient', function (): void {
    $ingredient = Ingredient::factory()->create(['unit_id' => $this->unit->id]);

    $this->actingAs($this->owner, 'staff')
        ->delete("/admin/ingredients/{$ingredient->id}", ingredientDeletePayload('not-a-reason'))
        ->assertSessionHasErrors('reason_code');

    expect($ingredient->fresh()->deleted_at)->toBeNull();
});

it('demands a note before deleting an ingredient that a recipe uses, then edits the recipe', function (): void {
    $ingredient = Ingredient::factory()->create(['name' => 'Special Salt', 'unit_id' => $this->unit->id]);
    $item = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'menu_category_id' => MenuCategory::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);
    $recipe = Recipe::query()->create(['menu_item_id' => $item->id]);
    $recipe->items()->create(['ingredient_id' => $ingredient->id, 'qty' => 10]);

    // No note: refused, nothing changes.
    $this->actingAs($this->owner, 'staff')
        ->delete("/admin/ingredients/{$ingredient->id}", ingredientDeletePayload())
        ->assertSessionHasErrors('note');

    expect($ingredient->fresh()->deleted_at)->toBeNull()
        ->and(RecipeItem::query()->where('ingredient_id', $ingredient->id)->count())->toBe(1);

    // With a note: the recipe line goes, the recipe and menu item stay.
    $this->actingAs($this->owner, 'staff')
        ->delete("/admin/ingredients/{$ingredient->id}", ingredientDeletePayload(note: 'wrong import, recipe pointed at the duplicate'))
        ->assertSessionHas('success');

    expect(RecipeItem::query()->where('ingredient_id', $ingredient->id)->count())->toBe(0)
        ->and($recipe->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull()
        ->and($ingredient->fresh()->deleted_at)->not->toBeNull();

    $log = Activity::query()->where('description', 'ingredient.deleted')->latest('id')->first();

    expect($log->properties->get('recipes_modified'))->toBe([$item->name]);
});

it('still names a deleted ingredient in the purchase history', function (): void {
    $ingredient = Ingredient::factory()->create(['name' => 'Imported Twice Flour', 'unit_id' => $this->unit->id]);

    $purchase = Purchase::query()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => Supplier::factory()->create()->id,
        'purchased_at' => now()->toDateString(),
        'status' => Purchase::STATUS_DRAFT,
    ]);

    $purchase->items()->create([
        'ingredient_id' => $ingredient->id,
        'unit_id' => $this->unit->id,
        'qty' => 5,
        'unit_cost' => 40,
        'line_total' => 200,
    ]);

    $this->actingAs($this->owner, 'staff')
        ->delete("/admin/ingredients/{$ingredient->id}", ingredientDeletePayload())
        ->assertSessionHas('success');

    $props = $this->actingAs($this->owner, 'staff')->get('/admin/purchases')->inertiaProps();
    $row = collect($props['purchases'])->firstWhere('id', $purchase->id);

    expect(collect($row['items'])->pluck('ingredient.name'))->toContain('Imported Twice Flour');
});

it('keeps ingredient deletion away from roles that only view stock', function (): void {
    $viewer = User::factory()->role('cashier', $this->branch->id)->create();
    $ingredient = Ingredient::factory()->create(['unit_id' => $this->unit->id]);

    $this->actingAs($viewer, 'staff')
        ->delete("/admin/ingredients/{$ingredient->id}", ingredientDeletePayload())
        ->assertForbidden();

    expect($ingredient->fresh()->deleted_at)->toBeNull();
});
