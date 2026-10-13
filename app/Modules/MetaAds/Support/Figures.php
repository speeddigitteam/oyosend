<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Support;

/**
 * Derived figures, always computed from summed counts and amounts (never an average of ratios) and always
 * integer arithmetic. A missing or zero denominator is "not reported" (null).
 */
final class Figures
{
    /** Clicks per impression in basis points (10000 = 100%). */
    public static function ctr(?int $clicks, ?int $impressions): ?int
    {
        return self::ratio($clicks, $impressions, 10000);
    }

    /** Cost per click, minor units. */
    public static function cpc(?int $spend, ?int $clicks): ?int
    {
        return self::ratio($spend, $clicks, 1);
    }

    /** Cost per thousand impressions, minor units. */
    public static function cpm(?int $spend, ?int $impressions): ?int
    {
        return self::ratio($spend, $impressions, 1000);
    }

    /** Cost per lead, minor units. */
    public static function costPerLead(?int $spend, ?int $leads): ?int
    {
        return self::ratio($spend, $leads, 1);
    }

    /**
     * Change from `$previous` to `$current` in basis points (+1250 = +12.50%); null when there is nothing
     * to compare against.
     */
    public static function delta(?int $current, ?int $previous): ?int
    {
        if ($current === null || $previous === null || $previous === 0) {
            return null;
        }

        return self::divide(($current - $previous) * 10000, $previous);
    }

    private static function ratio(?int $numerator, ?int $denominator, int $scale): ?int
    {
        if ($numerator === null || $denominator === null || $denominator <= 0) {
            return null;
        }

        return self::divide($numerator * $scale, $denominator);
    }

    /** Integer division rounded half away from zero. */
    private static function divide(int $numerator, int $denominator): int
    {
        $quotient = intdiv(abs($numerator) * 2 + abs($denominator), abs($denominator) * 2);

        return $numerator < 0 ? -$quotient : $quotient;
    }
}
