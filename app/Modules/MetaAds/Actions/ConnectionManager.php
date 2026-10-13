<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\MetaAds\Api\ApiUsage;
use App\Modules\MetaAds\Api\MarketingApi;
use App\Modules\MetaAds\Api\MetaApiException;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Events\ConnectionNeedsReauthorization;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\Connection;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The authorisation state machine:
 *
 *   (authorise) -> connected -> needs_reauthorization -> (authorise again) -> connected
 *                      \-> disconnected  (token discarded; tracked ad accounts are removed, history kept)
 *
 * The token is written only here, always through the model's encrypted cast, and is never put in an
 * exception message, an audit property, an event or a log line.
 */
final class ConnectionManager
{
    public const string LIMIT = 'meta_ads.ad_accounts';

    /** Pages of `me/adaccounts` read when discovering (100 each). */
    private const int DISCOVERY_PAGES = 5;

    public function __construct(
        private readonly MarketingApi $api,
        private readonly UsageLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Finish an authorisation: exchange the code, learn who authorised and what was granted, store the
     * token encrypted and list the ad accounts that person can reach.
     *
     * @throws ValidationException
     */
    public function complete(string $code, string $redirectUri, ?TenantMembership $by): Connection
    {
        try {
            $grant = $this->api->exchangeCode($code, $redirectUri);

            try {
                $grant = $this->api->extend($grant->accessToken);
            } catch (MetaApiException) {
                // Keep the short-lived token: it works, and the expiry below tells the person when to reconnect.
            }

            $me = $this->api->get($grant->accessToken, 'me', ['fields' => 'id,name']);
            $userId = $me->data['id'] ?? null;

            if (! is_string($userId) || $userId === '') {
                throw ValidationException::withMessages(['authorization' => __('Meta did not say who authorised. Try again.')]);
            }

            [$granted, $declined] = $this->permissions($grant->accessToken);
        } catch (MetaApiException $exception) {
            throw ValidationException::withMessages(['authorization' => $exception->getMessage()]);
        }

        if (! in_array('ads_read', $granted, true)) {
            throw ValidationException::withMessages([
                'authorization' => __('The ads_read permission was not granted. Authorise again and allow access to your ads.'),
            ]);
        }

        $name = $me->data['name'] ?? null;

        $connection = DB::transaction(function () use ($userId, $name, $grant, $granted, $declined, $by): Connection {
            try {
                $connection = Connection::query()->where('external_user_id', $userId)->lockForUpdate()->first();
                $connection ??= new Connection(['external_user_id' => $userId]);

                $connection->fill([
                    'user_name' => is_string($name) ? Str::limit($name, 250, '') : null,
                    'access_token' => $grant->accessToken,
                    'token_expires_at' => $grant->expiresAt,
                    'token_renewed_at' => now(),
                    'granted_scopes' => $granted,
                    'declined_scopes' => $declined,
                    'status' => ConnectionStatus::Connected,
                    'status_reason' => null,
                    'last_error' => null,
                    'connected_by' => $by->id ?? $connection->connected_by,
                    'connected_at' => now(),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // A concurrent callback stored the same person first; take its row and update the token.
                $connection = Connection::query()->where('external_user_id', $userId)->firstOrFail();
                $connection->fill(['access_token' => $grant->accessToken, 'status' => ConnectionStatus::Connected])->save();
            }

            $this->audit->record('meta_ads.connection.connected', $connection, ['scopes' => $granted]);

            return $connection;
        });

        $this->discoverAccounts($connection);

        return $connection;
    }

    /**
     * Read the ad accounts the authorising person can reach and record them as available (an account
     * already tracked stays tracked; a removed one becomes available again).
     *
     * @return int number of ad accounts listed
     */
    public function discoverAccounts(Connection $connection): int
    {
        $token = $connection->access_token;

        if ($token === null) {
            return 0;
        }

        $seen = 0;

        try {
            $this->api->paginate($token, 'me/adaccounts', [
                'fields' => 'id,account_id,name,currency,timezone_name,account_status,disable_reason,business_name',
                'limit' => 100,
            ], self::DISCOVERY_PAGES, function (array $rows) use ($connection, &$seen): void {
                foreach ($rows as $row) {
                    $seen += $this->recordAccount($connection, $row) ? 1 : 0;
                }
            });
        } catch (MetaApiException $exception) {
            $this->failed($connection, $exception);

            throw ValidationException::withMessages(['authorization' => $exception->getMessage()]);
        }

        $connection->forceFill(['last_checked_at' => now(), 'last_error' => null])->save();

        return $seen;
    }

    /** Start syncing an ad account. Counts against the plan unless it already did. */
    public function track(AdAccount $account): AdAccount
    {
        return DB::transaction(function () use ($account): AdAccount {
            $locked = AdAccount::query()->with('connection')->lockForUpdate()->findOrFail($account->id);

            if ($locked->status === AdAccountStatus::Tracked) {
                return $locked;
            }

            if ($locked->connection === null || ! $locked->connection->isUsable()) {
                throw ValidationException::withMessages(['account' => __('Authorise Meta again before tracking this ad account.')]);
            }

            $this->limits->ensure(self::LIMIT, 1, 'account');
            $locked->forceFill(['status' => AdAccountStatus::Tracked, 'sync_state' => null, 'sync_error' => null, 'sync_error_code' => null])->save();
            $this->audit->record('meta_ads.account.tracked', $locked, ['account' => $locked->external_id]);

            return $locked;
        });
    }

    public function untrack(AdAccount $account): AdAccount
    {
        return DB::transaction(function () use ($account): AdAccount {
            $locked = AdAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->status === AdAccountStatus::Tracked) {
                $locked->forceFill(['status' => AdAccountStatus::Available])->save();
                $this->audit->record('meta_ads.account.untracked', $locked, ['account' => $locked->external_id]);
            }

            return $locked;
        });
    }

    /** Stop using an authorisation: the token is discarded and its tracked ad accounts stop syncing. History stays. */
    public function disconnect(Connection $connection): Connection
    {
        return DB::transaction(function () use ($connection): Connection {
            $locked = Connection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->status === ConnectionStatus::Disconnected) {
                return $locked;
            }

            $locked->fill([
                'status' => ConnectionStatus::Disconnected,
                'status_reason' => null,
                'access_token' => null,
                'token_expires_at' => null,
            ])->save();

            AdAccount::query()->where('connection_id', $locked->id)
                ->where('status', AdAccountStatus::Tracked->value)
                ->update(['status' => AdAccountStatus::Removed->value]);

            $this->audit->record('meta_ads.connection.disconnected', $locked);

            return $locked;
        });
    }

    /** Meta no longer accepts the token: drop it and ask the person to authorise again. */
    public function markNeedsReauthorization(Connection $connection, string $reason): void
    {
        DB::transaction(function () use ($connection, $reason): void {
            $locked = Connection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->status !== ConnectionStatus::Connected) {
                return;
            }

            $locked->fill([
                'status' => ConnectionStatus::NeedsReauthorization,
                'status_reason' => Str::limit($reason, 480, ''),
                'access_token' => null,
                'token_expires_at' => null,
            ])->save();

            $this->audit->record('meta_ads.connection.needs_reauthorization', $locked);
            event(ConnectionNeedsReauthorization::from($locked));
        });
    }

    /**
     * Extend a long-lived token that expires within `$withinDays`, at most once a day. A token Meta no
     * longer accepts sends the connection to "needs reauthorization".
     */
    public function renewIfExpiring(Connection $connection, int $withinDays = 10): bool
    {
        $token = $connection->access_token;

        if ($token === null || $connection->status !== ConnectionStatus::Connected
            || $connection->token_expires_at === null || $connection->token_expires_at->gt(now()->addDays($withinDays))) {
            return false;
        }

        if ($connection->token_renewed_at !== null && $connection->token_renewed_at->gt(now()->subDay())) {
            return false;
        }

        try {
            $grant = $this->api->extend($token);
        } catch (MetaApiException $exception) {
            $this->failed($connection, $exception);

            return false;
        }

        $connection->forceFill([
            'access_token' => $grant->accessToken,
            'token_expires_at' => $grant->expiresAt ?? $connection->token_expires_at,
            'token_renewed_at' => now(),
            'last_error' => null,
        ])->save();

        return true;
    }

    /** Record a failed call on the connection: a rejected token needs authorising again. */
    public function failed(Connection $connection, MetaApiException $exception): void
    {
        if ($exception->needsReauthorization()) {
            $this->markNeedsReauthorization($connection, $exception->getMessage());

            return;
        }

        $connection->forceFill(['last_error' => Str::limit($exception->getMessage(), 290, '')])->save();
    }

    /** The usage Meta reported is kept on the account for display and for the throttle decision. */
    public function noteUsage(AdAccount $account, ApiUsage $usage): void
    {
        $account->forceFill(['usage_percent' => $usage->percent])->save();
    }

    /**
     * @return array{0: list<string>, 1: list<string>} granted, declined permission names
     */
    private function permissions(string $token): array
    {
        $response = $this->api->get($token, 'me/permissions');
        $granted = [];
        $declined = [];

        foreach ($response->rows() as $row) {
            $name = $row['permission'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            if (($row['status'] ?? null) === 'granted') {
                $granted[] = $name;
            } else {
                $declined[] = $name;
            }
        }

        return [$granted, $declined];
    }

    /** @param array<string, mixed> $row */
    private function recordAccount(Connection $connection, array $row): bool
    {
        $externalId = $row['id'] ?? null;
        $currency = $row['currency'] ?? null;

        if (! is_string($externalId) || preg_match('/^act_\d{1,30}$/D', $externalId) !== 1
            || ! is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
            return false;
        }

        $currency = strtoupper($currency);
        $account = AdAccount::query()->where('external_id', $externalId)->first() ?? new AdAccount(['external_id' => $externalId]);

        $attributes = [
            'connection_id' => $connection->id,
            'name' => Str::limit(is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : $externalId, 250, ''),
            'business_name' => is_string($row['business_name'] ?? null) ? Str::limit($row['business_name'], 250, '') : null,
            'currency' => $currency,
            'timezone_name' => is_string($row['timezone_name'] ?? null) ? Str::limit($row['timezone_name'], 60, '') : null,
            'meta_status' => is_numeric($row['account_status'] ?? null) ? (int) $row['account_status'] : null,
            'meta_disable_reason' => is_numeric($row['disable_reason'] ?? null) ? (int) $row['disable_reason'] : null,
        ];

        if (! $account->exists) {
            $attributes['status'] = AdAccountStatus::Available;
        } elseif ($account->status === AdAccountStatus::Removed) {
            $attributes['status'] = AdAccountStatus::Available;
        }

        // A currency change makes the old ceilings meaningless; never carry an amount across currencies.
        if ($account->exists && $account->currency !== $currency) {
            $attributes['daily_budget_ceiling'] = null;
            $attributes['lifetime_budget_ceiling'] = null;
        }

        $account->fill($attributes)->save();

        return true;
    }

    public function expiresSoon(Connection $connection, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $connection->token_expires_at !== null && $connection->token_expires_at->lte($now->addDays(7));
    }
}
