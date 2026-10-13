<?php

declare(strict_types=1);

namespace App\Modules\Communication\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Communication\Actions\MessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one outbox message. Idempotent: it does nothing unless the row is still queued and due, so a
 * duplicate delivery of the job, or the scheduler re-dispatching it, cannot send twice.
 */
final class SendMessage implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds; equal to the Horizon supervisor's worker timeout for this queue. */
    public int $timeout = 60;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue('notifications');
        // Wait for the surrounding transaction: the row must be committed before a worker looks for it.
        $this->afterCommit();
    }

    public function handle(MessageDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->messageId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:communication', 'message:'.$this->messageId];
    }
}
