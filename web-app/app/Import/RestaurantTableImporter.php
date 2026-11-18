<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\Branch;
use App\Models\DataVersion;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant tables by CSV: any mix of names, areas and seat counts the
 * range builder cannot express. Names stay unique per branch, in the file
 * and against the database.
 */
class RestaurantTableImporter extends Importer
{
    public function __construct(
        private readonly ?User $user,
        private readonly Branch $branch,
    ) {}

    public function headers(): array
    {
        return ['name', 'area', 'seats', 'is_active'];
    }

    public function sampleRows(): array
    {
        return [
            ['name' => 'T1', 'area' => 'Garden', 'seats' => '4', 'is_active' => 'yes'],
            ['name' => 'T2', 'area' => 'Garden', 'seats' => '4', 'is_active' => 'yes'],
            ['name' => 'T3', 'area' => 'Indoor', 'seats' => '2', 'is_active' => 'yes'],
        ];
    }

    public function filename(): string
    {
        return 'tables-sample';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'not_regex:/[\\/]/'],
            'area' => ['nullable', 'string', 'max:50'],
            'seats' => ['required', 'integer', 'min:1', 'max:30'],
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

        $seats = $this->nullableValue((string) ($row['seats'] ?? ''));

        return [
            'data' => [
                'name' => (string) $row['name'],
                'area' => $this->nullableValue((string) ($row['area'] ?? '')),
                'seats' => $seats === null ? null : (int) $seats,
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
     * Table names are unique per branch, exactly like the single and range
     * create forms.
     */
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

        $existing = RestaurantTable::query()
            ->where('branch_id', $this->branch->id)
            ->whereIn(DB::raw('lower(name)'), $names)
            ->pluck('name')
            ->map(fn (string $name): string => self::key($name))
            ->all();

        $conflicts = [];

        foreach ($rows as $offset => $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name !== '' && in_array(self::key($name), $existing, true)) {
                $conflicts[$offset] = ['name' => [__('tables.import_name_conflict', ['value' => $name])]];
            }
        }

        return $conflicts;
    }

    protected function createAll(array $rows): int
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                RestaurantTable::query()->create([
                    'branch_id' => $this->branch->id,
                    'name' => (string) $row['name'],
                    'area' => $row['area'] ?? null,
                    'seats' => (int) $row['seats'],
                    'status' => 'free',
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]);
            }
        });

        DataVersion::bump();

        activity()
            ->causedBy($this->user)
            ->withProperties(['branch_id' => $this->branch->id, 'count' => count($rows)])
            ->log('tables.imported');

        return count($rows);
    }
}
