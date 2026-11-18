<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RestaurantTable;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\UploadedFile;

function csvFile(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('import.csv', $content);
}

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, UnitSeeder::class]);

    $this->branch = Branch::factory()->create(['code' => 'BR01']);
    $this->manager = User::factory()->role('branch-manager', $this->branch->id)->create();
});

it('downloads a sample csv for every importable resource', function (string $url, string $filename): void {
    $response = $this->actingAs($this->manager, 'staff')->get($url);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain($filename)
        ->and($response->streamedContent())->toContain(PHP_EOL);
})->with([
    'ingredients' => ['/admin/ingredients/import/sample', 'ingredients-sample.csv'],
    'ingredient categories' => ['/admin/ingredient-categories/import/sample', 'ingredient-categories-sample.csv'],
    'menu items' => ['/admin/menu/items/import/sample', 'menu-items-sample.csv'],
    'menu categories' => ['/admin/menu/categories/import/sample', 'menu-categories-sample.csv'],
    'tables' => ['/admin/tables/import/sample', 'tables-sample.csv'],
    'recipes' => ['/admin/recipes/import/sample', 'recipes-sample.csv'],
]);

it('previews an ingredient csv with every row ready', function (): void {
    $category = IngredientCategory::query()->create(['name' => 'Meat']);

    $csv = csvFile("name,name_bn,unit,category,reorder_level,is_active\nChicken Breast,চিকেন ব্রেস্ট,kg,Meat,2,yes\nMilk,দুধ,l,,4,\n");

    $response = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredients/import/preview', ['file' => $csv]);

    $response->assertOk();
    expect($response->json('counts'))->toBe(['total' => 2, 'importable' => 2, 'errors' => 0])
        ->and($response->json('rows.0.status'))->toBe('valid')
        ->and($response->json('rows.0.data.ingredient_category_id'))->toBe($category->id)
        ->and($response->json('rows.1.status'))->toBe('valid')
        ->and($response->json('rows.1.data.is_active'))->toBeTrue();
});

it('reports rows with unknown lookups, invalid booleans and duplicates inside the file', function (): void {
    $csv = csvFile("name,name_bn,unit,category,reorder_level,is_active\nSalt,,kg,,,\nSalt,,ltr,,,\nSugar,,kg,,,maybe,\n");

    $response = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredients/import/preview', ['file' => $csv]);

    $response->assertOk();
    expect($response->json('counts'))->toBe(['total' => 3, 'importable' => 1, 'errors' => 2])
        ->and($response->json('rows.0.status'))->toBe('valid')
        ->and($response->json('rows.1.status'))->toBe('error')
        ->and($response->json('rows.2.errors.is_active.0'))->toBe(__('common.import_invalid_boolean', ['value' => 'maybe']));
});

it('warns but still imports when an ingredient name already exists', function (): void {
    $kg = unitId('kg');
    Ingredient::factory()->create(['name' => 'Milk', 'unit_id' => $kg]);

    $csv = csvFile("name,name_bn,unit,category,reorder_level,is_active\nMilk,,l,,,");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredients/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('rows.0.status'))->toBe('warning')
        ->and($preview->json('counts.importable'))->toBe(1);

    $import = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredients/import', [
        'rows' => [$preview->json('rows.0.data')],
    ]);

    $import->assertOk()->assertJson(['created' => 1, 'failed' => []]);
    expect(Ingredient::query()->where('name', 'Milk')->count())->toBe(2);
});

it('rejects a csv with missing columns', function (): void {
    $csv = csvFile("name,unit\nSalt,kg\n");

    $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredients/import/preview', ['file' => $csv])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

it('imports only the valid rows of an add-multiple payload and reports the rest', function (): void {
    $kg = unitId('kg');

    $response = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredients/import', [
        'rows' => [
            ['name' => 'Salt', 'name_bn' => null, 'unit_id' => $kg, 'ingredient_category_id' => null, 'reorder_level' => null, 'is_active' => true],
            ['name' => 'Pepper', 'name_bn' => null, 'unit_id' => null, 'ingredient_category_id' => null, 'reorder_level' => null, 'is_active' => true],
        ],
    ]);

    $response->assertOk();
    expect($response->json('created'))->toBe(1)
        ->and($response->json('failed'))->toHaveCount(1)
        ->and($response->json('failed.0.index'))->toBe(2);

    // An empty reorder level means zero, not a null against a NOT NULL column.
    expect(Ingredient::query()->where('name', 'Salt')->firstOrFail()->reorder_level)->toBe('0.000')
        ->and(Ingredient::query()->where('name', 'Pepper')->exists())->toBeFalse();
});

it('imports menu items from a csv into the acting branch', function (): void {
    $category = MenuCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Rice']);
    MenuCategory::factory()->create(['branch_id' => Branch::factory()->create(['code' => 'BR02'])->id, 'name' => 'Rice']);

    $csv = csvFile("category,name,name_bn,description,description_bn,price,is_available,is_vegetarian,barcode,sort_order\nRice,Biryani,বিরিয়ানি,,,350,yes,no,,1\n");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/menu/items/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('counts.importable'))->toBe(1);

    $import = $this->actingAs($this->manager, 'staff')->postJson('/admin/menu/items/import', [
        'rows' => [$preview->json('rows.0.data')],
    ]);

    $import->assertOk()->assertJson(['created' => 1, 'failed' => []]);

    $item = MenuItem::query()->where('name', 'Biryani')->firstOrFail();

    expect($item->branch_id)->toBe($this->branch->id)
        ->and($item->menu_category_id)->toBe($category->id)
        ->and($item->base_price)->toBe('350.00');
});

it('errors on menu item rows whose category does not exist in the branch', function (): void {
    $csv = csvFile("category,name,name_bn,description,description_bn,price,is_available,is_vegetarian,barcode,sort_order\nDrinks,Lassi,,,,80,yes,yes,,\n");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/menu/items/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('counts'))->toBe(['total' => 1, 'importable' => 0, 'errors' => 1])
        ->and($preview->json('rows.0.errors.category.0'))->toBe(__('menu.import_unknown_category', ['value' => 'Drinks']));
});

it('rejects a menu item barcode that the branch already uses', function (): void {
    MenuCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Rice']);
    MenuItem::factory()->create(['branch_id' => $this->branch->id, 'barcode' => 'RICE-1']);

    $csv = csvFile("category,name,name_bn,description,description_bn,price,is_available,is_vegetarian,barcode,sort_order\nRice,Biryani,,,,350,yes,no,RICE-1,\n");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/menu/items/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('counts.errors'))->toBe(1)
        ->and($preview->json('rows.0.status'))->toBe('error');
});

it('imports tables and refuses names the branch already has', function (): void {
    RestaurantTable::factory()->create(['branch_id' => $this->branch->id, 'name' => 'T1']);

    $csv = csvFile("name,area,seats,is_active\nT1,Garden,4,yes\nT2,Garden,4,yes\n");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/tables/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('counts'))->toBe(['total' => 2, 'importable' => 1, 'errors' => 1]);

    $import = $this->actingAs($this->manager, 'staff')->postJson('/admin/tables/import', [
        'rows' => array_map(fn (array $row): array => $row['data'], array_filter($preview->json('rows'), fn (array $row): bool => $row['status'] !== 'error')),
    ]);

    $import->assertOk()->assertJson(['created' => 1, 'failed' => []]);

    $table = RestaurantTable::query()->where('name', 'T2')->firstOrFail();

    expect($table->branch_id)->toBe($this->branch->id)
        ->and($table->seats)->toBe(4)
        ->and($table->status)->toBe('free');
});

it('groups recipe csv rows by menu item, converts units and replaces an existing recipe', function (): void {
    $kg = unitId('kg');
    $rice = MenuCategory::factory()->create(['branch_id' => $this->branch->id]);
    $biryani = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'menu_category_id' => $rice->id, 'name' => 'Biryani']);
    $chicken = Ingredient::factory()->create(['name' => 'Chicken Breast', 'unit_id' => $kg]);
    Ingredient::factory()->create(['name' => 'Basmati Rice', 'unit_id' => $kg]);

    Recipe::query()->create(['menu_item_id' => $biryani->id, 'note' => 'old']);
    $biryani->recipe->items()->create(['ingredient_id' => $chicken->id, 'qty' => 999]);

    $csv = csvFile("menu_item,ingredient,qty,unit,note\nBiryani,Chicken Breast,0.25,kg,new note\nBiryani,Basmati Rice,0.15,kg,\n");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/recipes/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('counts.importable'))->toBe(2)
        ->and($preview->json('rows.0.status'))->toBe('warning');

    $import = $this->actingAs($this->manager, 'staff')->postJson('/admin/recipes/import', [
        'rows' => array_map(fn (array $row): array => $row['data'], array_filter($preview->json('rows'), fn (array $row): bool => $row['status'] !== 'error')),
    ]);

    $import->assertOk()->assertJson(['created' => 1, 'failed' => []]);

    $recipe = Recipe::query()->where('menu_item_id', $biryani->id)->firstOrFail()->load('items');

    expect($recipe->note)->toBe('new note')
        ->and($recipe->items->pluck('qty')->all())->toBe(['250.000000', '150.000000']);
});

it('imports ingredient categories and blocks duplicate names', function (): void {
    IngredientCategory::query()->create(['name' => 'Meat']);

    $csv = csvFile("name,name_bn,sort_order,is_active\nMeat,মাংস,1,yes\nSpices,মসলা,3,yes\n");

    $preview = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredient-categories/import/preview', ['file' => $csv]);

    $preview->assertOk();
    expect($preview->json('counts'))->toBe(['total' => 2, 'importable' => 1, 'errors' => 1]);

    $import = $this->actingAs($this->manager, 'staff')->postJson('/admin/ingredient-categories/import', [
        'rows' => [$preview->json('rows.1.data')],
    ]);

    $import->assertOk()->assertJson(['created' => 1, 'failed' => []]);
    expect(IngredientCategory::query()->where('name', 'Spices')->firstOrFail()->sort_order)->toBe(3);
});

it('forbids a staff member without the manage permission', function (): void {
    $waiter = User::factory()->role('waiter', $this->branch->id)->create();
    $csv = csvFile("name,name_bn,unit,category,reorder_level,is_active\nSalt,,kg,,,\n");

    $this->actingAs($waiter, 'staff')->get('/admin/ingredients/import/sample')->assertForbidden();
    $this->actingAs($waiter, 'staff')->postJson('/admin/ingredients/import/preview', ['file' => $csv])->assertForbidden();
    $this->actingAs($waiter, 'staff')->postJson('/admin/ingredients/import', ['rows' => [['name' => 'Salt']]])->assertForbidden();
});

function unitId(string $symbol): int
{
    return (int) Unit::query()->where('symbol', $symbol)->value('id');
}
