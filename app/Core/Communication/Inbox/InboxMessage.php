<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One message of a conversation opened in the unified inbox (ADR 0030). Its text is untrusted customer or member
 * content and is rendered as text only. An attachment is described, never fetched.
 */
final readonly class InboxMessage
{
    /** Written by the customer (or the audience) to the workspace. */
    public const string IN = 'in';

    /** Written by the workspace: a member, a bot, a campaign or the Page. */
    public const string OUT = 'out';

    /** The kinds of attachment the inbox can name with an icon; anything else is described by its label only. */
    public const array ATTACHMENT_KINDS = ['image', 'video', 'audio', 'file'];

    /**
     * @param  string  $direction  {@see self::IN} or {@see self::OUT}.
     * @param  string|null  $text  Plain text; null when the message is only an attachment or cannot be shown.
     * @param  CarbonImmutable  $at  When it was written (UTC).
     * @param  string|null  $authorName  Who wrote it, when the source knows a name.
     * @param  string|null  $status  The channel's delivery state of an outgoing message (`queued`, `sent`, `delivered`,
     *                               `read`, `failed`, `blocked`) or of a comment (`hidden`, `deleted`); null otherwise.
     * @param  string|null  $attachmentLabel  A translated label for what is attached ("Image", "Document"), never the file.
     * @param  string|null  $attachmentKind  One of {@see self::ATTACHMENT_KINDS} when the source stores the attachment's
     *                                       type, so the page can show a matching icon; null otherwise. Optional and
     *                                       last, so a source that does not know it needs no change.
     */
    public function __construct(
        public string $id,
        public string $direction,
        public ?string $text,
        public CarbonImmutable $at,
        public ?string $authorName = null,
        public ?string $status = null,
        public ?string $attachmentLabel = null,
        public ?string $attachmentKind = null,
    ) {
        if (! in_array($direction, [self::IN, self::OUT], true)) {
            throw new InvalidArgumentException('An inbox message goes "in" or "out".');
        }

        if ($attachmentKind !== null && ! in_array($attachmentKind, self::ATTACHMENT_KINDS, true)) {
            throw new InvalidArgumentException('An inbox attachment is an image, a video, audio or a file.');
        }
    }

    public function isOutgoing(): bool
    {
        return $this->direction === self::OUT;
    }
}
