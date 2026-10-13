<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Billing\UsageLimits;
use App\Core\Communication\Data\InteractionEntry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Jobs\SendMessage;
use App\Modules\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way a message enters the outbox.
 *
 * `queue()` is idempotent, applies suppression and consent, counts against the plan's limit, records the row and
 * hands delivery to a queued job after commit. It never sends inline.
 */
final class Outbox
{
    public const string LIMIT = 'communication.messages';

    public const string BLOCKED_SUPPRESSED = 'suppressed';

    public const string BLOCKED_NO_CONSENT = 'no_consent';

    public const string FAILED_LIMIT = 'limit_reached';

    public function __construct(
        private readonly SuppressionManager $suppressions,
        private readonly ConsentManager $consents,
        private readonly UsageLimits $limits,
        private readonly QuietHours $quietHours,
        private readonly TimelineRecorder $timeline,
        private readonly TenantContext $context,
    ) {}

    /**
     * Why a message to this address would not go out, or null when it may.
     *
     * @return self::BLOCKED_*|null
     */
    public function blockedReason(Channel $channel, MessagePurpose $purpose, string $address, ?string $contactId): ?string
    {
        if ($this->suppressions->blocks($channel, $address, $purpose)) {
            return self::BLOCKED_SUPPRESSED;
        }

        if ($purpose === MessagePurpose::Marketing && ($contactId === null || ! $this->consents->hasOptedIn($contactId, $channel))) {
            return self::BLOCKED_NO_CONSENT;
        }

        return null;
    }

    /**
     * Refuse (as a validation error) when the plan's monthly message limit is used up. For interactive callers.
     *
     * @throws ValidationException
     */
    public function assertWithinLimit(string $field = 'limit'): void
    {
        $this->limits->ensure(self::LIMIT, 1 + $this->pending(), $field);
    }

    /**
     * Messages already accepted but not yet counted: queued ones (a plan's allowance is spent when the provider
     * accepts a message, so a backlog is owed against it) and ones being sent right now.
     */
    public function pending(?string $exceptId = null, bool $inFlightOnly = false): int
    {
        $statuses = $inFlightOnly ? [MessageStatus::Sending] : [MessageStatus::Queued, MessageStatus::Sending];

        return Message::query()
            ->whereIn('status', array_map(static fn (MessageStatus $status): string => $status->value, $statuses))
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->count();
    }

    /**
     * Whether one more message fits the plan's limit once what is already waiting or in flight is counted.
     * The worker asks this again right before a provider call, with only in-flight messages as the backlog
     * (the queue itself is not "used" until a message is accepted).
     */
    public function hasRoom(?string $exceptId = null, bool $inFlightOnly = false): bool
    {
        return $this->limits->allows(self::LIMIT, 1 + $this->pending($exceptId, $inFlightOnly));
    }

    public function queue(OutboundRequest $request): Message
    {
        $key = $request->idempotencyKey;

        if ($key === '' || strlen($key) > 160) {
            throw ValidationException::withMessages(['idempotency_key' => __('An idempotency key of 1 to 160 characters is required.')]);
        }

        $existing = $this->byKey($key);

        if ($existing !== null) {
            return $existing;
        }

        $address = $request->channel->normalize($request->recipient)
            ?? throw ValidationException::withMessages(['recipient' => __('Enter a valid address for this channel.')]);

        if ($request->channel->hasSubject() && trim((string) $request->subject) === '') {
            throw ValidationException::withMessages(['subject' => __('A subject is required.')]);
        }

        $blocked = $this->blockedReason($request->channel, $request->purpose, $address, $request->contactId);
        $now = CarbonImmutable::now();
        $status = MessageStatus::Queued;
        $failure = null;

        if ($blocked !== null) {
            $status = MessageStatus::Suppressed;
            $failure = $blocked;
        } elseif (! $this->hasRoom()) {
            $status = MessageStatus::Failed;
            $failure = self::FAILED_LIMIT;
        }

        $due = $request->sendAt === null ? $now : CarbonImmutable::instance($request->sendAt);

        if ($status === MessageStatus::Queued && $request->purpose === MessagePurpose::Marketing) {
            $quiet = $this->quietHours->endsAt($due);
            $due = $quiet !== null && $quiet > $due ? $quiet : $due;
        }

        try {
            $message = DB::transaction(function () use ($request, $address, $status, $failure, $now, $due): Message {
                $message = new Message([
                    'contact_id' => $request->contactId,
                    'company_id' => $request->companyId,
                    'membership_id' => $request->membershipId,
                    'template_id' => $request->templateId,
                    'channel' => $request->channel,
                    'purpose' => $request->purpose,
                    'status' => $status,
                    'recipient' => $address,
                    'subject' => $request->channel->hasSubject() ? mb_substr(trim((string) $request->subject), 0, 255) : null,
                    'body' => $request->body,
                    'locale' => $request->locale ?? $this->context->require()->locale,
                    'idempotency_key' => $request->idempotencyKey,
                    'source' => $request->source,
                    'attempts' => 0,
                    'queued_at' => $now,
                    'next_attempt_at' => $status === MessageStatus::Queued ? $due : null,
                    'failed_at' => $status === MessageStatus::Failed ? $now : null,
                    'failure_code' => $failure,
                    'meta' => $request->meta === [] ? null : $request->meta,
                ]);
                $message->save();

                if ($message->contact_id !== null || $message->company_id !== null) {
                    $this->timeline->write(new InteractionEntry(
                        type: $request->channel === Channel::Email ? 'email' : $request->channel->value,
                        summary: mb_substr((string) ($message->subject ?? $message->body), 0, 200),
                        contactId: $message->contact_id,
                        companyId: $message->company_id,
                        direction: 'outbound',
                        sourceModule: 'communication',
                        sourceType: 'message',
                        sourceId: $message->id,
                        dedupeKey: 'message:'.$message->id,
                        meta: ['status' => $status->value, 'purpose' => $request->purpose->value],
                    ), $message->id, $message->owner_id);
                }

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request queued the same intent first: its row is the answer.
            return $this->byKey($key) ?? throw new \RuntimeException('Message disappeared during creation.');
        }

        if ($message->status === MessageStatus::Queued) {
            $this->dispatch($message);
        }

        return $message;
    }

    /**
     * Hand a queued message to the worker, delayed until it is due. Safe to call again.
     */
    public function dispatch(Message $message): void
    {
        $due = $message->next_attempt_at;
        $delay = $due !== null && $due->isFuture() ? $due : null;

        Message::query()->whereKey($message->id)->update(['dispatched_at' => CarbonImmutable::now()]);

        $job = SendMessage::dispatch($message->id);

        if ($delay !== null) {
            $job->delay($delay);
        }
    }

    private function byKey(string $key): ?Message
    {
        return Message::query()->where('idempotency_key', $key)->first();
    }
}
