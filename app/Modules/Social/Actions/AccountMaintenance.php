<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;

/**
 * Keeps connected accounts usable: renews tokens that expire within a day, and flags the
 * accounts whose authorisation can no longer be renewed so the workspace sees it before a post is due.
 */
final class AccountMaintenance
{
    private const int RENEW_WITHIN_MINUTES = 1440;

    public function __construct(private readonly AccountManager $accounts) {}

    /** @return int accounts looked at */
    public function run(): int
    {
        $accounts = SocialAccount::query()
            ->where('status', AccountStatus::Connected->value)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addMinutes(self::RENEW_WITHIN_MINUTES))
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->accounts->credentials($account, self::RENEW_WITHIN_MINUTES);
            } catch (PublishFailed) {
                // credentials() already moved the account to needs_reauthorization, or the platform
                // was briefly unreachable; the next hourly run tries again.
            }
        }

        return $accounts->count();
    }
}
