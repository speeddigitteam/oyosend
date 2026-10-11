<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Actions\ExactMoney;
use App\Modules\Projects\Actions\TimeExpenseManager;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController
{
    private const int ROWS_PER_PAGE = 50;

    private const int TASK_OPTIONS_PER_PAGE = 50;

    public function __construct(private readonly RecordAccess $records, private readonly ProjectControllerSupport $support, private readonly TimeExpenseManager $finance, private readonly TenantContext $context) {}

    public function time(Request $request): Response
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $entryQuery = $this->records->constrain(TimeEntry::query(), $actor, 'projects.time.view');
        $summary = [
            'totalMinutes' => (int) (clone $entryQuery)->sum('minutes'),
            'billableMinutes' => (int) (clone $entryQuery)->where('is_billable', true)->sum('minutes'),
            'pendingMinutes' => (int) (clone $entryQuery)->whereIn('status', [EntryStatus::Draft, EntryStatus::Submitted])->sum('minutes'),
        ];
        $paginator = (clone $entryQuery)->with(['project:id,name', 'task:id,title', 'membership.user:id,name'])
            ->orderByDesc('worked_on')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::ROWS_PER_PAGE)->withQueryString();
        $entries = $paginator->getCollection();
        $pending = Approval::query()->where('entity_type', ApprovalEntity::TimeEntry)->where('status', ApprovalStatus::Pending)->whereIn('entity_id', $entries->pluck('id'))->pluck('entity_id')->flip();
        $projects = $this->records->constrain(Project::query(), $actor, ProjectController::VIEW)->orderBy('name')->get(['id', 'name']);
        $approvalMembers = $actor->can('projects.approvals.request') ? $this->support->approvalMemberOptions() : [];
        $canRequestApprovals = $actor->can('projects.approvals.request')
            && collect($approvalMembers)->contains(fn (array $member): bool => $member['id'] !== $membership->id);

        return Inertia::render('Modules/Projects/Pages/Time/Index', [
            'entries' => $entries->map(fn (TimeEntry $entry): array => ['id' => $entry->id, 'project' => ['id' => $entry->project->id, 'name' => $entry->project->name], 'task' => $entry->task === null ? null : ['id' => $entry->task->id, 'name' => $entry->task->title], 'member' => ['id' => $entry->membership_id, 'name' => $entry->membership->user->name], 'date' => $entry->worked_on->toDateString(), 'minutes' => $entry->minutes, 'billable' => $entry->is_billable, 'note' => $entry->description, 'status' => $entry->status->value, 'submittable' => in_array($entry->status, [EntryStatus::Draft, EntryStatus::Rejected], true) && $this->records->allows($actor, 'projects.time.log', $entry), 'approvable' => ! $pending->has($entry->id) && $this->records->allows($actor, 'projects.time.approve', $entry), 'approvalPending' => $pending->has($entry->id), 'approvalRequestable' => $canRequestApprovals && $entry->status === EntryStatus::Submitted && ! $pending->has($entry->id)])->all(),
            'entriesPagination' => $this->pagination($paginator),
            'projects' => $projects,
            'summary' => $summary,
            'canLog' => $actor->can('projects.time.log'),
            'approvalMembers' => $approvalMembers,
            'currentMembershipId' => $membership->id,
        ]);
    }

    public function taskOptions(Request $request): JsonResponse
    {
        $actor = $this->support->actor($request);
        abort_unless($actor->can('projects.time.log') || $actor->can('projects.expenses.manage'), 403);
        $filters = $request->validate([
            'project_id' => ['required', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $project = $this->records->constrain(Project::query(), $actor, ProjectController::VIEW)
            ->findOrFail($filters['project_id']);
        $search = trim((string) ($filters['search'] ?? ''));
        $paginator = $this->records->constrain(Task::query()->where('project_id', $project->id), $actor, TaskController::VIEW)
            ->when($search !== '', fn ($query) => $query->where('title', 'like', Like::contains($search)))
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(self::TASK_OPTIONS_PER_PAGE)
            ->withQueryString();

        return response()->json([
            'tasks' => $paginator->getCollection()
                ->map(fn (Task $task): array => ['id' => $task->id, 'name' => $task->title, 'projectId' => $task->project_id])
                ->all(),
            'pagination' => $this->pagination($paginator),
            'filters' => ['projectId' => $project->id, 'search' => $search],
        ]);
    }

    public function storeTime(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['project_id' => ['required', 'string', 'size:26'], 'task_id' => ['nullable', 'string', 'size:26'], 'worked_on' => ['nullable', 'date_format:Y-m-d'], 'date' => ['nullable', 'date_format:Y-m-d'], 'started_at' => ['nullable', 'date'], 'ended_at' => ['nullable', 'date', 'after:started_at'], 'minutes' => ['required', 'integer', 'between:1,10080'], 'description' => ['nullable', 'string', 'max:5000'], 'note' => ['nullable', 'string', 'max:5000'], 'is_billable' => ['nullable', 'boolean'], 'billable' => ['nullable', 'boolean'], 'billable_rate_amount' => ['nullable', 'integer', 'min:0', 'max:'.ExactMoney::MAX_AMOUNT], 'cost_rate_amount' => ['nullable', 'integer', 'min:0', 'max:'.ExactMoney::MAX_AMOUNT]]);
        $project = $this->records->constrain(Project::query(), $actor, ProjectController::VIEW)->findOrFail($input['project_id']);

        if (array_key_exists('billable_rate_amount', $input) || array_key_exists('cost_rate_amount', $input)) {
            $this->records->authorize($actor, ProjectController::FINANCIALS, $project);
        }

        if (($input['task_id'] ?? null) !== null) {
            $input['task_id'] = $this->records->constrain(Task::query()->where('project_id', $project->id), $actor, TaskController::VIEW)->findOrFail($input['task_id'])->id;
        }

        $input['worked_on'] = $input['worked_on'] ?? $input['date'] ?? CarbonImmutable::now($this->context->require()->timezone)->toDateString();
        $input['description'] = $input['description'] ?? $input['note'] ?? null;
        $input['is_billable'] = (bool) ($input['is_billable'] ?? $input['billable'] ?? false);
        unset($input['date'], $input['note'], $input['billable']);

        foreach (['started_at', 'ended_at'] as $field) {
            if (($input[$field] ?? null) !== null) {
                $input[$field] = CarbonImmutable::parse((string) $input[$field], $this->context->require()->timezone)->utc();
            }
        }

        if (($input['started_at'] ?? null) instanceof CarbonImmutable && ($input['ended_at'] ?? null) instanceof CarbonImmutable && $input['ended_at']->lessThanOrEqualTo($input['started_at'])) {
            throw ValidationException::withMessages(['ended_at' => __('The end time must be after the start time.')]);
        }
        $this->finance->logTime($project, $this->support->membership($actor), $input, $this->support->membership($actor));
        Toast::success(__('Time entry saved.'));

        return back();
    }

    public function submitTime(Request $request, TimeEntry $timeEntry): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, 'projects.time.log', $timeEntry);
        $this->finance->submitTime($timeEntry, $this->support->membership($actor));

        return back();
    }

    public function approveTime(Request $request, TimeEntry $timeEntry): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, 'projects.time.approve', $timeEntry);
        $input = $request->validate(['approve' => ['required', 'boolean']]);
        $this->finance->decideTime($timeEntry, $input['approve'], $this->support->membership($actor));

        return back();
    }

    public function expenses(Request $request): Response
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $expenseQuery = $this->records->constrain(Expense::query(), $actor, 'projects.expenses.view');
        $totalAmount = ExactMoney::sumString([(string) (clone $expenseQuery)->sum('amount')]);
        $pendingAmount = ExactMoney::sumString([(string) (clone $expenseQuery)->where('status', EntryStatus::Submitted)->sum('amount')]);
        $paginator = (clone $expenseQuery)->with(['project:id,name', 'task:id,title', 'submitter.user:id,name'])
            ->orderByDesc('incurred_on')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::ROWS_PER_PAGE)->withQueryString();
        $expenses = $paginator->getCollection();
        $pending = Approval::query()->where('entity_type', ApprovalEntity::Expense)->where('status', ApprovalStatus::Pending)->whereIn('entity_id', $expenses->pluck('id'))->pluck('entity_id')->flip();
        $projects = $this->records->constrain(Project::query(), $actor, ProjectController::VIEW)->orderBy('name')->get(['id', 'name']);
        $approvalMembers = $actor->can('projects.approvals.request') ? $this->support->approvalMemberOptions() : [];
        $canRequestApprovals = $actor->can('projects.approvals.request')
            && collect($approvalMembers)->contains(fn (array $member): bool => $member['id'] !== $membership->id);

        return Inertia::render('Modules/Projects/Pages/Expenses/Index', [
            'expenses' => $expenses->map(fn (Expense $expense): array => ['id' => $expense->id, 'project' => ['id' => $expense->project->id, 'name' => $expense->project->name], 'task' => $expense->task === null ? null : ['id' => $expense->task->id, 'name' => $expense->task->title], 'member' => ['id' => $expense->submitted_by, 'name' => $expense->submitter->user->name], 'date' => $expense->incurred_on->toDateString(), 'amount' => $expense->amount, 'currency' => $expense->currency, 'description' => $expense->description, 'billable' => $expense->is_billable, 'status' => $expense->status->value, 'submittable' => in_array($expense->status, [EntryStatus::Draft, EntryStatus::Rejected], true) && $this->records->allows($actor, 'projects.expenses.manage', $expense), 'approvable' => ! $pending->has($expense->id) && $this->records->allows($actor, 'projects.expenses.approve', $expense), 'approvalPending' => $pending->has($expense->id), 'approvalRequestable' => $canRequestApprovals && $expense->status === EntryStatus::Submitted && ! $pending->has($expense->id)])->all(),
            'expensesPagination' => $this->pagination($paginator),
            'projects' => $projects,
            'summary' => ['currency' => $this->context->require()->currency, 'totalAmount' => (string) $totalAmount, 'pendingAmount' => (string) $pendingAmount],
            'canCreate' => $actor->can('projects.expenses.manage'),
            'approvalMembers' => $approvalMembers,
            'currentMembershipId' => $membership->id,
        ]);
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['project_id' => ['required', 'string', 'size:26'], 'task_id' => ['nullable', 'string', 'size:26'], 'description' => ['required', 'string', 'max:500'], 'incurred_on' => ['nullable', 'date_format:Y-m-d'], 'date' => ['nullable', 'date_format:Y-m-d'], 'amount' => ['required', 'integer', 'min:0', 'max:'.ExactMoney::MAX_AMOUNT], 'is_billable' => ['nullable', 'boolean'], 'billable' => ['nullable', 'boolean']]);
        $project = $this->records->constrain(Project::query(), $actor, ProjectController::VIEW)->findOrFail($input['project_id']);

        if (($input['task_id'] ?? null) !== null) {
            $input['task_id'] = $this->records->constrain(Task::query()->where('project_id', $project->id), $actor, TaskController::VIEW)->findOrFail($input['task_id'])->id;
        }

        $input['incurred_on'] = $input['incurred_on'] ?? $input['date'] ?? CarbonImmutable::now($this->context->require()->timezone)->toDateString();
        $input['is_billable'] = (bool) ($input['is_billable'] ?? $input['billable'] ?? false);
        unset($input['date'], $input['billable']);
        $this->finance->createExpense($project, $this->support->membership($actor), $input);
        Toast::success(__('Expense saved.'));

        return back();
    }

    public function submitExpense(Request $request, Expense $expense): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, 'projects.expenses.manage', $expense);
        $this->finance->submitExpense($expense, $this->support->membership($actor));

        return back();
    }

    public function approveExpense(Request $request, Expense $expense): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, 'projects.expenses.approve', $expense);
        $input = $request->validate(['approve' => ['required', 'boolean']]);
        $this->finance->decideExpense($expense, $input['approve'], $this->support->membership($actor));

        return back();
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array{currentPage: int, perPage: int, total: int, lastPage: int, hasMorePages: bool, previousPageUrl: string|null, nextPageUrl: string|null}
     */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
            'hasMorePages' => $paginator->hasMorePages(),
            'previousPageUrl' => $paginator->previousPageUrl(),
            'nextPageUrl' => $paginator->nextPageUrl(),
        ];
    }
}
