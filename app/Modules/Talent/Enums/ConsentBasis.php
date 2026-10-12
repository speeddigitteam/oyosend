<?php

declare(strict_types=1);

namespace App\Modules\Talent\Enums;

/** How the candidate's data came to the workspace. */
enum ConsentBasis: string
{
    case CandidateProvided = 'candidate_provided';
    case AppliedDirectly = 'applied_directly';
    case ConsentForm = 'consent_form';
    case Referral = 'referral';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
