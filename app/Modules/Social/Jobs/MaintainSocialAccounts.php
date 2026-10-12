<?php

declare(strict_types=1);

namespace App\Modules\Social\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\Actions\AccountMaintenance;
use App\Modules\Social\Actions\MetricsCollector;
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

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(AccountMaintenance $accounts, MetricsCollector $metrics): void
    {
        $accounts->run();
        $metrics->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'schedule:social.maintenance'];
    }
}
