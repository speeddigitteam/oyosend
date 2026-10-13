<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Support;

use App\Core\Billing\Support\Money;

/**
 * Exact conversion of what Meta returns into integers. No float is ever involved.
 *
 * - Insight `spend` is a decimal string in the account currency's major unit ("12.34").
 * - Budgets (`daily_budget`, `lifetime_budget`, `budget_remaining`) are integer strings already in minor units.
 * - Counts (`impressions`, `clicks`, ...) are integer strings.
 *
 * Anything that is not one of those shapes is "not reported" (null), never zero.
 */
final class MinorUnits
{
    /** Largest value stored: a signed 64-bit integer minus headroom. */
    private const int MAX_DIGITS = 18;

    /**
     * "12.34" in a two-decimal currency is 1234; "12" in JPY is 12. Digits beyond the currency's minor unit
     * are rounded half up on the digit string (Meta returns spend to the minor unit, so this is not expected).
     */
    public static function fromDecimal(mixed $value, string $currency): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^(\d{1,15})(?:\.(\d{1,12}))?$/D', trim($value), $match) !== 1) {
            return null;
        }

        $decimals = Money::decimals($currency);
        $fraction = $match[2] ?? '';
        $kept = str_pad(substr($fraction, 0, $decimals), $decimals, '0');
        $minor = (int) ($match[1].$kept);

        if (strlen($fraction) > $decimals && (int) $fraction[$decimals] >= 5) {
            $minor++;
        }

        return $minor;
    }

    /** An integer string already in minor units (budgets). */
    public static function fromInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^\d{1,'.self::MAX_DIGITS.'}$/D', trim($value)) !== 1) {
            return null;
        }

        return (int) trim($value);
    }

    /** A non-negative count. */
    public static function count(mixed $value): ?int
    {
        return self::fromInteger($value);
    }

    /** The budget ceiling or amount typed by a person as a decimal in major units, in minor units; null if not a plain amount. */
    public static function fromInput(string $value, string $currency): ?int
    {
        $value = str_replace([' ', ','], '', trim($value));

        return $value === '' ? null : self::fromDecimal($value, $currency);
    }

    /** "1250.00" for display in forms; exact. */
    public static function toDecimal(int $minor, string $currency): string
    {
        return Money::decimal($minor, $currency);
    }
}
