<?php

declare(strict_types=1);

namespace App\Modules\Talent\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Talent\Actions\RetentionEnforcer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Anonymizes candidates past their retention date that have no open application. */
final class EnforceRetention implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(RetentionEnforcer $runner): void
    {
        $runner->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:talent', 'schedule:talent.retention'];
    }
}
