<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Invoicing\Actions\InvoiceManager;
use App\Modules\Invoicing\Actions\ReminderRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Marks past-due invoices overdue (announcing each once), then sends the reminders that are due. */
final class MaintainReceivables implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(InvoiceManager $invoices, ReminderRunner $reminders): void
    {
        $invoices->markOverdueInvoices();
        $reminders->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:invoicing', 'schedule:invoicing.receivables'];
    }
}
