<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\AudienceDecision;
use App\Core\Communication\Data\AudienceReason;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MessagingPurpose;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Consent;
use App\Modules\Communication\Models\Suppression;
use InvalidArgumentException;

/**
 * The implementation of the core {@see MarketingAudience} contract. It applies the same rules as
 * {@see Outbox::blockedReason()}: suppression first, then (marketing only) an explicit recorded opt-in.
 * Three queries per call whatever the list size.
 */
final class MarketingAudienceResolver implements MarketingAudience
{
    public function __construct(private readonly MarketingChannelStatus $status) {}

    public function canReceive(string $contactId, MarketingChannel $channel, MessagingPurpose $purpose = MessagingPurpose::Marketing): AudienceDecision
    {
        return $this->decideMany([$contactId], $channel, $purpose)[$contactId];
    }

    public function decideMany(array $contactIds, MarketingChannel $channel, MessagingPurpose $purpose = MessagingPurpose::Marketing): array
    {
        $ids = array_values(array_unique($contactIds));

        if (count($ids) > self::MAX_BATCH) {
            throw new InvalidArgumentException('At most '.self::MAX_BATCH.' contacts per call.');
        }

        if ($ids === []) {
            return [];
        }

        $decisions = [];

        if (! $this->status->check($channel)->isAvailable()) {
            foreach ($ids as $id) {
                $decisions[$id] = AudienceDecision::deny($id, $channel, AudienceReason::ChannelUnavailable);
            }

            return $decisions;
        }

        $internal = MarketingChannelStatus::channel($channel);
        $marketing = $purpose === MessagingPurpose::Marketing;

        /** @var array<string, Contact> $contacts */
        $contacts = Contact::query()->whereIn('id', $ids)->get(['id', 'email', 'phone'])->keyBy('id')->all();

        $addresses = [];

        foreach ($contacts as $id => $contact) {
            $addresses[$id] = $internal->normalize((string) ($internal === Channel::Email ? $contact->email : $contact->phone));
        }

        $present = array_values(array_unique(array_filter($addresses)));
        $scopes = $marketing ? [SuppressionScope::All->value, SuppressionScope::Marketing->value] : [SuppressionScope::All->value];

        // address => the reason that decides: any non-unsubscribe suppression outranks an unsubscribe.
        $suppressed = [];

        if ($present !== []) {
            $rows = Suppression::query()
                ->where('channel', $internal->value)
                ->whereIn('address', $present)
                ->whereIn('scope', $scopes)
                ->get(['address', 'reason']);

            foreach ($rows as $row) {
                $unsubscribed = $row->reason === SuppressionReason::Unsubscribed;
                $suppressed[$row->address] = ($suppressed[$row->address] ?? true) && $unsubscribed;
            }
        }

        /** @var array<string, ConsentStatus> $consents */
        $consents = [];

        if ($marketing && $contacts !== []) {
            $consents = Consent::query()
                ->whereIn('contact_id', array_keys($contacts))
                ->where('channel', $internal->value)
                ->where('purpose', 'marketing')
                ->get(['contact_id', 'status'])
                ->mapWithKeys(static fn (Consent $consent): array => [$consent->contact_id => $consent->status])
                ->all();
        }

        foreach ($ids as $id) {
            $address = $addresses[$id] ?? null;

            $decisions[$id] = match (true) {
                ! isset($contacts[$id]) => AudienceDecision::deny($id, $channel, AudienceReason::UnknownContact),
                $address === null => AudienceDecision::deny($id, $channel, AudienceReason::NoAddress),
                isset($suppressed[$address]) => AudienceDecision::deny($id, $channel, $suppressed[$address] ? AudienceReason::Unsubscribed : AudienceReason::Suppressed),
                ! $marketing => AudienceDecision::allow($id, $channel),
                ! isset($consents[$id]) => AudienceDecision::deny($id, $channel, AudienceReason::NoConsent),
                $consents[$id] === ConsentStatus::OptedIn => AudienceDecision::allow($id, $channel),
                default => AudienceDecision::deny($id, $channel, AudienceReason::OptedOut),
            };
        }

        return $decisions;
    }
}
