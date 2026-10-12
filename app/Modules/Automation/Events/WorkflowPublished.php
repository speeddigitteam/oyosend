<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

/** A workflow version was published (payload: workflow_id, version_id, version, actor_membership_id). */
final class WorkflowPublished extends AutomationEvent
{
    public static function name(): string
    {
        return 'automation.workflow.published';
    }
}
