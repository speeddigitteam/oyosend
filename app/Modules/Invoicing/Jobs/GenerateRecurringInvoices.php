<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Invoicing\Actions\RecurringRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Entitlement-gated scheduled generation of recurring invoices; idempotent per period. */
final class GenerateRecurringInvoices implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
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

    public function handle(RecurringRunner $runner): void
    {
        $runner->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:invoicing', 'schedule:invoicing.recurring_invoices'];
    }
}
