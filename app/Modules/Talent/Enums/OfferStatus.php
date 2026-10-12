<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of an offer. Tracking only: no contract is generated. */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
