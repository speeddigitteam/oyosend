<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Enums\InterviewStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Scorecard;
use App\Modules\Talent\Support\TalentPermissions;

/**
 * Presents interviews to one viewer with the scorecards they may see.
 *
 * Feedback is blind: someone who is on the panel but has no management rights sees only their own
 * scorecard until they have submitted it, then everyone's. Managing the interview, or holding the
 * hire permission over its application, shows all of them. Anyone else sees none.
 */
final class InterviewViews
{
    public function __construct(private readonly TalentSupport $support) {}

    /**
     * @param  iterable<Interview>  $interviews  with `panel` and `scorecards` loaded
     * @param  array<string, EmployeeSnapshot>  $people
     * @return list<array<string, mixed>>
     */
    public function present(iterable $interviews, User $actor, array $people): array
    {
        $own = $this->support->employeeId($actor);
        $timezone = $this->support->timezone();
        $rows = [];

        foreach ($interviews as $interview) {
            $onPanel = $own !== null && $interview->panel->contains('employee_id', $own);
            $manage = $this->support->allows($actor, TalentPermissions::INTERVIEWS_MANAGE, $interview);
            $sawAll = $manage || $this->support->allows($actor, TalentPermissions::APPLICATIONS_HIRE, $interview);
            $mine = $own === null ? null : $interview->scorecards->firstWhere('employee_id', $own);

            $visible = match (true) {
                $sawAll => $interview->scorecards,
                $onPanel && $mine !== null => $interview->scorecards,
                $onPanel => $interview->scorecards->where('employee_id', $own),
                default => $interview->scorecards->whereNull('id'),
            };

            $scheduled = $interview->status === InterviewStatus::Scheduled;

            $rows[] = TalentPresenter::interview($interview, $people, $timezone) + [
                'scorecards' => $visible->map(static fn (Scorecard $card): array => [
                    'employee' => TalentPresenter::person($card->employee_id, $people),
                    'rating' => $card->rating,
                    'recommendation' => $card->recommendation->value,
                    'strengths' => $card->strengths,
                    'concerns' => $card->concerns,
                    'submittedAt' => $card->submitted_at->toIso8601String(),
                ])->values()->all(),
                'scorecardCount' => $interview->scorecards->count(),
                'abilities' => [
                    'edit' => $manage && $scheduled,
                    'cancel' => $manage && $scheduled,
                    'complete' => $manage && $scheduled && $interview->starts_at->isPast(),
                    'feedback' => $onPanel && $this->support->can($actor, TalentPermissions::INTERVIEWS_FEEDBACK)
                        && in_array($interview->status, [InterviewStatus::Scheduled, InterviewStatus::Completed], true)
                        && $interview->starts_at->isPast(),
                ],
            ];
        }

        return $rows;
    }
}
