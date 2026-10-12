<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Enums;

/** Masking sender ids show a brand name (alphanumeric); non-masking ones show a number. */
enum SenderIdType: string
{
    case Masking = 'masking';
    case NonMasking = 'non_masking';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
