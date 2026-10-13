<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Consent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The implementation of the core {@see ConsentRecorder} contract: an opt-out the contact asked for is a
 * recorded consent decision and a marketing suppression of the address, together, immediately.
 */
final class MarketingConsentRecorder implements ConsentRecorder
{
    private const array METHODS = ['keyword', 'button'];

    /** Opt-in methods other modules may claim; both are stored as the platform's `form` method. */
    private const array OPT_IN_METHODS = ['form', 'double_opt_in'];

    private const int MAX_EVIDENCE = 500;

    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly ConsentManager $consents,
        private readonly SuppressionManager $suppressions,
    ) {}

    public function recordOptOut(string $contactId, MarketingChannel $channel, string $method, ?string $evidenceNote = null, ?string $address = null): bool
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('The method is one of: '.implode(', ', self::METHODS).'.');
        }

        if (! $this->access->entitled()) {
            return false;
        }

        $contact = Contact::query()->find($contactId);

        if ($contact === null) {
            return false;
        }

        $internal = MarketingChannelStatus::channel($channel);
        $saved = $internal->normalize((string) ($internal === Channel::Email ? $contact->email : $contact->phone));
        $sender = $address === null || $address === '' ? null : $internal->normalize($address);

        DB::transaction(function () use ($contact, $internal, $saved, $sender, $method, $evidenceNote): void {
            // The contact's saved address and, when it differs, the address the request came from.
            foreach (array_unique(array_filter([$saved, $sender])) as $target) {
                $this->suppressions->suppress($internal, $target, SuppressionScope::Marketing, SuppressionReason::Unsubscribed, $contact->id);
            }

            $this->consents->record($contact, $internal, ConsentStatus::OptedOut, $method, $evidenceNote);
        });

        return true;
    }

    public function recordOptIn(string $contactId, MarketingChannel $channel, string $method, string $evidence): bool
    {
        if (! in_array($method, self::OPT_IN_METHODS, true)) {
            throw new InvalidArgumentException('The method is one of: '.implode(', ', self::OPT_IN_METHODS).'.');
        }

        $evidence = trim($evidence);

        if ($evidence === '' || mb_strlen($evidence) > self::MAX_EVIDENCE) {
            throw new InvalidArgumentException('The evidence is required and at most '.self::MAX_EVIDENCE.' characters.');
        }

        if (! $this->access->entitled()) {
            return false;
        }

        $internal = MarketingChannelStatus::channel($channel);

        return DB::transaction(function () use ($contactId, $internal, $evidence): bool {
            $contact = Contact::query()->find($contactId);

            if ($contact === null) {
                return false;
            }

            $address = $internal->normalize((string) ($internal === Channel::Email ? $contact->email : $contact->phone));

            if ($address === null) {
                return false;
            }

            // Any marketing or all-scope suppression of the address (unsubscribe, bounce, complaint, manual) is
            // the platform's decision: a form confirmation does not lift it.
            if ($this->suppressions->blocks($internal, $address, MessagePurpose::Marketing)) {
                return false;
            }

            $existing = Consent::query()
                ->where('contact_id', $contact->id)
                ->where('channel', $internal->value)
                ->where('purpose', MessagePurpose::Marketing->value)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === ConsentStatus::OptedIn) {
                return true;
            }

            // A withdrawal caused by a complaint is never undone by a form.
            if ($existing !== null && $existing->method === 'complaint') {
                return false;
            }

            $this->consents->record($contact, $internal, ConsentStatus::OptedIn, 'form', $evidence);

            return true;
        });
    }
}
