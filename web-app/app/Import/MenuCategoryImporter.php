<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\Branch;
use App\Models\DataVersion;
use App\Models\MenuCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menu categories by CSV, always in the current branch. Names carry no
 * unique rule, so an existing name only warns.
 */
class MenuCategoryImporter extends Importer
{
    public function __construct(
        private readonly ?User $user,
        private readonly Branch $branch,
    ) {}

    public function headers(): array
    {
        return ['name', 'name_bn', 'sort_order', 'is_active'];
    }

    public function sampleRows(): array
    {
        return [
            ['name' => 'Rice', 'name_bn' => 'ভাত', 'sort_order' => '1', 'is_active' => 'yes'],
            ['name' => 'Curry', 'name_bn' => 'তরকারি', 'sort_order' => '2', 'is_active' => 'yes'],
            ['name' => 'Drinks', 'name_bn' => 'পানীয়', 'sort_order' => '3', 'is_active' => 'yes'],
        ];
    }

    public function filename(): string
    {
        return 'menu-categories-sample';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
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

    protected function existingWarnings(array $rows): array
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

        $existing = MenuCategory::query()
            ->where('branch_id', $this->branch->id)
            ->whereIn(DB::raw('lower(name)'), $names)
            ->pluck('name')
            ->map(fn (string $name): string => self::key($name))
            ->all();

        $warnings = [];

        foreach ($rows as $offset => $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '' && in_array(self::key($name), $existing, true)) {
                $warnings[$offset] = __('menu.import_category_name_exists', ['value' => $name]);
            }
        }

        return $warnings;
    }

    protected function createAll(array $rows): int
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                MenuCategory::query()->create([
                    'name' => $row['name'],
                    'name_bn' => $row['name_bn'] ?? null,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'branch_id' => $this->branch->id,
                ]);
            }

            DataVersion::bump();
        });

        activity()
            ->causedBy($this->user)
            ->withProperties(['branch_id' => $this->branch->id, 'count' => count($rows)])
            ->log('menu.categories_imported');

        return count($rows);
    }
}
