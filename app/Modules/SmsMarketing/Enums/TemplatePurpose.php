<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Enums;

/**
 * Why a template exists. A campaign may use only `Marketing` templates; a one-time-code style text is allowed
 * only as `Transactional` (it must never go out as a campaign).
 */
enum TemplatePurpose: string
{
    case Marketing = 'marketing';
    case Transactional = 'transactional';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $purpose): string => $purpose->value, self::cases());
    }
}
