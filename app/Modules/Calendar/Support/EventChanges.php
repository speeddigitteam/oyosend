<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Support;

/**
 * Validated changes to an event. `attributes` are model columns (times already in storage form: UTC instants, or
 * floating dates for all-day). The repetition and the guests are separate because a single occurrence cannot carry
 * either: `setsRecurrence` says whether `recurrence` was given at all (null then means "does not repeat"), and
 * `attendees` is null when the guest list is left as it is.
 */
final readonly class EventChanges
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>|null  $attendees  Membership ids.
     */
    public function __construct(
        public array $attributes,
        public bool $setsRecurrence = false,
        public ?RecurrenceRule $recurrence = null,
        public ?array $attendees = null,
    ) {}

    /** The same changes without the series-level parts, for one occurrence. */
    public function forOccurrence(): self
    {
        $attributes = $this->attributes;
        unset($attributes['calendar_id']);

        return new self($attributes);
    }
}
