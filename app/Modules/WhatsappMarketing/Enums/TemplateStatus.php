<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Enums;

/** The approval status of a template on the provider side. Only `approved` can be sent. */
enum TemplateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Paused = 'paused';
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isSendable(): bool
    {
        return $this === self::Approved;
    }
}
