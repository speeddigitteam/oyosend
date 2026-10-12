<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Support;

/**
 * CSV output that a spreadsheet cannot execute: a cell that would start a formula
 * (`=`, `+`, `-`, `@`, tab, carriage return) is prefixed with an apostrophe, and every value is quoted by
 * `fputcsv`. Use it for every CSV this module hands out. Input cells are never executed either: the importer
 * reads text and looks it up as a phone number.
 */
final class CsvSafe
{
    public static function cell(string|int|null $value): string
    {
        $text = (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|null>>  $rows
     */
    public static function render(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return '';
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map(static fn (string $cell): string => self::cell($cell), $header), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn (string|int|null $cell): string => self::cell($cell), $row), ',', '"', '');
        }

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        return $output;
    }
}
