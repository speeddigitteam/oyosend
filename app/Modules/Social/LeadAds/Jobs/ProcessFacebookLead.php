<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\LeadAds\LeadProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fetches one received lead with the Page token and hands it to the CRM. Idempotent: the lead row is claimed
 * atomically and the CRM capture is keyed by the lead-gen id, so a repeated or overlapping run changes nothing.
 * Retries with a wait are scheduled by the processor itself (a delayed copy), not by the queue.
 */
final class ProcessFacebookLead implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /** Seconds; under the worker timeout of the supervisor that works the `webhooks` queue (60). */
    public int $timeout = 50;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30];

    public function __construct(public readonly string $leadId, public readonly bool $force = false)
    {
        $this->onQueue('webhooks');
    }

    public function handle(LeadProcessor $processor): void
    {
        $processor->process($this->leadId, $this->force);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'lead-ad:'.$this->leadId];
    }
}
