<?php

declare(strict_types=1);

namespace App\Core\Communication\Support;

/**
 * The one canonical phone-number form of the communication platform: E.164 with a leading plus.
 *
 * Suppression, consent, conversation matching and every provider address use this form, so the same person is
 * the same key however a contact's phone was typed. Bangladesh (+880) is understood:
 * `01712345678`, `1712345678`, `8801712345678`, `+8801712345678`, `008801712345678` and Bengali digits all
 * become `+8801712345678`. A number of another country is accepted only when it already carries its country
 * code (`+` or `00`): nothing is guessed. Anything else is refused (null) rather than texted to a wrong number.
 */
final class PhoneNumber
{
    private const array BENGALI_DIGITS = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];

    public static function normalize(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        $value = strtr($value, self::BENGALI_DIGITS);

        // `(+880) 1711 000 001`: a bracketed country code. The plus then moves in front of the brackets.
        $value = preg_replace('/^\(\s*\+\s*(\d+)\s*\)/', '+$1', $value) ?? $value;

        // Digits, spaces, dashes, dots and brackets, with a plus only at the start. Letters (`ext 12`, `Tel:`)
        // are refused: stripping them would glue an extension onto the number.
        if (preg_match('/^\+?[0-9\s().\-]+$/u', $value) !== 1) {
            return null;
        }

        // `+880 (0)1712345678`: the bracketed trunk zero after a country code is not part of the number.
        if (str_starts_with($value, '+') || str_starts_with($value, '00')) {
            $value = preg_replace('/\(\s*0\s*\)/', '', $value, 1) ?? $value;
        }

        $international = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (! $international && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if (! $international) {
            $digits = match (true) {
                preg_match('/^01[3-9]\d{8}$/', $digits) === 1 => '880'.substr($digits, 1),
                preg_match('/^1[3-9]\d{8}$/', $digits) === 1 => '880'.$digits,
                preg_match('/^8801[3-9]\d{8}$/', $digits) === 1 => $digits,
                default => '',
            };
        }

        if ($digits === '' || $digits[0] === '0' || strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($digits, '880') && preg_match('/^8801[3-9]\d{8}$/', $digits) !== 1) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * The spellings a contact's phone may have been saved in, for an exact lookup of a normalized number.
     *
     * @return list<string>
     */
    public static function variants(string $e164): array
    {
        $variants = [$e164, substr($e164, 1)];

        if (preg_match('/^\+880(1[3-9]\d{8})$/', $e164, $match) === 1) {
            $variants[] = '0'.$match[1];
            $variants[] = $match[1];
            $variants[] = '+88 0'.$match[1];
            $variants[] = '00880'.$match[1];
        }

        return array_values(array_unique($variants));
    }

    /**
     * The digit-only forms of {@see variants()}: what a stored phone looks like once spaces, dashes,
     * brackets and the plus sign are stripped.
     *
     * @return list<string>
     */
    public static function digitVariants(string $e164): array
    {
        return array_values(array_unique(array_map(
            static fn (string $variant): string => preg_replace('/\D+/', '', $variant) ?? '',
            self::variants($e164),
        )));
    }

    public static function isBangladesh(string $e164): bool
    {
        return str_starts_with($e164, '+880');
    }
}
