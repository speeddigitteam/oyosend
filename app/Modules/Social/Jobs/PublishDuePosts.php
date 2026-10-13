<?php

declare(strict_types=1);

namespace App\Modules\Social\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\Actions\PostPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Publishes the posts that are due, every minute, through the entitlement-gated runner: a workspace
 * that loses the app stops publishing at once. Publishing is idempotent per target, so a
 * repeated or overlapping run publishes nothing twice.
 */
final class PublishDuePosts implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** The runner may re-run a failed job; a second pass is harmless because claims are atomic. */
    public int $tries = 2;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(PostPublisher $publisher): void
    {
        $publisher->runDue();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'schedule:social.publish_due'];
    }
}
