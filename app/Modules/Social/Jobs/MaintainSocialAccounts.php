<?php

declare(strict_types=1);

namespace App\Modules\Social\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\Actions\AccountMaintenance;
use App\Modules\Social\Actions\MetricsCollector;
use App\Modules\Social\Actions\PageInsightsCollector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Hourly: renews expiring tokens and collects engagement figures the platforms return. */
final class MaintainSocialAccounts implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AccountMaintenance $accounts, MetricsCollector $metrics, PageInsightsCollector $insights): void
    {
        $accounts->run();
        $metrics->run();

        // Page-level insights: bounded per run and failure-tolerant, so a platform outage never
        // blocks token renewal or post metrics.
        try {
            $insights->run(35);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'schedule:social.maintenance'];
    }
}
