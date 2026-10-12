<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Talent\Actions\EmployeeLookup;
use App\Modules\Talent\Actions\GoalManager;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\GoalKind;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\GoalProgressRequest;
use App\Modules\Talent\Http\Requests\GoalRequest;
use App\Modules\Talent\Http\Requests\GoalStatusRequest;
use App\Modules\Talent\Models\Goal;
use App\Modules\Talent\Models\GoalUpdate;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Goals and KPIs. Employees manage their own through the `own` scope of `talent.goals.manage`. */
final class GoalController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly GoalManager $goals,
        private readonly EmployeeLookup $employees,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'employee_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'in:active,achieved,missed,cancelled,all'],
            'cycle_id' => ['nullable', 'string', 'size:26'],
            'kind' => ['nullable', 'string', 'in:goal,kpi,all'],
        ]);
        $status = $filters['status'] ?? 'active';
        $kind = $filters['kind'] ?? 'all';

        $query = $this->support->scoped(Goal::query(), $actor, TalentPermissions::GOALS_VIEW);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($kind !== 'all') {
            $query->where('kind', $kind);
        }

        foreach (['employee_id', 'cycle_id'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->where($column, $filters[$column]);
            }
        }

        $page = $query->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('created_at')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->pluck('employee_id')->all());
        $page->through(static fn (Goal $goal): array => TalentPresenter::goal($goal, $people));

        $own = $this->support->employee($actor);

        return Inertia::render('Modules/Talent/Pages/Goals/Index', [
            'goals' => $page,
            'filters' => ['employee_id' => $filters['employee_id'] ?? '', 'status' => $status, 'cycle_id' => $filters['cycle_id'] ?? '', 'kind' => $kind],
            'form' => [
                'kinds' => GoalKind::values(),
                'employees' => $this->support->can($actor, TalentPermissions::GOALS_MANAGE) ? $this->support->employeeOptions($actor, TalentPermissions::GOALS_MANAGE) : [],
                'cycles' => ReviewCycle::query()->where('status', '!=', CycleStatus::Closed)->orderByDesc('starts_on')->get(['id', 'name'])
                    ->map(static fn (ReviewCycle $cycle): array => ['id' => $cycle->id, 'name' => $cycle->name])->all(),
                'ownEmployeeId' => $own?->id,
            ],
            'canCreate' => $this->support->can($actor, TalentPermissions::GOALS_MANAGE),
        ]);
    }

    public function show(Request $request, Goal $goal): Response
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::GOALS_VIEW, $goal);
        $people = $this->support->people([$goal->employee_id]);
        $manage = $goal->status === GoalStatus::Active && $this->support->allows($actor, TalentPermissions::GOALS_MANAGE, $goal);

        return Inertia::render('Modules/Talent/Pages/Goals/Show', [
            'goal' => TalentPresenter::goal($goal, $people),
            'updates' => GoalUpdate::query()->where('goal_id', $goal->id)->orderByDesc('created_at')->limit(100)->get()
                ->map(static fn (GoalUpdate $update): array => [
                    'id' => $update->id, 'value' => $update->value, 'note' => $update->note,
                    'recordedOn' => $update->recorded_on->toDateString(), 'recordedAt' => $update->created_at?->toIso8601String(),
                ])->all(),
            'abilities' => ['edit' => $manage, 'recordProgress' => $manage, 'close' => $manage],
        ]);
    }

    public function store(GoalRequest $request): RedirectResponse
    {
        $actor = $this->support->user($request);
        $data = $request->validated();
        $employeeId = $data['employee_id'] ?? $this->support->employeeId($actor);

        if ($employeeId === null) {
            abort(422, __('You have no employee record to attach a goal to.'));
        }

        $employee = $this->employees->require($employeeId, 'employee_id');
        $probe = new Goal;
        $probe->placeForEmployee($employee);
        $this->support->authorize($actor, TalentPermissions::GOALS_MANAGE, $probe);
        $goal = $this->goals->create($employee, $data, $this->support->membership($actor));
        Toast::success(__('Goal created.'));

        return to_route('talent.goals.show', $goal);
    }

    public function update(GoalRequest $request, Goal $goal): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::GOALS_MANAGE, $goal);
        /** @var array{title: string, description?: string|null, unit?: string|null, target_value: int|string, due_on?: string|null} $data */
        $data = $request->validated();
        $this->goals->update($goal, $data);
        Toast::success(__('Goal saved.'));

        return back();
    }

    public function progress(GoalProgressRequest $request, Goal $goal): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::GOALS_MANAGE, $goal);
        $this->goals->recordProgress($goal, (int) $request->validated('value'), $request->validated('note'), $this->support->membership($actor));
        Toast::success(__('Progress recorded.'));

        return back();
    }

    public function status(GoalStatusRequest $request, Goal $goal): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::GOALS_MANAGE, $goal);
        $this->goals->setStatus($goal, GoalStatus::from($request->validated('status')));
        Toast::success(__('Goal updated.'));

        return back();
    }
}
