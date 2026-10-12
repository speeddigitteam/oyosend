<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\Recipient;
use Carbon\CarbonInterface;

/**
 * Moves a message or a campaign recipient forward and never backward. Provider events arrive late and out of
 * order (a `sent` after a `delivered`); a failure is only taken for a message that was not yet delivered.
 */
final class StatusProgress
{
    public function message(Message $message, MessageStatus $to, ?string $failureCode, CarbonInterface $at): bool
    {
        $current = Message::query()->find($message->id) ?? $message;

        if (! self::moves($current->status->rank(), $to->rank(), $to === MessageStatus::Failed || $to === MessageStatus::Blocked, in_array($current->status, [MessageStatus::Failed, MessageStatus::Blocked, MessageStatus::Received], true))) {
            return false;
        }

        $current->status = $to;
        $current->failure_code = in_array($to, [MessageStatus::Failed, MessageStatus::Blocked], true) ? $failureCode : null;

        match ($to) {
            MessageStatus::Delivered => $current->delivered_at ??= $at,
            MessageStatus::Read => $current->read_at ??= $at,
            default => null,
        };

        if ($to === MessageStatus::Read) {
            $current->delivered_at ??= $at;
        }

        $current->save();
        $message->setRawAttributes($current->getAttributes(), true);

        return true;
    }

    public function recipient(Recipient $recipient, RecipientState $to, ?string $reason, CarbonInterface $at): bool
    {
        $current = Recipient::query()->find($recipient->id) ?? $recipient;
        $terminal = in_array($current->state, [RecipientState::Failed, RecipientState::Blocked, RecipientState::Skipped], true);
        $failing = $to === RecipientState::Failed || $to === RecipientState::Blocked || $to === RecipientState::Skipped;

        // `pending` is rank 0 too: from pending anything may happen.
        if ($current->state === RecipientState::Pending) {
            $allowed = true;
        } else {
            $allowed = self::moves($current->state->rank(), $to->rank(), $failing, $terminal);
        }

        if (! $allowed) {
            return false;
        }

        $current->state = $to;
        $current->reason = $failing || $to === RecipientState::Pending ? $reason : null;

        match ($to) {
            RecipientState::Queued => $current->queued_at ??= $at,
            RecipientState::Sent => $current->sent_at ??= $at,
            RecipientState::Delivered => $current->delivered_at ??= $at,
            RecipientState::Read => $current->read_at ??= $at,
            RecipientState::Failed, RecipientState::Blocked => $current->failed_at ??= $at,
            default => null,
        };

        if ($to === RecipientState::Read || $to === RecipientState::Delivered) {
            $current->sent_at ??= $at;
        }

        if ($to === RecipientState::Read) {
            $current->delivered_at ??= $at;
        }

        $current->save();
        $recipient->setRawAttributes($current->getAttributes(), true);

        return true;
    }

    /**
     * @param  int  $from  Rank of the current state (0 for terminal and initial states).
     * @param  int  $to  Rank of the new state.
     * @param  bool  $failing  The new state is a failure or a block.
     * @param  bool  $settled  The current state is terminal.
     */
    private static function moves(int $from, int $to, bool $failing, bool $settled): bool
    {
        if ($settled) {
            return false;
        }

        // A failure is possible until the message was delivered (rank 3).
        return $failing ? $from <= 2 : $to > $from;
    }
}
