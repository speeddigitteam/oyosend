<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine;

use App\Core\Access\AccessChecker;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Automation\Enums\ApprovalStatus;
use App\Modules\Automation\Events\ApprovalDecided;
use App\Modules\Automation\Events\ApprovalRequested;
use App\Modules\Automation\Models\Approval;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\RunStep;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Support\Limits;
use App\Modules\Automation\Support\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Human approval tasks created by approval nodes.
 *
 * A decision only records who decided what; the executor (the single writer of a run) applies it to the
 * run on its next pass. That keeps every run mutation in one place and one lease.
 */
final class ApprovalManager
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly AuditLogger $audit,
        private readonly RunStarter $starter,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The approval node's configuration, with templates resolved.
     */
    public function request(Run $run, RunStep $step, Workflow $workflow, array $config): Approval
    {
        $approver = is_array($config['approver'] ?? null) ? $config['approver'] : [];
        $hours = (int) ($config['timeout_hours'] ?? Limits::int('approval_default_hours'));

        $approval = Approval::query()->create([
            'run_id' => $run->id,
            'run_step_id' => $step->id,
            'workflow_id' => $workflow->id,
            'title' => mb_substr((string) ($config['title'] ?? ''), 0, 200),
            'message' => isset($config['message']) && is_string($config['message']) ? mb_substr($config['message'], 0, 5000) : null,
            'approver_type' => ($approver['type'] ?? '') === 'membership' ? 'membership' : 'permission',
            'approver_permission' => ($approver['type'] ?? '') === 'permission' ? (string) ($approver['permission'] ?? '') : null,
            'approver_membership_id' => ($approver['type'] ?? '') === 'membership' ? (string) ($approver['membership_id'] ?? '') : null,
            'prevent_self_approval' => (bool) ($config['prevent_self_approval'] ?? true),
            // The person the workflow acts for: whoever started the run, else the member it runs as.
            'requested_by_membership_id' => $run->started_by_membership_id ?? $workflow->actor_membership_id,
            'status' => ApprovalStatus::Pending,
            'on_timeout' => (string) ($config['on_timeout'] ?? 'reject'),
            'expires_at' => Carbon::now()->addHours(max(1, $hours)),
        ]);

        ApprovalRequested::dispatch([
            'approval_id' => $approval->id,
            'run_id' => $run->id,
            'workflow_id' => $workflow->id,
            'approver_type' => $approval->approver_type,
            'approver_permission' => $approval->approver_permission,
            'approver_membership_id' => $approval->approver_membership_id,
        ]);

        return $approval;
    }

    /**
     * Why this member may not decide the approval, or null when they may.
     *
     * The member must hold `automation.approvals.decide`, be the named approver (or hold the named
     * permission), and - when the node says so - not be the person the run is acting for.
     */
    public function refusal(Approval $approval, TenantMembership $member): ?string
    {
        if ($approval->status !== ApprovalStatus::Pending) {
            return 'closed';
        }

        // Past its deadline a decision is no longer accepted, even before the sweeper marks it expired.
        if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
            return 'expired';
        }

        if ($member->status !== MembershipStatus::Active) {
            return 'inactive';
        }

        $user = $member->user;

        if (! $this->access->allows($user, Permissions::APPROVALS_DECIDE)) {
            return 'forbidden';
        }

        if ($approval->prevent_self_approval && $approval->requested_by_membership_id === $member->id) {
            return 'self_approval';
        }

        if ($approval->approver_type === 'membership') {
            return $approval->approver_membership_id === $member->id ? null : 'not_approver';
        }

        $permission = (string) $approval->approver_permission;

        return $permission !== '' && $this->access->allows($user, $permission) ? null : 'not_approver';
    }

    /**
     * Whether the approval concerns this member: it is addressed to them (by id, or by a permission they
     * hold), they asked for it, or they decided it. Decides who sees it in the inbox history.
     */
    public function involves(Approval $approval, TenantMembership $member): bool
    {
        if ($approval->requested_by_membership_id === $member->id || $approval->decided_by_membership_id === $member->id) {
            return true;
        }

        if ($approval->approver_type === 'membership') {
            return $approval->approver_membership_id === $member->id;
        }

        $permission = (string) $approval->approver_permission;

        return $permission !== '' && $this->access->allows($member->user, $permission);
    }

    /**
     * @throws ValidationException
     */
    public function decide(Approval $approval, TenantMembership $member, bool $approve, ?string $comment): Approval
    {
        $expired = false;

        $decided = DB::transaction(function () use ($approval, $member, $approve, $comment, &$expired): Approval {
            $fresh = Approval::query()->lockForUpdate()->findOrFail($approval->id);

            $refusal = $this->refusal($fresh, $member);

            if ($refusal === 'expired') {
                // Close it now (the same transition the sweeper makes) so the run resolves by its timeout rule.
                $fresh->forceFill(['status' => ApprovalStatus::Expired, 'decided_at' => Carbon::now()])->save();
                $expired = true;

                return $fresh;
            }

            if ($refusal !== null) {
                throw ValidationException::withMessages(['approval' => match ($refusal) {
                    'closed' => __('This approval has already been decided.'),
                    'self_approval' => __('You cannot approve something you started yourself.'),
                    default => __('You are not allowed to decide this approval.'),
                }]);
            }

            $fresh->forceFill([
                'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_by_membership_id' => $member->id,
                'decision_comment' => $comment === null || trim($comment) === '' ? null : mb_substr(trim($comment), 0, 2000),
                'decided_at' => Carbon::now(),
            ])->save();

            $this->audit->record($approve ? 'automation.approval.approved' : 'automation.approval.rejected', $fresh, [
                'run_id' => $fresh->run_id,
                'workflow_id' => $fresh->workflow_id,
            ]);

            ApprovalDecided::dispatch([
                'approval_id' => $fresh->id,
                'run_id' => $fresh->run_id,
                'workflow_id' => $fresh->workflow_id,
                'status' => $fresh->status->value,
            ]);

            return $fresh;
        });

        $this->starter->queue($decided->run_id);

        if ($expired) {
            throw ValidationException::withMessages(['approval' => __('This approval has expired.')]);
        }

        return $decided;
    }
}
