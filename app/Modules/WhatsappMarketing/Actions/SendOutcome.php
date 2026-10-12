<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Data\SendReceipt;
use App\Modules\WhatsappMarketing\Models\Message;

/** What handing one message to the outbox produced: our row and the platform's receipt. */
final readonly class SendOutcome
{
    public function __construct(
        public Message $message,
        public SendReceipt $receipt,
    ) {}
}
