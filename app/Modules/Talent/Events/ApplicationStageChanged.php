<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** An application moved to another pipeline stage. */
final class ApplicationStageChanged extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.application.stage_changed';
    }
}
