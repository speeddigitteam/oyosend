<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Automation\AutomationRegistry;
use App\Core\Communication\Contracts\Notifier;
use App\Core\Communication\Data\NotificationMessage;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportContext;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Calendar\Actions\CalendarManager;
use App\Modules\Calendar\Actions\ReminderDispatcher;
use App\Modules\Calendar\Enums\AttendeeResponse;
use App\Modules\Calendar\Enums\CalendarVisibility;
use App\Modules\Calendar\Enums\EventStatus;
use App\Modules\Calendar\Models\Attendee;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\Preference;
use App\Modules\Calendar\Reporting\WorkspaceEventsReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->tenant = $this->createTenant('cal-home', ['timezone' => 'Asia/Dhaka']);
    $this->entitle($this->tenant, 'calendar');
    $this->owner = $this->ownerOf($this->tenant);
});

/** The primary calendar of a member, created the way the page does. */
function primaryCalendar(Tenant $tenant, User $user): Calendar
{
    return test()->inTenant($tenant, fn (): Calendar => app(CalendarManager::class)->primaryFor(test()->membershipOf($tenant, $user)));
}

function eventPayload(Calendar $calendar, array $overrides = []): array
{
    return [
        'calendar_id' => $calendar->id,
        'title' => 'Planning',
        'start' => '2026-10-17T13:00',
        'end' => '2026-10-17T14:00',
        'timezone' => 'Asia/Dhaka',
        'all_day' => false,
        ...$overrides,
    ];
}

function calendarPage(string $query = 'view=week&date=2026-10-17'): string
{
    return "/calendar?{$query}";
}

function calendarMember(Tenant $tenant, array $permissions = ['calendar.events.view', 'calendar.events.manage']): User
{
    return test()->addMember($tenant, test()->createRole($tenant, 'Calendar user '.Str::random(4), $permissions));
}

/** A notifier that records what it was asked to send. */
function recordingNotifier(): object
{
    $fake = new class implements Notifier
    {
        /** @var list<array{ids: list<string>, message: NotificationMessage}> */
        public array $sent = [];

        public function notify(array $membershipIds, NotificationMessage $message): int
        {
            $this->sent[] = ['ids' => $membershipIds, 'message' => $message];

            return count($membershipIds);
        }
    };

    app()->instance(Notifier::class, $fake);

    return $fake;
}

describe('isolation', function () {
    it('keeps every calendar table isolated per tenant', function () {
        $this->assertTenantIsolated(Calendar::class, function (Tenant $tenant): Calendar {
            $calendar = new Calendar(['name' => 'Team', 'color' => 'blue', 'visibility' => 'team']);
            $calendar->save();

            return $calendar;
        });

        $this->assertTenantIsolated(CalendarEvent::class, function (Tenant $tenant): CalendarEvent {
            $calendar = new Calendar(['name' => 'Team', 'color' => 'blue', 'visibility' => 'team']);
            $calendar->save();

            return CalendarEvent::query()->create(['calendar_id' => $calendar->id, 'title' => 'x', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'UTC']);
        });

        $this->assertTenantIsolated(Attendee::class, function (Tenant $tenant): Attendee {
            $calendar = new Calendar(['name' => 'Team', 'color' => 'blue', 'visibility' => 'team']);
            $calendar->save();
            $event = CalendarEvent::query()->create(['calendar_id' => $calendar->id, 'title' => 'x', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'UTC']);

            return Attendee::query()->create(['event_id' => $event->id, 'membership_id' => TenantMembership::query()->firstOrFail()->id]);
        });

        $this->assertTenantIsolated(Preference::class, fn (Tenant $tenant): Preference => Preference::query()->create(['membership_id' => TenantMembership::query()->firstOrFail()->id]));
    });

    it('hides another workspace\'s events and calendars behind a 404', function () {
        $calendar = primaryCalendar($this->tenant, $this->owner);
        $member = $this->membershipOf($this->tenant, $this->owner);
        $event = $this->inTenant($this->tenant, function () use ($calendar, $member): CalendarEvent {
            $event = CalendarEvent::query()->create(['calendar_id' => $calendar->id, 'title' => 'x', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'UTC']);
            // A guest row, so answering is a real action in the home workspace.
            Attendee::query()->create(['event_id' => $event->id, 'membership_id' => $member->id]);

            return $event;
        });
        $intruder = $this->createTenant('cal-intruder');
        $this->entitle($intruder, 'calendar');
        $intruderCalendar = primaryCalendar($intruder, $this->ownerOf($intruder));

        $this->assertNotFoundFromOtherTenant('PUT', "calendar/events/{$event->id}", $this->tenant, eventPayload($intruderCalendar), $intruder);
        $this->assertNotFoundFromOtherTenant('PATCH', "calendar/events/{$event->id}/move", $this->tenant, ['start' => '2026-10-17T10:00', 'end' => '2026-10-17T11:00', 'timezone' => 'UTC'], $intruder);
        // The control request really deletes, so the event goes last.
        $this->assertNotFoundFromOtherTenant('POST', "calendar/events/{$event->id}/response", $this->tenant, ['response' => 'accepted'], $intruder);
        $this->assertNotFoundFromOtherTenant('DELETE', "calendar/events/{$event->id}", $this->tenant, [], $intruder);
        $this->assertNotFoundFromOtherTenant('PUT', "calendar/calendars/{$calendar->id}", $this->tenant, ['name' => 'x', 'color' => 'red'], $intruder);
        $this->assertNotFoundFromOtherTenant('DELETE', "calendar/calendars/{$calendar->id}", $this->tenant, [], $intruder);
        $this->assertNotFoundFromOtherTenant('PATCH', "calendar/calendars/{$calendar->id}/visibility", $this->tenant, ['visible' => false], $intruder);
        $this->assertNotFoundFromOtherTenant('DELETE', "calendar/people/{$member->id}", $this->tenant, [], $intruder);
    });

    it('returns 404 for a workspace that is not entitled to the calendar', function () {
        $other = $this->createTenant('cal-off');

        $this->actingAsOwner($other)->get($this->tenantUrl($other, '/calendar'))->assertNotFound();
    });
});

describe('the page', function () {
    it('gives a member a primary calendar and shows the week in their time zone', function () {
        $this->actingAsOwner($this->tenant)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Modules/Calendar/Pages/Index')
                ->where('view', 'week')
                ->where('range', ['start' => '2026-10-11', 'end' => '2026-10-18'])
                ->where('timezone', 'Asia/Dhaka')
                ->where('calendars.0.isPrimary', true)
                ->where('calendars.0.name', $this->owner->name)
                ->where('can.create', true));

        expect($this->inTenant($this->tenant, fn (): int => Calendar::query()->count()))->toBe(1);
    });

    it('stores a timed event in UTC and shows it back in the viewer\'s zone', function () {
        $calendar = primaryCalendar($this->tenant, $this->owner);

        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($calendar, ['reminders' => [10, 60], 'location' => 'Room 2']))
            ->assertRedirect();

        $event = $this->inTenant($this->tenant, fn (): CalendarEvent => CalendarEvent::query()->sole());
        expect($event->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-17 07:00')
            ->and($event->reminders)->toBe([10, 60])
            ->and($event->created_by)->not->toBeNull();

        // A teammate in London reads the same event in their own zone.
        $this->inTenant($this->tenant, fn () => Preference::query()->create(['membership_id' => $this->membershipOf($this->tenant, $this->owner)->id, 'timezone' => 'Europe/London']));

        $this->actingAsOwner($this->tenant)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('timezone', 'Europe/London')
                ->where('events.0.start', '2026-10-17T08:00')
                ->where('events.0.title', 'Planning')
                ->where('events.0.location', 'Room 2'));
    });

    it('takes an all-day event with an inclusive last day', function () {
        $calendar = primaryCalendar($this->tenant, $this->owner);

        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($calendar, ['all_day' => true, 'start' => '2026-10-16', 'end' => '2026-10-17']))
            ->assertRedirect();

        $this->actingAsOwner($this->tenant)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.allDay', true)
                ->where('events.0.start', '2026-10-16')
                ->where('events.0.end', '2026-10-18'));
    });

    it('refuses an event that ends before it starts', function () {
        $calendar = primaryCalendar($this->tenant, $this->owner);

        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($calendar, ['end' => '2026-10-17T12:00']))
            ->assertSessionHasErrors('end');
    });
});

describe('repeating events', function () {
    beforeEach(function () {
        $this->calendar = primaryCalendar($this->tenant, $this->owner);
        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($this->calendar, [
                'title' => 'Stand-up',
                'start' => '2026-10-12T09:00',
                'end' => '2026-10-12T09:15',
                'recurrence' => ['frequency' => 'daily', 'interval' => 1],
            ]))
            ->assertRedirect();
        $this->series = $this->inTenant($this->tenant, fn (): CalendarEvent => CalendarEvent::query()->sole());
        $this->week = fn () => $this->actingAsOwner($this->tenant)->get($this->tenantUrl($this->tenant, calendarPage('view=week&date=2026-10-12')));
    });

    it('expands a series into its occurrences', function () {
        ($this->week)()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 6)
            ->where('events.0.start', '2026-10-12T09:00')
            ->where('events.0.recurring', true)
            ->where('events.0.occurrence', '2026-10-12T03:00:00Z'));
    });

    it('changes one occurrence only', function () {
        $this->actingAsOwner($this->tenant)
            ->put($this->tenantUrl($this->tenant, "/calendar/events/{$this->series->id}"), eventPayload($this->calendar, [
                'title' => 'Long stand-up',
                'start' => '2026-10-14T10:00',
                'end' => '2026-10-14T11:00',
                'scope' => 'this',
                'occurrence' => '2026-10-14T03:00:00Z',
            ]))
            ->assertRedirect();

        ($this->week)()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 6)
            ->where('events.2.title', 'Long stand-up')
            ->where('events.2.start', '2026-10-14T10:00')
            ->where('events.3.title', 'Stand-up'));
    });

    it('splits the series for this and following events', function () {
        $this->actingAsOwner($this->tenant)
            ->put($this->tenantUrl($this->tenant, "/calendar/events/{$this->series->id}"), eventPayload($this->calendar, [
                'title' => 'Late stand-up',
                'start' => '2026-10-15T11:00',
                'end' => '2026-10-15T11:15',
                'recurrence' => ['frequency' => 'daily', 'interval' => 1],
                'scope' => 'following',
                'occurrence' => '2026-10-15T03:00:00Z',
            ]))
            ->assertRedirect();

        $this->series->refresh();
        expect($this->series->recurrence['until'])->toBe('2026-10-14')
            ->and($this->inTenant($this->tenant, fn (): int => CalendarEvent::query()->whereNull('recurring_event_id')->count()))->toBe(2);

        ($this->week)()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 6)
            ->where('events.2.title', 'Stand-up')
            ->where('events.3.title', 'Late stand-up')
            ->where('events.3.start', '2026-10-15T11:00'));
    });

    it('moves the whole series by the change made to one occurrence', function () {
        $this->actingAsOwner($this->tenant)
            ->patch($this->tenantUrl($this->tenant, "/calendar/events/{$this->series->id}/move"), [
                'start' => '2026-10-13T10:30',
                'end' => '2026-10-13T10:45',
                'timezone' => 'Asia/Dhaka',
                'scope' => 'all',
                'occurrence' => '2026-10-13T03:00:00Z',
            ])
            ->assertRedirect();

        ($this->week)()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 6)
            ->where('events.0.start', '2026-10-12T10:30')
            ->where('events.5.start', '2026-10-17T10:30'));
    });

    it('cancels one occurrence and refuses a date that is not in the series', function () {
        $this->actingAsOwner($this->tenant)
            ->delete($this->tenantUrl($this->tenant, "/calendar/events/{$this->series->id}"), ['scope' => 'this', 'occurrence' => '2026-10-13T03:00:00Z'])
            ->assertRedirect();

        expect($this->inTenant($this->tenant, fn () => CalendarEvent::query()->whereNotNull('recurring_event_id')->sole()->status))->toBe(EventStatus::Cancelled);
        ($this->week)()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 5));

        $this->actingAsOwner($this->tenant)
            ->delete($this->tenantUrl($this->tenant, "/calendar/events/{$this->series->id}"), ['scope' => 'this', 'occurrence' => '2026-10-13T04:00:00Z'])
            ->assertSessionHasErrors('occurrence');
    });

    it('ends the series for this and following when deleting', function () {
        $this->actingAsOwner($this->tenant)
            ->delete($this->tenantUrl($this->tenant, "/calendar/events/{$this->series->id}"), ['scope' => 'following', 'occurrence' => '2026-10-15T03:00:00Z'])
            ->assertRedirect();

        ($this->week)()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 3));
    });
});

describe('privacy and sharing', function () {
    beforeEach(function () {
        $this->colleague = calendarMember($this->tenant);
        $this->mine = primaryCalendar($this->tenant, $this->owner);
        $this->inTenant($this->tenant, function (): void {
            CalendarEvent::query()->create(['calendar_id' => $this->mine->id, 'title' => 'Doctor', 'starts_at' => '2026-10-15 04:00:00', 'ends_at' => '2026-10-15 05:00:00', 'timezone' => 'Asia/Dhaka']);
        });
        $this->follow = fn () => $this->actingAsMember($this->tenant, $this->colleague)
            ->post($this->tenantUrl($this->tenant, '/calendar/people'), ['member_id' => $this->membershipOf($this->tenant, $this->owner)->id])
            ->assertRedirect();
        $this->colleaguePage = fn () => $this->actingAsMember($this->tenant, $this->colleague)->get($this->tenantUrl($this->tenant, calendarPage()));
    });

    it('shows a followed teammate only as busy by default', function () {
        ($this->follow)();

        ($this->colleaguePage)()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)
            ->where('events.0.busy', true)
            ->where('events.0.title', null)
            ->missing('events.0.location'));
    });

    it('shows nothing of a private calendar, even to the workspace owner', function () {
        $this->inTenant($this->tenant, fn () => $this->mine->forceFill(['visibility' => CalendarVisibility::Private])->save());
        ($this->follow)();

        ($this->colleaguePage)()->assertInertia(fn (AssertableInertia $page) => $page->has('events', 0));

        $theirs = primaryCalendar($this->tenant, $this->colleague);
        $this->inTenant($this->tenant, fn () => $theirs->forceFill(['visibility' => CalendarVisibility::Private])->save());
        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/people'), ['member_id' => $this->membershipOf($this->tenant, $this->colleague)->id]);
        $event = $this->inTenant($this->tenant, fn (): CalendarEvent => CalendarEvent::query()->create(['calendar_id' => $theirs->id, 'title' => 'Secret', 'starts_at' => '2026-10-15 04:00:00', 'ends_at' => '2026-10-15 05:00:00', 'timezone' => 'Asia/Dhaka']));

        $this->actingAsOwner($this->tenant)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('events', fn ($events) => collect($events)->pluck('title')->doesntContain('Secret')));
        $this->actingAsOwner($this->tenant)
            ->delete($this->tenantUrl($this->tenant, "/calendar/events/{$event->id}"))
            ->assertNotFound();
    });

    it('shows details of a team calendar but keeps a private event busy', function () {
        $this->inTenant($this->tenant, function (): void {
            $this->mine->forceFill(['visibility' => CalendarVisibility::Team])->save();
            CalendarEvent::query()->create(['calendar_id' => $this->mine->id, 'title' => 'Salary talk', 'visibility' => 'private', 'starts_at' => '2026-10-16 04:00:00', 'ends_at' => '2026-10-16 05:00:00', 'timezone' => 'Asia/Dhaka']);
        });
        ($this->follow)();

        ($this->colleaguePage)()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 2)
            ->where('events.0.title', 'Doctor')
            ->where('events.0.editable', false)
            ->where('events.1.busy', true)
            ->where('events.1.title', null));

        $doctor = $this->inTenant($this->tenant, fn (): CalendarEvent => CalendarEvent::query()->where('title', 'Doctor')->sole());
        $this->actingAsMember($this->tenant, $this->colleague)
            ->put($this->tenantUrl($this->tenant, "/calendar/events/{$doctor->id}"), eventPayload($this->mine))
            ->assertForbidden();
    });

    it('never searches what a viewer may not read', function () {
        ($this->follow)();

        $this->actingAsMember($this->tenant, $this->colleague)
            ->get($this->tenantUrl($this->tenant, calendarPage('search=Doctor')))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results', []));

        $this->actingAsOwner($this->tenant)
            ->get($this->tenantUrl($this->tenant, calendarPage('search=doc')))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.0.title', 'Doctor'));
    });
});

describe('guests', function () {
    beforeEach(function () {
        $this->guest = calendarMember($this->tenant);
        $this->calendar = primaryCalendar($this->tenant, $this->owner);
        $this->inTenant($this->tenant, fn () => $this->calendar->forceFill(['visibility' => CalendarVisibility::Private])->save());
        $this->notifier = recordingNotifier();
        $this->guestId = $this->membershipOf($this->tenant, $this->guest)->id;

        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($this->calendar, ['guests' => [$this->guestId]]))
            ->assertRedirect();
        $this->event = $this->inTenant($this->tenant, fn (): CalendarEvent => CalendarEvent::query()->sole());
    });

    it('invites a teammate, who sees the event on a private calendar and answers', function () {
        expect($this->notifier->sent)->toHaveCount(1)
            ->and($this->notifier->sent[0]['ids'])->toBe([$this->guestId])
            ->and($this->notifier->sent[0]['message']->titleKey)->toBe('You are invited to :title');

        $this->actingAsMember($this->tenant, $this->guest)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.title', 'Planning')
                ->where('events.0.myResponse', 'needs_action')
                ->where('events.0.editable', false));

        $this->actingAsMember($this->tenant, $this->guest)
            ->post($this->tenantUrl($this->tenant, "/calendar/events/{$this->event->id}/response"), ['response' => 'accepted'])
            ->assertRedirect();

        expect($this->inTenant($this->tenant, fn () => Attendee::query()->sole()->response))->toBe(AttendeeResponse::Accepted)
            ->and($this->notifier->sent)->toHaveCount(2)
            ->and($this->notifier->sent[1]['message']->titleKey)->toBe(':name accepted :title');
    });

    it('does not let someone who was not invited answer', function () {
        $stranger = calendarMember($this->tenant);

        $this->actingAsMember($this->tenant, $stranger)
            ->post($this->tenantUrl($this->tenant, "/calendar/events/{$this->event->id}/response"), ['response' => 'accepted'])
            ->assertNotFound();
    });

    it('sends reminders when they fall due, once', function () {
        $this->inTenant($this->tenant, fn () => $this->event->forceFill(['reminders' => [10]])->save());
        $this->notifier->sent = [];
        // 13:00 Dhaka is 07:00 UTC; the 10-minute reminder is due at 06:50 UTC.
        $run = fn (string $at) => $this->inTenant($this->tenant, fn (): int => app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::parse($at, 'UTC')));

        expect($run('2026-10-17 06:45'))->toBe(0)
            ->and($run('2026-10-17 06:52'))->toBe(2)
            ->and($this->notifier->sent[0]['message']->titleKey)->toBe('Reminder: :title')
            ->and($this->notifier->sent[0]['message']->dedupeKey)->toBe('cal-rem:'.$this->event->id.':'.CarbonImmutable::parse('2026-10-17 07:00', 'UTC')->getTimestamp().':10')
            ->and($run('2026-10-17 07:30'))->toBe(0);
    });
});

describe('permissions', function () {
    it('lets a reader see but not add', function () {
        $reader = calendarMember($this->tenant, ['calendar.events.view']);
        $calendar = primaryCalendar($this->tenant, $this->owner);

        $this->actingAsMember($this->tenant, $reader)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false)->where('calendars', []));

        $this->actingAsMember($this->tenant, $reader)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($calendar))
            ->assertForbidden();
    });

    it('keeps workspace calendars to those who manage them', function () {
        $member = calendarMember($this->tenant);

        $this->actingAsMember($this->tenant, $member)
            ->post($this->tenantUrl($this->tenant, '/calendar/calendars'), ['name' => 'Holidays', 'color' => 'green', 'shared' => true])
            ->assertForbidden();

        $this->actingAsOwner($this->tenant)
            ->post($this->tenantUrl($this->tenant, '/calendar/calendars'), ['name' => 'Holidays', 'color' => 'green', 'shared' => true])
            ->assertRedirect();
        $shared = $this->inTenant($this->tenant, fn (): Calendar => Calendar::query()->whereNull('owner_id')->sole());

        $this->actingAsMember($this->tenant, $member)
            ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($shared))
            ->assertSessionHasErrors('calendar_id');

        $this->actingAsMember($this->tenant, $member)
            ->get($this->tenantUrl($this->tenant, calendarPage()))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('calendars.1.shared', true)->where('calendars.1.editable', false));
    });

    it('keeps the primary calendar from being deleted', function () {
        $calendar = primaryCalendar($this->tenant, $this->owner);

        $this->actingAsOwner($this->tenant)
            ->delete($this->tenantUrl($this->tenant, "/calendar/calendars/{$calendar->id}"))
            ->assertStatus(422);
    });

    it('remembers hidden calendars and preferences per member', function () {
        $calendar = primaryCalendar($this->tenant, $this->owner);

        $this->actingAsOwner($this->tenant)
            ->patch($this->tenantUrl($this->tenant, "/calendar/calendars/{$calendar->id}/visibility"), ['visible' => false])
            ->assertRedirect();
        $this->actingAsOwner($this->tenant)
            ->put($this->tenantUrl($this->tenant, '/calendar/preferences'), [
                'timezone' => 'America/New_York', 'week_starts_on' => 6, 'time_format' => '24h', 'default_view' => 'month',
                'default_duration' => 30, 'default_reminder' => null, 'show_declined' => false,
            ])
            ->assertRedirect();

        $this->actingAsOwner($this->tenant)
            ->get($this->tenantUrl($this->tenant, '/calendar?date=2026-10-17'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('view', 'month')
                ->where('timezone', 'America/New_York')
                ->where('preferences.weekStartsOn', 6)
                ->where('range.start', '2026-09-26')
                ->where('calendars.0.visible', false));
    });
});

describe('what it publishes', function () {
    it('registers its trigger and report, and reports only workspace calendars', function () {
        expect(array_keys(app(AutomationRegistry::class)->registeredTriggers()))->toContain('calendar.event.created')
            ->and(collect(app()->tagged(ReportProvider::TAG))->map(fn (object $p): string => $p::class))->toContain(WorkspaceEventsReport::class);

        $personal = primaryCalendar($this->tenant, $this->owner);
        $this->actingAsOwner($this->tenant)->post($this->tenantUrl($this->tenant, '/calendar/calendars'), ['name' => 'Office', 'color' => 'teal', 'shared' => true]);
        $shared = $this->inTenant($this->tenant, fn (): Calendar => Calendar::query()->whereNull('owner_id')->sole());

        foreach ([$personal, $shared] as $calendar) {
            $this->actingAsOwner($this->tenant)
                ->post($this->tenantUrl($this->tenant, '/calendar/events'), eventPayload($calendar, ['start' => now()->format('Y-m-d\TH:i'), 'end' => now()->addHour()->format('Y-m-d\TH:i')]))
                ->assertRedirect();
        }

        $rows = $this->inTenant($this->tenant, fn () => app(WorkspaceEventsReport::class)->run(new ReportContext(
            $this->owner, AccessScope::All, '2020-01-01', '2036-01-01', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2036-01-01'), 'UTC', [], 100,
        ))->rows);

        expect($this->inTenant($this->tenant, fn (): int => CalendarEvent::query()->count()))->toBe(2)
            ->and($rows)->toBe([['calendar' => 'Office', 'events' => 1, 'all_day' => 0, 'repeating' => 0]]);
    });
});
