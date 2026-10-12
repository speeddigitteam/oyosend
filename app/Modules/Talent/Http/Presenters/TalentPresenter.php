<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Presenters;

use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateDocument;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Note;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Pipeline;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewForm;
use App\Modules\Talent\Models\TrainingSession;
use App\Modules\Talent\Support\GoalProgress;
use App\Modules\Talent\Support\ReviewScoring;
use Carbon\CarbonInterface;

/**
 * The array shapes Talent pages receive. Keys are camelCase; dates are `Y-m-d`, instants ISO 8601 UTC.
 * People are `{id, number, name}` built from employee directory snapshots, never from HRM models.
 * Money is integer minor units next to a currency; scores are integer hundredths.
 */
final class TalentPresenter
{
    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array{id: string, number: string, name: string}|null
     */
    public static function person(?string $employeeId, array $people): ?array
    {
        $employee = $employeeId === null ? null : ($people[$employeeId] ?? null);

        return $employee === null ? null : ['id' => $employee->id, 'number' => $employee->number, 'name' => $employee->name];
    }

    private static function instant(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }

    /** @return array<string, mixed> */
    public static function pipeline(Pipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'isDefault' => $pipeline->is_default,
            'isActive' => $pipeline->is_active,
            'stages' => $pipeline->relationLoaded('stages')
                ? $pipeline->stages->map(static fn (PipelineStage $stage): array => self::stage($stage))->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public static function stage(PipelineStage $stage): array
    {
        return ['id' => $stage->id, 'pipelineId' => $stage->pipeline_id, 'name' => $stage->name, 'type' => $stage->type->value, 'position' => $stage->position];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function opening(JobOpening $opening, array $people, int $applications = 0, int $hired = 0): array
    {
        return [
            'id' => $opening->id,
            'title' => $opening->title,
            'status' => $opening->status->value,
            'employmentType' => $opening->employment_type,
            'location' => $opening->location,
            'headcount' => $opening->headcount,
            'hired' => $hired,
            'applications' => $applications,
            'department' => $opening->department === null ? null : ['id' => $opening->department->id, 'name' => $opening->department->name],
            'branch' => $opening->branch === null ? null : ['id' => $opening->branch->id, 'name' => $opening->branch->name],
            'hiringManager' => self::person($opening->hiring_manager_id, $people),
            'pipelineId' => $opening->pipeline_id,
            'description' => $opening->description,
            'requirements' => $opening->requirements,
            'opensOn' => $opening->opens_on?->toDateString(),
            'closesOn' => $opening->closes_on?->toDateString(),
            'openedAt' => self::instant($opening->opened_at),
            'closedAt' => self::instant($opening->closed_at),
            'createdAt' => self::instant($opening->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function candidate(Candidate $candidate, bool $detail = false): array
    {
        $row = [
            'id' => $candidate->id,
            'name' => $candidate->isAnonymized() ? null : $candidate->fullName(),
            'firstName' => $candidate->first_name,
            'lastName' => $candidate->last_name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'headline' => $candidate->headline,
            'currentCompany' => $candidate->current_company,
            'location' => $candidate->location,
            'source' => $candidate->source,
            'isAnonymized' => $candidate->isAnonymized(),
            'retainUntil' => $candidate->retain_until?->toDateString(),
            'createdAt' => self::instant($candidate->created_at),
        ];

        if (! $detail) {
            return $row;
        }

        return $row + [
            'summary' => $candidate->summary,
            'expectedAmount' => $candidate->expected_amount,
            'currency' => $candidate->currency,
            'consentBasis' => $candidate->consent_basis->value,
            'consentGivenAt' => self::instant($candidate->consent_given_at),
            'anonymizedAt' => self::instant($candidate->anonymized_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function document(CandidateDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'title' => $document->title,
            'originalName' => $document->original_name,
            'mimeType' => $document->mime_type,
            'size' => $document->size,
            'applicationId' => $document->application_id,
            'uploadedAt' => self::instant($document->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function note(Note $note): array
    {
        return ['id' => $note->id, 'body' => $note->body, 'applicationId' => $note->application_id, 'authorId' => $note->author_id, 'createdAt' => self::instant($note->created_at)];
    }

    /**
     * List/summary shape. Expects `candidate`, `opening` and `stage` loaded. Contact details of the
     * candidate (headline, email, phone) are only included when `$candidateDetail` is true (the viewer holds
     * `candidates.view`); the name stays, because the people working an application need it.
     *
     * @return array<string, mixed>
     */
    public static function application(Application $application, bool $candidateDetail = false): array
    {
        $candidate = $application->candidate;

        return [
            'id' => $application->id,
            'status' => $application->status->value,
            'appliedOn' => $application->applied_on->toDateString(),
            'source' => $application->source,
            'stage' => ['id' => $application->stage->id, 'name' => $application->stage->name, 'type' => $application->stage->type->value],
            'opening' => ['id' => $application->opening->id, 'title' => $application->opening->title, 'status' => $application->opening->status->value],
            'candidate' => [
                'id' => $candidate->id,
                'name' => $candidate->isAnonymized() ? null : $candidate->fullName(),
                'headline' => $candidateDetail ? $candidate->headline : null,
                'isAnonymized' => $candidate->isAnonymized(),
                'email' => $candidateDetail ? $candidate->email : null,
                'phone' => $candidateDetail ? $candidate->phone : null,
            ],
            'rejectionReasonId' => $application->rejection_reason_id,
            'rejectionNote' => $application->rejection_note,
            'startOn' => $application->start_on?->toDateString(),
            'hiredAt' => self::instant($application->hired_at),
            'statusChangedAt' => self::instant($application->status_changed_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function offer(Offer $offer): array
    {
        return [
            'id' => $offer->id,
            'applicationId' => $offer->application_id,
            'status' => $offer->status->value,
            'amount' => $offer->amount,
            'currency' => $offer->currency,
            'proposedStartOn' => $offer->proposed_start_on?->toDateString(),
            'expiresOn' => $offer->expires_on?->toDateString(),
            'sentAt' => self::instant($offer->sent_at),
            'decidedAt' => self::instant($offer->decided_at),
            'notes' => $offer->notes,
        ];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @param  string  $timezone  The zone the local fields are rendered in.
     * @return array<string, mixed>
     */
    public static function interview(Interview $interview, array $people, string $timezone): array
    {
        $local = $interview->starts_at->copy()->setTimezone($timezone);
        $localEnd = $interview->ends_at->copy()->setTimezone($timezone);

        return [
            'id' => $interview->id,
            'applicationId' => $interview->application_id,
            'roundName' => $interview->round_name,
            'mode' => $interview->mode->value,
            'status' => $interview->status->value,
            'outcome' => $interview->outcome?->value,
            'startsAt' => self::instant($interview->starts_at),
            'endsAt' => self::instant($interview->ends_at),
            'timezone' => $interview->timezone,
            'date' => $local->toDateString(),
            'time' => $local->format('H:i'),
            'endTime' => $localEnd->format('H:i'),
            'durationMinutes' => (int) $interview->starts_at->diffInMinutes($interview->ends_at),
            'location' => $interview->location,
            'notes' => $interview->notes,
            'panel' => $interview->relationLoaded('panel')
                ? $interview->panel->map(static fn ($member): ?array => self::person($member->employee_id, $people))->filter()->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function checklistTask(ChecklistTask $task, array $people, array $overrides = []): array
    {
        return [
            'id' => $task->id,
            'checklistId' => $task->checklist_id,
            'title' => $task->title,
            'description' => $task->description,
            'assigneeRole' => $task->assignee_role->value,
            'assignee' => self::person($task->assignee_employee_id, $people),
            'dueOn' => $task->due_on?->toDateString(),
            'status' => $task->status->value,
            'completedAt' => self::instant($task->completed_at),
            'note' => $task->note,
        ] + $overrides;
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function checklist(Checklist $checklist, array $people, bool $withTasks = false): array
    {
        $tasks = $checklist->relationLoaded('tasks') ? $checklist->tasks : collect();
        $done = $tasks->filter(static fn (ChecklistTask $task): bool => $task->status->value !== 'pending')->count();

        return [
            'id' => $checklist->id,
            'name' => $checklist->name,
            'kind' => $checklist->kind->value,
            'status' => $checklist->status->value,
            'employee' => self::person($checklist->employee_id, $people),
            'anchorDate' => $checklist->anchor_date->toDateString(),
            'completedAt' => self::instant($checklist->completed_at),
            'notes' => $checklist->notes,
            'taskCount' => $tasks->count(),
            'doneCount' => $done,
            'tasks' => $withTasks ? $tasks->map(static fn (ChecklistTask $task): array => self::checklistTask($task, $people))->values()->all() : [],
        ];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function template(ChecklistTemplate $template, array $people = []): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'kind' => $template->kind->value,
            'isDefault' => $template->is_default,
            'isActive' => $template->is_active,
            'tasks' => $template->relationLoaded('tasks')
                ? $template->tasks->map(static fn ($task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'assigneeRole' => $task->assignee_role->value,
                    'assignee' => self::person($task->assignee_employee_id, $people),
                    'assigneeEmployeeId' => $task->assignee_employee_id,
                    'dueOffsetDays' => $task->due_offset_days,
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function probation(Probation $probation, array $people, bool $withReviews = false): array
    {
        return [
            'id' => $probation->id,
            'employee' => self::person($probation->employee_id, $people),
            'status' => $probation->status->value,
            'startedOn' => $probation->started_on->toDateString(),
            'endsOn' => $probation->ends_on->toDateString(),
            'closedOn' => $probation->closed_on?->toDateString(),
            'reviews' => $withReviews && $probation->relationLoaded('reviews')
                ? $probation->reviews->map(static fn ($review): array => [
                    'id' => $review->id,
                    'decision' => $review->decision->value,
                    'extendUntil' => $review->extend_until?->toDateString(),
                    'note' => $review->note,
                    'decidedOn' => $review->decided_on->toDateString(),
                ])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public static function form(ReviewForm $form): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'description' => $form->description,
            'scaleMax' => $form->scale_max,
            'isActive' => $form->is_active,
            'items' => $form->relationLoaded('items')
                ? $form->items->map(static fn ($item): array => [
                    'id' => $item->id, 'name' => $item->name, 'description' => $item->description, 'weightBp' => $item->weight_bp, 'position' => $item->position,
                ])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public static function cycle(ReviewCycle $cycle, int $reviews = 0): array
    {
        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'status' => $cycle->status->value,
            'formId' => $cycle->form_id,
            'startsOn' => $cycle->starts_on->toDateString(),
            'endsOn' => $cycle->ends_on->toDateString(),
            'selfDueOn' => $cycle->self_due_on?->toDateString(),
            'managerDueOn' => $cycle->manager_due_on?->toDateString(),
            'requiresSelfReview' => $cycle->requires_self_review,
            'departmentId' => $cycle->department_id,
            'launchedAt' => self::instant($cycle->launched_at),
            'closedAt' => self::instant($cycle->closed_at),
            'reviews' => $reviews,
        ];
    }

    /**
     * A review row. `$viewerIsSubject` hides everything the manager wrote until the review is
     * calibrated: an employee never sees the manager's score or comments before release.
     *
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function review(Review $review, array $people, bool $viewerIsSubject = false): array
    {
        $released = in_array($review->status->value, ['calibrated', 'acknowledged'], true);
        $hide = $viewerIsSubject && ! $released;

        return [
            'id' => $review->id,
            'cycleId' => $review->cycle_id,
            'employee' => self::person($review->employee_id, $people),
            'reviewer' => self::person($review->reviewer_employee_id, $people),
            'status' => $review->status->value,
            'selfScore' => $review->self_score,
            'managerScore' => $hide ? null : $review->manager_score,
            'finalScore' => $viewerIsSubject && ! $released ? null : $review->final_score,
            'finalOverridden' => $hide ? false : $review->final_overridden,
            'selfSubmittedAt' => self::instant($review->self_submitted_at),
            'managerSubmittedAt' => $hide ? null : self::instant($review->manager_submitted_at),
            'calibratedAt' => self::instant($review->calibrated_at),
            'acknowledgedAt' => self::instant($review->acknowledged_at),
            'scoreText' => [
                'self' => $review->self_score === null ? null : ReviewScoring::format($review->self_score),
                'manager' => $hide || $review->manager_score === null ? null : ReviewScoring::format($review->manager_score),
                'final' => ($viewerIsSubject && ! $released) || $review->final_score === null ? null : ReviewScoring::format($review->final_score),
            ],
        ];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function goal(Goal $goal, array $people): array
    {
        return [
            'id' => $goal->id,
            'employee' => self::person($goal->employee_id, $people),
            'cycleId' => $goal->cycle_id,
            'title' => $goal->title,
            'description' => $goal->description,
            'kind' => $goal->kind->value,
            'unit' => $goal->unit,
            'startValue' => $goal->start_value,
            'targetValue' => $goal->target_value,
            'currentValue' => $goal->current_value,
            'progressBp' => GoalProgress::basisPoints($goal->start_value, $goal->target_value, $goal->current_value),
            'status' => $goal->status->value,
            'dueOn' => $goal->due_on?->toDateString(),
            'achievedAt' => self::instant($goal->achieved_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function course(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'code' => $course->code,
            'provider' => $course->provider,
            'description' => $course->description,
            'durationMinutes' => $course->duration_minutes,
            'validityMonths' => $course->validity_months,
            'isMandatory' => $course->is_mandatory,
            'isActive' => $course->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function session(TrainingSession $session, string $timezone, ?int $enrolled = null): array
    {
        $local = $session->starts_at->copy()->setTimezone($timezone);

        return [
            'id' => $session->id,
            'courseId' => $session->course_id,
            'status' => $session->status->value,
            'startsAt' => self::instant($session->starts_at),
            'endsAt' => self::instant($session->ends_at),
            'timezone' => $session->timezone,
            'date' => $local->toDateString(),
            'time' => $local->format('H:i'),
            'location' => $session->location,
            'trainer' => $session->trainer,
            'capacity' => $session->capacity,
            'enrolled' => $enrolled,
        ];
    }

    /**
     * @param  array<string, EmployeeSnapshot>  $people
     * @return array<string, mixed>
     */
    public static function enrolment(Enrolment $enrolment, array $people): array
    {
        return [
            'id' => $enrolment->id,
            'course' => $enrolment->relationLoaded('course') ? ['id' => $enrolment->course->id, 'title' => $enrolment->course->title, 'validityMonths' => $enrolment->course->validity_months] : ['id' => $enrolment->course_id],
            'sessionId' => $enrolment->session_id,
            'employee' => self::person($enrolment->employee_id, $people),
            'status' => $enrolment->status->value,
            'enrolledOn' => $enrolment->enrolled_on->toDateString(),
            'completedOn' => $enrolment->completed_on?->toDateString(),
            'score' => $enrolment->score,
            'expiresOn' => $enrolment->expires_on?->toDateString(),
            'notes' => $enrolment->notes,
        ];
    }
}
