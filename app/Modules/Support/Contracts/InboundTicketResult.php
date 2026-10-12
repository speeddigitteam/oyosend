<?php

declare(strict_types=1);

namespace App\Modules\Support\Contracts;

/** What happened to an {@see InboundTicketMessage}. */
final readonly class InboundTicketResult
{
    public function __construct(
        public string $ticketId,
        public string $number,
        public string $messageId,
        /** True when the message opened a new ticket; false when it joined an existing one. */
        public bool $created,
        /** True when this exact external id had been delivered before and nothing changed. */
        public bool $duplicate,
    ) {}
}
