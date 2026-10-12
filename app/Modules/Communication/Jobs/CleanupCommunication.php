<?php

declare(strict_types=1);

namespace App\Modules\Communication\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Communication\Models\UnsubscribeToken;
use App\Modules\Communication\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Daily: forgets expired unsubscribe tokens and old provider event ids. Messages and the timeline are kept. */
final class CleanupCommunication implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        UnsubscribeToken::query()->where('expires_at', '<', $now)->delete();
        WebhookEvent::query()
            ->where('received_at', '<', $now->subDays((int) config('communication.webhook_retention_days', 90)))
            ->delete();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:communication', 'schedule:communication.cleanup'];
    }
}
