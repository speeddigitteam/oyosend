<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\DeliveryReport;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\SendReceipt;
use InvalidArgumentException;

/**
 * Outbound marketing messages through the communication platform's outbox.
 *
 * The platform owns provider selection, credentials, consent and suppression (re-checked at worker time),
 * quiet hours, per-channel rate limits, retries and plan-limit metering. A marketing app never talks to a
 * provider. `send()` never delivers inline: it records the message and queues a job.
 *
 * A {@see MarketingMessage} is marketing unless it says otherwise: `MessagingPurpose::Transactional` is accepted
 * for WhatsApp only, for a reply inside the customer-service window of a conversation the customer started.
 *
 * Learn outcomes with the `App\Core\Communication\Events\MarketingMessageStatusChanged` event, and reconcile
 * with `statusFor()` / `statusForMany()`.
 *
 * Without the communication app (or when the workspace is not entitled) availability is `not_configured`
 * and `send()` answers `unavailable` without recording anything. Call inside the active tenant context.
 */
interface ChannelGateway
{
    public const int MAX_BATCH = 1000;

    public function availability(MarketingChannel $channel): ChannelAvailability;

    /**
     * Queue one marketing message. Idempotent per (module, idempotency key): a second call returns the
     * original message's receipt with `replayed = true` and queues nothing.
     *
     * A contact without consent or on the suppression list yields a recorded `blocked` receipt. A missing
     * contact/address or an unavailable channel yields `unavailable` and records nothing.
     */
    public function send(MarketingMessage $message): SendReceipt;

    /**
     * Only marketing messages created through this gateway are visible; anything else returns null.
     */
    public function statusFor(string $messageId): ?DeliveryReport;

    /**
     * @param  list<string>  $messageIds  Up to MAX_BATCH.
     * @return array<string, DeliveryReport> Keyed by message id; unknown ids are absent.
     *
     * @throws InvalidArgumentException When more than MAX_BATCH ids are given.
     */
    public function statusForMany(array $messageIds): array;
}
