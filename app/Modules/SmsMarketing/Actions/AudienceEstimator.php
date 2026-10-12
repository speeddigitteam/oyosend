<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Modules\SmsMarketing\Models\GroupMember;
use App\Modules\SmsMarketing\Support\PhoneNumber;
use App\Modules\SmsMarketing\Support\SegmentCounter;
use App\Modules\SmsMarketing\Support\TemplateVariables;

/**
 * What a campaign to some groups would reach and cost, before anything is sent.
 *
 * Up to {@see self::EXACT_LIMIT} contacts are checked one by one: the number must be valid, the contract
 * (`MarketingAudience`) must allow the contact, and the text is rendered with that contact's own values, so a
 * Bengali name that turns the message into Unicode is counted. Above that the audience is counted and the
 * message is measured with sample values, and consent is not checked.
 */
final class AudienceEstimator
{
    public const int EXACT_LIMIT = 5000;

    public function __construct(
        private readonly MarketingAudience $audience,
        private readonly ChannelGateway $gateway,
        private readonly MessageComposer $composer,
        private readonly SmsSettings $settings,
    ) {}

    /**
     * @param  list<string>  $groupIds
     * @return array{
     *     contacts: int, validNumbers: int, invalidNumbers: int, checked: bool, sendable: int|null,
     *     blocked: array<string, int>, segmentsPerMessage: int, encoding: string, segments: int,
     *     exact: bool, costAmount: int|null, currency: string|null, tooLarge: bool
     * }
     */
    public function estimate(array $groupIds, string $body): array
    {
        $sample = $this->composer->preview($body);
        $contacts = $this->contactIds($groupIds);
        $total = count($contacts);
        $max = (int) config('sms_marketing.max_audience', 50000);

        $valid = 0;
        $sendable = null;
        $blocked = [];
        $segments = 0;
        $exact = false;
        $checked = $total > 0 && $total <= self::EXACT_LIMIT;
        $suffix = $this->composer->sampleSuffix();
        $channelUp = $this->gateway->availability(MarketingChannel::Sms)->isAvailable();

        if ($checked) {
            $sendable = 0;
            $exact = true;

            foreach (array_chunk($contacts, MarketingAudience::MAX_BATCH) as $chunk) {
                $rows = Contact::query()->with('company:id,name')->whereIn('id', $chunk)->get(['id', 'first_name', 'last_name', 'phone', 'company_id']);
                $withNumber = $rows->filter(static fn (Contact $contact): bool => PhoneNumber::normalize($contact->phone) !== null);
                $valid += $withNumber->count();
                $decisions = $this->audience->decideMany($withNumber->pluck('id')->all(), MarketingChannel::Sms);

                foreach ($withNumber as $contact) {
                    $decision = $decisions[$contact->id] ?? null;

                    if ($decision === null || ! $decision->allowed) {
                        $reason = $decision === null ? 'unknown' : $decision->reason->value;
                        $blocked[$reason] = ($blocked[$reason] ?? 0) + 1;

                        continue;
                    }

                    $sendable++;
                    $segments += SegmentCounter::count(TemplateVariables::render($body, self::values($contact)).$suffix)->segments;
                }
            }
        } else {
            $valid = $this->validNumberCount($contacts);
            $segments = $valid * $sample->segments;
        }

        // Without a channel nothing is sendable, but the figures above still describe the audience.
        $cost = $this->settings->current()->segment_cost_amount;

        return [
            'contacts' => $total,
            'validNumbers' => $valid,
            'invalidNumbers' => $total - $valid,
            'checked' => $checked,
            'sendable' => $sendable,
            'blocked' => $blocked,
            'segmentsPerMessage' => $sample->segments,
            'encoding' => $sample->encoding,
            'segments' => $segments,
            'exact' => $exact,
            'costAmount' => $cost === null ? null : $cost * $segments,
            'currency' => $cost === null ? null : $this->settings->currency(),
            'tooLarge' => $total > $max,
            'channelAvailable' => $channelUp,
        ];
    }

    /**
     * The distinct, live contact ids of the groups, in id order.
     *
     * @param  list<string>  $groupIds
     * @return list<string>
     */
    public function contactIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $ids = GroupMember::query()
            ->whereIn('group_id', $groupIds)
            ->join('contacts', 'contacts.id', '=', 'sms_marketing_group_members.contact_id')
            ->whereNull('contacts.deleted_at')
            ->distinct()
            ->orderBy('sms_marketing_group_members.contact_id')
            ->pluck('sms_marketing_group_members.contact_id')
            ->all();

        return array_values(array_map('strval', $ids));
    }

    /** @return array<string, string|null> */
    public static function values(Contact $contact): array
    {
        return [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->fullName(),
            'company_name' => $contact->company?->name,
        ];
    }

    /** @param  list<string>  $contactIds */
    private function validNumberCount(array $contactIds): int
    {
        $valid = 0;

        foreach (array_chunk($contactIds, 2000) as $chunk) {
            foreach (Contact::query()->whereIn('id', $chunk)->pluck('phone') as $phone) {
                if (PhoneNumber::normalize(is_string($phone) ? $phone : null) !== null) {
                    $valid++;
                }
            }
        }

        return $valid;
    }
}
