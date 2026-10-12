<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An onboarding or offboarding checklist was started. */
final class ChecklistStarted extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.checklist.started';
    }
}
