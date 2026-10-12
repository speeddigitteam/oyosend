<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** Who a checklist task is for; resolved to an employee when the checklist is started. */
enum AssigneeRole: string
{
    case Employee = 'employee';
    case Manager = 'manager';
    case Hr = 'hr';
    case Person = 'person';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
