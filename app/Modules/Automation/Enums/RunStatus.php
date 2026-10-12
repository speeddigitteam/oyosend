<?php

declare(strict_types=1);

namespace App\Modules\Automation\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case AwaitingApproval = 'awaiting_approval';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
