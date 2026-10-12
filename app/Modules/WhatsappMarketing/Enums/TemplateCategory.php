<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** A template's category as Meta defines it. Marketing templates always need a recorded opt-in. */
enum TemplateCategory: string
{
    case Marketing = 'marketing';
    case Utility = 'utility';
    case Authentication = 'authentication';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isMarketing(): bool
    {
        return $this === self::Marketing;
    }
}
