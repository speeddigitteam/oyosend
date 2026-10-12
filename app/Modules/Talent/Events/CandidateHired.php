<?php

declare(strict_types=1);

namespace App\Modules\Talent\Events;

/** A candidate was hired. HRM (or an operator) creates the employee from this payload. */
final class CandidateHired extends TalentEvent
{
    public static function name(): string
    {
        return 'talent.candidate.hired';
    }
}
