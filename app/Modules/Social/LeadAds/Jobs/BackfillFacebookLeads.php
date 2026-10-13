<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\LeadAds\LeadBackfill;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Fetches a Page's recent leads (manual "Fetch recent leads"), bounded by a day window and a lead budget. */
final class BackfillFacebookLeads implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Seconds; under the worker timeout of the supervisor that works the `webhooks` queue (60). */
    public int $timeout = 55;

    /** @var list<int> */
    public array $backoff = [60];

    public function __construct(public readonly string $pageId, public readonly int $days)
    {
        $this->onQueue('webhooks');
    }

    public function handle(LeadBackfill $backfill): void
    {
        $page = LeadAdsPage::query()->find($this->pageId);

        if ($page !== null) {
            $backfill->run($page, $this->days);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'lead-ad-backfill'];
    }
}
