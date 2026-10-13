<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Window;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily housekeeping: forgets menu answers nobody gave and conversation windows older than 400 days (the
 * metered usage records stay in the billing ledger). Conversations and messages are never deleted here.
 */
final class RunMaintenance implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        Conversation::query()
            ->whereNotNull('bot_state_expires_at')
            ->where('bot_state_expires_at', '<', $now)
            ->update(['bot_state' => null, 'bot_state_expires_at' => null]);

        Window::query()->where('expires_at', '<', $now->subDays(400))->delete();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:whatsapp_marketing', 'schedule:whatsapp_marketing.maintenance'];
    }
}
