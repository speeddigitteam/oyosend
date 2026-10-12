<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ChecklistManager;
use App\Modules\Talent\Actions\EmployeeLookup;
use App\Modules\Talent\Enums\AssigneeRole;
use App\Modules\Talent\Enums\ChecklistKind;
use App\Modules\Talent\Enums\ChecklistStatus;
use App\Modules\Talent\Enums\TaskStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\ChecklistRequest;
use App\Modules\Talent\Http\Requests\NoteOnlyRequest;
use App\Modules\Talent\Http\Requests\TaskRequest;
use App\Modules\Talent\Models\Checklist;
use App\Modules\Talent\Models\ChecklistTask;
use App\Modules\Talent\Models\ChecklistTemplate;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Onboarding and offboarding checklists and their tasks. */
final class ChecklistController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly ChecklistManager $checklists,
        private readonly EmployeeLookup $employees,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'kind' => ['nullable', 'string', 'in:onboarding,offboarding,all'],
            'status' => ['nullable', 'string', 'in:open,completed,cancelled,all'],
        ]);
        $kind = $filters['kind'] ?? 'all';
        $status = $filters['status'] ?? 'open';

        $query = $this->support->scoped(Checklist::query(), $actor, TalentPermissions::ONBOARDING_VIEW)->with('tasks');

        if ($kind !== 'all') {
            $query->where('kind', $kind);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->pluck('employee_id')->all());
        $page->through(static fn (Checklist $checklist): array => TalentPresenter::checklist($checklist, $people));

        return Inertia::render('Modules/Talent/Pages/Checklists/Index', [
            'checklists' => $page,
            'filters' => ['kind' => $kind, 'status' => $status],
            'templates' => ChecklistTemplate::query()->where('is_active', true)->orderBy('kind')->orderBy('name')->get(['id', 'name', 'kind', 'is_default'])
                ->map(static fn (ChecklistTemplate $template): array => ['id' => $template->id, 'name' => $template->name, 'kind' => $template->kind->value, 'isDefault' => $template->is_default])->all(),
            'employees' => $this->support->can($actor, TalentPermissions::ONBOARDING_MANAGE) ? $this->support->employeeOptions($actor, TalentPermissions::ONBOARDING_MANAGE) : [],
            'kinds' => ChecklistKind::values(),
            'canStart' => $this->support->can($actor, TalentPermissions::ONBOARDING_MANAGE),
        ]);
    }

    /** The signed-in person's own open tasks (they are the assignee). */
    public function tasks(Request $request): Response
    {
        $actor = $this->support->user($request);
        $own = $this->support->employeeId($actor);
        $page = $own === null
            ? ChecklistTask::query()->whereRaw('1 = 0')->paginate(25)
            : ChecklistTask::query()->with('checklist')->where('assignee_employee_id', $own)->where('status', TaskStatus::Pending)
                ->orderByRaw('due_on is null')->orderBy('due_on')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->map(static fn (ChecklistTask $task): string => $task->checklist->employee_id)->all());
        $page->through(static fn (ChecklistTask $task): array => TalentPresenter::checklistTask($task, [], [
            'checklistName' => $task->checklist->name,
            'checklistKind' => $task->checklist->kind->value,
            'subject' => TalentPresenter::person($task->checklist->employee_id, $people),
            'canComplete' => true,
        ]));

        return Inertia::render('Modules/Talent/Pages/Checklists/Tasks', [
            'tasks' => $page,
            'today' => $this->support->today()->toDateString(),
            'hasProfile' => $own !== null,
        ]);
    }

    public function show(Request $request, Checklist $checklist): Response
    {
        $actor = $this->support->user($request);
        $own = $this->support->employeeId($actor);
        $checklist->load('tasks');
        $assigned = $own !== null && $checklist->tasks->contains('assignee_employee_id', $own);

        abort_unless($assigned || $this->support->allows($actor, TalentPermissions::ONBOARDING_VIEW, $checklist), 404);

        $people = $this->support->people([$checklist->employee_id, ...$checklist->tasks->pluck('assignee_employee_id')->all()]);
        $manage = $this->support->allows($actor, TalentPermissions::ONBOARDING_MANAGE, $checklist);
        $open = $checklist->status !== ChecklistStatus::Cancelled;

        return Inertia::render('Modules/Talent/Pages/Checklists/Show', [
            'checklist' => TalentPresenter::checklist($checklist, $people, true),
            'tasks' => $checklist->tasks->map(fn (ChecklistTask $task): array => TalentPresenter::checklistTask($task, $people, [
                'canComplete' => $open && $this->mayComplete($actor, $task, $checklist, $own),
                'canSkip' => $open && $manage,
            ]))->values()->all(),
            'abilities' => ['manage' => $manage && $open, 'cancel' => $manage && $checklist->status === ChecklistStatus::Open, 'addTask' => $manage && $open],
            'employees' => $manage ? $this->support->employeeOptions($actor, TalentPermissions::ONBOARDING_MANAGE) : [],
            'assigneeRoles' => AssigneeRole::values(),
        ]);
    }

    public function store(ChecklistRequest $request): RedirectResponse
    {
        $actor = $this->support->user($request);
        $data = $request->validated();
        $employee = $this->employees->require($data['employee_id'], 'employee_id', allowTerminated: true);
        $kind = ChecklistKind::from($data['kind']);
        $templateId = $data['template_id'] ?? null;
        $template = $templateId === null ? null : ChecklistTemplate::query()->findOrFail($templateId);

        // Scope: the person may start checklists for employees inside their onboarding scope.
        $probe = new Checklist;
        $probe->placeForEmployee($employee);
        $this->support->authorize($actor, TalentPermissions::ONBOARDING_MANAGE, $probe);

        $checklist = $this->checklists->start($employee, $kind, $template, 'manual:'.Str::ulid(), $data['anchor_date'] ?? null, $this->support->membership($actor));

        if ($checklist === null) {
            throw ValidationException::withMessages(['template_id' => __('There is no active template for this kind of checklist.')]);
        }

        Toast::success(__('Checklist started.'));

        return to_route('talent.checklists.show', $checklist);
    }

    public function cancel(Request $request, Checklist $checklist): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::ONBOARDING_MANAGE, $checklist);
        $this->checklists->cancel($checklist, $this->support->membership($actor));
        Toast::success(__('Checklist cancelled.'));

        return back();
    }

    public function storeTask(TaskRequest $request, Checklist $checklist): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::ONBOARDING_MANAGE, $checklist);
        /** @var array{title: string, description?: string|null, assignee_role?: string, assignee_employee_id?: string|null, due_on?: string|null} $data */
        $data = $request->validated();

        if (($data['assignee_employee_id'] ?? null) !== null) {
            $this->support->assertEmployeesInScope($actor, TalentPermissions::ONBOARDING_MANAGE, [$data['assignee_employee_id']], 'assignee_employee_id');
        }

        $this->checklists->addTask($checklist, $data);
        Toast::success(__('Task added.'));

        return back();
    }

    public function completeTask(NoteOnlyRequest $request, ChecklistTask $task): RedirectResponse
    {
        $actor = $this->support->user($request);
        $checklist = Checklist::query()->findOrFail($task->checklist_id);
        abort_unless($this->mayComplete($actor, $task, $checklist, $this->support->employeeId($actor)), 404);
        $this->checklists->completeTask($task, $this->support->membership($actor), $request->validated('note'));
        Toast::success(__('Task completed.'));

        return back();
    }

    public function reopenTask(Request $request, ChecklistTask $task): RedirectResponse
    {
        $actor = $this->support->user($request);
        $checklist = Checklist::query()->findOrFail($task->checklist_id);
        abort_unless($this->mayComplete($actor, $task, $checklist, $this->support->employeeId($actor)), 404);
        $this->checklists->reopenTask($task, $this->support->membership($actor));
        Toast::success(__('Task reopened.'));

        return back();
    }

    public function skipTask(NoteOnlyRequest $request, ChecklistTask $task): RedirectResponse
    {
        $actor = $this->support->user($request);
        $checklist = Checklist::query()->findOrFail($task->checklist_id);
        $this->support->authorize($actor, TalentPermissions::ONBOARDING_MANAGE, $checklist);
        $this->checklists->skipTask($task, $this->support->membership($actor), $request->validated('note'));
        Toast::success(__('Task skipped.'));

        return back();
    }

    /** The assignee (unless a manager skipped the task), or someone who manages the checklist in their scope. */
    private function mayComplete(User $actor, ChecklistTask $task, Checklist $checklist, ?string $ownEmployeeId): bool
    {
        // A task a manager skipped is theirs to change: its assignee can neither complete nor reopen it.
        if ($ownEmployeeId !== null && $task->assignee_employee_id === $ownEmployeeId && $task->status !== TaskStatus::Skipped
            && $this->support->can($actor, TalentPermissions::TASKS_COMPLETE)) {
            return true;
        }

        return $this->support->allows($actor, TalentPermissions::ONBOARDING_MANAGE, $checklist);
    }
}
