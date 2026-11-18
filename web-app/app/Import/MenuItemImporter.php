<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\Branch;
use App\Models\DataVersion;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Menu items by CSV. Categories resolve by name within the current branch;
 * the branch itself is never taken from the file, matching the item form.
 * Variants and add-ons stay out of imports on purpose: they are edited per
 * item afterwards.
 */
class MenuItemImporter extends Importer
{
    /** @var array<string, int>|null */
    private ?array $categoryLookup = null;

    public function __construct(
        private readonly ?User $user,
        private readonly Branch $branch,
    ) {}

    public function headers(): array
    {
        return ['category', 'name', 'name_bn', 'description', 'description_bn', 'price', 'is_available', 'is_vegetarian', 'barcode', 'sort_order'];
    }

    public function sampleRows(): array
    {
        return [
            ['category' => 'Rice', 'name' => 'Biryani', 'name_bn' => 'বিরিয়ানি', 'description' => 'Basmati biryani with chicken', 'description_bn' => 'চিকেন বিরিয়ানি', 'price' => '350', 'is_available' => 'yes', 'is_vegetarian' => 'no', 'barcode' => '', 'sort_order' => '1'],
            ['category' => 'Drinks', 'name' => 'Mango Lassi', 'name_bn' => 'আম লাচ্ছি', 'description' => 'Chilled mango lassi', 'description_bn' => 'ঠান্ডা আম লাচ্ছি', 'price' => '120', 'is_available' => 'yes', 'is_vegetarian' => 'yes', 'barcode' => '', 'sort_order' => '2'],
        ];
    }

    public function filename(): string
    {
        return 'menu-items-sample';
    }

    public function rules(): array
    {
        return [
            'menu_category_id' => ['required', 'integer', Rule::exists('menu_categories', 'id')->where('branch_id', $this->branch->id)],
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'description_bn' => ['nullable', 'string', 'max:1000'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'is_available' => ['boolean'],
            'is_vegetarian' => ['boolean'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function normalizeRow(array $row): array
    {
        $errors = [];

        $categoryId = $this->categoryLookup()[self::key((string) $row['category'])] ?? null;

        if ($categoryId === null) {
            $errors['category'] = [__('menu.import_unknown_category', ['value' => (string) $row['category']])];
        }

        $availableCell = trim((string) ($row['is_available'] ?? ''));
        $isAvailable = $this->boolValue($availableCell);

        if ($isAvailable === null && $availableCell !== '') {
            $errors['is_available'] = [__('common.import_invalid_boolean', ['value' => $availableCell])];
        }

        $isAvailable ??= true;

        $vegetarianCell = trim((string) ($row['is_vegetarian'] ?? ''));
        $isVegetarian = $this->boolValue($vegetarianCell);

        if ($isVegetarian === null && $vegetarianCell !== '') {
            $errors['is_vegetarian'] = [__('common.import_invalid_boolean', ['value' => $vegetarianCell])];
        }

        $isVegetarian ??= false;

        $sortOrder = $this->nullableValue((string) ($row['sort_order'] ?? ''));

        return [
            'data' => [
                'menu_category_id' => $categoryId,
                'name' => (string) $row['name'],
                'name_bn' => $this->nullableValue((string) ($row['name_bn'] ?? '')),
                'description' => $this->nullableValue((string) ($row['description'] ?? '')),
                'description_bn' => $this->nullableValue((string) ($row['description_bn'] ?? '')),
                'base_price' => $this->nullableValue((string) $row['price']),
                'is_available' => $isAvailable,
                'is_vegetarian' => $isVegetarian,
                'barcode' => $this->nullableValue((string) ($row['barcode'] ?? '')),
                'sort_order' => $sortOrder === null ? 0 : (int) $sortOrder,
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
     * Barcodes are unique per branch, within the file and against the
     * database. Item names carry no rule of their own, so a matching name
     * only warns.
     */
    protected function existingConflicts(array $rows): array
    {
        $conflicts = [];
        $fileBarcodes = [];

        foreach ($rows as $offset => $row) {
            $barcode = $this->nullableValue((string) ($row['barcode'] ?? ''));

            if ($barcode === null) {
                continue;
            }

            $key = self::key($barcode);

            if (isset($fileBarcodes[$key])) {
                $conflicts[$offset] = ['barcode' => [__('menu.import_barcode_exists', ['value' => $barcode])]];
            }

            $fileBarcodes[$key] = $offset;
        }

        $codes = array_keys($fileBarcodes);

        if ($codes !== []) {
            $existing = MenuItem::query()
                ->where('branch_id', $this->branch->id)
                ->whereIn(DB::raw('lower(barcode)'), $codes)
                ->pluck('barcode')
                ->map(fn (string $barcode): string => self::key((string) $barcode))
                ->all();

            foreach ($rows as $offset => $row) {
                $barcode = $this->nullableValue((string) ($row['barcode'] ?? ''));

                if ($barcode !== null && in_array(self::key($barcode), $existing, true)) {
                    $conflicts[$offset] = ['barcode' => [__('menu.import_barcode_exists', ['value' => $barcode])]];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Items are allowed to share a name; the preview just says one exists.
     */
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

        $existing = MenuItem::query()
            ->where('branch_id', $this->branch->id)
            ->whereIn(DB::raw('lower(name)'), $names)
            ->pluck('name')
            ->map(fn (string $name): string => self::key($name))
            ->all();

        $warnings = [];

        foreach ($rows as $offset => $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '' && in_array(self::key($name), $existing, true)) {
                $warnings[$offset] = __('menu.import_name_exists', ['value' => $name]);
            }
        }

        return $warnings;
    }

    protected function createAll(array $rows): int
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                MenuItem::query()->create([
                    ...$row,
                    'branch_id' => $this->branch->id,
                    'menu_category_id' => (int) $row['menu_category_id'],
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ]);
            }

            DataVersion::bump();
        });

        activity()
            ->causedBy($this->user)
            ->withProperties(['branch_id' => $this->branch->id, 'count' => count($rows)])
            ->log('menu.items_imported');

        return count($rows);
    }

    /**
     * Categories of the current branch, by English and Bangla name.
     *
     * @return array<string, int>
     */
    private function categoryLookup(): array
    {
        if ($this->categoryLookup === null) {
            $this->categoryLookup = [];

            foreach (MenuCategory::query()->where('branch_id', $this->branch->id)->get(['id', 'name', 'name_bn']) as $category) {
                foreach ([$category->name, $category->name_bn] as $label) {
                    $this->categoryLookup[self::key((string) $label)] = $category->id;
                }
            }
        }

        return $this->categoryLookup;
    }
}
