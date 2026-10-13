<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Support;

use App\Core\Communication\Support\PhoneNumber;

/**
 * WhatsApp addresses people by their full international number. This is the platform's one canonical
 * normalizer ({@see PhoneNumber}): a Bangladesh number in any usual spelling (`01711000000`, `8801711000000`,
 * `+8801711000000`) is one address; a number of another country is used only when it already carries its
 * country code. Nothing else is guessed.
 */
final class Phone
{
    /** The number in E.164 form with the plus sign, or null when it cannot be read without guessing. */
    public static function e164(?string $raw): ?string
    {
        return PhoneNumber::normalize($raw);
    }

    /** A number with all but the last four digits hidden, for places that must not show the whole number. */
    public static function masked(string $e164): string
    {
        $digits = ltrim($e164, '+');

        return '+'.str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
