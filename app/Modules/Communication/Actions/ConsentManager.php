<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Events\ContactUnsubscribed;
use App\Modules\Communication\Models\Consent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marketing consent per contact and channel. Only an explicit, recorded opt-in lets a marketing message
 * through; the absence of a record is "no".
 */
final class ConsentManager
{
    /** How consent was obtained. */
    public const array METHODS = ['form', 'verbal', 'written', 'import', 'unsubscribe_link', 'complaint', 'other'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SuppressionManager $suppressions,
    ) {}

    public function hasOptedIn(string $contactId, Channel $channel, MessagePurpose $purpose = MessagePurpose::Marketing): bool
    {
        return Consent::query()
            ->where('contact_id', $contactId)
            ->where('channel', $channel->value)
            ->where('purpose', $purpose->value)
            ->where('status', ConsentStatus::OptedIn->value)
            ->exists();
    }

    /**
     * Record a decision. Opting in needs the method and a note of the evidence (what the contact did).
     */
    public function record(
        Contact $contact,
        Channel $channel,
        ConsentStatus $status,
        string $method,
        ?string $evidence = null,
        ?string $recordedBy = null,
        MessagePurpose $purpose = MessagePurpose::Marketing,
    ): Consent {
        if (! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['method' => __('Choose how the consent was given.')]);
        }

        if ($status === ConsentStatus::OptedIn && trim((string) $evidence) === '') {
            throw ValidationException::withMessages(['evidence' => __('Describe the evidence of the opt-in.')]);
        }

        $attributes = [
            'status' => $status,
            'method' => $method,
            'evidence' => $evidence === null || trim($evidence) === '' ? null : mb_substr(trim($evidence), 0, 500),
            'recorded_by' => $recordedBy,
            'recorded_at' => CarbonImmutable::now(),
        ];

        $consent = DB::transaction(function () use ($contact, $channel, $purpose, $attributes, $status): Consent {
            $key = ['contact_id' => $contact->id, 'channel' => $channel->value, 'purpose' => $purpose->value];
            $existing = Consent::query()->where($key)->lockForUpdate()->first();
            $previous = $existing?->status;

            try {
                $consent = $existing ?? new Consent([...$key, 'channel' => $channel, 'purpose' => $purpose]);
                $consent->fill($attributes)->save();
            } catch (UniqueConstraintViolationException) {
                $consent = Consent::query()->where($key)->firstOrFail();
                $consent->fill($attributes)->save();
            }

            $this->audit->record('communication.consent.recorded', $consent, [
                'contact_id' => $contact->id,
                'channel' => $channel->value,
                'purpose' => $purpose->value,
                'from' => $previous?->value,
                'to' => $status->value,
                'method' => $attributes['method'],
            ], null, $contact->fullName());

            if ($status === ConsentStatus::OptedIn) {
                $address = $channel->normalize((string) ($channel === Channel::Email ? $contact->email : $contact->phone));

                if ($address !== null) {
                    // A fresh, explicit opt-in replaces an earlier unsubscribe of the same address.
                    $this->suppressions->liftUnsubscribe($channel, $address);
                }
            }

            return $consent;
        });

        if ($status === ConsentStatus::OptedOut) {
            event(new ContactUnsubscribed($contact->id, $channel->value, $method));
        }

        return $consent;
    }
}
