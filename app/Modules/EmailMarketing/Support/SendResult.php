<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

use App\Modules\EmailMarketing\Enums\DeliveryStatus;

/**
 * What handing one delivery to the platform came to. `pause` is set when the whole run must stop for a reason
 * that is not the recipient's (plan limit, channel unavailable); the delivery was released back to `pending`.
 */
final readonly class SendResult
{
    public function __construct(
        public ?DeliveryStatus $status,
        public ?string $pause = null,
    ) {}

    public function paused(): bool
    {
        return $this->pause !== null;
    }
}
