<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\AccountDiagnostic;
use App\Modules\Social\Models\PageInsight;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\PageAccessReport;
use App\Modules\Social\Platforms\PageInsightsProviders;
use App\Modules\Social\Platforms\PageInsightsSnapshot;
use App\Modules\Social\Platforms\SocialPlatforms;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads Page-level insights for connected accounts and stores one row per account per platform
 * day. Bounded: a few accounts per run, a bounded backfill on first read, a short re-collection
 * window afterwards, and a stop at the platform's first rate-limit signal. A figure the platform
 * did not return is never written as zero, and a figure already stored is never overwritten by
 * "not reported". Tokens are never logged.
 */
final class PageInsightsCollector
{
    public function __construct(
        private readonly SocialPlatforms $platforms,
        private readonly PageInsightsProviders $providers,
        private readonly AccountManager $accounts,
    ) {}

    /**
     * Collect for the accounts that are due, oldest read first.
     *
     * @return int accounts that received insights rows
     */
    public function run(?int $budgetSeconds = null): int
    {
        $started = microtime(true);
        $limit = max(1, (int) config('social.insights.accounts_per_run', 3));
        $stale = now()->subHours(max(1, (int) config('social.insights.refresh_hours', 6)));
        $platformKeys = array_keys(array_filter(
            $this->platforms->all(),
            static fn ($platform): bool => $platform->availability()->available && $platform->capabilities()->insights,
        ));
        $platformKeys = array_values(array_intersect($platformKeys, $this->providers->platforms()));

        if ($platformKeys === []) {
            return 0;
        }

        $accounts = SocialAccount::query()
            ->where('status', AccountStatus::Connected->value)
            ->whereIn('platform', $platformKeys)
            ->get();

        if ($accounts->isEmpty()) {
            return 0;
        }

        $diagnostics = AccountDiagnostic::query()->whereIn('account_id', $accounts->pluck('id'))->get()->keyBy('account_id');

        $due = $accounts->filter(static function (SocialAccount $account) use ($diagnostics, $stale): bool {
            $synced = $diagnostics->get($account->id)?->insights_synced_at;

            return $synced === null || $synced->lt($stale);
        })->sortBy(static fn (SocialAccount $account): int => $diagnostics->get($account->id)?->insights_synced_at?->getTimestamp() ?? 0)
            ->take($limit);

        $stored = 0;

        foreach ($due as $account) {
            if ($budgetSeconds !== null && (microtime(true) - $started) > $budgetSeconds) {
                break;
            }

            $status = $this->collect($account);

            if ($status === 'ok') {
                $stored++;
            }

            if ($status === PageInsightsSnapshot::RATE_LIMITED || $status === PageInsightsSnapshot::THROTTLED) {
                break;
            }
        }

        return $stored;
    }

    /**
     * Read one account now. Returns `ok`, `no_data`, or the reason the read stopped.
     */
    public function collect(SocialAccount $account, ?CarbonImmutable $now = null): string
    {
        $provider = $this->providers->for($account->platform);
        $now ??= CarbonImmutable::now();
        $diagnostic = $this->diagnostic($account);

        if ($provider === null) {
            return $this->finish($diagnostic, 'unavailable', null, $now);
        }

        try {
            $credentials = $this->accounts->credentials($account);
        } catch (PublishFailed) {
            return $this->finish($diagnostic, PageInsightsSnapshot::TOKEN_INVALID, null, $now);
        }

        $zone = new DateTimeZone($provider->dayTimezone());
        $today = $now->setTimezone($zone)->startOfDay();
        $backfilled = $diagnostic->insights_backfilled_at !== null;
        $span = $backfilled
            ? max(1, (int) config('social.insights.recollect_days', 3))
            : min(90, max(1, (int) config('social.insights.backfill_days', 28)));
        $first = $today->subDays($span);

        $snapshot = $provider->daily($credentials, $first, $today);

        DB::transaction(function () use ($account, $snapshot, $now): void {
            $this->store($account, $snapshot, $now);
        });

        $access = null;
        $checkedAt = $diagnostic->tasks_checked_at;
        $recheck = (int) config('social.insights.access_check_hours', 24);

        if (($snapshot->stopped === null || $snapshot->stopped === PageInsightsSnapshot::PERMISSION_MISSING)
            && ($checkedAt === null || $checkedAt->lt($now->subHours(max(1, $recheck))))) {
            $access = $this->checkAccess($account, $diagnostic, $now);
        }

        $status = $snapshot->stopped ?? ($snapshot->isEmpty() ? 'no_data' : 'ok');
        $diagnostic->forceFill([
            'insights_backfilled_at' => $snapshot->stopped === null && ! $snapshot->isEmpty() && ! $backfilled
                ? $now
                : $diagnostic->insights_backfilled_at,
        ]);

        return $this->finish($diagnostic, $status, $snapshot->errorCode, $now, $access);
    }

    /**
     * Refresh the granted permissions and Meta tasks of one account (also the manual "check access").
     */
    public function checkAccess(SocialAccount $account, ?AccountDiagnostic $diagnostic = null, ?CarbonImmutable $now = null): ?PageAccessReport
    {
        $provider = $this->providers->for($account->platform);
        $diagnostic ??= $this->diagnostic($account);
        $now ??= CarbonImmutable::now();

        if ($provider === null) {
            return null;
        }

        try {
            $report = $provider->access($this->accounts->credentials($account));
        } catch (Throwable) {
            return null;
        }

        $diagnostic->forceFill([
            'granted_scopes' => $report->scopes,
            'tasks' => $report->tasks,
            'tasks_status' => $report->status,
            'tasks_checked_at' => $now,
        ])->save();

        return $report;
    }

    private function diagnostic(SocialAccount $account): AccountDiagnostic
    {
        return AccountDiagnostic::query()->firstOrCreate(['account_id' => $account->id]);
    }

    private function finish(AccountDiagnostic $diagnostic, string $status, ?string $errorCode, CarbonImmutable $now, ?PageAccessReport $access = null): string
    {
        $diagnostic->forceFill([
            'insights_status' => $status,
            'insights_error_code' => $errorCode,
            'insights_synced_at' => $now,
        ])->save();

        return $status;
    }

    private function store(SocialAccount $account, PageInsightsSnapshot $snapshot, CarbonImmutable $now): void
    {
        foreach ($snapshot->days as $date => $figures) {
            $figures = array_intersect_key($figures, array_flip(PageInsight::FIGURES));

            if ($figures === []) {
                continue;
            }

            $row = PageInsight::query()->where('account_id', $account->id)->whereDate('metric_date', $date)->first();
            $sources = [];

            foreach (array_keys($figures) as $figure) {
                if (isset($snapshot->sources[$figure])) {
                    $sources[$figure] = $snapshot->sources[$figure];
                }
            }

            if ($row === null) {
                PageInsight::query()->create([
                    'account_id' => $account->id,
                    'metric_date' => $date,
                    ...$figures,
                    'sources' => $sources,
                    'captured_at' => $now,
                ]);

                continue;
            }

            // Only figures that were returned are written; earlier values are never blanked.
            $row->fill([...$figures, 'sources' => [...($row->sources ?? []), ...$sources], 'captured_at' => $now])->save();
        }
    }
}
