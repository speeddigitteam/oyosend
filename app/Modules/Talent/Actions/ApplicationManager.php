<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Talent\Enums\ApplicationEvent;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\OfferStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Enums\StageType;
use App\Modules\Talent\Events\ApplicationCreated;
use App\Modules\Talent\Events\ApplicationRejected;
use App\Modules\Talent\Events\ApplicationStageChanged;
use App\Modules\Talent\Events\CandidateHired;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\ApplicationHistory;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\RejectionReason;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The candidate pipeline: applying, moving between stages, rejecting, withdrawing, reopening and
 * hiring. Every change writes an immutable history row and an audit entry in the same transaction.
 *
 * Hiring announces `talent.candidate.hired`. HRM exposes no hire-intake contract yet, so the
 * employee record is created from that event's payload by a person or a future listener; nothing
 * here writes HRM tables.
 */
final class ApplicationManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecruitmentCatalogue $catalogue,
        private readonly OpeningManager $openings,
        private readonly TalentSettings $settings,
    ) {}

    /** @param array{source?: string|null} $attributes */
    public function apply(JobOpening $opening, Candidate $candidate, array $attributes, ?TenantMembership $actor): Application
    {
        if ($candidate->isAnonymized()) {
            throw ValidationException::withMessages(['candidate_id' => __('An anonymized candidate cannot apply.')]);
        }

        return DB::transaction(function () use ($opening, $candidate, $attributes, $actor): Application {
            /** @var JobOpening $locked */
            $locked = JobOpening::query()->whereKey($opening->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OpeningStatus::Open) {
                throw ValidationException::withMessages(['opening_id' => __('Applications can only be added to an open job opening.')]);
            }

            if (Application::query()->where('opening_id', $locked->id)->where('candidate_id', $candidate->id)->exists()) {
                throw ValidationException::withMessages(['candidate_id' => __('This candidate already has an application for this opening.')]);
            }

            $stage = $this->catalogue->firstStage($locked->pipeline_id);
            $application = new Application([
                'opening_id' => $locked->id,
                'candidate_id' => $candidate->id,
                'stage_id' => $stage->id,
                'status' => ApplicationStatus::Open,
                'source' => $attributes['source'] ?? $candidate->source,
                'applied_on' => $this->settings->today()->toDateString(),
                'status_changed_at' => now(),
                'created_by' => $actor?->id,
            ]);
            $application->copyPlacement($locked);

            try {
                $application->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['candidate_id' => __('This candidate already has an application for this opening.')]);
            }

            $this->history($application, ApplicationEvent::Applied, null, $stage->id, null, null, $actor);
            ApplicationCreated::dispatch([
                'application_id' => $application->id, 'opening_id' => $locked->id, 'candidate_id' => $candidate->id, 'stage_id' => $stage->id,
            ]);

            return $application;
        });
    }

    public function move(Application $application, PipelineStage $stage, ?string $note, ?TenantMembership $actor): Application
    {
        return DB::transaction(function () use ($application, $stage, $note, $actor): Application {
            $locked = $this->lock($application);
            $this->requireOpen($locked);
            $locked->loadMissing('opening');

            if ($stage->pipeline_id !== $locked->opening->pipeline_id) {
                throw ValidationException::withMessages(['stage_id' => __('Choose a stage of this opening\'s pipeline.')]);
            }

            if ($stage->type !== StageType::Active) {
                throw ValidationException::withMessages(['stage_id' => __('Use Hire or Reject to finish an application.')]);
            }

            if ($stage->id === $locked->stage_id) {
                throw ValidationException::withMessages(['stage_id' => __('The application is already at this stage.')]);
            }

            $from = $locked->stage_id;
            $locked->stage_id = $stage->id;
            $locked->status_changed_at = now();
            $locked->save();

            $this->history($locked, ApplicationEvent::Moved, $from, $stage->id, null, $note, $actor);
            $this->audit->record('talent.application.moved', $locked, ['from_stage_id' => $from, 'to_stage_id' => $stage->id]);
            ApplicationStageChanged::dispatch([
                'application_id' => $locked->id, 'opening_id' => $locked->opening_id, 'candidate_id' => $locked->candidate_id,
                'from_stage_id' => $from, 'to_stage_id' => $stage->id, 'stage_name' => $stage->name, 'stage_type' => $stage->type->value,
            ]);

            return $locked;
        });
    }

    public function reject(Application $application, ?RejectionReason $reason, ?string $note, ?TenantMembership $actor): Application
    {
        if ($reason !== null && ! $reason->is_active) {
            throw ValidationException::withMessages(['reason_id' => __('Choose an active rejection reason.')]);
        }

        return DB::transaction(function () use ($application, $reason, $note, $actor): Application {
            $locked = $this->lock($application);
            $this->requireOpen($locked);
            $locked->loadMissing('opening');

            $from = $locked->stage_id;
            $outcome = $this->catalogue->outcomeStage($locked->opening->pipeline_id, StageType::Rejected);
            $locked->status = ApplicationStatus::Rejected;
            $locked->stage_id = $outcome->id ?? $locked->stage_id;
            $locked->rejection_reason_id = $reason?->id;
            $locked->rejection_note = $note;
            $locked->status_changed_at = now();
            $locked->save();

            $this->withdrawLiveOffer($locked);
            $this->history($locked, ApplicationEvent::Rejected, $from, $locked->stage_id, $reason?->id, $note, $actor);
            $this->audit->record('talent.application.rejected', $locked, ['reason_id' => $reason?->id]);
            ApplicationRejected::dispatch([
                'application_id' => $locked->id, 'opening_id' => $locked->opening_id, 'candidate_id' => $locked->candidate_id, 'reason_id' => $reason?->id,
            ]);

            return $locked;
        });
    }

    public function withdraw(Application $application, ?string $note, ?TenantMembership $actor): Application
    {
        return DB::transaction(function () use ($application, $note, $actor): Application {
            $locked = $this->lock($application);
            $this->requireOpen($locked);

            $locked->status = ApplicationStatus::Withdrawn;
            $locked->status_changed_at = now();
            $locked->save();

            $this->withdrawLiveOffer($locked);
            $this->history($locked, ApplicationEvent::Withdrawn, $locked->stage_id, null, null, $note, $actor);
            $this->audit->record('talent.application.withdrawn', $locked);

            return $locked;
        });
    }

    /** Put a rejected or withdrawn application back into the pipeline. */
    public function reopen(Application $application, ?PipelineStage $stage, ?string $note, ?TenantMembership $actor): Application
    {
        return DB::transaction(function () use ($application, $stage, $note, $actor): Application {
            $locked = $this->lock($application);
            $locked->loadMissing(['opening', 'candidate']);

            if (! in_array($locked->status, [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn], true)) {
                throw ValidationException::withMessages(['status' => __('Only a rejected or withdrawn application can be reopened.')]);
            }

            if ($locked->opening->status === OpeningStatus::Closed) {
                throw ValidationException::withMessages(['status' => __('The job opening is closed.')]);
            }

            if ($locked->candidate->isAnonymized()) {
                throw ValidationException::withMessages(['status' => __('An anonymized candidate cannot be reopened.')]);
            }

            $target = $stage ?? $this->catalogue->firstStage($locked->opening->pipeline_id);

            if ($target->pipeline_id !== $locked->opening->pipeline_id || $target->type !== StageType::Active) {
                throw ValidationException::withMessages(['stage_id' => __('Choose an active stage of this opening\'s pipeline.')]);
            }

            $from = $locked->stage_id;
            $locked->status = ApplicationStatus::Open;
            $locked->stage_id = $target->id;
            $locked->rejection_reason_id = null;
            $locked->rejection_note = null;
            $locked->status_changed_at = now();
            $locked->save();

            $this->history($locked, ApplicationEvent::Reopened, $from, $target->id, null, $note, $actor);
            $this->audit->record('talent.application.reopened', $locked);

            return $locked;
        });
    }

    /**
     * Hire the candidate. A live offer must be accepted; the opening must have a free position, and
     * is closed automatically when the last one is filled.
     *
     * @param  array{start_on?: string|null}  $attributes
     */
    public function hire(Application $application, array $attributes, ?TenantMembership $actor): Application
    {
        return DB::transaction(function () use ($application, $attributes, $actor): Application {
            $locked = $this->lock($application);
            $this->requireOpen($locked);
            $locked->loadMissing(['opening', 'candidate']);

            /** @var JobOpening $opening */
            $opening = JobOpening::query()->whereKey($locked->opening_id)->lockForUpdate()->firstOrFail();

            if ($opening->status !== OpeningStatus::Open) {
                throw ValidationException::withMessages(['status' => __('Only an open job opening can hire.')]);
            }

            $hired = $this->openings->hiredCount($opening);

            if ($hired >= $opening->headcount) {
                throw ValidationException::withMessages(['status' => __('Every position of this opening is already filled.')]);
            }

            // The latest offer decides, whether or not it is still "live": a declined or withdrawn offer
            // must be replaced by an accepted one before the candidate can be hired.
            $offer = Offer::query()->where('application_id', $locked->id)->orderByDesc('created_at')->orderByDesc('id')->first();

            if ($offer !== null && $offer->status !== OfferStatus::Accepted) {
                throw ValidationException::withMessages(['status' => __('The offer has not been accepted yet.')]);
            }

            $stage = $this->catalogue->outcomeStage($opening->pipeline_id, StageType::Hired)
                ?? throw ValidationException::withMessages(['status' => __('The pipeline has no hired stage.')]);

            $startOn = $attributes['start_on'] ?? $offer?->proposed_start_on?->toDateString();

            if ($startOn !== null && $startOn !== '' && $startOn < $this->settings->today()->subYear()->toDateString()) {
                throw ValidationException::withMessages(['start_on' => __('The start date is too far in the past.')]);
            }

            $from = $locked->stage_id;
            $locked->status = ApplicationStatus::Hired;
            $locked->stage_id = $stage->id;
            $locked->hired_at = now();
            $locked->setAttribute('start_on', $startOn === '' ? null : $startOn);
            $locked->status_changed_at = now();
            $locked->save();

            $this->history($locked, ApplicationEvent::Hired, $from, $stage->id, null, null, $actor);
            $this->audit->record('talent.application.hired', $locked, ['opening_id' => $opening->id]);

            $candidate = $locked->candidate;
            CandidateHired::dispatch([
                'application_id' => $locked->id,
                'opening_id' => $opening->id,
                'candidate_id' => $candidate->id,
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'job_title' => $opening->title,
                'employment_type' => $opening->employment_type,
                'department_id' => $opening->department_id,
                'branch_id' => $opening->branch_id,
                'hiring_manager_id' => $opening->hiring_manager_id,
                'start_on' => $locked->start_on?->toDateString(),
                'offer_amount' => $offer?->amount,
                'offer_currency' => $offer?->currency,
            ]);

            if ($hired + 1 >= $opening->headcount) {
                $this->openings->changeStatus($opening, OpeningStatus::Closed, $actor);
            }

            return $locked;
        });
    }

    private function lock(Application $application): Application
    {
        /** @var Application */
        return Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
    }

    private function requireOpen(Application $application): void
    {
        if ($application->status !== ApplicationStatus::Open) {
            throw ValidationException::withMessages(['status' => __('This application is already finished.')]);
        }
    }

    private function withdrawLiveOffer(Application $application): void
    {
        Offer::query()->where('application_id', $application->id)->whereNotNull('live_marker')
            ->whereIn('status', [OfferStatus::Draft, OfferStatus::Sent])
            ->update(['status' => OfferStatus::Withdrawn, 'live_marker' => null, 'decided_at' => now()]);
    }

    private function history(
        Application $application,
        ApplicationEvent $event,
        ?string $from,
        ?string $to,
        ?string $reasonId,
        ?string $note,
        ?TenantMembership $actor,
    ): void {
        ApplicationHistory::query()->create([
            'application_id' => $application->id,
            'event' => $event,
            'from_stage_id' => $from,
            'to_stage_id' => $to,
            'reason_id' => $reasonId,
            'note' => $note,
            'actor_id' => $actor?->id,
        ]);
    }
}
