<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Messages\Inbox\DirectMessageInboxSource;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\DirectMessage;
use App\Modules\Social\Messages\Models\MessagingSetting;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Facebook\FacebookMessaging;
use App\Modules\Social\Platforms\Facebook\FacebookMessagingException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything a member does to a direct-message conversation. The ONE code path for a reply: the Social Messages page
 * and the unified inbox (`InboxReplies::reply()`) both call {@see reply()}. Callers authorise first (permission, record
 * scope, account allow-list through {@see ConversationAccess}); this class applies the channel's rules.
 *
 * A reply is text only, sent as the Page through the Send API, and only inside the standard messaging window (24 hours
 * after the person's last message). Outside it nothing is sent: message tags such as HUMAN_AGENT need separate Meta
 * approval and are not built (ADR 0031). A refusal is a validation error on `text` written for a person.
 */
final class DirectMessages
{
    public function __construct(
        private readonly FacebookMessaging $graph,
        private readonly AccountManager $accounts,
        private readonly AuditLogger $audit,
        private readonly InboxSignals $signals,
    ) {}

    /**
     * Why a reply would be refused now, or null when it can be sent. Shown in advance; {@see reply()} decides again.
     */
    public function blockedReason(DirectConversation $conversation): ?string
    {
        if (! MessagingAvailability::platformEnabled($conversation->platform)) {
            return __('Direct messages are not switched on for this installation.');
        }

        $setting = MessagingSetting::query()->where('social_account_id', $conversation->social_account_id)->first();
        $captured = $setting !== null && ($conversation->platform === DirectConversation::INSTAGRAM ? $setting->capturesInstagram() : $setting->is_enabled);

        if (! $captured) {
            return __('Message capture is off for this Page. Switch it on in Social Media, Messages.');
        }

        if ($conversation->account->status !== AccountStatus::Connected || $conversation->account->access_token === null) {
            return __('This Page must be connected again before messages can be sent.');
        }

        if ($this->windowEndsAt($conversation) === null) {
            return __('The 24-hour reply window has closed. The person must write again before the Page can answer.');
        }

        return null;
    }

    /** When the standard messaging window closes, or null when it is closed already. */
    public function windowEndsAt(DirectConversation $conversation): ?CarbonImmutable
    {
        if ($conversation->last_inbound_at === null) {
            return null;
        }

        $ends = CarbonImmutable::instance($conversation->last_inbound_at)->addHours(MessagingAvailability::windowHours());

        return $ends->isFuture() ? $ends : null;
    }

    /**
     * Send a text reply as the Page and store it. Nothing is stored when the platform refuses it.
     *
     * Messenger: `POST /{page-id}/messages` with `messaging_type=RESPONSE` and the person's PSID; Instagram: the same
     * call with the IGSID (FacebookMessaging). Written against the documented API, NOT run live.
     *
     * @throws ValidationException on `text`
     */
    public function reply(DirectConversation $conversation, string $text, TenantMembership $by): DirectMessage
    {
        $text = trim(str_replace("\0", '', $text));
        $max = MessagingAvailability::textMax($conversation->platform);

        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('Write a message first.')]);
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['text' => __('A message can be at most :max characters on this platform.', ['max' => $max])]);
        }

        $conversation->loadMissing('account');
        $blocked = $this->blockedReason($conversation);

        if ($blocked !== null) {
            throw ValidationException::withMessages(['text' => $blocked]);
        }

        $account = $conversation->account;

        try {
            $credentials = $this->accounts->credentials($account);
        } catch (PublishFailed) {
            throw ValidationException::withMessages(['text' => __('This Page must be connected again before messages can be sent.')]);
        }

        // Written before the call, so an echo of this very message that arrives first completes it instead of
        // adding a second copy (MessageIngestor).
        $pending = DirectMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => DirectMessage::OUT,
            'text' => $text,
            'sent_by_membership_id' => $by->id,
            'status' => DirectMessage::SENDING,
            'sent_at' => now(),
        ]);

        try {
            $mid = $this->graph->send($credentials->externalId, $credentials->accessToken, $conversation->platform, $conversation->participant_id, $text);
        } catch (FacebookMessagingException $failure) {
            $pending->delete();

            if ($failure->kind === FacebookMessagingException::TOKEN) {
                $this->accounts->markNeedsReauthorization($account, $failure->getMessage());
            }

            $this->audit->record('social.message.send_failed', $conversation, ['platform' => $conversation->platform, 'code' => $failure->errorCode, 'maybe_sent' => $failure->maybeSent]);

            throw ValidationException::withMessages(['text' => $failure->getMessage()]);
        }

        return DB::transaction(function () use ($conversation, $pending, $mid, $by): DirectMessage {
            $message = $this->complete($pending, $mid, $by);
            $conversation->forceFill([
                'last_message_at' => $message->sent_at,
                'last_message_preview' => mb_substr(preg_replace('/\s+/u', ' ', (string) $message->text) ?? '', 0, 280),
                'unread_count' => 0,
            ])->save();
            $this->audit->record('social.message.sent', $conversation, ['message_id' => $message->id, 'platform' => $conversation->platform]);
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);

            return $message;
        });
    }

    /** The conversation was read here: its unread count is cleared. */
    public function markRead(DirectConversation $conversation): void
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);
        }
    }

    /** Close a conversation (done) or open it again. A new message from the person reopens it anyway. */
    public function setStatus(DirectConversation $conversation, string $status, TenantMembership $by): void
    {
        if (! in_array($status, [DirectConversation::OPEN, DirectConversation::CLOSED], true) || $conversation->status === $status) {
            return;
        }

        DB::transaction(function () use ($conversation, $status, $by): void {
            $conversation->forceFill(['status' => $status])->save();
            $this->audit->record($status === DirectConversation::CLOSED ? 'social.message.conversation_closed' : 'social.message.conversation_reopened', $conversation, ['by' => $by->id]);
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);
        });
    }

    /** Assign a conversation to a member (or to nobody); its department and branch follow the member. */
    public function assign(DirectConversation $conversation, ?TenantMembership $to, TenantMembership $by): void
    {
        DB::transaction(function () use ($conversation, $to, $by): void {
            $conversation->assignOwner($to)->save();
            $this->audit->record('social.message.conversation_assigned', $conversation, ['to' => $to?->id, 'by' => $by->id]);
            $this->signals->changed(DirectMessageInboxSource::keyFor($conversation->platform), $conversation->id);
        });
    }

    /** Record the platform's id on the reply. If its echo was stored first, that row is kept and completed. */
    private function complete(DirectMessage $pending, string $mid, TenantMembership $by): DirectMessage
    {
        $pending->refresh();

        if ($pending->provider_message_id === $mid) {
            $pending->forceFill(['status' => DirectMessage::SENT])->save();

            return $pending;
        }

        try {
            $pending->forceFill(['provider_message_id' => $mid, 'status' => DirectMessage::SENT])->save();

            return $pending;
        } catch (UniqueConstraintViolationException) {
            $pending->delete();
            $echo = DirectMessage::query()->where('provider_message_id', $mid)->firstOrFail();
            $echo->forceFill(['sent_by_membership_id' => $by->id])->save();

            return $echo;
        }
    }
}
