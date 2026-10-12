<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Support;

/**
 * Counts how many SMS segments a text takes, the way handsets and carriers do.
 *
 * - A text made only of GSM 03.38 characters is sent as GSM-7: 160 septets in one segment, 153 per segment
 *   once it needs several (the rest of each segment is the concatenation header). Characters of the extension
 *   table (`^ { } \ [ ~ ] | €` and form feed) cost two septets and are never split across segments.
 * - Any other character (Bengali, Arabic, emoji, a typographic quote) makes the whole text UCS-2: 70 code
 *   units in one segment, 67 per segment otherwise. A character outside the Basic Multilingual Plane is a
 *   surrogate pair (two units) and is not split across segments either.
 *
 * The counter is pure: no framework, no I/O.
 */
final class SegmentCounter
{
    public const int GSM_SINGLE = 160;

    public const int GSM_MULTI = 153;

    public const int UCS_SINGLE = 70;

    public const int UCS_MULTI = 67;

    /** The GSM 03.38 default alphabet (each character is one septet). */
    private const string BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** The GSM 03.38 extension table (each character is an escape plus one septet). */
    private const string EXTENDED = "\f^{}\\[~]|€";

    /** @var array<string, true>|null */
    private static ?array $basic = null;

    /** @var array<string, true>|null */
    private static ?array $extended = null;

    public static function count(string $text): SegmentCount
    {
        $characters = self::characters($text);

        if ($characters === []) {
            return new SegmentCount(SegmentCount::GSM7, 0, 0, 0, self::GSM_SINGLE, self::GSM_SINGLE);
        }

        $unicode = [];

        foreach ($characters as $character) {
            if (! self::isGsm($character)) {
                $unicode[$character] = true;
            }
        }

        if ($unicode === []) {
            $widths = array_map(static fn (string $character): int => isset(self::extended()[$character]) ? 2 : 1, $characters);

            return self::pack(SegmentCount::GSM7, $characters, $widths, self::GSM_SINGLE, self::GSM_MULTI, []);
        }

        $widths = array_map(static fn (string $character): int => mb_ord($character, 'UTF-8') > 0xFFFF ? 2 : 1, $characters);

        return self::pack(SegmentCount::UCS2, $characters, $widths, self::UCS_SINGLE, self::UCS_MULTI, array_slice(array_keys($unicode), 0, 5));
    }

    /** Whether every character of the text is in the GSM 03.38 default or extension table. */
    public static function isGsm7(string $text): bool
    {
        foreach (self::characters($text) as $character) {
            if (! self::isGsm($character)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $characters
     * @param  list<int>  $widths
     * @param  list<string>  $unicode
     */
    private static function pack(string $encoding, array $characters, array $widths, int $single, int $multi, array $unicode): SegmentCount
    {
        $units = array_sum($widths);

        if ($units <= $single) {
            return new SegmentCount($encoding, count($characters), $units, 1, $single, $single - $units, $unicode);
        }

        $segments = 1;
        $used = 0;

        foreach ($widths as $width) {
            if ($used + $width > $multi) {
                $segments++;
                $used = 0;
            }

            $used += $width;
        }

        return new SegmentCount($encoding, count($characters), $units, $segments, $multi, $multi - $used, $unicode);
    }

    /** @return list<string> */
    private static function characters(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function isGsm(string $character): bool
    {
        return isset(self::basic()[$character]) || isset(self::extended()[$character]);
    }

    /** @return array<string, true> */
    private static function basic(): array
    {
        return self::$basic ??= array_fill_keys(preg_split('//u', self::BASIC, -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
    }

    /** @return array<string, true> */
    private static function extended(): array
    {
        return self::$extended ??= array_fill_keys(preg_split('//u', self::EXTENDED, -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
    }
}
