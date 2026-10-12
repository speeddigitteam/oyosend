<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\ChannelEventListener;
use App\Core\Communication\Data\ChannelEvent;
use App\Core\Communication\Data\ChannelEventType;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\Data\ProviderEvent;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ProviderEventType;
use App\Modules\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands a verified, first-seen provider event to the apps that registered a
 * {@see ChannelEventListener} (inbound messages, read receipts, template status). The platform has already applied
 * the event to its own tables. A listener's exception is contained: it is logged without content and never
 * breaks delivery tracking or another listener.
 */
final class ChannelEventRelay
{
    public function __construct(private readonly Container $container) {}

    public function forward(ChannelProvider $provider, ProviderEvent $event): void
    {
        $listeners = iterator_to_array($this->container->tagged(ChannelEventListener::TAG), false);

        if ($listeners === []) {
            return;
        }

        $channel = $provider->channel();
        $address = $event->address === null ? null : $channel->normalize($event->address);
        $message = $event->providerMessageId === null ? null : Message::query()
            ->where('provider', $provider->key())
            ->where('provider_message_id', $event->providerMessageId)
            ->first();

        $channelEvent = new ChannelEvent(
            channel: MarketingChannel::from($channel->value),
            provider: $provider->key(),
            eventId: $event->eventId,
            type: ChannelEventType::from($event->type->value),
            providerMessageId: $event->providerMessageId,
            messageId: $message?->id,
            address: $address ?? $message?->recipient,
            contactId: $message->contact_id ?? ($event->type === ProviderEventType::Inbound ? $this->contactId($channel, $address) : null),
            text: $event->text,
            occurredAt: CarbonImmutable::now(),
            module: is_string($message?->meta[MarketingGateway::META_MODULE] ?? null) ? $message->meta[MarketingGateway::META_MODULE] : null,
            campaignId: is_string($message?->meta[MarketingGateway::META_CAMPAIGN] ?? null) ? $message->meta[MarketingGateway::META_CAMPAIGN] : null,
            recipientId: is_string($message?->meta[MarketingGateway::META_RECIPIENT] ?? null) ? $message->meta[MarketingGateway::META_RECIPIENT] : null,
            details: [
                ...$event->meta,
                ...($event->reason === null ? [] : ['reason' => mb_substr($event->reason, 0, 300)]),
            ],
        );

        foreach ($listeners as $listener) {
            try {
                $listener->handle($channelEvent);
            } catch (Throwable $exception) {
                // Class only: the event may carry a customer's message.
                Log::warning('A channel event listener failed.', [
                    'listener' => $listener::class,
                    'exception' => $exception::class,
                    'event_type' => $event->type->value,
                ]);
            }
        }
    }

    private function contactId(Channel $channel, ?string $address): ?string
    {
        if ($address === null || $channel === Channel::Email) {
            return $channel === Channel::Email && $address !== null
                ? Contact::query()->where('email', $address)->value('id')
                : null;
        }

        $strip = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

        $id = Contact::query()->whereNotNull('phone')->whereRaw($strip.' = ?', [ltrim($address, '+')])->value('id');

        return is_string($id) ? $id : null;
    }
}
