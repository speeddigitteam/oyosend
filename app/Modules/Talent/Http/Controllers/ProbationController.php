<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ProbationManager;
use App\Modules\Talent\Actions\TalentSettings;
use App\Modules\Talent\Enums\ProbationDecision;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\ProbationDecisionRequest;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Probation tracking. The decision recorded here is a recommendation announced as an event: the
 * employee's status and probation dates are changed in HRM.
 */
final class ProbationController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly ProbationManager $probations,
        private readonly TalentSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate(['status' => ['nullable', 'string', 'in:in_progress,confirmed,ended,all']]);
        $status = $filters['status'] ?? 'in_progress';

        $query = $this->support->scoped(Probation::query(), $actor, TalentPermissions::PROBATION_VIEW);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->orderBy('ends_on')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->pluck('employee_id')->all());
        $today = $this->settings->today()->toDateString();
        $page->through(fn (Probation $probation): array => TalentPresenter::probation($probation, $people) + [
            'daysLeft' => $this->settings->daysUntil($probation->ends_on->toDateString()),
            'isOverdue' => $probation->status === ProbationStatus::InProgress && $probation->ends_on->toDateString() < $today,
        ]);

        return Inertia::render('Modules/Talent/Pages/Probations/Index', [
            'probations' => $page,
            'filters' => ['status' => $status],
            'today' => $today,
            'reminderDays' => $this->settings->current()->probation_reminder_days,
        ]);
    }

    public function show(Request $request, Probation $probation): Response
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::PROBATION_VIEW, $probation);
        $probation->load('reviews');
        $people = $this->support->people([$probation->employee_id]);

        return Inertia::render('Modules/Talent/Pages/Probations/Show', [
            'probation' => TalentPresenter::probation($probation, $people, true) + [
                'daysLeft' => $this->settings->daysUntil($probation->ends_on->toDateString()),
            ],
            'decisions' => ProbationDecision::values(),
            'abilities' => [
                'decide' => $probation->status === ProbationStatus::InProgress
                    && $this->support->allows($actor, TalentPermissions::PROBATION_MANAGE, $probation)
                    && ! $this->support->isOwnRecordBlocked($actor, TalentPermissions::PROBATION_MANAGE, $probation->employee_id),
            ],
        ]);
    }

    public function decide(ProbationDecisionRequest $request, Probation $probation): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::PROBATION_MANAGE, $probation);
        abort_if($this->support->isOwnRecordBlocked($actor, TalentPermissions::PROBATION_MANAGE, $probation->employee_id), 403);
        /** @var array{decision: string, note?: string|null, extend_until?: string|null} $data */
        $data = $request->validated();
        $this->probations->recordDecision($probation, $data, $this->support->membership($actor));
        Toast::success(__('Probation decision recorded.'));

        return back();
    }
}
