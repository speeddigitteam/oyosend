<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

/**
 * The deterministic A/B split: the same contact always lands in the same bucket of the same campaign, so a retry,
 * a resumed run or a second worker can never move a person from one variant to the other.
 */
final class VariantSplitter
{
    /** A stable number from 0 to 99 for a contact in a campaign. */
    public static function bucket(string $campaignId, string $contactId): int
    {
        return (int) (hexdec(substr(hash('sha256', 'ab-bucket|'.$campaignId.'|'.$contactId), 0, 8)) % 100);
    }

    /** `a` or `b`, half and half within the test cohort. */
    public static function variant(string $campaignId, string $contactId): string
    {
        return hexdec(substr(hash('sha256', 'ab-variant|'.$campaignId.'|'.$contactId), 0, 8)) % 2 === 0 ? 'a' : 'b';
    }

    /** Whether the contact is part of the cohort that is sent the subject variants first. */
    public static function inTestCohort(string $campaignId, string $contactId, int $percent): bool
    {
        return self::bucket($campaignId, $contactId) < $percent;
    }
}
