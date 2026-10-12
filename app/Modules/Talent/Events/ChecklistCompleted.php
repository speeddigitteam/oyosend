<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** Every task of a checklist is done. */
final class ChecklistCompleted extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.checklist.completed';
    }
}
