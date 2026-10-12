<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Events\MarketingMessageStatusChanged;
use App\Modules\Communication\Models\Message;

/**
 * Re-announces the status of messages queued through the core `ChannelGateway` as the core
 * {@see MarketingMessageStatusChanged} event, so marketing apps never import this module's events.
 */
final class MarketingStatusRelay
{
    /** Listener for the module's own message events (sent, delivered, failed, bounced). */
    public function handle(object $event): void
    {
        $id = method_exists($event, 'payload') ? ($event->payload()['message_id'] ?? null) : null;

        if (! is_string($id)) {
            return;
        }

        $message = Message::query()->find($id);

        if ($message !== null) {
            $this->announce($message);
        }
    }

    public function announce(Message $message): void
    {
        $module = $message->meta[MarketingGateway::META_MODULE] ?? null;

        if (! is_string($module)) {
            return;
        }

        $state = MarketingGateway::stateOf($message->status);

        if (! $state->isFinal()) {
            return;
        }

        event(new MarketingMessageStatusChanged([
            'message_id' => $message->id,
            'module' => $module,
            'campaign_id' => $message->meta[MarketingGateway::META_CAMPAIGN] ?? null,
            'recipient_id' => $message->meta[MarketingGateway::META_RECIPIENT] ?? null,
            'contact_id' => $message->contact_id,
            'channel' => $message->channel->value,
            'state' => $state->value,
            'failure_code' => $message->failure_code,
        ]));
    }
}
