<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\AudienceReason;
use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\DeliveryReport;
use App\Core\Communication\Data\DeliveryState;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\MessagingPurpose;
use App\Core\Communication\Data\SendReceipt;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The implementation of the core {@see ChannelGateway} contract. Everything goes through {@see Outbox::queue()}
 * (idempotency, suppression, consent, plan limit, quiet hours, timeline) and the normal worker (provider
 * registry, rate limit, metering). Nothing here talks to a provider.
 *
 * The message's `source` is the calling module's key. The campaign and recipient ids are kept in `meta` under
 * `marketing_*` keys; the idempotency key is namespaced `{module}:{key}`.
 */
final class MarketingGateway implements ChannelGateway
{
    public const string META_MODULE = 'marketing_module';

    public const string META_CAMPAIGN = 'marketing_campaign_id';

    public const string META_RECIPIENT = 'marketing_recipient_id';

    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly MarketingChannelStatus $status,
        private readonly MarketingAudienceResolver $audience,
        private readonly Outbox $outbox,
    ) {}

    public function availability(MarketingChannel $channel): ChannelAvailability
    {
        return $this->status->check($channel);
    }

    public function send(MarketingMessage $message): SendReceipt
    {
        if (! $this->access->entitled()) {
            return SendReceipt::unavailable('module_unavailable');
        }

        $key = $message->module.':'.$message->idempotencyKey;
        $existing = Message::query()->where('idempotency_key', $key)->first();

        if ($existing !== null) {
            return $this->receipt($existing, null, true);
        }

        $availability = $this->status->check($message->channel);

        if (! $availability->isAvailable()) {
            return SendReceipt::unavailable((string) $availability->reason);
        }

        $contact = Contact::query()->find($message->contactId, ['id', 'email', 'phone']);

        if ($contact === null) {
            return SendReceipt::unavailable(AudienceReason::UnknownContact->value);
        }

        $channel = MarketingChannelStatus::channel($message->channel);
        $address = $channel->normalize((string) ($message->channel === MarketingChannel::Email ? $contact->email : $contact->phone));

        if ($address === null) {
            return SendReceipt::unavailable(AudienceReason::NoAddress->value);
        }

        $decision = $this->audience->canReceive($contact->id, $message->channel, $message->purpose);

        // Provider hints first: they can never replace the marketing references.
        $meta = [...$message->providerHints, self::META_MODULE => $message->module];

        if ($message->campaignId !== null) {
            $meta[self::META_CAMPAIGN] = $message->campaignId;
        }

        if ($message->recipientId !== null) {
            $meta[self::META_RECIPIENT] = $message->recipientId;
        }

        $queued = $this->outbox->queue(new OutboundRequest(
            channel: $channel,
            purpose: $message->purpose === MessagingPurpose::Transactional ? MessagePurpose::Transactional : MessagePurpose::Marketing,
            recipient: $address,
            subject: $message->subject,
            body: $message->body,
            idempotencyKey: $key,
            contactId: $contact->id,
            locale: $message->locale,
            source: $message->module,
            sendAt: $message->sendAt,
            meta: $meta,
        ));

        return $this->receipt($queued, $decision->allowed ? null : $decision->reason->value, false);
    }

    public function statusFor(string $messageId): ?DeliveryReport
    {
        return $this->statusForMany([$messageId])[$messageId] ?? null;
    }

    public function statusForMany(array $messageIds): array
    {
        $ids = array_values(array_unique($messageIds));

        if (count($ids) > self::MAX_BATCH) {
            throw new InvalidArgumentException('At most '.self::MAX_BATCH.' messages per call.');
        }

        if ($ids === [] || ! $this->access->entitled()) {
            return [];
        }

        $reports = [];

        $messages = Message::query()
            ->whereIn('id', $ids)
            ->get();

        foreach ($messages as $message) {
            if (! isset($message->meta[self::META_MODULE])) {
                continue;
            }

            $reports[$message->id] = new DeliveryReport(
                $message->id,
                self::stateOf($message->status),
                $message->failure_code,
                self::immutable($message->sent_at),
                self::immutable($message->delivered_at),
                self::immutable($message->failed_at),
            );
        }

        return $reports;
    }

    public static function stateOf(MessageStatus $status): DeliveryState
    {
        return match ($status) {
            MessageStatus::Queued => DeliveryState::Queued,
            MessageStatus::Sending => DeliveryState::Sending,
            MessageStatus::Sent => DeliveryState::Sent,
            MessageStatus::Delivered => DeliveryState::Delivered,
            MessageStatus::Failed => DeliveryState::Failed,
            MessageStatus::Bounced => DeliveryState::Bounced,
            MessageStatus::Suppressed => DeliveryState::Blocked,
            MessageStatus::Cancelled => DeliveryState::Cancelled,
        };
    }

    private function receipt(Message $message, ?string $blockedReason, bool $replayed): SendReceipt
    {
        $state = self::stateOf($message->status);
        $reason = $message->failure_code;

        if ($state === DeliveryState::Blocked && $blockedReason !== null) {
            $reason = $blockedReason;
        }

        return new SendReceipt(
            $state,
            $message->id,
            $reason,
            $replayed,
            $state === DeliveryState::Queued ? self::immutable($message->next_attempt_at) : null,
        );
    }

    private static function immutable(?CarbonInterface $moment): ?CarbonImmutable
    {
        return $moment === null ? null : CarbonImmutable::instance($moment);
    }
}
