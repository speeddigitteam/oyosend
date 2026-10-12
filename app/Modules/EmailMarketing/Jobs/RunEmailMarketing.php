<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\EmailMarketing\Actions\CampaignRunner;
use App\Modules\EmailMarketing\Actions\SequenceRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One pass of the Email Marketing runner for the active workspace, on the `campaigns` queue: starts due
 * campaigns, works out audiences in chunks, hands out at most the workspace's per-minute capacity and runs the
 * sequence steps that are due. It runs every minute through the entitlement-gated app runner (a workspace that
 * loses the app stops at once) and once right after a member sends or resumes a campaign. A pass is bounded and
 * re-entrant: overlapping passes find nothing to do, and the idempotent send keys make a repeat harmless.
 */
final class RunEmailMarketing implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('campaigns');
    }

    public function handle(CampaignRunner $campaigns, SequenceRunner $sequences): void
    {
        $handed = $campaigns->run();
        $sequences->run($campaigns->leftover($handed));
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:email_marketing', 'schedule:email_marketing.run'];
    }
}
