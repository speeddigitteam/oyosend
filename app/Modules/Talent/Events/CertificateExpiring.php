<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A certification expires soon. */
final class CertificateExpiring extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.certificate.expiring';
    }
}
