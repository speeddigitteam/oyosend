<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use App\Modules\Projects\Enums\EntryStatus;
use App\Modules\Projects\Events\TimeEntryApproved;
use App\Modules\Projects\Models\Approval;
use App\Modules\Projects\Models\Expense;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TimeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TimeExpenseManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly ActivityRecorder $activity, private readonly TenantContext $context) {}

    /** @param array<string, mixed> $attributes */
    public function logTime(Project $project, TenantMembership $member, array $attributes, ?TenantMembership $actor = null): TimeEntry
    {
        $this->guardMember($member);

        if ($actor !== null) {
            $this->guardMember($actor);
        }

        $task = ($attributes['task_id'] ?? null) === null ? null : Task::query()->findOrFail($attributes['task_id']);

        if ($task !== null && $task->project_id !== $project->id) {
            throw ValidationException::withMessages(['task_id' => __('The selected task does not belong to this project.')]);
        }

        $minutes = (int) ($attributes['minutes'] ?? 0);

        if ($minutes < 1 || $minutes > 10080) {
            throw ValidationException::withMessages(['minutes' => __('Time must be between 1 minute and 7 days.')]);
        }

        return DB::transaction(function () use ($project, $member, $attributes, $actor, $minutes): TimeEntry {
            $entry = new TimeEntry([
                ...$attributes,
                'project_id' => $project->id,
                'membership_id' => $member->id,
                'minutes' => $minutes,
                'currency' => $project->currency,
                'billable_rate_amount' => ExactMoney::amount($attributes['billable_rate_amount'] ?? 0, 'billable_rate_amount'),
                'cost_rate_amount' => ExactMoney::amount($attributes['cost_rate_amount'] ?? 0, 'cost_rate_amount'),
                'status' => EntryStatus::Draft,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            if ($entry->started_at !== null && $entry->ended_at !== null && $entry->ended_at->lessThanOrEqualTo($entry->started_at)) {
                throw ValidationException::withMessages(['ended_at' => __('The end time must be after the start time.')]);
            }

            $entry->assignOwner($member)->save();
            $this->activity->record($project->id, 'projects.time.logged', __('Time entry logged.'), $actor, $attributes['task_id'] ?? null, ['time_entry_id' => $entry->id, 'minutes' => $minutes]);
            $this->audit->record('projects.time.logged', $entry, ['project_id' => $project->id, 'minutes' => $minutes, 'billable' => $entry->is_billable]);

            return $entry;
        });
    }

    public function submitTime(TimeEntry $entry, ?TenantMembership $actor = null): TimeEntry
    {
        return DB::transaction(function () use ($entry, $actor): TimeEntry {
            $locked = TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EntryStatus::Draft && $locked->status !== EntryStatus::Rejected) {
                throw ValidationException::withMessages(['status' => __('Only draft or rejected time can be submitted.')]);
            }

            $locked->update(['status' => EntryStatus::Submitted, 'approved_by' => null, 'approved_at' => null]);
            $this->activity->record($locked->project_id, 'projects.time.submitted', __('Time entry submitted.'), $actor, $locked->task_id, ['time_entry_id' => $locked->id]);

            return $locked;
        });
    }

    public function decideTime(TimeEntry $entry, bool $approve, TenantMembership $actor): TimeEntry
    {
        $this->guardMember($actor);

        return DB::transaction(function () use ($entry, $approve, $actor): TimeEntry {
            $locked = TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EntryStatus::Submitted) {
                throw ValidationException::withMessages(['status' => __('Only submitted time can be decided.')]);
            }

            $this->guardNoPendingApproval(ApprovalEntity::TimeEntry, $locked->id);

            $status = $approve ? EntryStatus::Approved : EntryStatus::Rejected;
            $locked->update(['status' => $status, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->activity->record($locked->project_id, 'projects.time.'.$status->value, $approve ? __('Time entry approved.') : __('Time entry rejected.'), $actor, $locked->task_id, ['time_entry_id' => $locked->id]);
            $this->audit->record('projects.time.'.$status->value, $locked, ['project_id' => $locked->project_id]);

            if ($approve) {
                TimeEntryApproved::dispatch($locked->id, $locked->project_id, $locked->membership_id, $locked->minutes, $locked->is_billable, $locked->billable_rate_amount, $locked->cost_rate_amount, $locked->currency);
            }

            return $locked;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createExpense(Project $project, TenantMembership $submitter, array $attributes): Expense
    {
        $this->guardMember($submitter);
        $task = ($attributes['task_id'] ?? null) === null ? null : Task::query()->findOrFail($attributes['task_id']);

        if ($task !== null && $task->project_id !== $project->id) {
            throw ValidationException::withMessages(['task_id' => __('The selected task does not belong to this project.')]);
        }

        return DB::transaction(function () use ($project, $submitter, $attributes): Expense {
            $expense = new Expense([...$attributes, 'project_id' => $project->id, 'submitted_by' => $submitter->id, 'currency' => $project->currency, 'amount' => ExactMoney::amount($attributes['amount'], 'amount'), 'status' => EntryStatus::Draft, 'approved_by' => null, 'approved_at' => null]);
            $expense->assignOwner($submitter)->save();
            $this->activity->record($project->id, 'projects.expense.created', __('Expense created.'), $submitter, $attributes['task_id'] ?? null, ['expense_id' => $expense->id, 'amount' => $expense->amount, 'currency' => $expense->currency]);
            $this->audit->record('projects.expense.created', $expense, ['project_id' => $project->id, 'amount' => $expense->amount, 'currency' => $expense->currency]);

            return $expense;
        });
    }

    public function submitExpense(Expense $expense, ?TenantMembership $actor = null): Expense
    {
        return DB::transaction(function () use ($expense, $actor): Expense {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EntryStatus::Draft && $locked->status !== EntryStatus::Rejected) {
                throw ValidationException::withMessages(['status' => __('Only draft or rejected expenses can be submitted.')]);
            }

            $locked->update(['status' => EntryStatus::Submitted, 'approved_by' => null, 'approved_at' => null]);
            $this->activity->record($locked->project_id, 'projects.expense.submitted', __('Expense submitted.'), $actor, $locked->task_id, ['expense_id' => $locked->id]);

            return $locked;
        });
    }

    public function decideExpense(Expense $expense, bool $approve, TenantMembership $actor): Expense
    {
        $this->guardMember($actor);

        return DB::transaction(function () use ($expense, $approve, $actor): Expense {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EntryStatus::Submitted) {
                throw ValidationException::withMessages(['status' => __('Only submitted expenses can be decided.')]);
            }

            $this->guardNoPendingApproval(ApprovalEntity::Expense, $locked->id);

            $status = $approve ? EntryStatus::Approved : EntryStatus::Rejected;
            $locked->update(['status' => $status, 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->activity->record($locked->project_id, 'projects.expense.'.$status->value, $approve ? __('Expense approved.') : __('Expense rejected.'), $actor, $locked->task_id, ['expense_id' => $locked->id]);
            $this->audit->record('projects.expense.'.$status->value, $locked, ['project_id' => $locked->project_id, 'amount' => $locked->amount, 'currency' => $locked->currency]);

            return $locked;
        });
    }

    private function guardMember(TenantMembership $member): void
    {
        if ($member->tenant_id !== $this->context->requireId() || $member->status !== MembershipStatus::Active) {
            throw ValidationException::withMessages(['membership_id' => __('Choose an active workspace member.')]);
        }
    }

    private function guardNoPendingApproval(ApprovalEntity $entityType, string $entityId): void
    {
        if (Approval::query()->where('entity_type', $entityType)->where('entity_id', $entityId)->where('status', ApprovalStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['status' => __('Decide the pending approval request instead.')]);
        }
    }
}
