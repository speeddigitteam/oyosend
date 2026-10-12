<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\Talent\Actions\TalentSettings;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Enums\InterviewStatus;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Enums\TaskStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\InterviewPanelist;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in person's own Talent summary: what is waiting for them and their own records. */
final class MeController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly TalentSettings $settings,
    ) {}

    public function show(Request $request): Response
    {
        $actor = $this->support->user($request);
        $employee = $this->support->employee($actor);

        if ($employee === null) {
            return Inertia::render('Modules/Talent/Pages/Me/Show', ['hasProfile' => false]);
        }

        $people = $this->support->people([$employee->id]);
        $timezone = $this->support->timezone();

        $reviews = Review::query()->with('cycle')
            ->where(fn ($query) => $query->where('employee_id', $employee->id)->orWhere('reviewer_employee_id', $employee->id))
            ->whereIn('cycle_id', ReviewCycle::query()->where('status', '!=', CycleStatus::Closed)->select('id'))
            ->orderByDesc('created_at')->limit(50)->get();
        $reviewPeople = $this->support->people($reviews->flatMap(static fn (Review $review): array => [$review->employee_id, $review->reviewer_employee_id])->all());

        $panel = InterviewPanelist::query()->where('employee_id', $employee->id)->select('interview_id');
        $interviews = Interview::query()->with(['panel', 'application.candidate', 'application.opening'])
            ->whereIn('id', $panel)->where('status', InterviewStatus::Scheduled)->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')->limit(20)->get();

        return Inertia::render('Modules/Talent/Pages/Me/Show', [
            'hasProfile' => true,
            'employee' => TalentPresenter::person($employee->id, $people),
            'today' => $this->settings->today()->toDateString(),
            'tasks' => ChecklistTask::query()->with('checklist')->where('assignee_employee_id', $employee->id)->where('status', TaskStatus::Pending)
                ->orderByRaw('due_on is null')->orderBy('due_on')->limit(50)->get()
                ->map(static fn (ChecklistTask $task): array => TalentPresenter::checklistTask($task, [], [
                    'checklistName' => $task->checklist->name, 'checklistKind' => $task->checklist->kind->value, 'checklistId' => $task->checklist_id,
                ]))->all(),
            'reviews' => $reviews->map(static fn (Review $review): array => TalentPresenter::review($review, $reviewPeople, $review->employee_id === $employee->id) + [
                'cycle' => ['id' => $review->cycle->id, 'name' => $review->cycle->name, 'status' => $review->cycle->status->value],
                'role' => $review->employee_id === $employee->id ? 'subject' : 'reviewer',
                'awaitingMe' => ($review->employee_id === $employee->id && $review->status === ReviewStatus::PendingSelf)
                    || ($review->reviewer_employee_id === $employee->id && $review->status === ReviewStatus::PendingManager)
                    || ($review->employee_id === $employee->id && $review->status === ReviewStatus::Calibrated),
            ])->all(),
            'goals' => Goal::query()->where('employee_id', $employee->id)->where('status', GoalStatus::Active)->orderBy('due_on')->limit(50)->get()
                ->map(static fn (Goal $goal): array => TalentPresenter::goal($goal, $people))->all(),
            'enrolments' => Enrolment::query()->with('course')->where('employee_id', $employee->id)
                ->whereIn('status', [EnrolmentStatus::Enrolled, EnrolmentStatus::Completed])->orderByDesc('created_at')->limit(50)->get()
                ->map(static fn (Enrolment $enrolment): array => TalentPresenter::enrolment($enrolment, $people))->all(),
            'probation' => ($probation = Probation::query()->where('employee_id', $employee->id)->where('status', ProbationStatus::InProgress)->first()) === null
                ? null
                : ['endsOn' => $probation->ends_on->toDateString(), 'daysLeft' => $this->settings->daysUntil($probation->ends_on->toDateString())],
            'interviews' => $interviews->map(static fn (Interview $interview): array => TalentPresenter::interview($interview, $people, $timezone) + [
                'candidate' => ['id' => $interview->application->candidate->id, 'name' => $interview->application->candidate->isAnonymized() ? null : $interview->application->candidate->fullName()],
                'opening' => ['id' => $interview->application->opening->id, 'title' => $interview->application->opening->title],
            ])->all(),
        ]);
    }
}
