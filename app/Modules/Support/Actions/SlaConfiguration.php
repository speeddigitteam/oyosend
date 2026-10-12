<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Models\Calendar;
use App\Modules\Support\Models\SlaPolicy;
use App\Modules\Support\Support\BusinessCalendar;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * SLA policies (targets in integer minutes per priority) and business-hours calendars.
 *
 * A policy or calendar change applies to clocks that start or restart afterwards; running clocks keep
 * their computed due instants.
 */
final class SlaConfiguration
{
    /** One year of minutes: a sanity ceiling, not a recommendation. */
    public const int MAX_MINUTES = 525600;

    /**
     * @param  array<string, mixed>  $attributes  name, calendar_id?, targets, pause_on_waiting, is_active
     */
    public function savePolicy(?SlaPolicy $policy, array $attributes): SlaPolicy
    {
        $targets = $this->targets((array) ($attributes['targets'] ?? []));

        return DB::transaction(function () use ($policy, $attributes, $targets): SlaPolicy {
            $calendarId = $attributes['calendar_id'] ?? null;

            if ($calendarId !== null && $calendarId !== '' && ! Calendar::query()->whereKey((string) $calendarId)->exists()) {
                throw ValidationException::withMessages(['calendar_id' => __('The selected value is not valid.')]);
            }

            $duplicate = SlaPolicy::query()->where('name', (string) $attributes['name'])
                ->when($policy !== null, fn ($query) => $query->whereKeyNot($policy?->id))->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => __('That name is already in use.')]);
            }

            $policy ??= new SlaPolicy;
            $policy->fill([
                'name' => $attributes['name'],
                'calendar_id' => $calendarId === '' ? null : $calendarId,
                'targets' => $targets,
                'pause_on_waiting' => (bool) ($attributes['pause_on_waiting'] ?? true),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            return $policy;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  name, timezone?, hours, holidays?, is_active
     */
    public function saveCalendar(?Calendar $calendar, array $attributes): Calendar
    {
        $timezone = $attributes['timezone'] ?? null;

        if ($timezone === '') {
            $timezone = null;
        }

        if ($timezone !== null && ! in_array((string) $timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => __('Choose a valid timezone.')]);
        }

        try {
            $hours = BusinessCalendar::normalizeHours((array) ($attributes['hours'] ?? []));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['hours' => __($exception->getMessage())]);
        }

        $holidays = [];

        foreach ((array) ($attributes['holidays'] ?? []) as $date) {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', (int) strtotime($date)) !== $date) {
                throw ValidationException::withMessages(['holidays' => __('Holidays are dates written YYYY-MM-DD.')]);
            }

            $holidays[$date] = $date;
        }

        ksort($holidays);

        return DB::transaction(function () use ($calendar, $attributes, $timezone, $hours, $holidays): Calendar {
            $duplicate = Calendar::query()->where('name', (string) $attributes['name'])
                ->when($calendar !== null, fn ($query) => $query->whereKeyNot($calendar?->id))->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => __('That name is already in use.')]);
            }

            $calendar ??= new Calendar;
            $calendar->fill([
                'name' => $attributes['name'],
                'timezone' => $timezone,
                'hours' => $hours,
                'holidays' => array_values($holidays),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            return $calendar;
        });
    }

    /**
     * @param  array<string, mixed>  $targets
     * @return array<string, array{first_response: int, resolution: int}>
     */
    private function targets(array $targets): array
    {
        $clean = [];

        foreach (TicketPriority::cases() as $priority) {
            $row = $targets[$priority->value] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $first = $this->minutes($row['first_response'] ?? 0, "targets.{$priority->value}.first_response");
            $resolution = $this->minutes($row['resolution'] ?? 0, "targets.{$priority->value}.resolution");

            if ($first > 0 && $resolution > 0 && $resolution < $first) {
                throw ValidationException::withMessages(["targets.{$priority->value}.resolution" => __('The resolution target cannot be shorter than the first-response target.')]);
            }

            if ($first > 0 || $resolution > 0) {
                $clean[$priority->value] = ['first_response' => $first, 'resolution' => $resolution];
            }
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['targets' => __('Set at least one target.')]);
        }

        return $clean;
    }

    private function minutes(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw ValidationException::withMessages([$field => __('Enter whole minutes.')]);
        }

        $minutes = (int) $value;

        if ($minutes < 0 || $minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages([$field => __('Enter a number of minutes between 0 and :max.', ['max' => self::MAX_MINUTES])]);
        }

        return $minutes;
    }
}
