<?php

declare(strict_types=1);

namespace App\Modules\Talent\Widgets;

use App\Core\Identity\Models\User;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Enums\TaskStatus;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Support\TalentActor;

/** The signed-in person's own Talent summary (their review step, goals, tasks and training). */
final class TalentSelfWidget
{
    public function __construct(private readonly TalentActor $actor) {}

    /** @return array<string, mixed> */
    public function __invoke(User $user): array
    {
        $employeeId = $this->actor->employeeId($user);

        if ($employeeId === null) {
            return ['hasProfile' => false, 'pendingTasks' => 0, 'reviewsToWrite' => 0, 'reviewsToAcknowledge' => 0, 'activeGoals' => 0, 'openEnrolments' => 0];
        }

        $reviewCycles = ReviewCycle::query()->where('status', CycleStatus::Active)->select('id');

        return [
            'hasProfile' => true,
            'pendingTasks' => ChecklistTask::query()->where('assignee_employee_id', $employeeId)->where('status', TaskStatus::Pending)->count(),
            // Reviews waiting on this person: their own self review, or a team member's manager review.
            'reviewsToWrite' => Review::query()->whereIn('cycle_id', $reviewCycles)->where(function ($query) use ($employeeId): void {
                $query->where(fn ($self) => $self->where('employee_id', $employeeId)->where('status', ReviewStatus::PendingSelf))
                    ->orWhere(fn ($manager) => $manager->where('reviewer_employee_id', $employeeId)->where('status', ReviewStatus::PendingManager));
            })->count(),
            'reviewsToAcknowledge' => Review::query()->where('employee_id', $employeeId)->where('status', ReviewStatus::Calibrated)->count(),
            'activeGoals' => Goal::query()->where('employee_id', $employeeId)->where('status', GoalStatus::Active)->count(),
            'openEnrolments' => Enrolment::query()->where('employee_id', $employeeId)->where('status', EnrolmentStatus::Enrolled)->count(),
        ];
    }
}
