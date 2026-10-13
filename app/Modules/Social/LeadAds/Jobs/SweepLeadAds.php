<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\LeadAds\LeadSweep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Every minute through the entitlement-gated runner: retries due leads, sends waiting ones, housekeeping. */
final class SweepLeadAds implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
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

    public function handle(LeadSweep $sweep): void
    {
        $sweep->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'schedule:social.lead_ads_sweep'];
    }
}
