<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** Where a campaign is. `sending` covers recipient snapshot and the chunked hand-over to the outbox. */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }
}
