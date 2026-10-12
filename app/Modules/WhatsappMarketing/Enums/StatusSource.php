<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** Who recorded a template status: the provider (a verified webhook event) or an administrator by hand. */
enum StatusSource: string
{
    case Provider = 'provider';
    case Admin = 'admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
