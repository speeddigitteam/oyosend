<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** Kind of candidate document. */
enum DocumentType: string
{
    case Resume = 'resume';
    case CoverLetter = 'cover_letter';
    case Portfolio = 'portfolio';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
