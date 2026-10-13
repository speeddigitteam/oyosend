<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\WhatsappMarketing\Actions\ConversationTimeline;
use App\Modules\WhatsappMarketing\Actions\WhatsappAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Copies a conversation's earlier inbound messages to the timeline of the contact it was just linked to. */
final class BackfillConversationTimeline implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds; below the worker timeout of the supervisor for the default queue. */
    public int $timeout = 55;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $conversationId, public readonly string $contactId)
    {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(ConversationTimeline $timeline, WhatsappAccess $access): void
    {
        if (! $access->entitled()) {
            return;
        }

        $timeline->backfill($this->conversationId, $this->contactId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:whatsapp_marketing', 'conversation:'.$this->conversationId];
    }
}
