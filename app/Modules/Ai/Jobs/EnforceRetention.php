<?php

declare(strict_types=1);

namespace App\Modules\Ai\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Ai\Actions\RetentionEnforcer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Deletes conversations past the workspace retention setting and expires stale proposals. */
final class EnforceRetention implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('ai');
    }

    public function handle(RetentionEnforcer $runner): void
    {
        $runner->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:ai', 'schedule:ai.retention'];
    }
}
