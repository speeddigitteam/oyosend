<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\LeadCapture;
use App\Core\Crm\Data\LeadCaptureResult;
use App\Core\Crm\Data\LeadCaptureStatus;
use Tests\Support\InteractsWithCrmContracts;

/**
 * Stand-in for the CRM's LeadIntake in other apps' tests: records every capture and answers per idempotency key,
 * so a repeated key is `replayed` with the same ids, like the real contract. Bound by
 * {@see InteractsWithCrmContracts::fakeLeadIntake()}.
 */
final class RecordingLeadIntake implements LeadIntake
{
    /** @var list<LeadCapture> */
    public array $captures = [];

    /** @var array<string, LeadCaptureResult> */
    private array $results = [];

    private int $sequence = 0;

    public function __construct(private readonly bool $available = true) {}

    public function capture(LeadCapture $capture): LeadCaptureResult
    {
        $this->captures[] = $capture;

        if (! $this->available) {
            return LeadCaptureResult::unavailable();
        }

        $key = $capture->source.'|'.$capture->idempotencyKey;

        if (isset($this->results[$key])) {
            $first = $this->results[$key];

            return new LeadCaptureResult(LeadCaptureStatus::Replayed, $first->contactId, $first->leadId, $first->contactCreated, $first->leadCreated);
        }

        $this->sequence++;

        return $this->results[$key] = new LeadCaptureResult(
            LeadCaptureStatus::Captured,
            sprintf('fake-contact-%04d', $this->sequence),
            sprintf('fake-lead-%04d', $this->sequence),
            true,
            true,
        );
    }
}
