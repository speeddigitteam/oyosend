<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\AudienceDecision;
use App\Core\Communication\Data\AudienceReason;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MessagingPurpose;
use InvalidArgumentException;

/** Bound when no communication module is installed: nobody may be messaged. */
final class NullMarketingAudience implements MarketingAudience
{
    public function canReceive(string $contactId, MarketingChannel $channel, MessagingPurpose $purpose = MessagingPurpose::Marketing): AudienceDecision
    {
        return AudienceDecision::deny($contactId, $channel, AudienceReason::ChannelUnavailable);
    }

    public function decideMany(array $contactIds, MarketingChannel $channel, MessagingPurpose $purpose = MessagingPurpose::Marketing): array
    {
        if (count($contactIds) > self::MAX_BATCH) {
            throw new InvalidArgumentException('At most '.self::MAX_BATCH.' contacts per call.');
        }

        $decisions = [];

        foreach (array_unique($contactIds) as $id) {
            $decisions[$id] = $this->canReceive($id, $channel, $purpose);
        }

        return $decisions;
    }
}
