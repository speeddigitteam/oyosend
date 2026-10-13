<?php

declare(strict_types=1);

namespace App\Modules\Support\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Support\Actions\SlaMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Marks missed first-response and resolution targets, announcing each once. */
final class MonitorSla implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 300;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(SlaMonitor $monitor): void
    {
        $monitor->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:support', 'schedule:support.sla'];
    }
}
