<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http;

use App\Modules\Calendar\Enums\EditScope;
use App\Modules\Calendar\Enums\EventColor;
use App\Modules\Calendar\Enums\EventVisibility;
use App\Modules\Calendar\Enums\MonthlyMode;
use App\Modules\Calendar\Enums\RecurrenceFrequency;
use App\Modules\Calendar\Enums\ShowAs;
use App\Modules\Calendar\Support\EventChanges;
use App\Modules\Calendar\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads an event form (or a drag) into {@see EventChanges}. Times arrive as wall-clock values in the zone the person
 * planned in (`Y-m-d\TH:i`, or `Y-m-d` with an inclusive last day for an all-day event) and leave in storage form.
 */
final class EventInput
{
    /** The longest a repeating event may last, in days; occurrences are looked up that far back. */
    public const int MAX_REPEATING_DAYS = 31;

    /** The longest any event may last, in days. */
    public const int MAX_DAYS = 366;

    public static function changes(Request $request): EventChanges
    {
        $allDay = $request->boolean('all_day');
        $timeFormat = $allDay ? 'date_format:Y-m-d' : 'date_format:Y-m-d\TH:i';

        $input = $request->validate([
            'calendar_id' => ['required', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'all_day' => ['sometimes', 'boolean'],
            'start' => ['required', 'string', $timeFormat],
            'end' => ['required', 'string', $timeFormat],
            'timezone' => ['required', 'string', 'timezone:all'],
            'recurrence' => ['nullable', 'array'],
            'recurrence.frequency' => ['required_with:recurrence', Rule::enum(RecurrenceFrequency::class)],
            'recurrence.interval' => ['sometimes', 'integer', 'min:1', 'max:'.RecurrenceRule::MAX_INTERVAL],
            'recurrence.weekdays' => ['sometimes', 'array', 'max:7'],
            'recurrence.weekdays.*' => ['integer', 'min:0', 'max:6'],
            'recurrence.monthly' => ['sometimes', Rule::enum(MonthlyMode::class)],
            'recurrence.count' => ['nullable', 'integer', 'min:1', 'max:'.RecurrenceRule::MAX_COUNT, 'prohibits:recurrence.until'],
            'recurrence.until' => ['nullable', 'date_format:Y-m-d'],
            'color' => ['nullable', Rule::enum(EventColor::class)],
            'show_as' => ['sometimes', Rule::enum(ShowAs::class)],
            'visibility' => ['sometimes', Rule::enum(EventVisibility::class)],
            'reminders' => ['sometimes', 'array', 'max:5'],
            'reminders.*' => ['integer', 'min:0', 'max:40320', 'distinct'],
            'guests' => ['sometimes', 'array', 'max:100'],
            'guests.*' => ['string', 'size:26'],
        ]);

        [$startsAt, $endsAt] = self::times($allDay, $input['start'], $input['end'], $input['timezone']);
        $rule = isset($input['recurrence']) ? RecurrenceRule::fromArray($input['recurrence']) : null;
        self::checkLength($startsAt, $endsAt, $rule !== null);

        $reminders = array_values(array_unique(array_map(intval(...), $input['reminders'] ?? [])));
        sort($reminders);

        return new EventChanges(
            attributes: [
                'calendar_id' => $input['calendar_id'],
                'title' => trim((string) ($input['title'] ?? '')),
                'description' => self::text($input['description'] ?? null),
                'location' => self::text($input['location'] ?? null),
                'is_all_day' => $allDay,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $input['timezone'],
                'color' => $input['color'] ?? null,
                'show_as' => $input['show_as'] ?? ShowAs::Busy->value,
                'visibility' => $input['visibility'] ?? EventVisibility::Default->value,
                'reminders' => $reminders === [] ? null : $reminders,
            ],
            setsRecurrence: true,
            recurrence: $rule,
            attendees: array_key_exists('guests', $input) ? array_values($input['guests']) : null,
        );
    }

    /** A drag: new times (and possibly the all-day switch) for an event that keeps everything else. */
    public static function move(Request $request, bool $allDayNow, string $zoneNow): EventChanges
    {
        $allDay = $request->has('all_day') ? $request->boolean('all_day') : $allDayNow;
        $timeFormat = $allDay ? 'date_format:Y-m-d' : 'date_format:Y-m-d\TH:i';
        $input = $request->validate([
            'all_day' => ['sometimes', 'boolean'],
            'start' => ['required', 'string', $timeFormat],
            'end' => ['required', 'string', $timeFormat],
            'timezone' => ['required', 'string', 'timezone:all'],
        ]);
        // A timed event keeps the zone it was planned in; the drag happened in the viewer's zone.
        [$startsAt, $endsAt] = self::times($allDay, $input['start'], $input['end'], $input['timezone']);
        self::checkLength($startsAt, $endsAt, false);

        return new EventChanges([
            'is_all_day' => $allDay,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $zoneNow,
        ]);
    }

    /** @return array{0: EditScope, 1: CarbonImmutable|null} */
    public static function target(Request $request): array
    {
        $input = $request->validate([
            'scope' => ['sometimes', Rule::enum(EditScope::class)],
            'occurrence' => ['nullable', 'string', 'date_format:Y-m-d\TH:i:s\Z'],
        ]);

        $occurrence = isset($input['occurrence']) ? CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $input['occurrence'], 'UTC') : null;

        return [EditScope::tryFrom((string) ($input['scope'] ?? '')) ?? EditScope::All, $occurrence ?: null];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function times(bool $allDay, string $start, string $end, string $zone): array
    {
        try {
            if ($allDay) {
                $from = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
                // The form names the last day; storage keeps the day after it.
                $to = CarbonImmutable::createFromFormat('!Y-m-d', $end, 'UTC')->addDay();
            } else {
                $from = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $start, $zone)->utc();
                $to = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $end, $zone)->utc();
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['start' => __('Enter a valid date and time.')]);
        }

        if ($to->lessThan($from) || ($allDay && $to->equalTo($from))) {
            throw ValidationException::withMessages(['end' => __('The event cannot end before it starts.')]);
        }

        return [$from, $to];
    }

    private static function checkLength(CarbonImmutable $from, CarbonImmutable $to, bool $repeats): void
    {
        $limit = $repeats ? self::MAX_REPEATING_DAYS : self::MAX_DAYS;

        if ($from->diffInDays($to) > $limit) {
            throw ValidationException::withMessages(['end' => $repeats
                ? __('A repeating event can last at most :count days.', ['count' => $limit])
                : __('An event can last at most :count days.', ['count' => $limit])]);
        }
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
