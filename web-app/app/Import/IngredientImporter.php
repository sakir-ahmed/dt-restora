<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Ingredients by CSV: name, name_bn, unit, category, reorder_level,
 * is_active. Units resolve by symbol or name, categories by name, so the
 * file stays readable for a person typing it by hand.
 */
class IngredientImporter extends Importer
{
    /** @var array<string, int>|null */
    private ?array $unitLookup = null;

    /** @var array<string, int>|null */
    private ?array $categoryLookup = null;

    public function headers(): array
    {
        return ['name', 'name_bn', 'unit', 'category', 'reorder_level', 'is_active'];
    }

    public function sampleRows(): array
    {
        return [
            ['name' => 'Chicken Breast', 'name_bn' => 'চিকেন ব্রেস্ট', 'unit' => 'kg', 'category' => 'Meat', 'reorder_level' => '2', 'is_active' => 'yes'],
            ['name' => 'Tomato', 'name_bn' => 'টমেটো', 'unit' => 'kg', 'category' => 'Vegetables', 'reorder_level' => '5', 'is_active' => 'yes'],
            ['name' => 'Milk', 'name_bn' => 'দুধ', 'unit' => 'l', 'category' => '', 'reorder_level' => '4', 'is_active' => 'yes'],
        ];
    }

    public function filename(): string
    {
        return 'ingredients-sample';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'ingredient_category_id' => ['nullable', 'integer', 'exists:ingredient_categories,id'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function normalizeRow(array $row): array
    {
        $errors = [];

        $unitId = $this->unitLookup()[self::key((string) $row['unit'])] ?? null;

        if ($unitId === null) {
            $errors['unit'] = [__('inventory.import_unknown_unit', ['value' => (string) $row['unit']])];
        }

        $categoryId = null;

        if (($row['category'] ?? '') !== '') {
            $categoryId = $this->categoryLookup()[self::key((string) $row['category'])] ?? null;

            if ($categoryId === null) {
                $errors['category'] = [__('inventory.import_unknown_category', ['value' => (string) $row['category']])];
            }
        }

        $activeCell = trim((string) ($row['is_active'] ?? ''));
        $isActive = $this->boolValue($activeCell);

        if ($isActive === null && $activeCell !== '') {
            $errors['is_active'] = [__('common.import_invalid_boolean', ['value' => $activeCell])];
        }

        $isActive ??= true;

        return [
            'data' => [
                'name' => (string) $row['name'],
                'name_bn' => $this->nullableValue((string) ($row['name_bn'] ?? '')),
                'unit_id' => $unitId,
                'ingredient_category_id' => $categoryId,
                'reorder_level' => $this->nullableValue((string) ($row['reorder_level'] ?? '')) ?? '0',
                'is_active' => $isActive,
            ],
            'errors' => $errors,
        ];
    }

    protected function duplicateField(): ?string
    {
        return 'name';
    }

    protected function duplicateKey(array $row): ?string
    {
        $name = (string) ($row['name'] ?? '');

        return $name === '' ? null : self::key($name);
    }

    /**
     * Ingredient names carry no unique rule, so an existing name only
     * warns: the preview says so, the import still goes through.
     */
    protected function existingWarnings(array $rows): array
    {
        $existing = Ingredient::query()
            ->whereIn(DB::raw('lower(name)'), $this->names($rows))
            ->pluck('name')
            ->map(fn (string $name): string => self::key($name))
            ->all();

        $warnings = [];

        foreach ($rows as $offset => $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '' && in_array(self::key($name), $existing, true)) {
                $warnings[$offset] = __('inventory.import_name_exists', ['value' => $name]);
            }
        }

        return $warnings;
    }

    protected function createAll(array $rows): int
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                Ingredient::query()->create([
                    'name' => $row['name'],
                    'name_bn' => $row['name_bn'] ?? null,
                    'unit_id' => (int) $row['unit_id'],
                    'ingredient_category_id' => $row['ingredient_category_id'] ?? null,
                    // The column has no null: an empty cell or form field means zero.
                    'reorder_level' => $row['reorder_level'] ?? 0,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]);
            }
        });

        return count($rows);
    }

    /**
     * @return array<string, int>
     */
    private function unitLookup(): array
    {
        if ($this->unitLookup === null) {
            $this->unitLookup = [];

            foreach (Unit::query()->get(['id', 'name', 'name_bn', 'symbol']) as $unit) {
                foreach ([$unit->symbol, $unit->name, $unit->name_bn] as $label) {
                    $this->unitLookup[self::key((string) $label)] = $unit->id;
                }
            }
        }

        return $this->unitLookup;
    }

    /**
     * @return array<string, int>
     */
    private function categoryLookup(): array
    {
        if ($this->categoryLookup === null) {
            $this->categoryLookup = [];

            foreach (IngredientCategory::query()->get(['id', 'name', 'name_bn']) as $category) {
                foreach ([$category->name, $category->name_bn] as $label) {
                    $this->categoryLookup[self::key((string) $label)] = $category->id;
                }
            }
        }

        return $this->categoryLookup;
    }

    /**
     * Lowercased name keys of the rows, ready for the database check.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function names(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): string => self::key((string) ($row['name'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
