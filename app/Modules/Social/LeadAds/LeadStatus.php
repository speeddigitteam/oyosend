<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

/** Where one received lead is in its processing. */
enum LeadStatus: string
{
    /** Delivered (or listed), not yet fetched or handed on. Includes a scheduled retry. */
    case Received = 'received';
    case Captured = 'captured';
    /** The CRM already had this lead (a replay of the same idempotency key). */
    case Duplicate = 'duplicate';
    case Failed = 'failed';
    /** Kept, with its answers, until the CRM is available in the workspace. */
    case WaitingForCrm = 'waiting_for_crm';
    /** The Page connection must be authorised again; resumes once it is connected. */
    case WaitingForAccount = 'waiting_for_account';
    /** Not processed on purpose: the form is not switched on. */
    case Skipped = 'skipped';

    /** @return list<string> */
    public static function open(): array
    {
        return [self::Received->value, self::WaitingForCrm->value, self::WaitingForAccount->value];
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Captured, self::Duplicate], true);
    }
}
