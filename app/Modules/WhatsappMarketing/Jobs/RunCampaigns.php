<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\WhatsappMarketing\Actions\CampaignManager;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Every minute, for entitled workspaces: starts campaigns whose time has come and gives a campaign that stopped
 * making progress (a restart, a lost job) its next chunk. At most 20 campaigns per run.
 */
final class RunCampaigns implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function handle(CampaignManager $campaigns): void
    {
        $now = CarbonImmutable::now();

        Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get()
            ->each(fn (Campaign $campaign) => $campaigns->start($campaign));

        // Sending but silent for five minutes: the chunk lock makes a duplicate dispatch harmless.
        Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->where('updated_at', '<', $now->subMinutes(5))
            ->orderBy('updated_at')
            ->limit(20)
            ->get()
            ->each(function (Campaign $campaign): void {
                $campaign->touch();
                SendCampaignChunk::dispatch($campaign->id);
            });
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:whatsapp_marketing', 'schedule:whatsapp_marketing.campaigns'];
    }
}
