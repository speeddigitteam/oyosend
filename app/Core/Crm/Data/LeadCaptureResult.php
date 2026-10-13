<?php

declare(strict_types=1);

namespace App\Core\Crm\Data;

/** Ids and flags only. On a replay the flags are those of the original capture. */
final readonly class LeadCaptureResult
{
    public function __construct(
        public LeadCaptureStatus $status,
        public ?string $contactId = null,
        public ?string $leadId = null,
        public bool $contactCreated = false,
        public bool $leadCreated = false,
    ) {}

    public static function unavailable(): self
    {
        return new self(LeadCaptureStatus::Unavailable);
    }

    public function wasCaptured(): bool
    {
        return $this->status !== LeadCaptureStatus::Unavailable && $this->leadId !== null;
    }

    public function isReplay(): bool
    {
        return $this->status === LeadCaptureStatus::Replayed;
    }
}
