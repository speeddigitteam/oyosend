<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApprovalManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly ActivityRecorder $activity, private readonly TimeExpenseManager $finance, private readonly TenantContext $context, private readonly AccessChecker $access) {}

    public function request(Project $project, ApprovalEntity $type, string $entityId, TenantMembership $requester, TenantMembership $approver, ?string $note = null): Approval
    {
        $approver->loadMissing('user');

        if ($requester->tenant_id !== $this->context->requireId() || $approver->tenant_id !== $this->context->requireId() || $requester->status !== MembershipStatus::Active || $approver->status !== MembershipStatus::Active || $requester->id === $approver->id || ! $this->access->allows($approver->user, 'projects.approvals.decide')) {
            throw ValidationException::withMessages(['approver_id' => __('Choose another active member who can decide approvals.')]);
        }

        $pendingKey = $type->value.':'.$entityId;

        try {
            return DB::transaction(function () use ($project, $type, $entityId, $requester, $approver, $note, $pendingKey): Approval {
                $entity = $this->entity($type, $entityId, lock: true);

                if ($entity->getAttribute('project_id') !== $project->id) {
                    throw ValidationException::withMessages(['entity_id' => __('The approval subject does not belong to this project.')]);
                }

                if (($entity instanceof TimeEntry || $entity instanceof Expense) && $entity->status !== EntryStatus::Submitted) {
                    throw ValidationException::withMessages(['entity_id' => __('Submit the time entry or expense before requesting approval.')]);
                }

                if ($entity instanceof TimeEntry) {
                    $this->finance->guardDecider($approver, $entity, 'projects.time.approve', $entity->membership_id);
                }

                if ($entity instanceof Expense) {
                    $this->finance->guardDecider($approver, $entity, 'projects.expenses.approve', $entity->submitted_by);
                }

                if (Approval::query()->where('entity_type', $type)->where('entity_id', $entityId)->where('status', ApprovalStatus::Pending)->exists()) {
                    throw ValidationException::withMessages(['entity_id' => __('This item already has a pending approval request.')]);
                }

                /** @var Approval $approval */
                $approval = Approval::query()->create(['project_id' => $project->id, 'entity_type' => $type, 'entity_id' => $entityId, 'status' => ApprovalStatus::Pending, 'pending_key' => $pendingKey, 'request_note' => $note, 'requested_by' => $requester->id, 'approver_id' => $approver->id]);
                $this->activity->record($project->id, 'projects.approval.requested', __('Approval requested.'), $requester, metadata: ['approval_id' => $approval->id, 'entity_type' => $type->value, 'entity_id' => $entityId]);
                $this->audit->record('projects.approval.requested', $approval, ['entity_type' => $type->value, 'entity_id' => $entityId, 'approver_id' => $approver->id]);

                return $approval;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['entity_id' => __('This item already has a pending approval request.')]);
        }
    }

    public function decide(Approval $approval, bool $approve, TenantMembership $actor, ?string $note = null): Approval
    {
        $actor->loadMissing('user');

        return DB::transaction(function () use ($approval, $approve, $actor, $note): Approval {
            $locked = Approval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($actor->tenant_id !== $this->context->requireId() || $actor->status !== MembershipStatus::Active || ! $this->access->allows($actor->user, 'projects.approvals.decide') || $locked->status !== ApprovalStatus::Pending || $locked->approver_id !== $actor->id) {
                throw ValidationException::withMessages(['status' => __('This approval cannot be decided by this member.')]);
            }

            $status = $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected;
            $locked->update(['status' => $status, 'pending_key' => null, 'decision_note' => $note, 'decided_at' => now()]);

            if ($locked->entity_type === ApprovalEntity::TimeEntry) {
                $entry = TimeEntry::query()->findOrFail($locked->entity_id);

                $this->finance->decideTime($entry, $approve, $actor);
            }

            if ($locked->entity_type === ApprovalEntity::Expense) {
                $expense = Expense::query()->findOrFail($locked->entity_id);

                $this->finance->decideExpense($expense, $approve, $actor);
            }

            $this->activity->record($locked->project_id, 'projects.approval.'.$status->value, $approve ? __('Approval granted.') : __('Approval rejected.'), $actor, metadata: ['approval_id' => $locked->id]);
            $this->audit->record('projects.approval.'.$status->value, $locked, ['decision_note' => $note]);

            return $locked;
        });
    }

    public function cancel(Approval $approval, TenantMembership $actor): Approval
    {
        $actor->loadMissing('user');

        return DB::transaction(function () use ($approval, $actor): Approval {
            $locked = Approval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('approver.user');
            $canCancelOwn = $locked->requested_by === $actor->id
                && $this->access->allows($actor->user, 'projects.approvals.request');
            $approverAvailable = $locked->approver->status === MembershipStatus::Active
                && $this->access->allows($locked->approver->user, 'projects.approvals.decide');
            $canRecover = ! $approverAvailable
                && $this->access->allows($actor->user, 'projects.approvals.decide');

            if ($actor->tenant_id !== $this->context->requireId()
                || $actor->status !== MembershipStatus::Active
                || $locked->status !== ApprovalStatus::Pending
                || (! $canCancelOwn && ! $canRecover)) {
                throw ValidationException::withMessages(['status' => __('This approval request cannot be cancelled by this member.')]);
            }

            $locked->update([
                'status' => ApprovalStatus::Cancelled,
                'pending_key' => null,
                'decided_at' => now(),
            ]);

            $this->activity->record($locked->project_id, 'projects.approval.cancelled', __('Approval request cancelled.'), $actor, metadata: ['approval_id' => $locked->id]);
            $this->audit->record('projects.approval.cancelled', $locked);

            return $locked;
        });
    }

    private function entity(ApprovalEntity $type, string $id, bool $lock = false): Model
    {
        $query = match ($type) {
            ApprovalEntity::Task => Task::query(),
            ApprovalEntity::TimeEntry => TimeEntry::query(),
            ApprovalEntity::Expense => Expense::query(),
            ApprovalEntity::Milestone => Milestone::query(),
        };

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }
}
