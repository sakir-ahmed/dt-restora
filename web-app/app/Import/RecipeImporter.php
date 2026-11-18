<?php

declare(strict_types=1);

namespace App\Import;

use App\Actions\Inventory\SaveRecipeAction;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Recipes by CSV: one row per ingredient line, grouped by menu item. The
 * note of a group's first row becomes the recipe note. A line without a
 * unit is already in the ingredient's base unit; a line with one must be
 * compatible, checked up front so the preview can say so. Saving goes
 * through SaveRecipeAction, so conversions and the update-or-create
 * semantics are exactly those of the recipe form.
 */
class RecipeImporter extends Importer
{
    /** @var array<string, int>|null */
    private ?array $itemLookup = null;

    /** @var array<string, array{id: int, unit: ?Unit}>|null */
    private ?array $ingredientLookup = null;

    /** @var array<string, Unit>|null */
    private ?array $unitLookup = null;

    public function __construct(
        private readonly ?User $user,
        private readonly Branch $branch,
    ) {}

    public function headers(): array
    {
        return ['menu_item', 'ingredient', 'qty', 'unit', 'note'];
    }

    public function sampleRows(): array
    {
        return [
            ['menu_item' => 'Biryani', 'ingredient' => 'Chicken Breast', 'qty' => '0.25', 'unit' => 'kg', 'note' => 'Biryani recipe'],
            ['menu_item' => 'Biryani', 'ingredient' => 'Basmati Rice', 'qty' => '0.15', 'unit' => 'kg', 'note' => ''],
            ['menu_item' => 'Mango Lassi', 'ingredient' => 'Milk', 'qty' => '0.2', 'unit' => 'l', 'note' => 'Lassi recipe'],
        ];
    }

    public function filename(): string
    {
        return 'recipes-sample';
    }

    public function rules(): array
    {
        return [
            'menu_item_id' => ['required', 'integer', Rule::exists('menu_items', 'id')->where('branch_id', $this->branch->id)],
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function normalizeRow(array $row): array
    {
        $errors = [];

        $itemId = $this->itemLookup()[self::key((string) $row['menu_item'])] ?? null;

        if ($itemId === null) {
            $errors['menu_item'] = [__('inventory.import_unknown_menu_item', ['value' => (string) $row['menu_item']])];
        }

        $ingredient = $this->ingredientLookup()[self::key((string) $row['ingredient'])] ?? null;

        if ($ingredient === null) {
            $errors['ingredient'] = [__('inventory.unknown_ingredient')];
        }

        $unitId = null;

        if (($row['unit'] ?? '') !== '') {
            $unit = $this->unitLookup()[self::key((string) $row['unit'])] ?? null;

            if ($unit === null) {
                $errors['unit'] = [__('inventory.import_unknown_unit', ['value' => (string) $row['unit']])];
            } elseif ($ingredient !== null && $ingredient['unit'] !== null && ! $unit->isCompatibleWith($ingredient['unit'])) {
                $errors['unit'] = [__('inventory.unit_incompatible')];
            } else {
                $unitId = $unit->id;
            }
        }

        return [
            'data' => [
                'menu_item_id' => $itemId,
                'ingredient_id' => $ingredient['id'] ?? null,
                'qty' => $this->nullableValue((string) ($row['qty'] ?? '')),
                'unit_id' => $unitId,
                'note' => $this->nullableValue((string) ($row['note'] ?? '')),
            ],
            'errors' => $errors,
        ];
    }

    protected function duplicateField(): ?string
    {
        return 'ingredient';
    }

    protected function duplicateKey(array $row): ?string
    {
        $itemId = (string) ($row['menu_item_id'] ?? '');
        $ingredientId = (string) ($row['ingredient_id'] ?? '');

        return $itemId === '' || $ingredientId === '' ? null : $itemId.'|'.$ingredientId;
    }

    protected function duplicateMessage(): string
    {
        return __('inventory.import_duplicate_line');
    }

    /**
     * Recipes save through update-or-create, so an item that already has
     * one will have it replaced; the preview says so instead of failing.
     */
    protected function existingWarnings(array $rows): array
    {
        $itemIds = collect($rows)
            ->map(fn (array $row): int => (int) ($row['menu_item_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        $withRecipe = Recipe::query()->whereIn('menu_item_id', $itemIds)->pluck('menu_item_id')->all();
        $names = MenuItem::query()->whereKey($itemIds)->pluck('name', 'id');

        $warnings = [];

        foreach ($rows as $offset => $row) {
            $itemId = (int) ($row['menu_item_id'] ?? 0);

            if (in_array($itemId, $withRecipe, true)) {
                $warnings[$offset] = __('inventory.import_recipe_exists', ['value' => (string) ($names[$itemId] ?? '')]);
            }
        }

        return $warnings;
    }

    protected function createAll(array $rows): int
    {
        $groups = [];

        foreach ($rows as $row) {
            $itemId = (int) $row['menu_item_id'];

            if (! isset($groups[$itemId])) {
                $groups[$itemId] = ['note' => $row['note'] ?? null, 'lines' => []];
            }

            $groups[$itemId]['lines'][] = [
                'ingredient_id' => (int) $row['ingredient_id'],
                'qty' => $row['qty'],
                'unit_id' => $row['unit_id'] ?? null,
            ];
        }

        $items = MenuItem::query()->findMany(array_keys($groups))->keyBy('id');
        $action = new SaveRecipeAction;

        DB::transaction(function () use ($groups, $items, $action): void {
            foreach ($groups as $itemId => $group) {
                $action->execute($items[$itemId], $group['lines'], $group['note'], $this->user);
            }
        });

        return count($groups);
    }

    /**
     * Menu items of the current branch, by English and Bangla name.
     *
     * @return array<string, int>
     */
    private function itemLookup(): array
    {
        if ($this->itemLookup === null) {
            $this->itemLookup = [];

            foreach (MenuItem::query()->where('branch_id', $this->branch->id)->get(['id', 'name', 'name_bn']) as $item) {
                foreach ([$item->name, $item->name_bn] as $label) {
                    $this->itemLookup[self::key((string) $label)] = $item->id;
                }
            }
        }

        return $this->itemLookup;
    }

    /**
     * Every ingredient with its stock unit, for the compatibility check.
     *
     * @return array<string, array{id: int, unit: ?Unit}>
     */
    private function ingredientLookup(): array
    {
        if ($this->ingredientLookup === null) {
            $this->ingredientLookup = [];

            foreach (Ingredient::query()->with('unit')->get(['id', 'name', 'unit_id']) as $ingredient) {
                foreach ([$ingredient->name, $ingredient->name_bn] as $label) {
                    $this->ingredientLookup[self::key((string) $label)] = ['id' => $ingredient->id, 'unit' => $ingredient->unit];
                }
            }
        }

        return $this->ingredientLookup;
    }

    /**
     * @return array<string, Unit>
     */
    private function unitLookup(): array
    {
        if ($this->unitLookup === null) {
            $this->unitLookup = [];

            foreach (Unit::query()->get(['id', 'name', 'name_bn', 'symbol']) as $unit) {
                foreach ([$unit->symbol, $unit->name, $unit->name_bn] as $label) {
                    $this->unitLookup[self::key((string) $label)] = $unit;
                }
            }
        }

        return $this->unitLookup;
    }
}
