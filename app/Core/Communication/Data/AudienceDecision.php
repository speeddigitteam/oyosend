<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** Whether one contact may be messaged on one channel for one purpose, and why. */
final readonly class AudienceDecision
{
    public function __construct(
        public string $contactId,
        public MarketingChannel $channel,
        public bool $allowed,
        public AudienceReason $reason,
    ) {}

    public static function allow(string $contactId, MarketingChannel $channel): self
    {
        return new self($contactId, $channel, true, AudienceReason::Ok);
    }

    public static function deny(string $contactId, MarketingChannel $channel, AudienceReason $reason): self
    {
        return new self($contactId, $channel, false, $reason);
    }
}
