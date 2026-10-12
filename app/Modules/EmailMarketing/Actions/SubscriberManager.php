<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\EmailMarketing\Enums\ConsentMethod;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Events\SubscriberSubscribed;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use App\Modules\EmailMarketing\Support\SegmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membership of contacts in lists.
 *
 * A subscriber is never a record of its own: it is a {@see Subscription} (list + shared contact) with properties.
 * `subscribed` needs consent evidence (a method and a description) on the membership; without it the member is
 * `pending` and is never sent to. The status here is list state. Whether a message may actually go out is the
 * platform's audience decision (consent and suppression) at send time, which this class never overrides.
 */
final class SubscriberManager
{
    public const string LIMIT = 'email_marketing.subscribers';

    public function __construct(
        private readonly UsageLimits $limits,
        private readonly SequenceEnroller $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Add a contact to a list, or bring an existing membership forward.
     *
     * @param  array{consent_method?: string|null, consent_evidence?: string|null, consented_at?: string|null, properties?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function add(MailingList $list, string $contactId, array $input, SubscriptionSource $source, ?TenantMembership $by = null, bool $audit = true): Subscription
    {
        if ($list->status !== ListStatus::Active) {
            throw ValidationException::withMessages(['list_id' => __('This list is archived. Restore it before adding subscribers.')]);
        }

        $evidence = $this->evidence($input);
        $properties = $this->properties($input['properties'] ?? null);

        try {
            $subscription = DB::transaction(function () use ($list, $contactId, $evidence, $properties, $source, $by): Subscription {
                $existing = Subscription::query()->where('list_id', $list->id)->where('contact_id', $contactId)->lockForUpdate()->first();

                if ($existing === null) {
                    $this->ensureRoomFor($contactId);

                    return $this->create($list, $contactId, $evidence, $properties, $source, $by);
                }

                return $this->reconcile($existing, $evidence, $properties);
            });
        } catch (UniqueConstraintViolationException) {
            $subscription = Subscription::query()->where('list_id', $list->id)->where('contact_id', $contactId)->firstOrFail();
        }

        if ($audit) {
            $this->audit->record('email_marketing.subscriber.added', $subscription, [
                'list_id' => $list->id, 'contact_id' => $contactId, 'status' => $subscription->status->value, 'source' => $source->value,
            ], subjectLabel: $list->name);
        }

        return $subscription;
    }

    /**
     * Give a pending member consent evidence, which makes them subscribed.
     *
     * @param  array{consent_method?: string|null, consent_evidence?: string|null, consented_at?: string|null}  $input
     */
    public function confirm(Subscription $subscription, array $input): Subscription
    {
        $evidence = $this->evidence($input);

        if ($evidence === null) {
            throw ValidationException::withMessages(['consent_evidence' => __('Describe how this person agreed to receive email.')]);
        }

        if ($subscription->status !== SubscriptionStatus::Pending) {
            throw ValidationException::withMessages(['status' => __('Only a pending member can be confirmed.')]);
        }

        $subscription = DB::transaction(fn (): Subscription => $this->reconcile($subscription->refresh(), $evidence, []));
        $this->audit->record('email_marketing.subscriber.confirmed', $subscription, ['list_id' => $subscription->list_id, 'contact_id' => $subscription->contact_id]);

        return $subscription;
    }

    /** Close the membership in this list. Platform-wide consent and suppression are not touched here. */
    public function unsubscribe(Subscription $subscription, string $reason = 'removed_by_member'): Subscription
    {
        if (! $subscription->status->isActive()) {
            return $subscription;
        }

        DB::transaction(function () use ($subscription, $reason): void {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Unsubscribed,
                'unsubscribed_at' => CarbonImmutable::now(),
                'status_reason' => mb_substr($reason, 0, 120),
            ])->save();
            $this->sequences->stopForContact($subscription->contact_id, 'unsubscribed', $subscription->list_id);
        });

        $this->audit->record('email_marketing.subscriber.unsubscribed', $subscription, [
            'list_id' => $subscription->list_id, 'contact_id' => $subscription->contact_id, 'reason' => $reason,
        ]);

        return $subscription;
    }

    public function updateProperties(Subscription $subscription, mixed $properties): Subscription
    {
        $subscription->forceFill(['properties' => $this->properties($properties) ?: null])->save();

        return $subscription;
    }

    public function remove(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $this->sequences->stopForContact($subscription->contact_id, 'removed', $subscription->list_id);
            $subscription->delete();
        });

        $this->audit->record('email_marketing.subscriber.removed', null, [
            'list_id' => $subscription->list_id, 'contact_id' => $subscription->contact_id,
        ], subjectLabel: 'Subscriber');
    }

    /**
     * Mirror a platform-level change onto every open membership of the contact: the person unsubscribed through
     * a link, asked to be left out, complained or their address bounced. Never reopens anything.
     */
    public function mirror(string $contactId, SubscriptionStatus $status, string $reason): int
    {
        if (! $status->isClosed()) {
            throw new \InvalidArgumentException('A mirrored status closes the membership.');
        }

        return DB::transaction(function () use ($contactId, $status, $reason): int {
            $count = Subscription::query()
                ->where('contact_id', $contactId)
                ->whereIn('status', [SubscriptionStatus::Pending->value, SubscriptionStatus::Subscribed->value])
                ->update([
                    'status' => $status->value,
                    'unsubscribed_at' => CarbonImmutable::now(),
                    'status_reason' => $reason,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            $this->sequences->stopForContact($contactId, $status->value);

            return $count;
        });
    }

    /** Contacts that count against the plan: distinct contacts with an open membership anywhere. */
    public static function activeCount(): int
    {
        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Pending->value, SubscriptionStatus::Subscribed->value])
            ->distinct()
            ->count('contact_id');
    }

    /**
     * @param  array{method: ConsentMethod, evidence: string, at: CarbonImmutable}|null  $evidence
     * @param  array<string, string>  $properties
     */
    private function create(MailingList $list, string $contactId, ?array $evidence, array $properties, SubscriptionSource $source, ?TenantMembership $by): Subscription
    {
        $subscribed = $evidence !== null;

        $subscription = Subscription::query()->create([
            'list_id' => $list->id,
            'contact_id' => $contactId,
            'status' => $subscribed ? SubscriptionStatus::Subscribed : SubscriptionStatus::Pending,
            'source' => $source->value,
            'consent_method' => $evidence['method']->value ?? null,
            'consent_evidence' => $evidence['evidence'] ?? null,
            'consented_at' => $evidence['at'] ?? null,
            'subscribed_at' => $subscribed ? CarbonImmutable::now() : null,
            'properties' => $properties === [] ? null : $properties,
            'added_by' => $by?->id,
        ]);

        if ($subscribed) {
            $this->subscribed($subscription);
        }

        return $subscription;
    }

    /**
     * Bring an existing membership up to date. A closed membership reopens only with fresh evidence, and only an
     * unsubscribed one: a bounce or a complaint is dealt with in the communication app.
     *
     * @param  array{method: ConsentMethod, evidence: string, at: CarbonImmutable}|null  $evidence
     * @param  array<string, string>  $properties
     */
    private function reconcile(Subscription $existing, ?array $evidence, array $properties): Subscription
    {
        $merged = array_merge((array) $existing->properties, $properties);

        if ($existing->status === SubscriptionStatus::Subscribed) {
            $existing->forceFill(['properties' => $merged === [] ? null : $merged])->save();

            return $existing;
        }

        if (in_array($existing->status, [SubscriptionStatus::Bounced, SubscriptionStatus::Complained], true)) {
            throw ValidationException::withMessages(['contact_id' => __('This address bounced or complained. It cannot be added again here.')]);
        }

        if ($existing->status === SubscriptionStatus::Unsubscribed && $evidence === null) {
            throw ValidationException::withMessages([
                'consent_evidence' => __('This person unsubscribed. Record fresh consent evidence to add them again.'),
            ]);
        }

        $becomesSubscribed = $evidence !== null;
        $this->ensureRoomFor($existing->contact_id, $existing->status->isActive());

        $existing->forceFill([
            'status' => $becomesSubscribed ? SubscriptionStatus::Subscribed : SubscriptionStatus::Pending,
            'consent_method' => $evidence['method']->value ?? $existing->consent_method,
            'consent_evidence' => $evidence['evidence'] ?? $existing->consent_evidence,
            'consented_at' => $evidence['at'] ?? $existing->consented_at,
            'subscribed_at' => $becomesSubscribed ? CarbonImmutable::now() : $existing->subscribed_at,
            'unsubscribed_at' => null,
            'status_reason' => null,
            'properties' => $merged === [] ? null : $merged,
        ])->save();

        if ($becomesSubscribed) {
            $this->subscribed($existing);
        }

        return $existing;
    }

    private function subscribed(Subscription $subscription): void
    {
        event(SubscriberSubscribed::from($subscription));
        $this->sequences->enrollSubscribed($subscription);
    }

    /** Refuse to go past the plan's subscriber limit; a contact already counted elsewhere costs nothing. */
    private function ensureRoomFor(string $contactId, bool $alreadyCounted = false): void
    {
        if ($alreadyCounted) {
            return;
        }

        $counted = Subscription::query()
            ->where('contact_id', $contactId)
            ->whereIn('status', [SubscriptionStatus::Pending->value, SubscriptionStatus::Subscribed->value])
            ->exists();

        if (! $counted) {
            $this->limits->ensure(self::LIMIT, 1, 'contact_id');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{method: ConsentMethod, evidence: string, at: CarbonImmutable}|null
     *
     * @throws ValidationException
     */
    private function evidence(array $input): ?array
    {
        $method = $input['consent_method'] ?? null;
        $text = ContentBlocks::line($input['consent_evidence'] ?? '');

        if (($method === null || $method === '') && $text === '') {
            return null;
        }

        $parsed = is_string($method) ? ConsentMethod::tryFrom($method) : null;

        if ($parsed === null) {
            throw ValidationException::withMessages(['consent_method' => __('Choose how the person agreed.')]);
        }

        if (mb_strlen($text) < 5 || mb_strlen($text) > 500) {
            throw ValidationException::withMessages(['consent_evidence' => __('Describe the evidence in 5 to 500 characters.')]);
        }

        $at = CarbonImmutable::now();

        if (isset($input['consented_at']) && $input['consented_at'] !== '') {
            try {
                $at = CarbonImmutable::parse((string) $input['consented_at'])->utc();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['consented_at' => __('Enter a valid date.')]);
            }

            if ($at->isFuture()) {
                throw ValidationException::withMessages(['consented_at' => __('The date of consent cannot be in the future.')]);
            }
        }

        return ['method' => $parsed, 'evidence' => $text, 'at' => $at];
    }

    /**
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function properties(mixed $properties): array
    {
        if ($properties === null || $properties === [] || $properties === '') {
            return [];
        }

        $max = (int) config('email_marketing.import.max_properties', 10);

        if (! is_array($properties) || count($properties) > $max) {
            throw ValidationException::withMessages(['properties' => __('A member can have up to :count properties.', ['count' => $max])]);
        }

        $clean = [];

        foreach ($properties as $key => $value) {
            $key = is_string($key) ? strtolower(trim($key)) : '';

            if (preg_match(SegmentRules::PROPERTY_KEY, $key) !== 1 || ! is_scalar($value)) {
                throw ValidationException::withMessages(['properties' => __('A property name uses lower-case letters, digits and underscores, and starts with a letter.')]);
            }

            $text = ContentBlocks::line($value);

            if (mb_strlen($text) > 190) {
                throw ValidationException::withMessages(['properties' => __('A property value is up to 190 characters.')]);
            }

            if ($text !== '') {
                $clean[$key] = $text;
            }
        }

        return $clean;
    }
}
