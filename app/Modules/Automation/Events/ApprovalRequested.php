<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

/** A run is waiting for a human decision (payload: approval_id, run_id, workflow_id, approver_type, approver_permission, approver_membership_id). */
final class ApprovalRequested extends AutomationEvent
{
    public static function name(): string
    {
        return 'automation.approval.requested';
    }
}
