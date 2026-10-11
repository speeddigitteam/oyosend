<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Actions\ExactMoney;
use App\Modules\Projects\Actions\PortfolioAnalytics;
use App\Modules\Projects\Actions\ProjectManager;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\ActivityHistory;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Attachment;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectTemplate;
use App\Modules\Projects\Models\Sprint;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ProjectController
{
    public const string VIEW = 'projects.projects.view';

    public const string EDIT = 'projects.projects.edit';

    public const string MEMBERS = 'projects.projects.manage_members';

    public const string FINANCIALS = 'projects.projects.manage_financials';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly ProjectControllerSupport $support,
        private readonly ProjectManager $projects,
        private readonly PortfolioAnalytics $analytics,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(ProjectStatus::class)], 'category' => ['nullable', 'string', 'size:26']]);
        $search = trim((string) ($filters['search'] ?? ''));
        $visibleTaskIds = $this->records->constrain(Task::query(), $actor, TaskController::VIEW)->select('id');
        $projects = $this->records->constrain(Project::query(), $actor, self::VIEW)
            ->with(['category:id,name,colour', 'contact:id,first_name,last_name', 'company:id,name', 'owner.user:id,name'])
            ->withCount(['tasks as open_tasks_count' => fn ($query) => $query->whereIn('project_tasks.id', clone $visibleTaskIds)->whereNotIn('status', ['completed', 'cancelled'])])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', Like::contains($search)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category_id', $category))
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('created_at')->paginate(25)->withQueryString()
            ->through(fn (Project $project): array => $this->presentProject($project, $actor));

        return Inertia::render('Modules/Projects/Pages/Projects/Index', [
            'projects' => $projects,
            'filters' => ['search' => $search, 'status' => $filters['status'] ?? null, 'category' => $filters['category'] ?? null],
            'statuses' => array_column(ProjectStatus::cases(), 'value'),
            'categories' => ProjectCategory::query()->where('is_active', true)->orderBy('position')->get(['id', 'name', 'colour']),
            'templates' => ProjectTemplate::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category_id']),
            'contacts' => $this->support->contactOptions($actor),
            'companies' => $this->support->companyOptions($actor),
            'members' => $actor->can(self::EDIT) ? $this->support->memberOptions($actor) : [],
            'currency' => $this->context->require()->currency,
            'canCreate' => $actor->can(self::EDIT),
            'canManageFinancials' => $actor->can(self::FINANCIALS),
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $this->records->authorize($actor, self::VIEW, $project);
        $project->load(['category:id,name,colour', 'contact:id,first_name,last_name,email', 'company:id,name', 'owner.user:id,name', 'members.membership.user:id,name']);
        if ($actor->can(TaskController::VIEW)) {
            $taskQuery = $this->records->constrain(Task::query()->where('project_id', $project->id), $actor, TaskController::VIEW)
                ->with([
                    'owner.user:id,name', 'assignees.membership.user:id,name', 'checklistItems', 'sprint:id,name', 'milestone:id,name', 'dependencies.dependsOn:id,title',
                    'comments' => fn ($query) => $query->with(['author.user:id,name', 'clientAccess.contact:id,first_name,last_name'])->oldest(),
                    'watchers' => fn ($query) => $query->where('membership_id', $membership->id),
                ]);

            if ($actor->can('projects.time.view')) {
                $visibleTimeEntryIds = $this->records->constrain(TimeEntry::query(), $actor, 'projects.time.view')->select('project_time_entries.id');
                $taskQuery->withSum([
                    'timeEntries as time_entries_sum_minutes' => fn ($query) => $query->whereIn('project_time_entries.id', clone $visibleTimeEntryIds),
                ], 'minutes');
            }

            $tasks = $taskQuery->orderBy('position')->orderBy('created_at')->get();
        } else {
            $tasks = collect();
        }
        $attachments = Attachment::query()
            ->where(fn ($query) => $query->where(fn ($projectFiles) => $projectFiles->where('entity_type', 'project')->where('entity_id', $project->id))
                ->orWhere(fn ($taskFiles) => $taskFiles->where('entity_type', 'task')->whereIn('entity_id', $tasks->pluck('id'))))
            ->with('uploader.user:id,name')->latest()->get();
        $taskAttachments = $attachments->where('entity_type', 'task')->groupBy('entity_id');
        $pendingApprovalKeys = Approval::query()->where('project_id', $project->id)->where('status', ApprovalStatus::Pending)->pluck('pending_key')->filter()->flip();
        $approvalMembers = $actor->can('projects.approvals.request') ? $this->support->approvalMemberOptions() : [];
        $canRequestApprovals = $actor->can('projects.approvals.request')
            && collect($approvalMembers)->contains(fn (array $member): bool => $member['id'] !== $membership->id);
        $taskRows = $tasks->map(function (Task $task) use ($actor, $taskAttachments, $pendingApprovalKeys, $canRequestApprovals): array {
            $row = TaskController::present($task, $actor, $this->records, $this->context->require()->timezone);
            $row['attachments'] = $taskAttachments->get($task->id, collect())->map(fn (Attachment $attachment): array => $this->presentAttachment($attachment, (bool) $row['editable']))->values()->all();
            $row['approvalPending'] = $pendingApprovalKeys->has('task:'.$task->id);
            $row['approvalRequestable'] = $canRequestApprovals && ! $row['approvalPending'];

            return $row;
        })->all();
        $canManageMembers = $this->records->allows($actor, self::MEMBERS, $project);
        $canManageFinancials = $this->records->allows($actor, self::FINANCIALS, $project);
        $projectMemberOptions = $project->members->map(fn ($member): array => ['id' => $member->membership_id, 'name' => $member->membership->user->name])->values()->all();
        $memberOptions = $canManageMembers && $actor->can('core.members.view')
            ? $this->support->memberOptions($actor)
            : $projectMemberOptions;
        $clientAccesses = $canManageMembers
            ? ClientAccess::query()->where('project_id', $project->id)->with(['contact:id,first_name,last_name,email', 'company:id,name'])->latest('invited_at')->get()
                ->map(fn (ClientAccess $access): array => [
                    'id' => $access->id,
                    'contact' => ['id' => $access->contact->id, 'name' => $access->contact->fullName(), 'email' => $access->contact->email],
                    'company' => $access->company?->only(['id', 'name']),
                    'status' => $access->status->value,
                    'canComment' => $access->can_comment,
                    'canViewFinancials' => $access->can_view_financials,
                    'invitedAt' => $access->invited_at?->toIso8601String(),
                    'acceptedAt' => $access->accepted_at?->toIso8601String(),
                    'expiresAt' => $access->expires_at?->toIso8601String(),
                    'revocable' => $access->token_hash !== null,
                ])->all()
            : [];

        return Inertia::render('Modules/Projects/Pages/Projects/Show', [
            'project' => $this->presentProject($project, $actor),
            'members' => $project->members->map(fn ($member): array => ['id' => $member->id, 'membershipId' => $member->membership_id, 'name' => $member->membership->user->name, 'role' => $member->role, 'allocationPercent' => $member->allocation_percent, 'removable' => $member->membership_id !== $project->owner_id])->all(),
            'tasks' => $taskRows,
            'milestones' => Milestone::query()->where('project_id', $project->id)->withCount([
                'tasks' => fn ($query) => $query->whereIn('project_tasks.id', $tasks->pluck('id')),
                'tasks as completed_tasks_count' => fn ($query) => $query->whereIn('project_tasks.id', $tasks->pluck('id'))->where('status', 'completed'),
            ])->orderBy('due_on')->get()->map(fn (Milestone $milestone): array => ['id' => $milestone->id, 'name' => $milestone->name, 'status' => $milestone->status->value, 'dueDate' => $milestone->due_on->toDateString(), 'completed' => $milestone->status->value === 'completed', 'taskCount' => (int) $milestone->getAttribute('tasks_count'), 'completedTaskCount' => (int) $milestone->getAttribute('completed_tasks_count'), 'approvalPending' => $pendingApprovalKeys->has('milestone:'.$milestone->id), 'approvalRequestable' => $canRequestApprovals && ! $pendingApprovalKeys->has('milestone:'.$milestone->id)])->all(),
            'sprints' => Sprint::query()->where('project_id', $project->id)->orderByDesc('starts_on')->get(),
            'activity' => ActivityHistory::query()->where('project_id', $project->id)->with('actor.user:id,name')->latest('occurred_at')->limit(100)->get()->map(fn (ActivityHistory $entry): array => ['id' => $entry->id, 'event' => $entry->event, 'summary' => $entry->summary, 'actor' => $entry->actor?->user?->name, 'metadata' => $entry->metadata, 'occurredAt' => $entry->occurred_at->toIso8601String()])->all(),
            'financials' => $canManageFinancials ? $this->analytics->financials($project) : null,
            'workload' => $this->analytics->workload($project, $this->records->constrain(Task::query(), $actor, TaskController::VIEW)),
            'memberOptions' => $memberOptions,
            'approvalMembers' => $approvalMembers,
            'projectForm' => $this->projectForm($actor),
            'taskForm' => ['canAssign' => $actor->can(TaskController::ASSIGN), 'members' => $this->support->taskAssigneeOptions($actor), 'milestones' => Milestone::query()->where('project_id', $project->id)->where('status', 'open')->orderBy('due_on')->get(['id', 'name']), 'sprints' => Sprint::query()->where('project_id', $project->id)->whereIn('status', ['planned', 'active'])->orderByDesc('starts_on')->get(['id', 'name']), 'parentTasks' => $tasks->reject(fn (Task $task): bool => in_array($task->status, [TaskStatus::Completed, TaskStatus::Cancelled], true))->map(fn (Task $task): array => ['id' => $task->id, 'name' => $task->title, 'parentId' => $task->parent_id])->values()->all()],
            'clientAccesses' => $clientAccesses,
            'projectAttachments' => $attachments->where('entity_type', 'project')->map(fn (Attachment $attachment): array => $this->presentAttachment($attachment, $this->records->allows($actor, self::EDIT, $project)))->values()->all(),
            'defaultView' => in_array($request->query('view'), ['board', 'list', 'calendar', 'gantt'], true) ? $request->query('view') : 'board',
            'canManageMembers' => $canManageMembers,
            'canManageFinancials' => $canManageFinancials,
            'canRequestApprovals' => $canRequestApprovals,
            'currentMembershipId' => $membership->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $this->validated($request, $actor);
        $owner = $this->support->ownerForCreate($input, $actor);

        if ($request->filled('budget_amount')) {
            $candidate = (new Project)->assignOwner($owner);
            $this->records->authorize($actor, self::FINANCIALS, $candidate);
        }

        unset($input['owner_id']);
        $project = $this->projects->create($input, $owner, $this->support->membership($actor));
        Toast::success(__('Project created.'));

        return redirect()->route('projects.show', $project);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $project);
        $input = $this->validated($request, $actor, $project);
        $requestedStatus = $input['status'] ?? null;
        unset($input['owner_id'], $input['template_id'], $input['status']);
        $membership = $this->support->membership($actor);

        DB::transaction(function () use ($project, $input, $requestedStatus, $membership): void {
            $updated = $this->projects->update($project, $input, $membership);

            if ($requestedStatus !== null && ProjectStatus::from($requestedStatus) !== $updated->status) {
                $this->projects->changeStatus($updated, ProjectStatus::from($requestedStatus), $membership);
            }
        });
        Toast::success(__('Project saved.'));

        return back();
    }

    public function status(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::EDIT, $project);
        $input = $request->validate(['status' => ['required', Rule::enum(ProjectStatus::class)]]);
        $this->projects->changeStatus($project, ProjectStatus::from($input['status']), $this->support->membership($actor));
        Toast::success(__('Project status changed.'));

        return back();
    }

    public function addMember(Request $request, Project $project): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::MEMBERS, $project);
        $input = $request->validate(['membership_id' => ['required', 'string', 'size:26'], 'role' => ['required', Rule::in(['manager', 'member', 'viewer'])], 'allocation_percent' => ['required', 'integer', 'between:0,100']]);
        $this->projects->addMember($project, $this->support->activeMember($input['membership_id']), $input['role'], $input['allocation_percent'], $this->support->membership($actor));
        Toast::success(__('Project member saved.'));

        return back();
    }

    public function removeMember(Request $request, Project $project, TenantMembership $membership): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->records->authorize($actor, self::MEMBERS, $project);
        abort_unless($project->members()->where('membership_id', $membership->id)->exists(), 404);
        $this->projects->removeMember($project, $membership, $this->support->membership($actor));
        Toast::success(__('Project member removed.'));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $actor, ?Project $project = null): array
    {
        $canManageFinancials = $project === null
            ? $actor->can(self::FINANCIALS)
            : $this->records->allows($actor, self::FINANCIALS, $project);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:200'], 'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('projects', 'code')->where('tenant_id', $this->context->requireId())->ignore($project?->id)], 'description' => ['nullable', 'string', 'max:20000'],
            'template_id' => [$project === null ? 'nullable' : 'sometimes', 'nullable', 'string', 'size:26'], 'category_id' => ['nullable', 'string', 'size:26'],
            'contact_id' => ['nullable', 'string', 'size:26'], 'company_id' => ['nullable', 'string', 'size:26'], 'client_id' => ['nullable', 'string', 'size:26'],
            'priority' => ['required', Rule::enum(Priority::class)], 'status' => ['sometimes', Rule::enum(ProjectStatus::class)], 'starts_on' => ['nullable', 'date_format:Y-m-d'], 'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'currency' => ['sometimes', Rule::in([$this->context->require()->currency])],
            'budget_amount' => $canManageFinancials
                ? [$project === null ? 'required' : 'sometimes', 'integer', 'min:0', 'max:'.ExactMoney::MAX_AMOUNT]
                : ['prohibited'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $input['company_id'] ??= $input['client_id'] ?? null;
        $input['contact_id'] = $this->support->contact($input['contact_id'] ?? null, $actor)?->id;
        $input['company_id'] = $this->support->company($input['company_id'] ?? null, $actor)?->id;
        $input['starts_on'] ??= $input['start_date'] ?? null;
        $input['due_on'] ??= $input['due_date'] ?? null;
        unset($input['client_id'], $input['start_date'], $input['due_date']);

        if ($project === null) {
            $input['budget_amount'] ??= 0;
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function presentProject(Project $project, User $actor): array
    {
        $members = $project->relationLoaded('members') ? $project->members->map(fn ($member): array => ['id' => $member->membership_id, 'name' => $member->membership->user->name, 'allocationPercent' => $member->allocation_percent])->all() : [];

        return ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'description' => $project->description, 'status' => $project->status->value, 'priority' => $project->priority->value, 'startsOn' => $project->starts_on?->toDateString(), 'dueOn' => $project->due_on?->toDateString(), 'startDate' => $project->starts_on?->toDateString(), 'dueDate' => $project->due_on?->toDateString(), 'completedAt' => $project->completed_at?->toIso8601String(), 'progress' => $project->progress, 'budgetAmount' => $this->records->allows($actor, self::FINANCIALS, $project) ? $project->budget_amount : null, 'currency' => $project->currency, 'openTasks' => (int) $project->getAttribute('open_tasks_count'), 'category' => $project->category?->only(['id', 'name', 'colour']), 'contact' => $project->contact === null ? null : ['id' => $project->contact->id, 'name' => $project->contact->fullName()], 'company' => $project->company?->only(['id', 'name']), 'client' => $project->company?->only(['id', 'name']) ?? ($project->contact === null ? null : ['id' => $project->contact->id, 'name' => $project->contact->fullName()]), 'owner' => $project->owner === null ? null : ['id' => $project->owner->id, 'name' => $project->owner->user->name], 'members' => $members, 'editable' => $this->records->allows($actor, self::EDIT, $project), 'taskCreatable' => $actor->can(TaskController::EDIT) && ! in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true), 'createdAt' => $project->created_at->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function projectForm(User $actor): array
    {
        return ['categories' => ProjectCategory::query()->where('is_active', true)->orderBy('position')->get(['id', 'name']), 'owners' => $this->support->memberOptions($actor), 'clients' => $this->support->companyOptions($actor), 'contacts' => $this->support->contactOptions($actor), 'companies' => $this->support->companyOptions($actor), 'templates' => ProjectTemplate::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'currency' => $this->context->require()->currency, 'currencies' => [$this->context->require()->currency]];
    }

    /** @return array<string, mixed> */
    private function presentAttachment(Attachment $attachment, bool $deletable): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mimeType' => $attachment->mime_type,
            'size' => $attachment->size,
            'uploader' => $attachment->uploader?->user?->name,
            'createdAt' => $attachment->created_at->toIso8601String(),
            'deletable' => $deletable,
        ];
    }
}
