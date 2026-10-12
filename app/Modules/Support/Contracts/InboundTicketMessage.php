<?php

declare(strict_types=1);

namespace App\Modules\Support\Contracts;

use Carbon\CarbonImmutable;

/**
 * A customer message that arrived through a channel outside the workspace UI (email, chat, a form
 * on a website). The Support app does not fetch mail itself: a channel integration (the Communication
 * app, a webhook module) builds this value object and hands it to {@see InboundTicketReceiver}.
 *
 * Contract, version 1:
 * - `channel` is a `TicketChannel` value (`email`, `chat`, `phone`, `web`, `api`).
 * - `externalId` identifies the message at its source (an email Message-ID, a chat message id). It is the
 *   idempotency key: delivering the same message twice returns the first result and changes nothing.
 * - `fromEmail` is required and must be a valid address; it is matched to a shared contact by its
 *   normalized email, and stored as the requester when there is none.
 * - `inReplyToTicketNumber` threads the message into an existing ticket (for example parsed from a subject
 *   such as `Re: [TKT-00042] ...`). Unknown or merged numbers are followed or ignored, never guessed.
 * - `body` is plain text. Markup is stored and shown as text. Attachments are not part of version 1.
 */
final readonly class InboundTicketMessage
{
    public function __construct(
        public string $channel,
        public string $externalId,
        public string $fromEmail,
        public ?string $fromName,
        public string $subject,
        public string $body,
        public ?string $inReplyToTicketNumber = null,
        public ?CarbonImmutable $receivedAt = null,
    ) {}
}
