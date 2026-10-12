<?php

declare(strict_types=1);

namespace App\Modules\Automation\Enums;

enum RunSource: string
{
    case Event = 'event';
    case Webhook = 'webhook';
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Test = 'test';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
