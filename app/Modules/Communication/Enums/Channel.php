<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

/** An outbound message channel. */
enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';

    /**
     * The address in the form it is compared and suppressed in, or null when it is not an address
     * of this channel. Email is lower-cased; phone numbers keep digits and a leading plus.
     */
    public function normalize(string $address): ?string
    {
        $address = trim($address);

        if ($this === self::Email) {
            $address = mb_strtolower($address);

            return strlen($address) <= 190 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
        }

        $plus = str_starts_with($address, '+') ? '+' : '';
        $digits = preg_replace('/\D+/', '', $address) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $plus.$digits : null;
    }

    /** Whether messages of this channel carry a subject line. */
    public function hasSubject(): bool
    {
        return $this === self::Email;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
