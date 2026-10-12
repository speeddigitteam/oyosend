<?php

declare(strict_types=1);

namespace App\Core\Billing\Support;

use NumberFormatter;

/**
 * Money on the server is an integer number of minor units plus a currency
 * code. This turns it into text for mail and into the decimal string a
 * payment gateway expects - the only two places the server needs either.
 */
final class Money
{
    /** Currencies whose minor unit is not a hundredth (ISO 4217). */
    private const array DECIMALS = ['JPY' => 0, 'KRW' => 0, 'VND' => 0, 'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3];

    public static function decimals(string $currency): int
    {
        return self::DECIMALS[strtoupper($currency)] ?? 2;
    }

    /**
     * "1250.00" - exact, never through a float.
     */
    public static function decimal(int $minorUnits, string $currency): string
    {
        $decimals = self::decimals($currency);

        if ($decimals === 0) {
            return (string) $minorUnits;
        }

        $scale = 10 ** $decimals;
        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return $sign.intdiv($absolute, $scale).'.'.str_pad((string) ($absolute % $scale), $decimals, '0', STR_PAD_LEFT);
    }

    /**
     * The minor units of a decimal string such as "1250.00" or "1250", or
     * null when it is not a plain non-negative amount. Extra decimals are
     * cut, not rounded: a gateway's answer is compared, never adjusted.
     */
    public static function minorUnits(string $decimal, string $currency): ?int
    {
        if (preg_match('/^(\d{1,15})(?:\.(\d+))?$/D', trim($decimal), $match) !== 1) {
            return null;
        }

        $decimals = self::decimals($currency);
        $fraction = str_pad(substr($match[2] ?? '', 0, $decimals), $decimals, '0');

        return (int) ($match[1].$fraction);
    }

    /**
     * Written the way the locale writes money: "BDT 1,250.00", "৳১,২৫০.০০".
     */
    public static function format(int $minorUnits, string $currency, ?string $locale = null): string
    {
        $plain = strtoupper($currency).' '.self::decimal($minorUnits, $currency);

        // Without the intl extension the amount is still exact, only less pretty.
        if (! class_exists(NumberFormatter::class)) {
            return $plain;
        }

        // NumberFormatter takes a float. Up to 15 significant digits a double round-trips exactly;
        // beyond that the exact decimal text is shown rather than a rounded figure.
        if (abs($minorUnits) >= 10 ** 15) {
            return $plain;
        }

        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($minorUnits / (10 ** self::decimals($currency)), strtoupper($currency));

        return $formatted === false ? $plain : $formatted;
    }
}
