<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

/** A workflow was archived; its in-flight runs were cancelled (payload: workflow_id). */
final class WorkflowArchived extends AutomationEvent
{
    public static function name(): string
    {
        return 'automation.workflow.archived';
    }
}
