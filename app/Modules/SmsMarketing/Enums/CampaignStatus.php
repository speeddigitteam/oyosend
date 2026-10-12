<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Whether the campaign still has work in front of it (the dispatcher looks at these). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::Sending], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
