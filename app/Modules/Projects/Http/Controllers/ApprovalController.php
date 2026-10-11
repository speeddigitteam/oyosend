<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Toast;
use App\Modules\Projects\Actions\ApprovalManager;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ApprovalController
{
    public function __construct(private readonly ProjectControllerSupport $support, private readonly ApprovalManager $approvals, private readonly RecordAccess $records) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $canView = $actor->can('projects.approvals.view');
        $canRequest = $actor->can('projects.approvals.request');
        $canDecide = $actor->can('projects.approvals.decide');
        abort_unless($canView || $canRequest || $canDecide, 403);
        $availableApprovers = $canDecide
            ? collect($this->support->approvalMemberOptions())->pluck('id')->flip()
            : collect();
        $approvals = Approval::query()
            ->with(['project:id,name', 'requester.user:id,name', 'approver.user:id,name'])
            ->when(! $canView, function ($query) use ($membership, $canRequest, $canDecide, $availableApprovers): void {
                $query->where(function ($visible) use ($membership, $canRequest, $canDecide, $availableApprovers): void {
                    if ($canRequest) {
                        $visible->orWhere('requested_by', $membership->id);
                    }

                    if ($canDecide) {
                        $visible->orWhere('approver_id', $membership->id);
                        $visible->orWhere(function ($recoverable) use ($availableApprovers): void {
                            $recoverable->where('status', ApprovalStatus::Pending)
                                ->whereNotIn('approver_id', $availableApprovers->keys()->all());
                        });
                    }
                });
            })
            ->latest()->paginate(50)->withQueryString()
            ->through(fn (Approval $approval): array => ['id' => $approval->id, 'type' => $approval->entity_type->value, 'entityId' => $approval->entity_id, 'title' => $approval->project->name, 'project' => ['id' => $approval->project->id, 'name' => $approval->project->name], 'requester' => ['id' => $approval->requested_by, 'name' => $approval->requester->user->name], 'approver' => ['id' => $approval->approver_id, 'name' => $approval->approver->user->name], 'requestNote' => $approval->request_note, 'decisionNote' => $approval->decision_note, 'requestedAt' => $approval->created_at->toIso8601String(), 'status' => $approval->status->value, 'actionable' => $approval->status === ApprovalStatus::Pending && $approval->approver_id === $membership->id && $canDecide, 'cancelable' => $approval->status === ApprovalStatus::Pending && (($approval->requested_by === $membership->id && $canRequest) || ($canDecide && ! $availableApprovers->has($approval->approver_id)))]);

        return Inertia::render('Modules/Projects/Pages/Approvals/Index', ['approvals' => $approvals]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['project_id' => ['required', 'string', 'size:26'], 'entity_type' => ['required', Rule::enum(ApprovalEntity::class)], 'entity_id' => ['required', 'string', 'size:26'], 'approver_id' => ['required', 'string', 'size:26'], 'note' => ['nullable', 'string', 'max:10000']]);
        $project = Project::query()->findOrFail($input['project_id']);
        $this->records->authorize($actor, ProjectController::VIEW, $project);
        $type = ApprovalEntity::from($input['entity_type']);

        match ($type) {
            ApprovalEntity::Task => $this->records->authorize($actor, TaskController::VIEW, Task::query()->findOrFail($input['entity_id'])),
            ApprovalEntity::TimeEntry => $this->records->authorize($actor, 'projects.time.view', TimeEntry::query()->findOrFail($input['entity_id'])),
            ApprovalEntity::Expense => $this->records->authorize($actor, 'projects.expenses.view', Expense::query()->findOrFail($input['entity_id'])),
            ApprovalEntity::Milestone => abort_unless(Milestone::query()->where('project_id', $project->id)->whereKey($input['entity_id'])->exists(), 404),
        };
        $this->approvals->request($project, $type, $input['entity_id'], $this->support->membership($actor), $this->support->activeMember($input['approver_id']), $input['note'] ?? null);
        Toast::success(__('Approval requested.'));

        return back();
    }

    public function decide(Request $request, Approval $approval): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $input = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:10000']]);
        $this->approvals->decide($approval, $input['approve'], $this->support->membership($actor), $input['note'] ?? null);
        Toast::success(__('Approval decision saved.'));

        return back();
    }

    public function cancel(Request $request, Approval $approval): RedirectResponse
    {
        $actor = $this->support->actor($request);

        abort_unless($actor->can('projects.approvals.request') || $actor->can('projects.approvals.decide'), 403);
        $this->approvals->cancel($approval, $this->support->membership($actor));
        Toast::success(__('Approval request cancelled.'));

        return back();
    }
}
