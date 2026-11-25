<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Support;

use App\Modules\Calendar\Models\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * One event as it appears on the calendar: a single event, one expanded occurrence of a series, or an exception.
 *
 * `start` / `end` are UTC instants for a timed event and UTC midnights (floating dates, end exclusive) for an
 * all-day one. `originalStart` names the occurrence within its series and is what every per-occurrence change sends
 * back; it is null for an event that does not repeat.
 */
final readonly class Occurrence
{
    public function __construct(
        public CalendarEvent $event,
        public CalendarEvent $root,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ?CarbonImmutable $originalStart,
    ) {}

    public function key(): string
    {
        return $this->event->id.':'.($this->originalStart?->getTimestamp() ?? '');
    }
}
