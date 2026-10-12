<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\AudienceDecision;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MessagingPurpose;
use InvalidArgumentException;

/**
 * May this contact be messaged on this channel? The same answer the outbox itself gives when it sends.
 *
 * Checked in order: channel available, contact and address exist, suppression list, consent. For marketing,
 * no consent record means no. Consent is recorded in the communication app, never here.
 *
 * Always bound. Without the communication app (or when the workspace is not entitled) every answer is
 * `allowed = false` with `channel_unavailable`. Call inside the active tenant context. This is advice for
 * building an audience; the outbox re-checks at send time, so a stale "yes" can never cause a send.
 */
interface MarketingAudience
{
    public const int MAX_BATCH = 1000;

    public function canReceive(
        string $contactId,
        MarketingChannel $channel,
        MessagingPurpose $purpose = MessagingPurpose::Marketing,
    ): AudienceDecision;

    /**
     * A fixed number of queries regardless of list size.
     *
     * @param  list<string>  $contactIds  Up to MAX_BATCH; duplicates are collapsed.
     * @return array<string, AudienceDecision> Keyed by contact id; every requested id is present.
     *
     * @throws InvalidArgumentException When more than MAX_BATCH ids are given: chunk the list.
     */
    public function decideMany(
        array $contactIds,
        MarketingChannel $channel,
        MessagingPurpose $purpose = MessagingPurpose::Marketing,
    ): array;
}
