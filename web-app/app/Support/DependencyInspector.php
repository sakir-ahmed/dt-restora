<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Finds every record that still uses a given record, straight from the database foreign keys, so a
 * module needs no code of its own to explain a blocked delete and none can be forgotten.
 */
class DependencyInspector
{
    /**
     * What still blocks deleting this record, in the order to clear it: records the user can delete or
     * edit first (largest first), history that is never deleted last.
     *
     * @return array<int, array{label: string, count: int, url: ?string, history: bool}>
     */
    public static function for(Model $model): array
    {
        $found = [];

        foreach (self::referencing($model->getTable()) as [$table, $column]) {
            $map = config("dependencies.{$table}", []);
            $label = (string) __('common.dependencies.'.($map['label'] ?? $table));

            if ($label === 'common.dependencies.'.($map['label'] ?? $table)) {
                $label = ucfirst(str_replace('_', ' ', $table));
            }

            $count = self::count($table, $column, $model->getKey(), $map['parent'] ?? null);

            if ($count === 0) {
                continue;
            }

            $url = isset($map['route']) && \Illuminate\Support\Facades\Route::has($map['route'])
                ? route($map['route'], ! empty($map['search']) ? ['search' => (string) ($model->name ?? '')] : [])
                : null;

            // The same module can block through two tables (a wastage and its lines); keep the larger count.
            if (! isset($found[$label]) || $count > $found[$label]['count']) {
                $found[$label] = ['label' => $label, 'count' => $count, 'url' => $url, 'history' => (bool) ($map['history'] ?? false)];
            }
        }

        $list = array_values($found);

        usort($list, fn (array $a, array $b): int => [$a['history'], -$a['count']] <=> [$b['history'], -$b['count']]);

        return $list;
    }

    /**
     * The same list shaped for the warning dialog: numbered steps, each with a count and a link.
     *
     * @return array<int, array{label: string, value: string, url: ?string}>
     */
    public static function steps(Model $model): array
    {
        return collect(self::for($model))->values()->map(fn (array $row, int $index): array => [
            'label' => ($index + 1).'. '.$row['label'],
            'value' => $row['history']
                ? $row['count'].' ('.__('common.dependencies.history_note').')'
                : (string) $row['count'],
            'url' => $row['url'],
        ])->all();
    }

    private static function count(string $table, string $column, mixed $id, ?array $parent): int
    {
        if ($parent !== null) {
            return (int) DB::table($table)->where($column, $id)->distinct()->count($parent[1]);
        }

        return (int) DB::table($table)->where($column, $id)->count();
    }

    /**
     * Every [table, column] whose foreign key points at this table and would refuse the delete.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function referencing(string $target): array
    {
        $build = function () use ($target): array {
            $found = [];

            foreach (Schema::getTables() as $table) {
                foreach (Schema::getForeignKeys($table['name']) as $key) {
                    if ($key['foreign_table'] === $target && ! in_array(strtolower((string) $key['on_delete']), ['cascade', 'set null'], true)) {
                        $found[] = [$table['name'], $key['columns'][0]];
                    }
                }
            }

            return $found;
        };

        try {
            return Cache::remember("fk-referencing:{$target}", 3600, $build);
        } catch (Throwable) {
            return $build();
        }
    }
}
