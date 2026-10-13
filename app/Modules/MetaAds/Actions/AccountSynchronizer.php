<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\MetaAds\Api\ApiUsage;
use App\Modules\MetaAds\Api\ErrorKind;
use App\Modules\MetaAds\Api\MarketingApi;
use App\Modules\MetaAds\Api\MetaApiException;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ObjectLevel;
use App\Modules\MetaAds\Enums\SyncState;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Support\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads one tracked ad account from the Marketing API into this workspace's tables, bounded and idempotent.
 *
 * - Structure: campaigns, ad sets and ads (name, status, objective, budget, schedule), at most
 *   `sync.max_pages_per_object` pages of `sync.page_size` each. Objects Meta stops returning are marked
 *   missing only after a complete listing.
 * - Insights: daily account and campaign rows for a bounded window; recent days are read again because Meta
 *   restates them. Rows are upserted on a natural key, so running twice changes nothing.
 * - Quota: the usage headers are read after every call. At or above `rate_limit.pause_at_percent`, or on a
 *   throttling error, work stops and the account is paused until Meta says access returns.
 *
 * Money is parsed from Meta's decimal strings without a float; an insight row whose currency differs from
 * the account's is skipped, never combined.
 */
final class AccountSynchronizer
{
    private const string FIELDS_CAMPAIGN = 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time,updated_time';

    private const string FIELDS_ADSET = 'id,name,status,effective_status,campaign_id,daily_budget,lifetime_budget,budget_remaining,start_time,end_time,updated_time';

    private const string FIELDS_AD = 'id,name,status,effective_status,campaign_id,adset_id,updated_time';

    private const string FIELDS_INSIGHTS = 'spend,impressions,reach,clicks,actions,account_currency,date_start';

    private const int MAX_ACTION_TYPES = 25;

    public function __construct(
        private readonly MarketingApi $api,
        private readonly ConnectionManager $connections,
        private readonly MetaAdsSettings $settings,
        private readonly TenantContext $tenants,
    ) {}

    public function sync(AdAccount $account): SyncState
    {
        $account->loadMissing('connection');
        $connection = $account->connection;

        if ($account->status !== AdAccountStatus::Tracked) {
            return $account->sync_state ?? SyncState::Ok;
        }

        if ($connection === null || ! $connection->isUsable()) {
            return $this->finish($account, SyncState::Error, 'reauthorization_required', __('Authorise Meta again to continue syncing this ad account.'));
        }

        if ($account->isThrottled()) {
            return SyncState::Throttled;
        }

        // Extend a token that is about to expire before using it; a refusal sends the connection to re-authorisation.
        $this->connections->renewIfExpiring($connection);
        $connection->refresh();

        if (! $connection->isUsable()) {
            return $this->finish($account, SyncState::Error, 'reauthorization_required', __('Authorise Meta again to continue syncing this ad account.'));
        }

        $token = (string) $connection->access_token;
        $started = CarbonImmutable::now();
        $usage = ApiUsage::none();
        $partial = false;
        $note = null;

        try {
            foreach (ObjectLevel::cases() as $level) {
                $result = $this->syncLevel($account, $token, $level, $started);
                $usage = $usage->merge($result['usage']);

                if (! $result['complete']) {
                    $partial = true;
                }

                if ($this->overQuota($usage)) {
                    return $this->throttle($account, $usage, true);
                }
            }

            $insights = $this->syncInsights($account, $token, $started);
            $usage = $usage->merge($insights['usage']);
            $partial = $partial || ! $insights['complete'];
            $note = $insights['skipped'] > 0 ? __('Some days were skipped because Meta reported them in a different currency.') : null;

            if ($this->overQuota($usage)) {
                $this->connections->noteUsage($account, $usage);

                return $this->throttle($account, $usage, true);
            }
        } catch (MetaApiException $exception) {
            return $this->handleFailure($account, $connection, $exception, $usage);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish($account, SyncState::Error, 'sync_failed', __('The sync stopped unexpectedly and will be tried again.'), $usage);
        }

        $account->forceFill([
            'structure_synced_at' => $started,
            'last_synced_at' => $started,
            'sync_state' => $partial ? SyncState::Partial : SyncState::Ok,
            'sync_error_code' => $partial ? 'partial' : ($note !== null ? 'currency_mismatch' : null),
            'sync_error' => $partial ? __('Only part of this account was read this time. The rest follows on the next sync.') : $note,
            'throttled_until' => null,
            'usage_percent' => $usage->percent,
        ])->save();

        return $partial ? SyncState::Partial : SyncState::Ok;
    }

    /**
     * @return array{complete: bool, usage: ApiUsage}
     */
    private function syncLevel(AdAccount $account, string $token, ObjectLevel $level, CarbonImmutable $started): array
    {
        $fields = match ($level) {
            ObjectLevel::Campaign => self::FIELDS_CAMPAIGN,
            ObjectLevel::AdSet => self::FIELDS_ADSET,
            ObjectLevel::Ad => self::FIELDS_AD,
        };

        $result = $this->api->paginate(
            $token,
            $account->external_id.'/'.$level->edge(),
            ['fields' => $fields, 'limit' => (int) config('meta_ads.sync.page_size', 100)],
            (int) config('meta_ads.sync.max_pages_per_object', 10),
            function (array $rows) use ($account, $level, $started): void {
                $this->storeObjects($account, $level, $rows, $started);
            },
            fn (ApiUsage $usage): bool => $this->overQuota($usage),
        );

        if ($result['complete']) {
            // A listing that finished: whatever it did not return is no longer there.
            AdObject::query()->where('account_id', $account->id)->where('level', $level->value)
                ->where('last_seen_at', '<', $started)->whereNull('missing_at')
                ->update(['missing_at' => $started]);
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeObjects(AdAccount $account, ObjectLevel $level, array $rows, CarbonImmutable $seen): void
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_string($row['id'] ?? null) && preg_match('/^\d{1,30}$/D', $row['id']) === 1) {
                $ids[] = $row['id'];
            }
        }

        if ($ids === []) {
            return;
        }

        $existing = AdObject::query()->where('account_id', $account->id)->where('level', $level->value)
            ->whereIn('external_id', $ids)->get()->keyBy('external_id');

        DB::transaction(function () use ($rows, $account, $level, $seen, $existing): void {
            foreach ($rows as $row) {
                $id = $row['id'] ?? null;

                if (! is_string($id) || preg_match('/^\d{1,30}$/D', $id) !== 1) {
                    continue;
                }

                $effective = $this->text($row['effective_status'] ?? null, 40);

                // Deleted objects are gone for good; an archived one is kept so its history reads.
                if ($effective === 'DELETED') {
                    continue;
                }

                $campaign = $level === ObjectLevel::Campaign ? $id : $this->id($row['campaign_id'] ?? null);
                $parent = match ($level) {
                    ObjectLevel::Campaign => null,
                    ObjectLevel::AdSet => $this->id($row['campaign_id'] ?? null),
                    ObjectLevel::Ad => $this->id($row['adset_id'] ?? null),
                };

                $object = $existing->get($id) ?? new AdObject([
                    'account_id' => $account->id,
                    'level' => $level,
                    'external_id' => $id,
                    'first_seen_at' => $seen,
                ]);

                $object->fill([
                    'parent_external_id' => $parent,
                    'campaign_external_id' => $campaign,
                    'name' => Str::limit($this->text($row['name'] ?? null, 255) ?? $id, 250, ''),
                    'status' => $this->text($row['status'] ?? null, 24),
                    'effective_status' => $effective,
                    'objective' => $this->text($row['objective'] ?? null, 64),
                    // Meta returns "0" where a budget is not set at this level.
                    'daily_budget' => $this->budget($row['daily_budget'] ?? null),
                    'lifetime_budget' => $this->budget($row['lifetime_budget'] ?? null),
                    'budget_remaining' => $this->budget($row['budget_remaining'] ?? null),
                    'currency' => $account->currency,
                    'start_at' => $this->time($row['start_time'] ?? null),
                    'end_at' => $this->time($row['stop_time'] ?? $row['end_time'] ?? null),
                    'meta_updated_at' => $this->time($row['updated_time'] ?? null),
                    'last_seen_at' => $seen,
                    'missing_at' => null,
                ])->save();
            }
        });
    }

    /**
     * @return array{complete: bool, usage: ApiUsage, skipped: int}
     */
    private function syncInsights(AdAccount $account, string $token, CarbonImmutable $started): array
    {
        $timezone = $this->timezoneOf($account);
        $today = $started->setTimezone($timezone)->startOfDay();
        $lookback = $this->settings->lookbackDays();
        $earliest = $today->subDays($lookback - 1);
        $restate = (int) config('meta_ads.sync.restate_days', 3);

        $since = $account->insights_through !== null
            ? CarbonImmutable::parse($account->insights_through->toDateString(), $timezone)->subDays($restate)
            : $earliest;
        $since = $since->lt($earliest) ? $earliest : $since;

        $usage = ApiUsage::none();
        $complete = true;
        $skipped = 0;
        $maxPages = (int) config('meta_ads.sync.max_insight_pages_per_run', 50);

        foreach ([Insight::LEVEL_ACCOUNT, Insight::LEVEL_CAMPAIGN] as $level) {
            $query = [
                'level' => $level,
                'time_increment' => 1,
                'time_range' => json_encode(['since' => $since->toDateString(), 'until' => $today->toDateString()]),
                'fields' => self::FIELDS_INSIGHTS.($level === Insight::LEVEL_CAMPAIGN ? ',campaign_id' : ''),
                'limit' => (int) config('meta_ads.sync.page_size', 100),
            ];

            $result = $this->api->paginate(
                $token,
                $account->external_id.'/insights',
                $query,
                $maxPages,
                function (array $rows) use ($account, $level, $started, &$skipped): void {
                    $skipped += $this->storeInsights($account, $level, $rows, $started);
                },
                fn (ApiUsage $used): bool => $this->overQuota($used),
            );

            $usage = $usage->merge($result['usage']);
            $complete = $complete && $result['complete'];
        }

        if ($complete) {
            $account->forceFill(['insights_through' => $today->toDateString()])->save();
        }

        return ['complete' => $complete, 'usage' => $usage, 'skipped' => $skipped];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int rows skipped for a currency that is not the account's
     */
    private function storeInsights(AdAccount $account, string $level, array $rows, CarbonImmutable $syncedAt): int
    {
        $skipped = 0;
        $batch = [];
        $now = now();

        foreach ($rows as $row) {
            $date = $row['date_start'] ?? null;

            if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
                continue;
            }

            $currency = is_string($row['account_currency'] ?? null) ? strtoupper($row['account_currency']) : $account->currency;

            if ($currency !== $account->currency) {
                $skipped++;

                continue;
            }

            $object = $level === Insight::LEVEL_ACCOUNT ? $account->external_id : $this->id($row['campaign_id'] ?? null);

            if ($object === null) {
                continue;
            }

            [$actions, $leads] = $this->actions($row['actions'] ?? null);

            $batch[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $this->tenants->requireId(),
                'account_id' => $account->id,
                'level' => $level,
                'object_external_id' => $object,
                'metric_date' => $date,
                'currency' => $account->currency,
                'spend' => MinorUnits::fromDecimal($row['spend'] ?? null, $account->currency),
                'impressions' => MinorUnits::count($row['impressions'] ?? null),
                'reach' => MinorUnits::count($row['reach'] ?? null),
                'clicks' => MinorUnits::count($row['clicks'] ?? null),
                'leads' => $leads,
                'actions' => $actions === [] ? null : json_encode($actions),
                'synced_at' => $syncedAt->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($batch !== []) {
            Insight::query()->upsert(
                $batch,
                ['tenant_id', 'account_id', 'level', 'object_external_id', 'metric_date'],
                ['currency', 'spend', 'impressions', 'reach', 'clicks', 'leads', 'actions', 'synced_at', 'updated_at'],
            );
        }

        return $skipped;
    }

    /**
     * @return array{0: array<string, int>, 1: int|null} counts by action type, and the `lead` count when reported
     */
    private function actions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [[], null];
        }

        $counts = [];

        foreach ($actions as $action) {
            if (! is_array($action) || ! is_string($action['action_type'] ?? null)) {
                continue;
            }

            $type = Str::limit($action['action_type'], 80, '');
            $value = MinorUnits::count($action['value'] ?? null);

            if ($value !== null && count($counts) < self::MAX_ACTION_TYPES) {
                $counts[$type] = $value;
            }
        }

        // `lead` is Meta's total across on-site and pixel leads; the narrower lead types are subsets of it.
        return [$counts, $counts['lead'] ?? null];
    }

    private function handleFailure(AdAccount $account, Connection $connection, MetaApiException $exception, ApiUsage $usage): SyncState
    {
        if ($exception->needsReauthorization()) {
            $this->connections->markNeedsReauthorization($connection, $exception->getMessage());

            return $this->finish($account, SyncState::Error, 'reauthorization_required', $exception->getMessage(), $usage);
        }

        if ($exception->kind === ErrorKind::RateLimit) {
            return $this->throttle($account, $exception->usage ?? $usage, false, $exception->retryAfterSeconds);
        }

        return $this->finish($account, SyncState::Error, $exception->errorCode, $exception->getMessage(), $usage);
    }

    private function throttle(AdAccount $account, ApiUsage $usage, bool $keepData, ?int $retryAfter = null): SyncState
    {
        $max = (int) config('meta_ads.rate_limit.max_backoff_seconds', 3600);
        $seconds = $retryAfter ?? $usage->regainSeconds ?? (int) config('meta_ads.rate_limit.default_backoff_seconds', 900);
        $seconds = max(60, min($max, $seconds));

        $account->forceFill([
            'sync_state' => SyncState::Throttled,
            'sync_error_code' => 'rate_limited',
            'sync_error' => __('Meta is limiting requests for this ad account. Syncing resumes automatically.'),
            'throttled_until' => now()->addSeconds($seconds),
            'usage_percent' => $usage->percent > 0 ? $usage->percent : $account->usage_percent,
            'last_synced_at' => $keepData ? now() : $account->last_synced_at,
        ])->save();

        return SyncState::Throttled;
    }

    private function finish(AdAccount $account, SyncState $state, string $code, string $message, ?ApiUsage $usage = null): SyncState
    {
        $account->forceFill([
            'sync_state' => $state,
            'sync_error_code' => Str::limit($code, 60, ''),
            'sync_error' => Str::limit($message, 290, ''),
            'usage_percent' => $usage !== null && $usage->percent > 0 ? $usage->percent : $account->usage_percent,
        ])->save();

        return $state;
    }

    private function overQuota(ApiUsage $usage): bool
    {
        return $usage->percent >= (int) config('meta_ads.rate_limit.pause_at_percent', 75);
    }

    private function timezoneOf(AdAccount $account): string
    {
        $name = $account->timezone_name;

        if ($name !== null && in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            return $name;
        }

        return $this->settings->timezone();
    }

    private function budget(mixed $value): ?int
    {
        $minor = MinorUnits::fromInteger($value);

        return $minor === null || $minor === 0 ? null : $minor;
    }

    private function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,30}$/D', $value) === 1 ? $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $max, '') : null;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
