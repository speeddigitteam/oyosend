<?php

declare(strict_types=1);

namespace App\Core\Reporting\Support;

/**
 * Builds a CSV that a spreadsheet cannot turn into a formula. Text cells that begin (after any
 * leading whitespace) with `=`, `+`, `-`, `@`, a tab, a carriage return or a line feed get a
 * leading apostrophe; numbers are written as numbers. Every field is quoted. The file starts
 * with a UTF-8 byte order mark so Excel reads Bengali text correctly.
 */
final class CsvWriter
{
    public const string BOM = "\xEF\xBB\xBF";

    private const string TRIGGERS = "=+-@\t\r\n";

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|bool|null>>  $rows
     */
    public static function render(array $header, iterable $rows): string
    {
        $out = self::BOM.self::line($header);

        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /** A text cell made safe. */
    public static function safe(string $value): string
    {
        $probe = ltrim($value, " \u{00A0}");

        if ($probe !== '' && str_contains(self::TRIGGERS, $probe[0])) {
            return "'".$value;
        }

        return $value;
    }

    /** @param  list<string|int|float|bool|null>  $cells */
    private static function line(array $cells): string
    {
        $fields = [];

        foreach ($cells as $cell) {
            $text = match (true) {
                $cell === null => '',
                is_bool($cell) => $cell ? '1' : '0',
                is_int($cell) => (string) $cell,
                is_float($cell) => rtrim(rtrim(number_format($cell, 6, '.', ''), '0'), '.'),
                default => self::safe($cell),
            };

            $fields[] = '"'.str_replace('"', '""', $text).'"';
        }

        return implode(',', $fields)."\r\n";
    }
}
