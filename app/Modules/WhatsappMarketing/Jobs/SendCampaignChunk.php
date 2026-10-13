<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\WhatsappMarketing\Actions\CampaignSender;
use App\Modules\WhatsappMarketing\Actions\WhatsappAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Sends one bounded chunk of a campaign and queues the next. A per-campaign lock keeps two workers off the same
 * campaign; the per-recipient idempotency key means a repeated chunk cannot send anyone twice.
 */
final class SendCampaignChunk implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 300;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $campaignId)
    {
        $this->onQueue('campaigns');
        $this->afterCommit();
    }

    public function handle(CampaignSender $sender, WhatsappAccess $access): void
    {
        if (! $access->entitled()) {
            return;
        }

        $lock = Cache::lock('whatsapp:campaign:'.$this->campaignId, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $more = $sender->run($this->campaignId);
        } finally {
            $lock->release();
        }

        if ($more) {
            self::dispatch($this->campaignId)->delay(now()->addSeconds(2));
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:whatsapp_marketing', 'campaign:'.$this->campaignId];
    }
}
