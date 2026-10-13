<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\SmsMarketing\Actions\DeliveryReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Hourly: asks the communication platform about messages that never reported back. Safe to repeat. */
final class ReconcileDeliveries implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 300;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function handle(DeliveryReconciler $reconciler): void
    {
        $reconciler->run();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:sms_marketing', 'schedule:sms_marketing.reconcile'];
    }
}
