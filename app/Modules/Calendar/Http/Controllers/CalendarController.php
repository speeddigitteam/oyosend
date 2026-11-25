<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http\Controllers;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\Actions\CalendarManager;
use App\Modules\Calendar\Actions\Preferences;
use App\Modules\Calendar\Enums\AttendeeResponse;
use App\Modules\Calendar\Enums\CalendarView;
use App\Modules\Calendar\Enums\EventColor;
use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Http\EventPresenter;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\Preference;
use App\Modules\Calendar\Support\CalendarRange;
use App\Modules\Calendar\Support\OccurrenceFinder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The calendar page: one view (day, 4 days, week, month, year or schedule) around a date, or search results. */
final class CalendarController
{
    public function __construct(
        private readonly CalendarAccess $access,
        private readonly CalendarManager $calendars,
        private readonly Preferences $preferences,
        private readonly OccurrenceFinder $finder,
        private readonly EventPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);
        $me = $this->access->membership($user);
        $input = $request->validate([
            'view' => ['nullable', Rule::enum(CalendarView::class)],
            'date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2200-01-01'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $preference = $this->preferences->for($me);
        $zone = $this->preferences->timezone($preference);
        $now = CarbonImmutable::now($zone);
        $view = CalendarView::tryFrom((string) ($input['view'] ?? '')) ?? $preference->default_view;
        $anchor = $input['date'] ?? $now->format('Y-m-d');
        $range = CalendarRange::for($view, $anchor, $preference->week_starts_on);

        if ($user->can(CalendarAccess::MANAGE)) {
            $this->calendars->primaryFor($me);
        }

        $followed = $this->access->activeMemberIds(array_values(array_diff($preference->followed_member_ids ?? [], [$me->id])));
        $calendars = $this->access->visibleCalendars($me, $followed);
        $hidden = $preference->hidden_calendar_ids ?? [];
        $shown = array_values(array_diff($calendars->modelKeys(), $hidden));
        $primary = $calendars->first(fn (Calendar $calendar): bool => $calendar->owner_id === $me->id && $calendar->is_primary);
        // Invitations sit on the member's main calendar: hiding it hides them too.
        $guestOf = $primary !== null && in_array($primary->id, $hidden, true) ? [] : $this->access->invitedEventIds($me);
        $search = trim((string) ($input['search'] ?? ''));

        $events = $view === CalendarView::Year
            ? []
            : $this->presenter->present($this->finder->between($shown, $guestOf, $range, $zone), $user, $me, $zone);

        if (! $preference->show_declined) {
            $events = array_values(array_filter($events, static fn (array $event): bool => ($event['myResponse'] ?? null) !== AttendeeResponse::Declined->value));
        }

        return Inertia::render('Modules/Calendar/Pages/Index', [
            'view' => $view->value,
            'date' => $anchor,
            'today' => $now->format('Y-m-d'),
            'now' => $now->format('Y-m-d\TH:i'),
            'range' => ['start' => $range->start, 'end' => $range->end],
            'timezone' => $zone,
            'preferences' => $this->presentPreferences($preference),
            'calendars' => $calendars->map(fn (Calendar $calendar): array => $this->presentCalendar($calendar, $user, $me, $hidden))->values()->all(),
            'events' => $events,
            'members' => $this->members($me),
            'followed' => $followed,
            'search' => $search === '' ? null : $search,
            'results' => $search === '' ? null : $this->search($search, $calendars, $me, $zone),
            'colors' => array_map(static fn (EventColor $color): string => $color->value, EventColor::cases()),
            'can' => [
                'create' => $user->can(CalendarAccess::MANAGE),
                'createShared' => $user->can(CalendarAccess::MANAGE) && $user->can(CalendarAccess::SHARED),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function presentPreferences(Preference $preference): array
    {
        return [
            'timezone' => $preference->timezone,
            'weekStartsOn' => $preference->week_starts_on,
            'timeFormat' => $preference->time_format,
            'defaultView' => $preference->default_view->value,
            'defaultDuration' => $preference->default_duration,
            'defaultReminder' => $preference->default_reminder,
            'showDeclined' => $preference->show_declined,
        ];
    }

    /**
     * @param  list<string>  $hidden
     * @return array<string, mixed>
     */
    private function presentCalendar(Calendar $calendar, User $user, TenantMembership $me, array $hidden): array
    {
        return [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'description' => $calendar->description,
            'color' => $calendar->color->value,
            'visibility' => $calendar->visibility->value,
            'isPrimary' => $calendar->is_primary,
            'shared' => $calendar->isShared(),
            'mine' => $calendar->owner_id === $me->id,
            'owner' => $calendar->owner === null ? null : ['id' => $calendar->owner->id, 'name' => $calendar->owner->user->name],
            'busyOnly' => $this->access->detailLevel($me, $calendar) === CalendarAccess::BUSY,
            'visible' => ! in_array($calendar->id, $hidden, true),
            'editable' => $this->access->canEdit($user, $me, $calendar),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function members(TenantMembership $me): array
    {
        return TenantMembership::query()
            ->where('status', MembershipStatus::Active)
            ->whereKeyNot($me->id)
            ->with('user:id,name')
            ->limit(500)
            ->get()
            ->map(fn (TenantMembership $member): array => ['id' => $member->id, 'name' => $member->user->name])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * Events whose title, place or notes match, on the calendars the member may read in full (whether shown or
     * hidden) and the events they are a guest of. A series is listed once, at its first date.
     *
     * @param  Collection<int, Calendar>  $calendars
     * @return list<array<string, mixed>>
     */
    private function search(string $search, Collection $calendars, TenantMembership $me, string $zone): array
    {
        $readable = $calendars->filter(fn (Calendar $calendar): bool => $this->access->detailLevel($me, $calendar) === CalendarAccess::FULL);
        $calendarIds = $readable->modelKeys();
        $guestOf = $this->access->invitedEventIds($me);

        return CalendarEvent::query()
            ->whereNull('recurring_event_id')
            ->where('status', EventStatus::Confirmed->value)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $own) => $own
                    ->whereIn('calendar_id', $calendarIds === [] ? [''] : $calendarIds)
                    ->where(fn (Builder $open) => $open
                        ->where('visibility', 'default')
                        ->orWhereIn('calendar_id', $readable->filter(fn (Calendar $calendar): bool => $calendar->isShared() || $calendar->owner_id === $me->id)->modelKeys() ?: [''])))
                ->orWhereIn('id', $guestOf === [] ? [''] : $guestOf))
            ->where(fn (Builder $match) => $match
                ->where('title', 'like', Like::contains($search))
                ->orWhere('location', 'like', Like::contains($search))
                ->orWhere('description', 'like', Like::contains($search)))
            ->with('calendar')
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'location' => $event->location,
                'allDay' => $event->is_all_day,
                'start' => $event->is_all_day ? $event->starts_at->format('Y-m-d') : $event->starts_at->setTimezone($zone)->format('Y-m-d\TH:i'),
                'end' => $event->is_all_day ? $event->ends_at->format('Y-m-d') : $event->ends_at->setTimezone($zone)->format('Y-m-d\TH:i'),
                'color' => ($event->color ?? $event->calendar->color)->value,
                'recurring' => $event->recurrence !== null,
            ])->all();
    }
}
