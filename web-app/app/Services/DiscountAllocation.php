<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Spreads a bill discount over the lines of an order when some dishes have a maximum discount. A dish with a
 * maximum of 0 is skipped; a dish with a limit takes no more than it, and the rest goes to the other lines.
 */
class DiscountAllocation
{
    /**
     * A percent discount: every line takes that percent of its amount, up to its limit.
     *
     * @param  array<int, float>  $bases  What each line can be discounted from
     * @param  array<int, float>  $caps  The most each line may still take
     * @return array<int, float>
     */
    public static function percent(float $percent, array $bases, array $caps): array
    {
        $shares = [];

        foreach ($bases as $index => $base) {
            $shares[$index] = round(max(0.0, min($base * $percent / 100, $caps[$index], $base)), 2);
        }

        return $shares;
    }

    /**
     * A fixed amount: shared by line amount, a line that would pass its limit keeps the limit and the rest is
     * shared again among the others. The total is the amount, or the sum of the limits when that is less.
     *
     * @param  array<int, float>  $bases
     * @param  array<int, float>  $caps
     * @return array<int, float>
     */
    public static function fixed(float $amount, array $bases, array $caps): array
    {
        $shares = array_fill_keys(array_keys($bases), 0.0);
        $open = array_filter(array_keys($bases), fn (int $index): bool => $caps[$index] > 0 && $bases[$index] > 0);
        $remaining = max(0.0, $amount);

        while ($remaining > 0.004 && $open !== []) {
            $weight = array_sum(array_map(fn (int $index): float => $bases[$index], $open));
            $capped = false;

            foreach ($open as $index) {
                $room = min($caps[$index], $bases[$index]) - $shares[$index];

                if ($remaining * $bases[$index] / $weight >= $room - 0.004) {
                    $shares[$index] += $room;
                    $remaining -= $room;
                    $open = array_values(array_diff($open, [$index]));
                    $capped = true;

                    break;
                }
            }

            if (! $capped) {
                foreach ($open as $index) {
                    $shares[$index] += $remaining * $bases[$index] / $weight;
                }

                $remaining = 0.0;
            }
        }

        return array_map(fn (float $share): float => round($share, 2), $shares);
    }
}
