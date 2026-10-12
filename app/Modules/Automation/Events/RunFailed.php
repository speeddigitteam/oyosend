<?php

declare(strict_types=1);

namespace App\Modules\Automation\Events;

/** A live run failed: the signal Phase 8 turns into a failure alert (payload: run_id, workflow_id, error_code). */
final class RunFailed extends AutomationEvent
{
    public static function name(): string
    {
        return 'automation.run.failed';
    }
}
