<?php

declare(strict_types=1);

namespace App\Modules\Talent\Widgets;

use App\Core\Identity\Models\User;
use App\Modules\Talent\Actions\TalentSettings;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\ChecklistStatus;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\InterviewStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Enums\TaskStatus;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\JobOpening;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;

/** The Talent dashboard widget: workspace-wide counts, shown with `talent.analytics.view`. */
final class TalentOverviewWidget
{
    public function __construct(private readonly TalentSettings $settings) {}

    /** @return array<string, int> */
    public function __invoke(User $user): array
    {
        $today = $this->settings->today();
        $settings = $this->settings->current();

        return [
            'openOpenings' => JobOpening::query()->where('status', OpeningStatus::Open)->count(),
            'activeApplications' => Application::query()->where('status', ApplicationStatus::Open)->count(),
            'interviewsThisWeek' => Interview::query()->where('status', InterviewStatus::Scheduled)
                ->where('starts_at', '>=', now())->where('starts_at', '<', now()->addDays(7))->count(),
            'openOnboardingTasks' => ChecklistTask::query()->where('status', TaskStatus::Pending)
                ->whereIn('checklist_id', Checklist::query()->where('status', ChecklistStatus::Open)->select('id'))->count(),
            'probationsEnding' => Probation::query()->where('status', ProbationStatus::InProgress)
                ->where('ends_on', '<=', $today->addDays($settings->probation_reminder_days)->toDateString())->count(),
            'reviewsAwaitingCalibration' => Review::query()->where('status', ReviewStatus::PendingCalibration)
                ->whereIn('cycle_id', ReviewCycle::query()->where('status', CycleStatus::Calibration)->select('id'))->count(),
            'certificatesExpiring' => Enrolment::query()->where('status', EnrolmentStatus::Completed)
                ->where('expires_on', '>=', $today->toDateString())
                ->where('expires_on', '<=', $today->addDays($settings->certification_reminder_days)->toDateString())->count(),
        ];
    }
}
