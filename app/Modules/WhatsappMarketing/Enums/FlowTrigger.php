<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** When a chatbot flow starts: a customer keyword, or the first message of a conversation window. */
enum FlowTrigger: string
{
    case Keyword = 'keyword';
    case Welcome = 'welcome';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
