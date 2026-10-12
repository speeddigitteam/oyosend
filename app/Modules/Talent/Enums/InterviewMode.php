<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** How an interview is held. */
enum InterviewMode: string
{
    case InPerson = 'in_person';
    case Video = 'video';
    case Phone = 'phone';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
