<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A candidate record was anonymized (retention or request). */
final class CandidateAnonymized extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.candidate.anonymized';
    }
}
