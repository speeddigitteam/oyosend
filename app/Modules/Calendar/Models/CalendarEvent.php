<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Calendar\Enums\EventColor;
use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Enums\EventVisibility;
use App\Modules\Calendar\Enums\ShowAs;
use App\Modules\Calendar\Support\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One event, the root of a recurring series, or an exception to one occurrence of a series.
 *
 * Times: a timed event stores UTC instants in `starts_at` / `ends_at` and the zone it was planned in, which is the
 * zone its recurrence repeats in (so 9:00 stays 9:00 across daylight-saving changes). An all-day event stores
 * floating dates at 00:00 with an exclusive end: the same days everywhere.
 *
 * An exception row has `recurring_event_id` and `original_start` (the UTC start of the occurrence it replaces) and
 * either new details or `status = cancelled`. Guests belong to the series root.
 *
 * @property string $id
 * @property string $calendar_id
 * @property string|null $recurring_event_id
 * @property CarbonImmutable|null $original_start
 * @property EventStatus $status
 * @property string $title
 * @property string|null $description
 * @property string|null $location
 * @property bool $is_all_day
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $timezone
 * @property array<string, mixed>|null $recurrence
 * @property CarbonImmutable|null $recurrence_until
 * @property EventColor|null $color
 * @property ShowAs $show_as
 * @property EventVisibility $visibility
 * @property list<int>|null $reminders
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Calendar $calendar
 * @property-read CalendarEvent|null $series
 * @property-read Collection<int, Attendee> $attendees
 * @property-read TenantMembership|null $creator
 */
class CalendarEvent extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'calendar_events';

    protected $fillable = [
        'calendar_id', 'title', 'description', 'location', 'is_all_day', 'starts_at', 'ends_at', 'timezone',
        'color', 'show_as', 'visibility', 'reminders',
    ];

    protected $attributes = [
        'status' => 'confirmed',
        'show_as' => 'busy',
        'visibility' => 'default',
        'is_all_day' => false,
    ];

    protected function casts(): array
    {
        return [
            'original_start' => 'immutable_datetime',
            'status' => EventStatus::class,
            'is_all_day' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'recurrence' => 'array',
            'recurrence_until' => 'immutable_datetime',
            'color' => EventColor::class,
            'show_as' => ShowAs::class,
            'visibility' => EventVisibility::class,
            'reminders' => 'array',
        ];
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'calendar_id');
    }

    /** @return BelongsTo<CalendarEvent, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurring_event_id');
    }

    /** @return HasMany<CalendarEvent, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(self::class, 'recurring_event_id');
    }

    /** @return HasMany<Attendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class, 'event_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by');
    }

    public function isException(): bool
    {
        return $this->recurring_event_id !== null;
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== null && ! $this->isException();
    }

    public function rule(): ?RecurrenceRule
    {
        return $this->recurrence === null ? null : RecurrenceRule::fromArray($this->recurrence);
    }

    /** The zone the event's wall-clock times live in; all-day dates float, so they are read in UTC. */
    public function wallZone(): string
    {
        return $this->is_all_day ? 'UTC' : $this->timezone;
    }
}
