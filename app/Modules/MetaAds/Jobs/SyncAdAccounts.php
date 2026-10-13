<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\MetaAds\Actions\AccountSynchronizer;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Models\AdAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Hourly (and on demand for one ad account): reads tracked ad accounts from the Marketing API.
 *
 * Bounded per workspace: at most `sync.max_accounts_per_run` accounts, least recently synced first, and no
 * new account is started once the run has used its time budget. A throttled account is skipped until its
 * `throttled_until`; a failing account never stops the others.
 */
final class SyncAdAccounts implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Seconds; under the `campaigns` supervisor's worker timeout (300). */
    public int $timeout = 240;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [60, 300];

    /** Seconds after which no further ad account is started. */
    private const int TIME_BUDGET = 150;

    public function __construct(public readonly ?string $accountId = null)
    {
        $this->onQueue('campaigns');
    }

    public function handle(AccountSynchronizer $synchronizer): void
    {
        $started = microtime(true);

        $query = AdAccount::query()->with('connection')->where('status', AdAccountStatus::Tracked->value);

        if ($this->accountId !== null) {
            $query->whereKey($this->accountId);
        } else {
            $query->where(static function ($inner): void {
                $inner->whereNull('throttled_until')->orWhere('throttled_until', '<=', now());
            });
        }

        $accounts = $query->orderByRaw('last_synced_at IS NOT NULL, last_synced_at ASC')
            ->limit((int) config('meta_ads.sync.max_accounts_per_run', 5))
            ->get();

        foreach ($accounts as $account) {
            if (microtime(true) - $started > self::TIME_BUDGET) {
                break;
            }

            try {
                $synchronizer->sync($account);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:meta_ads', 'schedule:meta_ads.sync'];
    }
}
