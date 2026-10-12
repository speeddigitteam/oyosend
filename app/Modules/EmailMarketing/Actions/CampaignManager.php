<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Models\UsageRecord;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Jobs\RunEmailMarketing;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Template;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lifecycle of a campaign:
 *
 *   draft -> scheduled -> preparing -> sending -> (awaiting_winner ->) sent
 *                                 \-> paused <-/            cancelled
 *
 * Drafts and scheduled campaigns are editable; once preparing begins the content is frozen. Starting is never
 * done in this class (or in a web request): a campaign becomes `scheduled` and the queue worker takes it from
 * there, see {@see CampaignRunner}.
 */
final class CampaignManager
{
    public const string LIMIT = 'email_marketing.campaigns_per_month';

    public function __construct(
        private readonly EmailMarketingSettings $settings,
        private readonly ContentComposer $composer,
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?TenantMembership $owner = null): Campaign
    {
        $campaign = new Campaign($this->attributes($input));

        if ($owner !== null) {
            $campaign->assignOwner($owner);
        }

        $campaign->save();

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Campaign $campaign, array $input): Campaign
    {
        return DB::transaction(function () use ($campaign, $input): Campaign {
            $locked = $this->lock($campaign);
            $this->assertStatus($locked, [CampaignStatus::Draft, CampaignStatus::Scheduled], __('A campaign that has started can no longer be edited.'));

            $locked->fill($this->attributes($input, $locked));

            // A change after scheduling needs a fresh decision to send.
            if ($locked->status === CampaignStatus::Scheduled) {
                $locked->fill(['status' => CampaignStatus::Draft, 'scheduled_for' => null]);
            }

            $locked->save();

            return $locked;
        });
    }

    public function duplicate(Campaign $campaign, ?TenantMembership $owner = null): Campaign
    {
        return $this->create([
            'name' => mb_substr(__(':name (copy)', ['name' => $campaign->name]), 0, 150),
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'blocks' => $campaign->blocks,
            'list_id' => $campaign->list_id,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'track_clicks' => $campaign->track_clicks,
            'ab_enabled' => $campaign->ab_enabled,
            'ab_subject_b' => $campaign->ab_subject_b,
            'ab_test_percent' => $campaign->ab_test_percent,
            'ab_wait_hours' => $campaign->ab_wait_hours,
            'ab_metric' => $campaign->ab_metric,
        ], $owner);
    }

    public function delete(Campaign $campaign): void
    {
        $this->assertStatus($campaign, [CampaignStatus::Draft, CampaignStatus::Cancelled], __('Only a draft or a cancelled campaign can be deleted.'));

        if (Delivery::query()->where('campaign_id', $campaign->id)->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value, DeliveryStatus::Queued->value])->exists()) {
            throw ValidationException::withMessages(['campaign' => __('This campaign already sent email. It stays for the reports.')]);
        }

        $campaign->delete();
    }

    /**
     * Queue the campaign. `$at === null` means as soon as the next runner pass.
     *
     * @throws ValidationException
     */
    public function schedule(Campaign $campaign, ?CarbonImmutable $at): Campaign
    {
        $scheduled = DB::transaction(function () use ($campaign, $at): Campaign {
            $locked = $this->lock($campaign);
            $this->assertStatus($locked, [CampaignStatus::Draft, CampaignStatus::Scheduled], __('Only a draft can be scheduled.'));
            $this->assertSendable($locked);

            if ($at !== null && $at->isBefore(CarbonImmutable::now()->subMinutes(5))) {
                throw ValidationException::withMessages(['scheduled_for' => __('Choose a time in the future.')]);
            }

            $key = 'campaign:'.$locked->id;

            if (! UsageRecord::query()->where('meter', self::LIMIT)->where('idempotency_key', $key)->exists()) {
                $this->limits->ensure(self::LIMIT, 1, 'scheduled_for');
                $this->meter->record(self::LIMIT, 1, $key, ['campaign_id' => $locked->id]);
            }

            $locked->fill(['status' => CampaignStatus::Scheduled, 'scheduled_for' => $at ?? CarbonImmutable::now()])->save();
            $this->audit->record('email_marketing.campaign.scheduled', $locked, ['scheduled_for' => $locked->scheduled_for?->toIso8601String()]);

            return $locked;
        });

        // The runner (a queue job on `campaigns`) picks it up; nothing is sent inside this request.
        if ($scheduled->scheduled_for !== null && $scheduled->scheduled_for->lte(CarbonImmutable::now())) {
            RunEmailMarketing::dispatch();
        }

        return $scheduled;
    }

    /** Take a scheduled campaign back to draft. */
    public function unschedule(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = $this->lock($campaign);
            $this->assertStatus($locked, [CampaignStatus::Scheduled], __('Only a scheduled campaign can be taken back to draft.'));
            $locked->fill(['status' => CampaignStatus::Draft, 'scheduled_for' => null])->save();

            return $locked;
        });
    }

    public function pause(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = $this->lock($campaign);
            $this->assertStatus($locked, [CampaignStatus::Preparing, CampaignStatus::Sending, CampaignStatus::AwaitingWinner], __('Only a campaign that is going out can be paused.'));
            $locked->fill(['status' => CampaignStatus::Paused, 'paused_reason' => 'paused_by_member'])->save();
            $this->audit->record('email_marketing.campaign.paused', $locked);

            return $locked;
        });
    }

    public function resume(Campaign $campaign): Campaign
    {
        $resumed = DB::transaction(function () use ($campaign): Campaign {
            $locked = $this->lock($campaign);
            $this->assertStatus($locked, [CampaignStatus::Paused], __('Only a paused campaign can be resumed.'));

            $next = match (true) {
                $locked->prepared_at === null => CampaignStatus::Preparing,
                $locked->ab_enabled && $locked->ab_decide_at !== null && $locked->ab_winner === null => CampaignStatus::AwaitingWinner,
                default => CampaignStatus::Sending,
            };

            $locked->fill(['status' => $next, 'paused_reason' => null])->save();
            $this->audit->record('email_marketing.campaign.resumed', $locked);

            return $locked;
        });

        RunEmailMarketing::dispatch();

        return $resumed;
    }

    /** Stop for good. Anything not handed to the platform yet is cancelled; what was handed over is not recalled. */
    public function cancel(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = $this->lock($campaign);
            $this->assertStatus($locked, [
                CampaignStatus::Draft, CampaignStatus::Scheduled, CampaignStatus::Preparing, CampaignStatus::Sending,
                CampaignStatus::AwaitingWinner, CampaignStatus::Paused,
            ], __('This campaign is already finished.'));

            Delivery::query()
                ->where('campaign_id', $locked->id)
                ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Held->value])
                ->update(['status' => DeliveryStatus::Cancelled->value, 'reason' => 'campaign_cancelled', 'updated_at' => CarbonImmutable::now()]);

            $locked->fill(['status' => CampaignStatus::Cancelled, 'finished_at' => CarbonImmutable::now()])->save();
            $this->audit->record('email_marketing.campaign.cancelled', $locked);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attributes(array $input, ?Campaign $current = null): array
    {
        $list = MailingList::query()->find((string) ($input['list_id'] ?? $current?->list_id));

        if ($list === null) {
            throw ValidationException::withMessages(['list_id' => __('Choose a list.')]);
        }

        if ($list->status !== ListStatus::Active && $list->id !== $current?->list_id) {
            throw ValidationException::withMessages(['list_id' => __('This list is archived.')]);
        }

        $segmentId = array_key_exists('segment_id', $input) ? $input['segment_id'] : $current?->segment_id;

        if ($segmentId !== null && $segmentId !== '') {
            $segment = Segment::query()->find((string) $segmentId);

            if ($segment === null || $segment->list_id !== $list->id) {
                throw ValidationException::withMessages(['segment_id' => __('Choose a segment of the selected list.')]);
            }
        } else {
            $segmentId = null;
        }

        $templateId = $input['template_id'] ?? null;

        if ($templateId !== null && $templateId !== '' && ! Template::query()->whereKey((string) $templateId)->exists()) {
            throw ValidationException::withMessages(['template_id' => __('Choose a template that exists.')]);
        }

        $subject = (string) ContentBlocks::subject($input['subject'] ?? $current?->subject);
        $blocks = ContentBlocks::normalize($input['blocks'] ?? $current?->blocks);
        $trackClicks = (bool) ($input['track_clicks'] ?? $current?->getAttribute('track_clicks') ?? true);

        $attributes = [
            'name' => $this->name($input['name'] ?? $current?->name),
            'subject' => $subject,
            'preheader' => $this->preheader($input['preheader'] ?? $current?->preheader),
            'blocks' => $blocks,
            'list_id' => $list->id,
            'segment_id' => $segmentId,
            'template_id' => $templateId === '' ? null : $templateId,
            'track_clicks' => $trackClicks,
        ];

        return [...$attributes, ...$this->abTest($input, $current, $subject, $trackClicks)];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function abTest(array $input, ?Campaign $current, string $subject, bool $trackClicks): array
    {
        $enabled = (bool) ($input['ab_enabled'] ?? $current?->getAttribute('ab_enabled') ?? false);

        if (! $enabled) {
            return ['ab_enabled' => false, 'ab_subject_b' => null];
        }

        $subjectB = ContentBlocks::subject($input['ab_subject_b'] ?? $current?->ab_subject_b, 'ab_subject_b');
        $percent = (int) ($input['ab_test_percent'] ?? $current?->getAttribute('ab_test_percent') ?? 20);
        $wait = (int) ($input['ab_wait_hours'] ?? $current?->getAttribute('ab_wait_hours') ?? 4);
        $metric = (string) ($input['ab_metric'] ?? $current?->getAttribute('ab_metric') ?? 'clicks');

        if ($subjectB === $subject) {
            throw ValidationException::withMessages(['ab_subject_b' => __('The second subject must differ from the first.')]);
        }

        if ($percent < 10 || $percent > 100) {
            throw ValidationException::withMessages(['ab_test_percent' => __('Test between 10 and 100 percent of the audience.')]);
        }

        if ($wait < 1 || $wait > 72) {
            throw ValidationException::withMessages(['ab_wait_hours' => __('Wait between 1 and 72 hours for the result.')]);
        }

        if (! in_array($metric, ['clicks', 'views'], true)) {
            throw ValidationException::withMessages(['ab_metric' => __('Choose clicks or web-version views.')]);
        }

        if ($metric === 'clicks' && ! $trackClicks) {
            throw ValidationException::withMessages(['ab_metric' => __('Clicks are only measured while click tracking is on.')]);
        }

        return [
            'ab_enabled' => true,
            'ab_subject_b' => $subjectB,
            'ab_test_percent' => $percent,
            'ab_wait_hours' => $wait,
            'ab_metric' => $metric,
        ];
    }

    private function name(mixed $name): string
    {
        $text = ContentBlocks::line($name);

        if ($text === '' || mb_strlen($text) > 150) {
            throw ValidationException::withMessages(['name' => __('A name of up to 150 characters is required.')]);
        }

        return $text;
    }

    private function preheader(mixed $preheader): ?string
    {
        $text = ContentBlocks::line($preheader);

        if (mb_strlen($text) > 200) {
            throw ValidationException::withMessages(['preheader' => __('The preview text is up to 200 characters.')]);
        }

        return $text === '' ? null : $text;
    }

    /** @throws ValidationException */
    private function assertSendable(Campaign $campaign): void
    {
        if (! $this->settings->hasFooterAddress()) {
            throw ValidationException::withMessages(['campaign' => __('Add your postal address in the settings before sending. It is printed in the footer of every email.')]);
        }

        $list = MailingList::query()->find($campaign->list_id);

        if ($list === null || $list->status !== ListStatus::Active) {
            throw ValidationException::withMessages(['list_id' => __('Choose an active list.')]);
        }

        $blocks = ContentBlocks::normalize($campaign->blocks);
        $sample = $this->composer->sample($blocks, $campaign->subject, $campaign->preheader);

        if (strlen($sample->text) > 19000) {
            throw ValidationException::withMessages(['blocks' => __('The email is too long. Shorten the content.')]);
        }
    }

    private function lock(Campaign $campaign): Campaign
    {
        return Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
    }

    /** @param  list<CampaignStatus>  $allowed */
    private function assertStatus(Campaign $campaign, array $allowed, string $message): void
    {
        if (! in_array($campaign->status, $allowed, true)) {
            throw ValidationException::withMessages(['campaign' => $message]);
        }
    }
}
