<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Support;

use App\Modules\Calendar\Enums\MonthlyMode;
use App\Modules\Calendar\Enums\RecurrenceFrequency;
use InvalidArgumentException;

/**
 * How an event repeats: a small, declarative subset of RFC 5545 RRULE that covers what people actually choose
 * (daily, weekly on some weekdays, monthly by date or by "the nth / last weekday", yearly), every N periods,
 * for ever, N times or until a date. Stored as JSON on the series root; never evaluated as an expression.
 *
 * Weekdays are 0 (Sunday) to 6 (Saturday). `until` is an inclusive local date of the event.
 */
final readonly class RecurrenceRule
{
    public const int MAX_INTERVAL = 99;

    public const int MAX_COUNT = 730;

    /**
     * @param  list<int>  $weekdays
     */
    public function __construct(
        public RecurrenceFrequency $frequency,
        public int $interval = 1,
        public array $weekdays = [],
        public MonthlyMode $monthly = MonthlyMode::Day,
        public ?int $count = null,
        public ?string $until = null,
    ) {
        if ($interval < 1 || $interval > self::MAX_INTERVAL) {
            throw new InvalidArgumentException('A recurrence interval is between 1 and '.self::MAX_INTERVAL.'.');
        }

        foreach ($weekdays as $day) {
            if ($day < 0 || $day > 6) {
                throw new InvalidArgumentException('A weekday is between 0 (Sunday) and 6 (Saturday).');
            }
        }

        if ($count !== null && ($count < 1 || $count > self::MAX_COUNT)) {
            throw new InvalidArgumentException('A recurrence count is between 1 and '.self::MAX_COUNT.'.');
        }

        if ($count !== null && $until !== null) {
            throw new InvalidArgumentException('A recurrence ends after a count or on a date, not both.');
        }

        if ($until !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) !== 1) {
            throw new InvalidArgumentException('A recurrence end date is Y-m-d.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $weekdays = array_map(intval(...), array_values((array) ($data['weekdays'] ?? [])));
        $weekdays = array_values(array_unique($weekdays));
        sort($weekdays);

        return new self(
            frequency: RecurrenceFrequency::from((string) ($data['frequency'] ?? '')),
            interval: (int) ($data['interval'] ?? 1),
            weekdays: $weekdays,
            monthly: MonthlyMode::tryFrom((string) ($data['monthly'] ?? '')) ?? MonthlyMode::Day,
            count: isset($data['count']) ? (int) $data['count'] : null,
            until: isset($data['until']) && $data['until'] !== '' ? (string) $data['until'] : null,
        );
    }

    /** @return array{frequency: string, interval: int, weekdays: list<int>, monthly: string, count: int|null, until: string|null} */
    public function toArray(): array
    {
        return [
            'frequency' => $this->frequency->value,
            'interval' => $this->interval,
            'weekdays' => $this->frequency === RecurrenceFrequency::Weekly ? $this->weekdays : [],
            'monthly' => $this->frequency === RecurrenceFrequency::Monthly ? $this->monthly->value : MonthlyMode::Day->value,
            'count' => $this->count,
            'until' => $this->until,
        ];
    }

    public function endingOn(?string $until): self
    {
        return new self($this->frequency, $this->interval, $this->weekdays, $this->monthly, null, $until);
    }

    public function withCount(?int $count): self
    {
        return new self($this->frequency, $this->interval, $this->weekdays, $this->monthly, $count, null);
    }
}
