<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Communication\Inbox\InboxThread;
use App\Core\Identity\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An inbox source that also lets a member read a conversation and reply to it from the unified inbox (ADR 0030,
 * amending ADR 0020 decision 4). Optional: the source class implements it beside {@see InboxSource}, under the same
 * tag, and the inbox finds it by the source's key. A source without it keeps opening its own app.
 *
 * The inbox asks only after the workspace is entitled to the source's module and the member holds the source's view
 * permission; a reply also needs {@see replyPermission()}. The source still decides everything else:
 *
 *  - it narrows the conversation exactly as its own conversation page does, and answers "not found" for one the
 *    viewer may not see, whether it does not exist, belongs to another workspace or lies outside their scope;
 *  - it never sends anything itself: a reply goes to the owning app's existing action, which applies the channel's
 *    rules (consent, suppression, quiet hours, the WhatsApp 24-hour window, comment rules). A refusal comes back as a
 *    validation error on `text` with a reason a person can act on.
 */
interface InboxReplies
{
    /** Messages returned when the inbox does not ask for more. */
    public const int DEFAULT_LIMIT = 50;

    /** The most messages one thread returns; older ones stay in the owning app. */
    public const int MAX_LIMIT = 200;

    /** The longest reply the inbox accepts; the owning action may allow less. */
    public const int MAX_TEXT = 4096;

    /**
     * The conversation with its newest `$limit` messages (oldest first), or null when it does not exist or the viewer
     * may not see it. Changes nothing. A source whose conversations have an unread state also implements
     * {@see MarksInboxRead}; the inbox calls that only on an explicit POST, after this returned the thread for the
     * same viewer, never while a page loads.
     */
    public function thread(string $id, User $viewer, int $limit = self::DEFAULT_LIMIT): ?InboxThread;

    /**
     * Reply through the owning app's own action, as the viewer's membership, and return the stored message.
     *
     * @throws NotFoundHttpException when the conversation does not exist or the viewer may not see or answer it
     * @throws ValidationException on `text` when the channel or the owning app refuses the reply
     */
    public function reply(string $id, string $text, User $viewer): InboxMessage;

    /** The permission the owning app's own reply route requires; the inbox checks it before {@see reply()}. */
    public function replyPermission(): string;
}
