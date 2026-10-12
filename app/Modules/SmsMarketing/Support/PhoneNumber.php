<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Support;

/**
 * Phone numbers in E.164, with Bangladesh (+880) understood.
 *
 * A number is accepted when it can be read without guessing:
 *  - with `+` or `00` and a country code (8 to 15 digits in all);
 *  - a Bangladesh mobile number in any of its usual forms: `01712345678`, `1712345678`, `8801712345678`.
 * Anything else (a bare local number of another country, a landline, too few or too many digits) is refused:
 * the caller must say it was not valid rather than text a wrong number. Bengali digits are read as digits.
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

    /** Whether the number is a Bangladesh mobile number (useful for per-country notes). */
    public static function isBangladesh(string $e164): bool
    {
        return str_starts_with($e164, '+880');
    }
}
