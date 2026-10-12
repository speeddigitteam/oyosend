<?php

declare(strict_types=1);

namespace App\Core\Reporting;

/**
 * What a report column holds. The server only ever sends the raw value; the client formats it
 * (`useFormat()`): money from integer minor units next to a currency, ratios from basis points.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Integer = 'integer';
    /** Integer minor units; pairs with a currency column. */
    case Money = 'money';
    /** Integer basis points: 10000 = 100%. */
    case BasisPoints = 'basis_points';
    /** Integer hundredths: 450 = 4.50 (average ratings). */
    case Hundredths = 'hundredths';
    /** Whole minutes of elapsed or worked time. */
    case Minutes = 'minutes';
    /** A local calendar date, `Y-m-d`. */
    case Date = 'date';
    /** A UTC instant in ISO-8601. */
    case DateTime = 'datetime';

    /** Whether the value is a number (never a string a spreadsheet could read as a formula). */
    public function isNumeric(): bool
    {
        return in_array($this, [self::Integer, self::Money, self::BasisPoints, self::Hundredths, self::Minutes], true);
    }

    /** Suffix appended to the CSV header so a raw number is never mistaken for a formatted one. */
    public function unitSuffix(): string
    {
        return match ($this) {
            self::Money => ' (minor units)',
            self::BasisPoints => ' (basis points)',
            self::Hundredths => ' (hundredths)',
            self::Minutes => ' (minutes)',
            default => '',
        };
    }
}
