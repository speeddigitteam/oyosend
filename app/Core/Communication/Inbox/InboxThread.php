<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

/**
 * One conversation opened in the unified inbox, as the viewer may see it (ADR 0030): its newest messages and
 * whether a free-form reply may be written from the inbox right now. Nothing in it is a secret; the text is
 * untrusted and rendered as text.
 */
final readonly class InboxThread
{
    /**
     * @param  string  $sourceKey  The inbox source that owns it (`{module}.{source}`).
     * @param  string  $id  The conversation's id in that source, as in its inbox items.
     * @param  string  $title  The contact's name, the sender's address or the commenter, as in the inbox item.
     * @param  string  $channel  The source's channel (`whatsapp`, `facebook`).
     * @param  string|null  $contactId  The shared contact, when the conversation is linked to one.
     * @param  string  $url  The owning app's own page for this conversation.
     * @param  bool  $canReply  The viewer may write a free-form reply from the inbox now: they hold the reply permission
     *                          for this record and the channel accepts one.
     * @param  string|null  $replyBlockedReason  Why a reply cannot be written now, for a viewer who may reply in
     *                                           principle (the WhatsApp 24-hour window closed, the comment was deleted).
     *                                           Translated; null when there is nothing to explain.
     * @param  list<InboxMessage>  $messages  The newest messages, oldest first.
     * @param  bool  $hasOlder  Older messages exist beyond the ones returned.
     * @param  string|null  $subtitle  Short context under the title: a phone number, a Page and a post.
     */
    public function __construct(
        public string $sourceKey,
        public string $id,
        public string $title,
        public string $channel,
        public ?string $contactId,
        public string $url,
        public bool $canReply,
        public ?string $replyBlockedReason,
        public array $messages,
        public bool $hasOlder,
        public ?string $subtitle = null,
    ) {}
}
