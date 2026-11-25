<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Calendar\Enums\CalendarVisibility;
use App\Modules\Calendar\Enums\EventColor;
use App\Modules\Calendar\Models\Calendar;
use Illuminate\Support\Facades\DB;

/** Creates, changes and removes calendars, and gives every member their own primary calendar. */
final readonly class CalendarManager
{
    public function __construct(private AuditLogger $audit) {}

    /** The member's primary calendar, created on first use and named after them. */
    public function primaryFor(TenantMembership $member): Calendar
    {
        $existing = Calendar::query()->where('owner_id', $member->id)->where('is_primary', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($member): Calendar {
            // Serialise on the membership row so two first visits cannot both create one.
            TenantMembership::query()->whereKey($member->id)->lockForUpdate()->first();
            $existing = Calendar::query()->where('owner_id', $member->id)->where('is_primary', true)->first();

            if ($existing !== null) {
                return $existing;
            }

            $calendar = new Calendar([
                'name' => $member->loadMissing('user')->user->name,
                'color' => EventColor::Blue,
                'visibility' => CalendarVisibility::Busy,
            ]);
            $calendar->owner_id = $member->id;
            $calendar->is_primary = true;
            $calendar->save();

            return $calendar;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $owner): Calendar
    {
        return DB::transaction(function () use ($attributes, $owner): Calendar {
            $calendar = new Calendar($attributes);
            $calendar->owner_id = $owner?->id;

            if ($owner === null) {
                $calendar->visibility = CalendarVisibility::Team;
            }

            $calendar->save();
            $this->audit->record('calendar.calendar.created', $calendar, ['shared' => $owner === null]);

            return $calendar;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Calendar $calendar, array $attributes): Calendar
    {
        return DB::transaction(function () use ($calendar, $attributes): Calendar {
            if ($calendar->isShared()) {
                unset($attributes['visibility']);
            }

            $calendar->fill($attributes);

            if ($calendar->isDirty()) {
                $changed = array_keys($calendar->getDirty());
                $calendar->save();
                $this->audit->record('calendar.calendar.updated', $calendar, ['changed' => $changed]);
            }

            return $calendar;
        });
    }

    /** Removes a calendar and every event on it. A member's primary calendar stays. */
    public function delete(Calendar $calendar): void
    {
        abort_if($calendar->is_primary, 422, __('Your main calendar cannot be deleted.'));

        DB::transaction(function () use ($calendar): void {
            $this->audit->record('calendar.calendar.deleted', $calendar, ['events' => $calendar->events()->count()]);
            $calendar->delete();
        });
    }
}
