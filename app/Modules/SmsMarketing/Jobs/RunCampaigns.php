<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\SmsMarketing\Actions\CampaignRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Starts due campaigns, builds their recipient lists and hands a bounded batch to the communication platform.
 * Runs every minute through the entitlement-gated runner on the `campaigns` queue: a workspace that loses the
 * app stops sending at once. Overlapping runs are harmless (a per-workspace lock, atomic claims, idempotent keys).
 */
final class RunCampaigns implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 300;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function handle(CampaignRunner $runner): void
    {
        $runner->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:sms_marketing', 'schedule:sms_marketing.run_campaigns'];
    }
}
