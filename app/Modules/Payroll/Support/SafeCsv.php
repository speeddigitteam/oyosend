<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Core\Billing\Support\Money;

/**
 * CSV output that is safe to open in a spreadsheet.
 *
 * - UTF-8 with a byte-order mark, so Excel reads Bengali names correctly.
 * - Formula injection: any text cell whose first non-blank character is `=`, `+`, `-`, `@`, a tab
 *   or a carriage return gets a leading apostrophe, so it is shown as text and never evaluated.
 * - Amounts are written from integers (`number()`), as plain decimal strings, and are the only
 *   cells that may begin with `-`.
 */
final class SafeCsv
{
    private const string BOM = "\xEF\xBB\xBF";

    /** An exact decimal amount cell ("1250.00"), never run through the injection guard. */
    public static function number(int $minorUnits, string $currency): CsvNumber
    {
        return new CsvNumber(Money::decimal($minorUnits, $currency));
    }

    /** A text cell made safe. */
    public static function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $dangerous = ['=', '+', '-', '@', "\t", "\r"];
        $visible = ltrim($value, " \t\r\n");

        if (in_array($value[0], $dangerous, true) || ($visible !== '' && in_array($visible[0], $dangerous, true))) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|CsvNumber|null>>  $rows
     */
    public static function render(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, self::BOM);
        fputcsv($handle, array_map(self::text(...), $header), ',', '"', '\\', "\r\n");

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (mixed $cell): string => match (true) {
                $cell instanceof CsvNumber => $cell->value,
                is_int($cell) => (string) $cell,
                default => self::text($cell === null ? null : (string) $cell),
            }, $row), ',', '"', '\\', "\r\n");
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
