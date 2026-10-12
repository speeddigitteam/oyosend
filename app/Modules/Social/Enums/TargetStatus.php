<?php

declare(strict_types=1);

namespace App\Modules\Social\Enums;

enum TargetStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Published || $this === self::Failed;
    }
}
