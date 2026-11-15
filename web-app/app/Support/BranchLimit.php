<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use Illuminate\Validation\ValidationException;

/**
 * How many branches this installation may have. It is set per customer in .env (MAX_BRANCHES, default 1)
 * and raised only with the vendor's permission. Branches that already exist are never removed by it;
 * it only stops new ones from being added.
 */
final class BranchLimit
{
    public static function max(): int
    {
        return max(1, (int) config('app.max_branches', 1));
    }

    public static function used(): int
    {
        return Branch::query()->count();
    }

    public static function reached(): bool
    {
        return self::used() >= self::max();
    }

    public static function message(): string
    {
        return __('branches.limit_message', ['max' => self::max()]);
    }

    /**
     * @return array{max: int, used: int, reached: bool, message: string}
     */
    public static function summary(): array
    {
        return [
            'max' => self::max(),
            'used' => self::used(),
            'reached' => self::reached(),
            'message' => self::message(),
        ];
    }

    /**
     * @throws ValidationException when no more branches may be added
     */
    public static function assertCanAdd(): void
    {
        if (self::reached()) {
            throw ValidationException::withMessages(['branch' => self::message()]);
        }
    }
}
