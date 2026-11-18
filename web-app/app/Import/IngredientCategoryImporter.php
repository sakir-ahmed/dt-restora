<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\IngredientCategory;
use Illuminate\Support\Facades\DB;

/**
 * Ingredient categories by CSV. Names must be unique, in the file and
 * against the database, exactly like the single-create form.
 */
class IngredientCategoryImporter extends Importer
{
    public function headers(): array
    {
        return ['name', 'name_bn', 'sort_order', 'is_active'];
    }

    public function sampleRows(): array
    {
        return [
            ['name' => 'Meat', 'name_bn' => 'মাংস', 'sort_order' => '1', 'is_active' => 'yes'],
            ['name' => 'Vegetables', 'name_bn' => 'সবজি', 'sort_order' => '2', 'is_active' => 'yes'],
            ['name' => 'Spices', 'name_bn' => 'মসলা', 'sort_order' => '3', 'is_active' => 'yes'],
        ];
    }

    public function filename(): string
    {
        return 'ingredient-categories-sample';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_bn' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    protected function normalizeRow(array $row): array
    {
        $errors = [];

        $activeCell = trim((string) ($row['is_active'] ?? ''));
        $isActive = $this->boolValue($activeCell);

        if ($isActive === null && $activeCell !== '') {
            $errors['is_active'] = [__('common.import_invalid_boolean', ['value' => $activeCell])];
        }

        $isActive ??= true;

        $sortOrder = $this->nullableValue((string) ($row['sort_order'] ?? ''));

        return [
            'data' => [
                'name' => (string) $row['name'],
                'name_bn' => $this->nullableValue((string) ($row['name_bn'] ?? '')),
                'sort_order' => $sortOrder === null ? 0 : (int) $sortOrder,
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

    protected function existingConflicts(array $rows): array
    {
        $names = collect($rows)
            ->map(fn (array $row): string => self::key((string) ($row['name'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return [];
        }

        $existing = IngredientCategory::query()
            ->whereIn(DB::raw('lower(name)'), $names)
            ->pluck('name')
            ->map(fn (string $name): string => self::key($name))
            ->all();

        $conflicts = [];

        foreach ($rows as $offset => $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '' && in_array(self::key($name), $existing, true)) {
                $conflicts[$offset] = ['name' => [__('inventory.import_category_exists', ['value' => $name])]];
            }
        }

        return $conflicts;
    }

    protected function createAll(array $rows): int
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                IngredientCategory::query()->create([
                    'name' => $row['name'],
                    'name_bn' => $row['name_bn'] ?? null,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]);
            }
        });

        return count($rows);
    }
}
