<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** A rating request: waiting for the answer, answered, or ended (another message, or the window closed). */
enum FeedbackStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Expired = 'expired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
