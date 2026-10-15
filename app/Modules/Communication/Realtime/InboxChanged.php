<?php

declare(strict_types=1);

namespace App\Modules\Communication\Realtime;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * "A conversation of the unified inbox changed", broadcast on the workspace's private inbox channel (ADR 0035).
 *
 * The payload is the source key and the conversation id, nothing else: no text, no name, no number. A page that hears
 * it asks the inbox's own endpoints again, and those decide what the member may see. Queued on `notifications` (the
 * same queue as member notifications) with the workspace in the payload; one try, because a lost signal costs only
 * the wait until the next poll.
 */
final class InboxChanged implements RequiresTenantContext, ShouldBroadcast
{
    public string $queue = 'notifications';

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $source,
        public readonly string $id,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(InboxChannel::name($this->tenantId));
    }

    public function broadcastAs(): string
    {
        return 'inbox.changed';
    }

    /** @return array{source: string, id: string} */
    public function broadcastWith(): array
    {
        return ['source' => $this->source, 'id' => $this->id];
    }
}
