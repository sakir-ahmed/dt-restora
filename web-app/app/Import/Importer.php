<?php

declare(strict_types=1);

namespace App\Import;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared pipeline behind the admin "import from CSV" and "add multiple at
 * once" endpoints. Every row is normalized (names resolved to ids), then
 * validated with the same rules the single-create form uses, then checked
 * against the rest of the file and the database. Rows that survive are
 * created in one transaction; the rest are reported, never silently
 * skipped.
 */
abstract class Importer
{
    /**
     * CSV column headers, in file order.
     *
     * @return array<int, string>
     */
    abstract public function headers(): array;

    /**
     * Example rows shown in the downloadable sample file.
     *
     * @return array<int, array<string, string>>
     */
    abstract public function sampleRows(): array;

    /**
     * Downloadable sample file name, without extension.
     */
    abstract public function filename(): string;

    /**
     * Validation rules for one normalized row, mirroring the resource's
     * single-create FormRequest.
     *
     * @return array<string, array<int, mixed>>
     */
    abstract public function rules(): array;

    /**
     * Create the rows that passed every check. Already wrapped in the
     * caller's validation; run inside a transaction here.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    abstract protected function createAll(array $rows): int;

    /**
     * Turn one raw CSV row into the payload the create rules expect.
     *
     * @param  array<string, string>  $row
     * @return array{data: array<string, mixed>, errors: array<string, array<int, string>>}
     */
    abstract protected function normalizeRow(array $row): array;

    /**
     * Field the in-file duplicate check reports on (null: no check).
     */
    protected function duplicateField(): ?string
    {
        return null;
    }

    /**
     * Key that must be unique within one file, per row (null: no check).
     *
     * @param  array<string, mixed>  $row
     */
    protected function duplicateKey(array $row): ?string
    {
        return null;
    }

    protected function duplicateMessage(): string
    {
        return __('common.import_duplicate_in_file');
    }

    /**
     * Rows that collide with existing records and cannot be imported,
     * keyed by their offset in $rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, array<int, string>>>
     */
    protected function existingConflicts(array $rows): array
    {
        return [];
    }

    /**
     * Non-blocking warnings shown in the preview, keyed by row offset.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    protected function existingWarnings(array $rows): array
    {
        return [];
    }

    public function sample(): StreamedResponse
    {
        $headers = $this->headers();

        $callback = function () use ($headers): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, $headers);

            foreach ($this->sampleRows() as $row) {
                fputcsv($out, array_map(fn (string $header): string => (string) ($row[$header] ?? ''), $headers));
            }

            fclose($out);
        };

        return ResponseFacade::stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$this->filename().'.csv"',
        ]);
    }

    /**
     * Report what an import of this file would do: every row with its raw
     * values, normalized payload, per-field errors, an optional warning and
     * a valid/warning/error status, plus the totals for the footer.
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function preview(CsvFile $csv): array
    {
        $report = [];
        $normalized = [];
        $seen = [];

        foreach ($csv->rows as $offset => $raw) {
            $result = $this->normalizeRow($raw);
            $data = $result['data'];

            $errors = array_merge_recursive($result['errors'], $this->checkRow($data, $seen));

            $normalized[$offset] = $data;
            $report[$offset] = [
                'index' => $offset + 1,
                'raw' => $raw,
                'data' => $data,
                'errors' => $errors,
                'warning' => null,
                'status' => $errors === [] ? 'valid' : 'error',
            ];
        }

        $importable = array_filter(
            $normalized,
            fn (array $data, int $offset): bool => $report[$offset]['errors'] === [],
            ARRAY_FILTER_USE_BOTH,
        );

        $conflicts = $this->existingConflicts($importable);
        $warnings = $this->existingWarnings($importable);

        $errorCount = 0;

        foreach ($report as $offset => &$row) {
            if (isset($conflicts[$offset])) {
                $row['errors'] = array_merge($row['errors'], $conflicts[$offset]);
            }

            if (isset($warnings[$offset]) && $row['errors'] === []) {
                $row['warning'] = $warnings[$offset];
            }

            $row['status'] = $row['errors'] === [] ? ($row['warning'] !== null ? 'warning' : 'valid') : 'error';

            if ($row['status'] === 'error') {
                $errorCount++;
            }
        }

        unset($row);

        return [
            'rows' => array_values($report),
            'counts' => [
                'total' => count($report),
                'importable' => count($report) - $errorCount,
                'errors' => $errorCount,
            ],
        ];
    }

    /**
     * Re-validate rows the client believes are fine (they come from the
     * preview or the add-multiple form; either way nothing is trusted) and
     * create whatever survives.
     *
     * @param  array<int, mixed>  $rows
     * @return array{created: int, failed: array<int, array{index: int, message: string}>}
     */
    public function run(array $rows): array
    {
        $kept = [];
        $failed = [];
        $seen = [];

        foreach (array_values($rows) as $offset => $source) {
            $row = $this->checkableRow((array) $source);

            $errors = $this->checkRow($row, $seen);

            if ($errors !== []) {
                $failed[] = ['index' => $offset + 1, 'message' => implode(' ', Arr::flatten($errors))];

                continue;
            }

            $kept[$offset] = $row;
        }

        $conflicts = $this->existingConflicts($kept);

        foreach ($conflicts as $offset => $messages) {
            $failed[] = ['index' => $offset + 1, 'message' => implode(' ', Arr::flatten($messages))];

            unset($kept[$offset]);
        }

        if ($kept === []) {
            throw ValidationException::withMessages(['rows' => __('common.import_no_valid_rows')]);
        }

        $created = $this->createAll(array_values($kept));

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Keep only the fields this importer owns, so a hand-crafted payload
     * cannot set anything the CSV never could.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function checkableRow(array $row): array
    {
        return collect($row)->only(array_keys($this->rules()))->all();
    }

    /**
     * Rule validation plus the in-file duplicate check for one row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $seen
     * @return array<string, array<int, string>>
     */
    private function checkRow(array $row, array &$seen): array
    {
        $errors = [];

        $validator = Validator::make($row, $this->rules());

        if ($validator->fails()) {
            $errors = $validator->errors()->messages();
        }

        $key = $this->duplicateKey($row);

        if ($key !== null) {
            if (isset($seen[$key])) {
                $errors[$this->duplicateField()] = [$this->duplicateMessage()];
            }

            $seen[$key] = count($seen);
        }

        return $errors;
    }

    /**
     * Read a yes/no cell: 1/true/yes/on and 0/false/no/off, case
     * insensitive. Anything else is null so the importer can report it.
     */
    protected function boolValue(string $value): ?bool
    {
        return match (mb_strtolower($value)) {
            '1', 'true', 'yes', 'y', 'on' => true,
            '0', 'false', 'no', 'n', 'off' => false,
            default => null,
        };
    }

    /**
     * Empty cells become null so optional columns stay empty.
     */
    protected function nullableValue(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Lookup comparison key, shared with the CSV header matching.
     */
    protected static function key(string $value): string
    {
        return CsvFile::key($value);
    }
}
