<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Talent\Actions\InterviewManager;
use App\Modules\Talent\Enums\InterviewMode;
use App\Modules\Talent\Enums\InterviewOutcome;
use App\Modules\Talent\Enums\InterviewStatus;
use App\Modules\Talent\Http\Requests\InterviewOutcomeRequest;
use App\Modules\Talent\Http\Requests\InterviewRequest;
use App\Modules\Talent\Http\Requests\NoteOnlyRequest;
use App\Modules\Talent\Http\Requests\ScorecardRequest;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\InterviewPanelist;
use App\Modules\Talent\Support\TalentPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interview scheduling and panel feedback. A panelist reaches an interview through the panel; others
 * through the `talent.interviews.view` scope (hiring manager = owner).
 */
final class InterviewController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly InterviewManager $interviews,
        private readonly InterviewViews $views,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:scheduled,completed,cancelled,no_show,all'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'mine' => ['nullable', 'boolean'],
        ]);
        $status = $filters['status'] ?? 'scheduled';
        $own = $this->support->employeeId($actor);
        $panelIds = $own === null ? [] : InterviewPanelist::query()->where('employee_id', $own)->select('interview_id');

        $query = Interview::query()->with(['panel', 'scorecards', 'application.candidate', 'application.opening'])
            ->where(function ($match) use ($actor, $panelIds, $own): void {
                $scoped = $this->support->scoped(Interview::query(), $actor, TalentPermissions::INTERVIEWS_VIEW)->select('id');
                $match->whereIn('id', $scoped);

                if ($own !== null) {
                    $match->orWhereIn('id', $panelIds);
                }
            });

        if ($filters['mine'] ?? false) {
            $query->whereIn('id', $own === null ? [] : $panelIds);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $from = $this->support->date($request, 'from');
        $to = $this->support->date($request, 'to');

        if ($from !== null) {
            $query->where('starts_at', '>=', $this->localStart($from));
        }

        if ($to !== null) {
            $query->where('starts_at', '<', $this->localStart($to)->addDay());
        }

        $page = $query->orderBy('starts_at')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->flatMap(static fn (Interview $interview): array => [
            ...$interview->panel->pluck('employee_id')->all(),
            ...$interview->scorecards->pluck('employee_id')->all(),
        ])->all());
        $rows = $this->views->present($page->getCollection(), $actor, $people);
        $applications = $page->getCollection()->keyBy('id');

        $page->setCollection(collect($rows)->map(function (array $row) use ($applications): array {
            /** @var Interview $interview */
            $interview = $applications[$row['id']];
            $candidate = $interview->application->candidate;

            return $row + [
                'candidate' => ['id' => $candidate->id, 'name' => $candidate->isAnonymized() ? null : $candidate->fullName()],
                'opening' => ['id' => $interview->application->opening->id, 'title' => $interview->application->opening->title],
            ];
        }));

        return Inertia::render('Modules/Talent/Pages/Interviews/Index', [
            'interviews' => $page,
            'filters' => ['status' => $status, 'from' => $from ?? '', 'to' => $to ?? '', 'mine' => (bool) ($filters['mine'] ?? false)],
            'statuses' => InterviewStatus::values(),
            'timezone' => $this->support->timezone(),
            'today' => $this->support->today()->toDateString(),
            'hasPanelProfile' => $own !== null,
        ]);
    }

    public function show(Request $request, Interview $interview): Response
    {
        $actor = $this->support->user($request);
        $this->authorizeView($actor, $interview);
        $interview->load(['panel', 'scorecards', 'application.candidate', 'application.opening']);
        $people = $this->support->people([
            ...$interview->panel->pluck('employee_id')->all(),
            ...$interview->scorecards->pluck('employee_id')->all(),
        ]);
        $candidate = $interview->application->candidate;

        return Inertia::render('Modules/Talent/Pages/Interviews/Show', [
            'interview' => $this->views->present([$interview], $actor, $people)[0],
            'application' => ['id' => $interview->application->id, 'status' => $interview->application->status->value],
            'candidate' => ['id' => $candidate->id, 'name' => $candidate->isAnonymized() ? null : $candidate->fullName()],
            'opening' => ['id' => $interview->application->opening->id, 'title' => $interview->application->opening->title],
            'modes' => InterviewMode::values(),
            'outcomes' => InterviewOutcome::values(),
            'employees' => $this->support->can($actor, TalentPermissions::INTERVIEWS_MANAGE) ? $this->support->employeeOptions($actor, TalentPermissions::INTERVIEWS_MANAGE) : [],
            'timezone' => $this->support->timezone(),
        ]);
    }

    public function store(InterviewRequest $request, Application $application): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::INTERVIEWS_MANAGE, $application);
        $this->support->authorize($actor, TalentPermissions::APPLICATIONS_VIEW, $application);
        /** @var array{round_name: string, mode: string, date: string, time: string, duration_minutes: int, location?: string|null, notes?: string|null, panel: list<string>} $data */
        $data = $request->validated();
        $this->support->assertEmployeesInScope($actor, TalentPermissions::INTERVIEWS_MANAGE, $data['panel'], 'panel');
        $interview = $this->interviews->schedule($application, $data, $this->support->membership($actor));
        Toast::success(__('Interview scheduled.'));

        return to_route('talent.interviews.show', $interview);
    }

    public function update(InterviewRequest $request, Interview $interview): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::INTERVIEWS_MANAGE, $interview);
        /** @var array{round_name: string, mode: string, date: string, time: string, duration_minutes: int, location?: string|null, notes?: string|null, panel: list<string>} $data */
        $data = $request->validated();
        $this->support->assertEmployeesInScope($actor, TalentPermissions::INTERVIEWS_MANAGE, $data['panel'], 'panel');
        $this->interviews->reschedule($interview, $data);
        Toast::success(__('Interview updated.'));

        return back();
    }

    public function cancel(NoteOnlyRequest $request, Interview $interview): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::INTERVIEWS_MANAGE, $interview);
        $this->interviews->cancel($interview, $request->validated('note'));
        Toast::success(__('Interview cancelled.'));

        return back();
    }

    public function complete(InterviewOutcomeRequest $request, Interview $interview): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::INTERVIEWS_MANAGE, $interview);
        $this->interviews->complete($interview, InterviewOutcome::from($request->validated('outcome')), $request->validated('notes'));
        Toast::success(__('Interview completed.'));

        return back();
    }

    public function noShow(NoteOnlyRequest $request, Interview $interview): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::INTERVIEWS_MANAGE, $interview);
        $this->interviews->markNoShow($interview, $request->validated('note'));
        Toast::success(__('Interview marked as no-show.'));

        return back();
    }

    public function scorecard(ScorecardRequest $request, Interview $interview): RedirectResponse
    {
        $actor = $this->support->user($request);
        $employeeId = $this->support->employeeId($actor);
        // Only a panelist can score; anyone else does not learn the interview exists.
        abort_if($employeeId === null || InterviewPanelist::query()->where('interview_id', $interview->id)->where('employee_id', $employeeId)->doesntExist(), 404);
        /** @var array{rating: int, recommendation: string, strengths?: string|null, concerns?: string|null} $data */
        $data = $request->validated();
        $this->interviews->submitScorecard($interview, $employeeId, $data);
        Toast::success(__('Scorecard submitted.'));

        return back();
    }

    private function authorizeView(User $actor, Interview $interview): void
    {
        $own = $this->support->employeeId($actor);
        $onPanel = $own !== null && InterviewPanelist::query()->where('interview_id', $interview->id)->where('employee_id', $own)->exists();

        abort_unless($onPanel || $this->support->allows($actor, TalentPermissions::INTERVIEWS_VIEW, $interview), 404);
    }

    private function localStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 00:00:00', $this->support->timezone())->setTimezone('UTC');
    }
}
