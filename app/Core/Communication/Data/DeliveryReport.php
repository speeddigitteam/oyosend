<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use Carbon\CarbonImmutable;

/** The current state of one marketing message. */
final readonly class DeliveryReport
{
    public function __construct(
        public string $messageId,
        public DeliveryState $state,
        public ?string $failureCode = null,
        public ?CarbonImmutable $sentAt = null,
        public ?CarbonImmutable $deliveredAt = null,
        public ?CarbonImmutable $failedAt = null,
    ) {}
}
