<?php

declare(strict_types=1);

namespace App\Modules\Communication\Enums;

enum InteractionType: string
{
    case Note = 'note';
    case Call = 'call';
    case Meeting = 'meeting';
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case System = 'system';

    /** Types a person may log by hand (and later remove). */
    public function isManual(): bool
    {
        return in_array($this, [self::Note, self::Call, self::Meeting, self::Email], true);
    }

    /** @return list<self> */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isManual()));
    }
}
