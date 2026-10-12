<?php

declare(strict_types=1);

namespace App\Modules\Communication\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Every minute, for entitled workspaces: puts messages that are due and have no live job back on the queue
 * (after a restart, a deploy or a postponement) and returns messages stuck in `sending` to the queue.
 */
final class DispatchDueMessages implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(Outbox $outbox): void
    {
        $now = CarbonImmutable::now();
        $timeout = max(1, (int) config('communication.sending_timeout_minutes', 10));

        // A worker that died after claiming leaves `sending` behind: try again (at-least-once).
        Message::query()
            ->where('status', MessageStatus::Sending->value)
            ->where('sending_at', '<', $now->subMinutes($timeout))
            ->update(['status' => MessageStatus::Queued->value, 'next_attempt_at' => $now, 'dispatched_at' => null]);

        Message::query()
            ->where('status', MessageStatus::Queued->value)
            ->where('next_attempt_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $now->subMinutes(5)))
            ->orderBy('next_attempt_at')
            ->limit(200)
            ->get()
            ->each(fn (Message $message) => $outbox->dispatch($message));
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:communication', 'schedule:communication.dispatch_due'];
    }
}
