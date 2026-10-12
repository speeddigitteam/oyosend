<?php

declare(strict_types=1);

namespace App\Modules\Talent\Support;

/**
 * Progress of a goal or KPI in basis points (10 000 = reached), clamped to 0..10 000 and floored.
 * Works for targets above and below the start value (reduce churn, cut defects).
 */
final class GoalProgress
{
    public static function basisPoints(int $start, int $target, int $current): int
    {
        $span = $target - $start;

        if ($span === 0) {
            return $current === $target ? 10000 : 0;
        }

        $done = intdiv(($current - $start) * 10000, $span);

        return max(0, min(10000, $done));
    }

    public static function reached(int $start, int $target, int $current): bool
    {
        return $target >= $start ? $current >= $target : $current <= $target;
    }
}
