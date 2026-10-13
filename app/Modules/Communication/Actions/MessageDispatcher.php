<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Billing\UsageMeter;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Channels\Contracts\ChannelProvider;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Events\MessageFailed;
use App\Modules\Communication\Events\MessageSent;
use App\Modules\Communication\Jobs\SendMessage;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Takes one queued message through delivery. Runs inside the queued {@see SendMessage}.
 *
 * Every re-check happens here, at worker time, because the world may have changed since the message was queued:
 * the app may have been deactivated, the contact may have unsubscribed, quiet hours may have begun.
 */
final class MessageDispatcher
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly ChannelConnections $connections,
        private readonly CredentialResolver $credentials,
        private readonly Outbox $outbox,
        private readonly QuietHours $quietHours,
        private readonly CommunicationSettings $settings,
        private readonly UnsubscribeLinks $unsubscribe,
        private readonly UsageMeter $meter,
        private readonly TenantContext $context,
    ) {}

    public function deliver(string $messageId): void
    {
        $message = $this->claim($messageId);

        if ($message === null) {
            return;
        }

        ['connection' => $connection, 'provider' => $provider] = $this->connections->resolve($message->channel);
        $result = $this->attempt($message, $connection, $provider);

        $this->settle($message, $provider, $result);
    }

    /**
     * Decide whether this message may be attempted now, and mark it `sending`. Everything that can end or
     * postpone a message without calling a provider is handled here.
     */
    private function claim(string $messageId): ?Message
    {
        return DB::transaction(function () use ($messageId): ?Message {
            $message = Message::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($message === null || $message->status !== MessageStatus::Queued) {
                return null;
            }

            $now = CarbonImmutable::now();

            if ($message->next_attempt_at !== null && $message->next_attempt_at->isFuture()) {
                // Dispatched early (a delayed job on a driver without delay): the scheduler picks it up when due.
                Message::query()->whereKey($message->id)->update(['dispatched_at' => null]);

                return null;
            }

            if (! $this->access->entitled()) {
                return $this->end($message, MessageStatus::Cancelled, 'module_not_entitled');
            }

            $reason = $this->outbox->blockedReason($message->channel, $message->purpose, $message->recipient, $message->contact_id);

            if ($reason !== null) {
                return $this->end($message, MessageStatus::Suppressed, $reason);
            }

            if ($message->purpose === MessagePurpose::Marketing) {
                $quietEnds = $this->quietHours->endsAt($now);

                if ($quietEnds !== null) {
                    $this->postpone($message, $quietEnds);

                    return null;
                }
            }

            // The limit was checked when the message was queued, but a campaign may have queued far more than
            // the plan has left: the allowance is spent as messages are accepted, so it is checked again here.
            if (! $this->outbox->hasRoom($message->id, inFlightOnly: true)) {
                return $this->end($message, MessageStatus::Failed, Outbox::FAILED_LIMIT);
            }

            $resolved = $this->connections->resolve($message->channel);

            if (! $resolved['connection']->is_enabled) {
                return $this->end($message, MessageStatus::Failed, 'channel_disabled');
            }

            if ($this->connections->stateOf($resolved['connection'], $resolved['provider']) === ConfigurationState::NotConfigured) {
                return $this->end($message, MessageStatus::Failed, 'provider_not_configured');
            }

            $key = 'communication:send:'.$message->channel->value;
            $perMinute = $this->connections->rateLimit($message->channel);

            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                $this->postpone($message, $now->addSeconds(max(1, RateLimiter::availableIn($key))));

                return null;
            }

            RateLimiter::hit($key, 60);

            $message->forceFill([
                'status' => MessageStatus::Sending,
                'attempts' => $message->attempts + 1,
                'sending_at' => $now,
                'provider' => $resolved['provider']->key(),
            ])->save();

            return $message;
        });
    }

    private function attempt(Message $message, ChannelConnection $connection, ChannelProvider $provider): SendResult
    {
        try {
            $settings = $this->settings->current();
            $unsubscribeUrl = $message->purpose === MessagePurpose::Marketing
                ? $this->unsubscribe->issue($message)
                : null;

            $payload = new OutboundPayload(
                messageId: $message->id,
                channel: $message->channel,
                to: $message->recipient,
                subject: $message->subject,
                body: $message->body,
                locale: $message->locale,
                fromName: $settings->from_name ?? $this->context->require()->name,
                replyTo: $settings->reply_to_email,
                footer: $message->purpose === MessagePurpose::Marketing ? $settings->footer_text : null,
                unsubscribeUrl: $unsubscribeUrl,
                meta: [...($connection->settings ?? []), ...($message->meta ?? [])],
            );

            $credentials = $this->credentials->resolve($connection->credential_ref, $connection->provider);

            return $provider->send($payload, $connection, $credentials);
        } catch (Throwable $exception) {
            // Never the message: an adapter or transport exception may carry connection details.
            return SendResult::retry('provider_error', class_basename($exception));
        }
    }

    private function settle(Message $message, ChannelProvider $provider, SendResult $result): void
    {
        $now = CarbonImmutable::now();

        if ($result->outcome === SendResult::ACCEPTED) {
            $message->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => $now,
                'provider_message_id' => $result->providerMessageId,
                'failure_code' => null,
                'failure_message' => null,
                'next_attempt_at' => null,
            ])->save();

            $this->meter->record(Outbox::LIMIT, 1, 'message:'.$message->id, [
                'channel' => $message->channel->value,
                'purpose' => $message->purpose->value,
            ], $now);

            event(MessageSent::from($message));

            return;
        }

        $retries = $result->outcome === SendResult::RETRY && $message->attempts < (int) config('communication.max_attempts', 5);

        if ($retries) {
            $steps = array_values((array) config('communication.backoff_minutes', [1, 5, 15, 60]));
            $wait = (int) ($steps[min($message->attempts - 1, count($steps) - 1)] ?? 60);

            $message->forceFill([
                'status' => MessageStatus::Queued,
                'next_attempt_at' => $now->addMinutes($wait),
                'failure_code' => $result->code,
                'failure_message' => $this->clip($result->reason),
                'sending_at' => null,
            ])->save();

            $this->outbox->dispatch($message);

            return;
        }

        $message->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => $now,
            'failure_code' => $result->code ?? 'failed',
            'failure_message' => $this->clip($result->reason),
            'next_attempt_at' => null,
            'sending_at' => null,
        ])->save();

        if ($result->code === 'invalid_recipient') {
            app(SuppressionManager::class)->suppress(
                $message->channel,
                $message->recipient,
                SuppressionScope::All,
                SuppressionReason::Invalid,
                $message->contact_id,
            );
        }

        event(MessageFailed::from($message));
    }

    private function end(Message $message, MessageStatus $status, string $code): null
    {
        $message->forceFill([
            'status' => $status,
            'failed_at' => $status === MessageStatus::Failed ? CarbonImmutable::now() : null,
            'failure_code' => $code,
            'next_attempt_at' => null,
        ])->save();

        if ($status === MessageStatus::Failed) {
            event(MessageFailed::from($message));
        } else {
            // Suppressed or cancelled after queueing: marketing apps hear about it too.
            app(MarketingStatusRelay::class)->announce($message);
        }

        return null;
    }

    private function postpone(Message $message, CarbonImmutable $until): void
    {
        $message->forceFill(['next_attempt_at' => $until, 'dispatched_at' => null])->save();
        $this->outbox->dispatch($message);
    }

    private function clip(?string $reason): ?string
    {
        return $reason === null || $reason === '' ? null : mb_substr($reason, 0, 300);
    }
}
