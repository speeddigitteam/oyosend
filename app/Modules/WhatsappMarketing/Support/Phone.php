<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Support;

/**
 * International phone numbers. WhatsApp addresses people by their full number, so a contact's phone is only
 * used when it is already written with its country code (`+8801711000000`, or `008801711000000`). A national
 * number such as `01711000000` is not guessed at.
 */
final class Phone
{
    /** The number in E.164 form with the plus sign, or null when it is not an international number. */
    public static function e164(?string $raw): ?string
    {
        $number = preg_replace('/[\s().\-]/', '', trim((string) $raw)) ?? '';

        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }

        return preg_match('/^\+[1-9]\d{6,14}$/', $number) === 1 ? $number : null;
    }

    /** A number with all but the last four digits hidden, for places that must not show the whole number. */
    public static function masked(string $e164): string
    {
        $digits = ltrim($e164, '+');

        return '+'.str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
