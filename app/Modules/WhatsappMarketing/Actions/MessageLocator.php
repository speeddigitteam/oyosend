<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Recipient;

/**
 * Finds our rows for an outbox message the platform reported on. Campaign messages carry the campaign id and
 * the recipient row id; a conversation message carries our message row id as the recipient reference.
 */
final class MessageLocator
{
    /** @return array{recipient: Recipient|null, message: Message|null} */
    public function find(?string $campaignId, ?string $recipientId, ?string $communicationMessageId = null): array
    {
        $recipient = null;
        $message = null;

        if ($recipientId !== null && $recipientId !== '') {
            if ($campaignId !== null && $campaignId !== '') {
                $recipient = Recipient::query()->where('campaign_id', $campaignId)->find($recipientId);
                $message = $recipient?->message_id === null ? null : Message::query()->find($recipient->message_id);
            } else {
                $message = Message::query()->find($recipientId);
            }
        }

        if ($message === null && $communicationMessageId !== null) {
            $message = Message::query()->where('communication_message_id', $communicationMessageId)->first();
        }

        return ['recipient' => $recipient, 'message' => $message];
    }
}
