<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Projects\Models\TimeEntry;

/** Stable boundary for future HRM and Invoicing consumers. */
final class TimeEntryApproved extends TenantDomainEvent
{
    public function __construct(
        public readonly string $timeEntryId,
        public readonly string $projectId,
        public readonly string $membershipId,
        public readonly int $minutes,
        public readonly bool $billable,
        public readonly int $billableRateAmount,
        public readonly int $costRateAmount,
        public readonly string $currency,
    ) {
        parent::__construct();
    }

    public static function from(TimeEntry $entry): self
    {
        return new self($entry->id, $entry->project_id, $entry->membership_id, $entry->minutes, $entry->is_billable, $entry->billable_rate_amount, $entry->cost_rate_amount, $entry->currency);
    }

    public static function name(): string
    {
        return 'projects.time_entry.approved';
    }

    public function payload(): array
    {
        return ['time_entry_id' => $this->timeEntryId, 'project_id' => $this->projectId, 'membership_id' => $this->membershipId, 'minutes' => $this->minutes, 'is_billable' => $this->billable, 'billable_rate_amount' => $this->billableRateAmount, 'cost_rate_amount' => $this->costRateAmount, 'currency' => $this->currency];
    }
}
