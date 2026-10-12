<?php

declare(strict_types=1);

namespace App\Modules\Support\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Support\Actions\SupportMaintenance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Auto-closes long-resolved tickets and expires unanswered satisfaction surveys. */
final class RunMaintenance implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(SupportMaintenance $maintenance): void
    {
        $maintenance->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:support', 'schedule:support.maintenance'];
    }
}
