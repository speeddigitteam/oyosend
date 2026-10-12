<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** State of a course enrolment. */
enum EnrolmentStatus: string
{
    case Enrolled = 'enrolled';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
