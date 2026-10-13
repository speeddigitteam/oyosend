<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

use App\Core\Communication\Support\PhoneNumber;

/** An outbound message channel. */
enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';

    /**
     * The address in the form it is compared and suppressed in, or null when it is not an address
     * of this channel. Email is lower-cased; phone numbers are canonical E.164 ({@see PhoneNumber}), so
     * `01712345678`, `8801712345678` and `+8801712345678` are one address.
     */
    public function normalize(string $address): ?string
    {
        $address = trim($address);

        if ($this === self::Email) {
            $address = mb_strtolower($address);

            return strlen($address) <= 190 && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
        }

        return PhoneNumber::normalize($address);
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
