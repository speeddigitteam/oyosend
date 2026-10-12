<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Communication\Data\AudienceDecision;
use App\Core\Identity\Models\User;
use App\Modules\EmailMarketing\Actions\CampaignReports;
use App\Modules\EmailMarketing\Actions\ContentComposer;
use App\Modules\EmailMarketing\Actions\EmailMarketingSettings;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Models\Template;
use Illuminate\Support\Carbon;

/** Turns models into the arrays the Inertia pages receive. No address, token or tracking secret passes here. */
final class EmailMarketingPresenter
{
    public function __construct(
        private readonly CampaignReports $reports,
        private readonly ContentComposer $composer,
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly EmailMarketingSettings $settings,
    ) {}

    /**
     * @param  array<string, int>  $counts  status => count of the campaign's deliveries
     * @return array<string, mixed>
     */
    public function campaignSummary(Campaign $campaign, array $counts = []): array
    {
        $sent = ($counts['sent'] ?? 0) + ($counts['delivered'] ?? 0);

        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'status' => $campaign->status->value,
            'listId' => $campaign->list_id,
            'listName' => $campaign->relationLoaded('list') ? $campaign->list->name : null,
            'segmentName' => $campaign->relationLoaded('segment') ? $campaign->segment?->name : null,
            'scheduledFor' => $campaign->scheduled_for?->toIso8601String(),
            'startedAt' => $campaign->started_at?->toIso8601String(),
            'finishedAt' => $campaign->finished_at?->toIso8601String(),
            'pausedReason' => $campaign->paused_reason,
            'abEnabled' => $campaign->ab_enabled,
            'audienceTotal' => $campaign->audience_total,
            'counts' => [
                'planned' => array_sum($counts),
                'sent' => $sent,
                'blocked' => $counts['blocked'] ?? 0,
                'skipped' => $counts['skipped'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'bounced' => $counts['bounced'] ?? 0,
                'pending' => ($counts['pending'] ?? 0) + ($counts['held'] ?? 0) + ($counts['sending'] ?? 0) + ($counts['queued'] ?? 0),
            ],
            'createdAt' => $campaign->created_at->toIso8601String(),
            'owner' => $campaign->relationLoaded('owner') && $campaign->owner !== null
                ? ['id' => $campaign->owner->id, 'name' => $campaign->owner->user->name]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function campaignDetail(Campaign $campaign, User $actor): array
    {
        $campaign->loadMissing(['list', 'segment', 'owner.user']);
        $report = $this->reports->campaign($campaign);
        $preview = $this->composer->sample($campaign->blocks, $campaign->subject, $campaign->preheader);
        $editable = $campaign->status->isEditable();
        $canSend = $this->access->allows($actor, 'email_marketing.campaigns.send');

        return [
            ...$this->campaignSummary($campaign, array_map('intval', array_intersect_key($report['figures'], array_flip(['pending', 'held', 'sending', 'queued', 'sent', 'delivered', 'failed', 'bounced', 'blocked', 'skipped', 'cancelled'])))),
            'preheader' => $campaign->preheader,
            'blocks' => $campaign->blocks,
            'templateId' => $campaign->template_id,
            'segmentId' => $campaign->segment_id,
            'trackClicks' => $campaign->track_clicks,
            'scheduledForLocal' => $campaign->scheduled_for?->setTimezone($this->settings->timezone())->format('Y-m-d\TH:i'),
            'ab' => [
                'enabled' => $campaign->ab_enabled,
                'subjectB' => $campaign->ab_subject_b,
                'percent' => $campaign->ab_test_percent,
                'waitHours' => $campaign->ab_wait_hours,
                'metric' => $campaign->ab_metric,
                'winner' => $campaign->ab_winner,
                'winnerReason' => $campaign->ab_winner_reason,
                'decideAt' => $campaign->ab_decide_at?->toIso8601String(),
                'decidedAt' => $campaign->ab_decided_at?->toIso8601String(),
            ],
            'report' => $report,
            'preview' => ['subject' => $preview->subject, 'html' => $preview->html, 'text' => $preview->text],
            'abilities' => [
                'edit' => $editable && $this->records->allows($actor, 'email_marketing.campaigns.edit', $campaign),
                'delete' => in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Cancelled], true)
                    && $this->records->allows($actor, 'email_marketing.campaigns.delete', $campaign),
                'schedule' => $canSend && in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true),
                'unschedule' => $canSend && $campaign->status === CampaignStatus::Scheduled,
                'pause' => $canSend && $campaign->status->isActive(),
                'resume' => $canSend && $campaign->status === CampaignStatus::Paused,
                'cancel' => $canSend && ! $campaign->status->isFinal(),
                'test' => $canSend,
                'duplicate' => $this->access->allows($actor, 'email_marketing.campaigns.create'),
                'export' => $this->access->allows($actor, 'email_marketing.reports.view'),
            ],
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    public function list(MailingList $list, array $counts = []): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'status' => $list->status->value,
            'counts' => [
                'subscribed' => $counts['subscribed'] ?? 0,
                'pending' => $counts['pending'] ?? 0,
                'unsubscribed' => $counts['unsubscribed'] ?? 0,
                'bounced' => $counts['bounced'] ?? 0,
                'complained' => $counts['complained'] ?? 0,
            ],
            'createdAt' => $list->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function segment(Segment $segment, ?int $count = null): array
    {
        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'match' => $segment->match,
            'rules' => $segment->rules,
            'count' => $count,
        ];
    }

    /** @return array<string, mixed> */
    public function subscription(Subscription $subscription, ?AudienceDecision $decision = null): array
    {
        $contact = $subscription->contact;

        return [
            'id' => $subscription->id,
            'listId' => $subscription->list_id,
            'listName' => $subscription->relationLoaded('list') ? $subscription->list->name : null,
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->fullName(),
                'email' => $contact->email,
            ],
            'status' => $subscription->status->value,
            'source' => $subscription->source,
            'consentMethod' => $subscription->consent_method,
            'consentEvidence' => $subscription->consent_evidence,
            'consentedAt' => $subscription->consented_at?->toIso8601String(),
            'subscribedAt' => $subscription->subscribed_at?->toIso8601String(),
            'unsubscribedAt' => $subscription->unsubscribed_at?->toIso8601String(),
            'statusReason' => $subscription->status_reason,
            'properties' => $subscription->properties ?? (object) [],
            // The platform's answer for this address right now: only `ok` can ever be sent to.
            'platform' => $decision === null ? null : ['allowed' => $decision->allowed, 'reason' => $decision->reason->value],
        ];
    }

    /** @return array<string, mixed> */
    public function template(Template $template, bool $withBlocks = true): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'subject' => $template->subject,
            'preheader' => $template->preheader,
            'blockCount' => count($template->blocks),
            'blocks' => $withBlocks ? $template->blocks : [],
            'updatedAt' => $template->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function sequence(Sequence $sequence, bool $withSteps = false): array
    {
        $enrollments = Enrollment::query()
            ->where('sequence_id', $sequence->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'id' => $sequence->id,
            'name' => $sequence->name,
            'description' => $sequence->description,
            'status' => $sequence->status->value,
            'listId' => $sequence->list_id,
            'listName' => $sequence->relationLoaded('list') ? $sequence->list->name : null,
            'activatedAt' => $sequence->activated_at?->toIso8601String(),
            'stepCount' => $sequence->relationLoaded('steps') ? $sequence->steps->count() : SequenceStep::query()->where('sequence_id', $sequence->id)->count(),
            'enrollments' => [
                'active' => (int) ($enrollments['active'] ?? 0),
                'completed' => (int) ($enrollments['completed'] ?? 0),
                'stopped' => (int) ($enrollments['stopped'] ?? 0),
            ],
            'steps' => $withSteps
                ? $sequence->steps->map(fn (SequenceStep $step): array => [
                    'id' => $step->id,
                    'position' => $step->position,
                    'delayMinutes' => $step->delay_minutes,
                    'subject' => $step->subject,
                    'preheader' => $step->preheader,
                    'blocks' => $step->blocks,
                ])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public function delivery(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'contact' => $delivery->contact === null
                ? ['id' => $delivery->contact_id, 'name' => __('Deleted contact'), 'email' => null]
                : ['id' => $delivery->contact->id, 'name' => $delivery->contact->fullName(), 'email' => $delivery->contact->email],
            'variant' => $delivery->variant,
            'status' => $delivery->status->value,
            'reason' => $delivery->reason,
            'sentAt' => $delivery->sent_at?->toIso8601String(),
            'clickedAt' => $delivery->clicked_at?->toIso8601String(),
            'clickCount' => $delivery->click_count,
            'viewedAt' => $delivery->viewed_at?->toIso8601String(),
        ];
    }

    public function iso(?Carbon $moment): ?string
    {
        return $moment?->toIso8601String();
    }
}
