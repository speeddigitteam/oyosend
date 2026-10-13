<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Controllers;

use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\Change;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Support\MinorUnits;

/**
 * Turns models into the arrays the pages receive. A token is never part of any of them: the connection and
 * account models hide it, and nothing here reads it.
 */
final class MetaAdsPresenter
{
    /** Meta's `account_status` codes as keys the interface translates. */
    private const array META_STATUS = [
        1 => 'active', 2 => 'disabled', 3 => 'unsettled', 7 => 'risk_review', 8 => 'pending_settlement',
        9 => 'grace_period', 100 => 'pending_closure', 101 => 'closed', 201 => 'active', 202 => 'closed',
    ];

    /** @return array<string, mixed> */
    public function connection(Connection $connection): array
    {
        $expires = $connection->token_expires_at;

        return [
            'id' => $connection->id,
            'userName' => $connection->user_name,
            'status' => $connection->status->value,
            'statusReason' => $connection->status_reason,
            'tokenExpiresAt' => $expires?->toIso8601String(),
            'expiresSoon' => $expires !== null && $expires->lte(now()->addDays(7)),
            'expired' => $expires !== null && $expires->isPast(),
            'canManage' => $connection->canManage(),
            'grantedScopes' => $connection->granted_scopes ?? [],
            'connectedAt' => $connection->connected_at?->toIso8601String(),
            'lastError' => $connection->last_error,
        ];
    }

    /** @return array<string, mixed> */
    public function account(AdAccount $account): array
    {
        return [
            'id' => $account->id,
            'externalId' => $account->external_id,
            'name' => $account->name,
            'businessName' => $account->business_name,
            'currency' => $account->currency,
            'timezone' => $account->timezone_name,
            'metaStatus' => self::META_STATUS[$account->meta_status ?? -1] ?? ($account->meta_status === null ? null : 'other'),
            'metaActive' => $account->meta_status === null || in_array($account->meta_status, [1, 201], true),
            'status' => $account->status->value,
            'connectionId' => $account->connection_id,
            'syncState' => $account->sync_state?->value,
            'syncError' => $account->sync_error,
            'syncErrorCode' => $account->sync_error_code,
            'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
            'insightsThrough' => $account->insights_through?->toDateString(),
            'throttledUntil' => $account->isThrottled() ? $account->throttled_until?->toIso8601String() : null,
            'usagePercent' => $account->usage_percent,
            'dailyCeiling' => $account->daily_budget_ceiling,
            'lifetimeCeiling' => $account->lifetime_budget_ceiling,
        ];
    }

    /** @return array<string, mixed> */
    public function ceilingForm(AdAccount $account): array
    {
        return [
            'accountId' => $account->id,
            'name' => $account->name,
            'externalId' => $account->external_id,
            'currency' => $account->currency,
            'daily' => $account->daily_budget_ceiling === null ? '' : MinorUnits::toDecimal($account->daily_budget_ceiling, $account->currency),
            'lifetime' => $account->lifetime_budget_ceiling === null ? '' : MinorUnits::toDecimal($account->lifetime_budget_ceiling, $account->currency),
        ];
    }

    /** @return array<string, mixed> */
    public function change(Change $change): array
    {
        return [
            'id' => $change->id,
            'action' => $change->action->value,
            'field' => $change->field,
            'status' => $change->status->value,
            'before' => $change->before_value,
            'after' => $change->after_value,
            'currency' => $change->currency,
            'errorMessage' => $change->error_message,
        ];
    }
}
