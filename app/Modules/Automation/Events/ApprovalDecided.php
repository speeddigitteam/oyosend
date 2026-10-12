<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

/** An approval was approved, rejected or expired (payload: approval_id, run_id, workflow_id, status). */
final class ApprovalDecided extends AutomationEvent
{
    public static function name(): string
    {
        return 'automation.approval.decided';
    }
}
