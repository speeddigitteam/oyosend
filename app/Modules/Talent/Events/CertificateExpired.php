<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A certification has expired. */
final class CertificateExpired extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.certificate.expired';
    }
}
