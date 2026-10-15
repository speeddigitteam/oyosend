<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\InboxSignals;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Enums\ChatDirection;
use App\Modules\Support\Enums\ChatStatus;
use App\Modules\Support\Events\LiveChatStarted;
use App\Modules\Support\Inbox\LiveChatInboxSource;
use App\Modules\Support\Models\ChatConversation;
use App\Modules\Support\Models\ChatMessage;
use App\Modules\Support\Support\ChatAccess;
use App\Modules\Support\Support\ChatText;
use App\Modules\Support\Support\LiveChatLimits;
use App\Modules\Support\Support\VisitorToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a website chat goes through here: the visitor starting and writing, a member replying, closing
 * and reading. The Support "Live chat" page and the unified inbox source both call it, so the rules live in one
 * place (ADR 0032):
 *
 *  - messages are plain text ({@see ChatText}), 1 to 2000 characters, at most {@see LiveChatLimits::MAX_MESSAGES}
 *    per conversation, numbered by `seq` under the conversation's row lock;
 *  - a visitor writing in a closed conversation opens it again; a member cannot write in a closed one;
 *  - the first member reply to an unassigned conversation assigns it to that member;
 *  - unread is the number of visitor messages since a member last read or answered it.
 *
 * Who may do what is decided by the caller ({@see ChatAccess} for members, the
 * visitor token for visitors). Nothing here creates a shared contact.
 */
final class LiveChatManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InboxSignals $signals,
    ) {}

    /**
     * Start a conversation with the visitor's first message. The token is returned here once and never again; only
     * its SHA-256 is stored.
     *
     * @return array{conversation: ChatConversation, message: ChatMessage, token: string}
     *
     * @throws ValidationException on `text`
     */
    public function start(mixed $text, ?string $name, ?string $email, ?string $pageUrl): array
    {
        $clean = $this->text($text);
        $token = VisitorToken::issue();

        [$conversation, $message] = DB::transaction(function () use ($token, $clean, $name, $email, $pageUrl): array {
            $conversation = ChatConversation::query()->create([
                'visitor_token_hash' => VisitorToken::hash($token),
                'name' => $name,
                'email' => $email,
                'page_url' => $pageUrl,
                'status' => ChatStatus::Open,
                'message_count' => 0,
                'unread_count' => 0,
            ]);
            $message = $this->append($conversation, ChatDirection::In, $clean, null);
            LiveChatStarted::dispatch(['conversation_id' => $conversation->id]);

            return [$conversation, $message];
        });

        return ['conversation' => $conversation, 'message' => $message, 'token' => $token];
    }

    /** The conversation a visitor token belongs to, in the active workspace only. */
    public function forToken(#[\SensitiveParameter] mixed $token): ?ChatConversation
    {
        if (! VisitorToken::wellFormed($token)) {
            return null;
        }

        return ChatConversation::query()->where('visitor_token_hash', VisitorToken::hash((string) $token))->first();
    }

    /**
     * The visitor writes. A closed conversation opens again; the message counts as unread.
     *
     * @throws ValidationException on `text`
     */
    public function receive(ChatConversation $conversation, mixed $text): ChatMessage
    {
        $clean = $this->text($text);

        return DB::transaction(function () use ($conversation, $clean): ChatMessage {
            $locked = $this->lock($conversation);
            $this->ensureRoom($locked);

            if ($locked->isClosed()) {
                $locked->status = ChatStatus::Open;
                $locked->closed_at = null;
            }

            return $this->append($locked, ChatDirection::In, $clean, null);
        });
    }

    /**
     * A member replies. Refused on a closed conversation (the visitor reopens it by writing). The first reply to an
     * unassigned conversation assigns it to the member; replying clears the unread count.
     *
     * @throws ValidationException on `text`
     */
    public function reply(ChatConversation $conversation, mixed $text, TenantMembership $by): ChatMessage
    {
        $clean = $this->text($text);

        return DB::transaction(function () use ($conversation, $clean, $by): ChatMessage {
            $locked = $this->lock($conversation);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages(['text' => self::closedReason()]);
            }

            $this->ensureRoom($locked);
            $locked->assignee_membership_id ??= $by->id;
            $locked->unread_count = 0;

            return $this->append($locked, ChatDirection::Out, $clean, $by);
        });
    }

    /** A member closes the conversation. Closing twice changes nothing. */
    public function close(ChatConversation $conversation, TenantMembership $by): void
    {
        DB::transaction(function () use ($conversation, $by): void {
            $locked = $this->lock($conversation);

            if ($locked->isClosed()) {
                return;
            }

            $locked->status = ChatStatus::Closed;
            $locked->closed_at = Carbon::now();
            $locked->unread_count = 0;
            $locked->save();

            $this->audit->record('support.live_chat.closed', $locked, ['conversation_id' => $locked->id, 'closed_by' => $by->id]);
            $this->signals->changed(LiveChatInboxSource::KEY, $locked->id);
        });
    }

    /** A member read the conversation: nothing is unread any more. A no-op when nothing was. */
    public function markRead(ChatConversation $conversation): void
    {
        if (ChatConversation::query()->whereKey($conversation->id)->where('unread_count', '>', 0)->update(['unread_count' => 0]) > 0) {
            $this->signals->changed(LiveChatInboxSource::KEY, $conversation->id);
        }
    }

    /**
     * Cleaned message text, or a validation error on `text`.
     *
     * @throws ValidationException
     */
    public function text(mixed $raw): string
    {
        $text = match (true) {
            $raw === null => '',
            is_string($raw) => ChatText::message($raw),
            default => null,
        };

        if ($text === null) {
            throw ValidationException::withMessages(['text' => __('This message cannot be read. Write it again as plain text.')]);
        }

        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('Write a message.')]);
        }

        if (mb_strlen($text) > LiveChatLimits::MAX_TEXT) {
            throw ValidationException::withMessages(['text' => __('A message can be at most :max characters.', ['max' => LiveChatLimits::MAX_TEXT])]);
        }

        return $text;
    }

    /** Why a member cannot answer a closed conversation. */
    public static function closedReason(): string
    {
        return __('Closed — the visitor can reopen it by writing again.');
    }

    private function lock(ChatConversation $conversation): ChatConversation
    {
        return ChatConversation::query()->lockForUpdate()->findOrFail($conversation->id);
    }

    private function ensureRoom(ChatConversation $locked): void
    {
        if ($locked->message_count >= LiveChatLimits::MAX_MESSAGES) {
            throw ValidationException::withMessages(['text' => __('This chat has reached its limit of :max messages.', ['max' => LiveChatLimits::MAX_MESSAGES])]);
        }
    }

    /** Adds a message to a conversation that is locked (or created) in the current transaction. */
    private function append(ChatConversation $locked, ChatDirection $direction, string $text, ?TenantMembership $by): ChatMessage
    {
        $now = Carbon::now();
        $message = ChatMessage::query()->create([
            'conversation_id' => $locked->id,
            'seq' => $locked->message_count + 1,
            'direction' => $direction,
            'text' => $text,
            'sent_by_membership_id' => $by?->id,
        ]);

        $locked->message_count++;
        $locked->last_message_at = $now;

        if ($direction === ChatDirection::In) {
            $locked->last_visitor_message_at = $now;
            $locked->unread_count++;
        }

        $locked->save();
        $this->signals->changed(LiveChatInboxSource::KEY, $locked->id);

        return $message;
    }
}
