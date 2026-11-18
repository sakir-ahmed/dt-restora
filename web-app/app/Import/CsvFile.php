<?php

declare(strict_types=1);

namespace App\Import;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * One uploaded CSV file, parsed into rows keyed by the expected column
 * headers. The first line must carry those headers (case and spacing are
 * ignored, a UTF-8 BOM is stripped); any extra columns are dropped so a
 * merchant's notes column never breaks an import.
 */
class CsvFile
{
    /** Import caps, shared by the parse step and the route file rules. */
    public const MAX_ROWS = 500;

    /** @var array<int, array<string, string>> */
    public readonly array $rows;

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, string>>  $rows
     */
    private function __construct(
        public readonly array $headers,
        array $rows,
    ) {
        $this->rows = $rows;
    }

    /**
     * Header and lookup comparison key: trimmed and lowercased, so "Name"
     * and "name " both match, in English and Bangla alike.
     */
    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * The CSV upload of a preview request, already validated.
     */
    public static function validatedUpload(Request $request): UploadedFile
    {
        $file = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']])['file'];

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('common.import_unreadable')]);
        }

        return $file;
    }

    /**
     * The normalized rows of an import request, already validated.
     *
     * @return array<int, mixed>
     */
    public static function validatedRows(Request $request): array
    {
        $rows = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*' => ['array'],
        ])['rows'];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param  array<int, string>  $requiredHeaders
     */
    public static function fromUploadedFile(UploadedFile $file, array $requiredHeaders): self
    {
        $handle = fopen($file->getRealPath() ?: 'php://memory', 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('common.import_unreadable')]);
        }

        try {
            $columns = self::columns($handle, $requiredHeaders);

            $rows = [];

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $row = [];
                $empty = true;

                foreach ($requiredHeaders as $header) {
                    $value = trim((string) ($cells[$columns[self::key($header)]] ?? ''));

                    if ($value !== '') {
                        $empty = false;
                    }

                    $row[$header] = $value;
                }

                if (! $empty) {
                    if (count($rows) >= self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => __('common.import_too_many_rows', ['max' => self::MAX_ROWS])]);
                    }

                    $rows[] = $row;
                }
            }

            return new self($requiredHeaders, $rows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Map every expected header to its column position in the file.
     *
     * @param  resource  $handle
     * @param  array<int, string>  $requiredHeaders
     * @return array<string, int>
     */
    private static function columns($handle, array $requiredHeaders): array
    {
        $headerRow = fgetcsv($handle, null, ',', '"', '');

        if ($headerRow === false) {
            throw ValidationException::withMessages(['file' => __('common.import_empty_file')]);
        }

        $columns = [];

        foreach ($headerRow as $position => $cell) {
            $cell = (string) $cell;

            if ($position === 0) {
                $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
            }

            $columns[self::key($cell)] = $position;
        }

        $missing = [];

        foreach ($requiredHeaders as $header) {
            if (! isset($columns[self::key($header)])) {
                $missing[] = $header;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => __('common.import_missing_columns', ['columns' => implode(', ', $missing)])]);
        }

        return $columns;
    }
}
