<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Data\InteractionEntry;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\Data\ProviderEvent;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\ProviderEventType;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Events\MessageBounced;
use App\Modules\Communication\Events\MessageDelivered;
use App\Modules\Communication\Events\MessageFailed;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies verified provider events. Each (provider, event id) is applied once.
 * Callers must have verified the signature first; this class trusts only {@see ProviderEvent} objects.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly SuppressionManager $suppressions,
        private readonly ConsentManager $consents,
        private readonly TimelineRecorder $timeline,
        private readonly ChannelEventRelay $relay,
    ) {}

    /**
     * @param  list<ProviderEvent>  $events
     * @return int How many events changed something (duplicates and unknown messages do not count).
     */
    public function process(ChannelProvider $provider, array $events): int
    {
        $applied = 0;

        foreach ($events as $event) {
            if (! $this->firstTime($provider, $event)) {
                continue;
            }

            $applied += $this->apply($provider, $event) ? 1 : 0;
            $this->relay->forward($provider, $event);
        }

        return $applied;
    }

    private function firstTime(ChannelProvider $provider, ProviderEvent $event): bool
    {
        try {
            WebhookEvent::query()->create([
                'provider' => $provider->key(),
                'event_id' => mb_substr($event->eventId, 0, 190),
                'type' => $event->type->value,
                'provider_message_id' => $event->providerMessageId === null ? null : mb_substr($event->providerMessageId, 0, 190),
                'received_at' => CarbonImmutable::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function apply(ChannelProvider $provider, ProviderEvent $event): bool
    {
        if ($event->type === ProviderEventType::Inbound) {
            return $this->inbound($provider, $event);
        }

        $message = $event->providerMessageId === null ? null : Message::query()
            ->where('provider', $provider->key())
            ->where('provider_message_id', $event->providerMessageId)
            ->first();

        if ($message === null) {
            return false;
        }

        $now = CarbonImmutable::now();

        return match ($event->type) {
            ProviderEventType::Delivered => $this->delivered($message, $now),
            ProviderEventType::Failed => $this->failed($message, $now, $event),
            ProviderEventType::Bounced => $this->bounced($message, $now),
            ProviderEventType::Complained => $this->complained($message),
            // Read receipts and template status only reach channel-event listeners.
            ProviderEventType::Read, ProviderEventType::TemplateStatus => false,
        };
    }

    private function delivered(Message $message, CarbonImmutable $now): bool
    {
        if ($message->status !== MessageStatus::Sent) {
            return false;
        }

        $message->forceFill(['status' => MessageStatus::Delivered, 'delivered_at' => $now])->save();
        event(MessageDelivered::from($message));

        return true;
    }

    private function failed(Message $message, CarbonImmutable $now, ProviderEvent $event): bool
    {
        if (! in_array($message->status, [MessageStatus::Sent, MessageStatus::Sending], true)) {
            return false;
        }

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => $now,
            'failure_code' => 'provider_reported_failure',
            'failure_message' => $event->reason === null ? null : mb_substr($event->reason, 0, 300),
        ])->save();
        event(MessageFailed::from($message));

        return true;
    }

    private function bounced(Message $message, CarbonImmutable $now): bool
    {
        DB::transaction(function () use ($message, $now): void {
            $message->forceFill([
                'status' => MessageStatus::Bounced,
                'failed_at' => $now,
                'failure_code' => 'bounced',
            ])->save();

            $this->suppressions->suppress(
                $message->channel,
                $message->recipient,
                SuppressionScope::All,
                SuppressionReason::Bounced,
                $message->contact_id,
            );
        });
        event(MessageBounced::from($message));

        return true;
    }

    private function complained(Message $message): bool
    {
        DB::transaction(function () use ($message): void {
            $this->suppressions->suppress(
                $message->channel,
                $message->recipient,
                SuppressionScope::All,
                SuppressionReason::Complained,
                $message->contact_id,
            );

            $contact = $message->contact_id === null ? null : Contact::query()->find($message->contact_id);

            if ($contact !== null) {
                $this->consents->record($contact, $message->channel, ConsentStatus::OptedOut, 'complaint');
            }
        });

        return true;
    }

    private function inbound(ChannelProvider $provider, ProviderEvent $event): bool
    {
        $channel = $provider->channel();
        $address = $event->address === null ? null : $channel->normalize($event->address);

        if ($address === null || $event->text === null) {
            return false;
        }

        $contact = $this->contactFor($channel, $address);

        if ($contact === null) {
            return false;
        }

        return $this->timeline->write(new InteractionEntry(
            type: $channel->value,
            summary: mb_substr(trim($event->text), 0, 200) ?: __('Message received'),
            contactId: $contact->id,
            companyId: $contact->company_id,
            body: mb_substr($event->text, 0, 4000),
            direction: 'inbound',
            sourceModule: 'communication',
            sourceType: 'provider_event',
            dedupeKey: 'inbound:'.$provider->key().':'.mb_substr($event->eventId, 0, 120),
        )) !== null;
    }

    private function contactFor(Channel $channel, string $address): ?Contact
    {
        if ($channel === Channel::Email) {
            return Contact::query()->where('email', $address)->first();
        }

        $digits = ltrim($address, '+');
        $strip = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

        return Contact::query()->whereNotNull('phone')->whereRaw($strip.' = ?', [$digits])->first();
    }
}
