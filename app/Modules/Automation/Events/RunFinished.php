<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

/** A live run reached a terminal state (payload: run_id, workflow_id, status, error_code). */
final class RunFinished extends AutomationEvent
{
    public static function name(): string
    {
        return 'automation.run.finished';
    }
}
