<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A candidate accepted an offer. */
final class OfferAccepted extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.offer.accepted';
    }
}
