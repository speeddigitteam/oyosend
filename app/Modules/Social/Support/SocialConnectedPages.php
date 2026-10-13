<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Social\Contracts\ConnectedPages;
use App\Core\Social\Data\ConnectedPage;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\SocialAccount;

/**
 * Social's answer to {@see ConnectedPages}: the workspace's Facebook Page accounts, without tokens.
 * Empty and unavailable when the workspace is not entitled to the Social app.
 */
final class SocialConnectedPages implements ConnectedPages
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementChecker $entitlements,
    ) {}

    public function isAvailable(): bool
    {
        $tenant = $this->tenants->tenant();

        return $tenant !== null && $this->entitlements->allows($tenant, 'social');
    }

    public function pages(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        return SocialAccount::query()
            ->where('platform', 'facebook')
            ->whereIn('status', [AccountStatus::Connected->value, AccountStatus::NeedsReauthorization->value])
            ->orderBy('name')
            ->get(['id', 'name', 'external_id', 'status'])
            ->map(static fn (SocialAccount $account): ConnectedPage => new ConnectedPage(
                (string) $account->id,
                $account->name,
                $account->external_id,
                $account->status === AccountStatus::Connected
                    ? ConnectedPage::STATUS_CONNECTED
                    : ConnectedPage::STATUS_NEEDS_REAUTHORIZATION,
            ))->values()->all();
    }
}
