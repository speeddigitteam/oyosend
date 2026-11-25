# Calendar

Module key `calendar`, category Productivity, free offer, depends on `core` only. A team calendar in the shape people
know from Google Calendar: personal and workspace calendars, day / 4-day / week / month / year / schedule views,
drag and drop, repeating events with "this / this and following / all" changes, guests who answer, reminders, and
every member's own time zone. It follows the standard module shape in `docs/MODULES.md`; the two things that are new
are explained in sections 2 (privacy by person) and 3 (time).

## 1. What it is

| Table | What a row is |
|---|---|
| `calendar_calendars` | A calendar. `owner_id` set: a member's personal calendar (one `is_primary`, created on first visit, named after them). `owner_id` null: a workspace calendar everyone sees. `color` is one of 11 palette keys (`Enums\EventColor`), `visibility` (`private` / `busy` / `team`) applies to personal calendars only |
| `calendar_events` | A single event, the root of a repeating series (`recurrence` JSON), or an exception to one occurrence of a series (`recurring_event_id` + `original_start`, with new details or `status = cancelled`) |
| `calendar_event_attendees` | A member invited to an event (always the series root) and their answer: `needs_action`, `accepted`, `tentative`, `declined` |
| `calendar_preferences` | One member's settings: own time zone (null: the workspace's), week start (Saturday, Sunday or Monday), 12/24-hour clock, default view, default length and notification, show declined events, hidden calendar ids, followed teammates |

Columns and indexes: `docs/DATABASE.md` section 3k6.

Writes go through `Actions\EventManager` (events, answers), `Actions\CalendarManager` (calendars, the primary calendar)
and `Actions\Preferences`. Each change is audited inside its transaction (`calendar.event.created|updated|deleted|responded`,
`calendar.calendar.created|updated|deleted`).

## 2. Who sees what: privacy by person, not by scope

A calendar is private by person. The module therefore does **not** use `HasRecordOwner` / `RecordAccess` scopes, and
its permissions are not scopable: a role with an `all` scope elsewhere must not read a colleague's diary.
`Access\CalendarAccess` decides everything:

- **Your own calendars**: everything, and you change them (with `calendar.events.manage`).
- **Workspace calendars**: everyone holding `calendar.events.view` sees them in full; only members holding
  `calendar.shared.manage` (and `calendar.events.manage`) create them and change their events.
- **A teammate's calendar** appears only after you add the teammate ("Search for people"), and then only as its owner
  allows: `team` shows details, `busy` shows busy blocks with no title, place, notes or guests (free events are left
  out), `private` shows nothing. A new primary calendar is `busy`.
- **An event marked private** on a team-visible calendar shows as busy to everyone but its owner and guests.
- **A guest** sees the event they were invited to in full, whatever its calendar, and can answer. Invitations sit on
  the guest's primary calendar (hiding it hides them).
- An event you may not see is a **404** on every route; one you see but may not change is a 403.

What leaves the module follows the same line: search only covers what the reader may read in full; the audit label is
the title only for a workspace calendar or a team-visible event, otherwise "Private event"; the automation trigger and
the report cover **workspace calendars only**, because a workflow runs as whoever published it and a report reader may
not be allowed to see someone's personal calendar.

## 3. Time, for a calendar used anywhere

- A timed event stores UTC instants (`starts_at`, `ends_at`) and the IANA zone it was planned in (`timezone`). A
  series repeats in that zone, so 9:00 stays 9:00 across a daylight-saving change while its UTC instant moves
  (`Support\RecurrenceExpander`, tested with New York's change).
- An all-day event stores floating dates at 00:00 with an exclusive end: the same days for everyone. The form and the
  API take the last day inclusive.
- The page works in the **viewer's** zone (their preference, else the workspace's): the server sends every time as
  wall-clock text in that zone (`2026-10-17T13:00`), so no browser zone is involved. The editor shows a timed event in
  its own zone (converted in the browser with `Intl`, `lib/zones.ts`), so editing someone's London meeting from Dhaka
  keeps it a London meeting. The time grid labels its gutter with the zone's offset (`GMT+06`).
- Weeks start on Saturday, Sunday or Monday (the person's choice). "Every working day" means Sunday to Thursday when
  the week starts on Saturday (Bangladesh and much of the Middle East), otherwise Monday to Friday, and the choice
  names the days.

## 4. Repeating events

`Support\RecurrenceRule` is a declarative subset of RFC 5545 RRULE, stored as JSON and never evaluated: daily, weekly
on chosen weekdays (weeks start on Monday, as in RFC 5545), monthly on the same date / the nth weekday / the last
weekday, yearly; every 1 to 99 periods; for ever, 1 to 730 times, or until a date. A date a month does not have is
skipped, not moved (the 31st, 29 February). `recurrence_until` caches the last occurrence start (null for ever) so
the range query stays indexed. A repeating event lasts at most 31 days (occurrences are looked up that far back); any
event at most 366 days.

`Support\OccurrenceFinder` loads what a range shows (SQL windows widened by a day because all-day dates float) and
expands each series, applying exceptions: an exception replaces its occurrence wherever it was moved, a cancelled one
removes it. Changing a repeating event asks for the scope:

| Scope | Change | Delete |
|---|---|---|
| This event | Creates or updates the exception for that occurrence | A cancelled exception |
| This and following | Ends the series the day before (a count becomes the number already held) and starts a new series there with the changes and the same guests; exceptions after it are dropped | Ends the series before it |
| All events | Changes the series; a moved occurrence moves the series by the same number of days and to the new time of day (weekly days shift with it) | Deletes the series |

The occurrence is named by its original UTC start (`occurrence`, `2026-10-14T03:00:00Z`) and checked against the
series (422 if it is not part of it).

## 5. Guests and notifications

Guests are active workspace members (up to 100), chosen in the editor; the organiser (the calendar's owner, or for a
workspace calendar whoever created the event) is never a guest. Everything is sent through the core `Notifier`
contract, so nothing is sent without the communication app and nothing claims otherwise:

- An invitation to each new guest (`calendar.invitations`, dedupe `cal-inv:{event}`).
- The organiser hears each answer (`calendar.responses`).
- **Reminders**: up to 5 per event, 0 minutes to 4 weeks before the start; for an all-day event counted from 9:00 on
  the day in the event's zone. The scheduled job `calendar.reminders` (`Jobs\SendCalendarReminders`, every minute,
  `notifications` queue, only for entitled workspaces) sends what fell due in the last 10 minutes to the organiser and
  every guest who has not declined; the dedupe key `cal-rem:{event}:{occurrence}:{lead}` keeps each to one. Reminders
  belong to the event, not to each guest.

## 6. Permissions

Not scopable (section 2):

| Name | Group | What it allows |
|---|---|---|
| `calendar.events.view` | `calendar` | Use the calendar: own calendars, workspace calendars, followed teammates as they allow; answer invitations; own settings |
| `calendar.events.manage` | `calendar` | Create, change, move and delete events and calendars of your own; invite guests |
| `calendar.shared.manage` | `calendar` | Create workspace calendars and add and change their events (with `calendar.events.manage`) |

## 7. Routes

Workspace host, group `['auth:web', 'auth.session', 'member', 'subscribed']` + `entitled:calendar`, all under
`/calendar`:

| Route name | Method | Permission |
|---|---|---|
| `calendar.index` | GET (`view`, `date`, `search`) | `calendar.events.view` |
| `calendar.preferences.update` | PUT | `calendar.events.view` |
| `calendar.calendars.toggle` | PATCH `calendars/{calendar}/visibility` | `calendar.events.view` |
| `calendar.people.store` / `calendar.people.destroy` | POST `people` / DELETE `people/{membership}` | `calendar.events.view` |
| `calendar.events.respond` | POST `events/{event}/response` | `calendar.events.view` (guests only) |
| `calendar.events.store` / `.update` / `.move` / `.destroy` | POST / PUT / PATCH `move` / DELETE | `calendar.events.manage` + the calendar check |
| `calendar.calendars.store` / `.update` / `.destroy` | POST / PUT / DELETE | `calendar.events.manage` (+ `calendar.shared.manage` for a workspace calendar) |

## 8. What it publishes to other apps

- **Automation trigger** `calendar.event.created` (`Automation\Triggers\CalendarEventCreatedTrigger`, event
  `Events\CalendarEventCreated`): an event was added to a **workspace** calendar. Payload `event_id`, `calendar_id`,
  `title`, `starts_at`, `ends_at`, `is_all_day`, `is_recurring`, `created_by`. No automation action.
- **Report provider** `calendar.workspace_events` (`Reporting\WorkspaceEventsReport`): events on workspace calendars
  that start in the period, by calendar (a series counts once).
- Planned: other apps' dates as read-only overlays (Projects due dates, HRM leave and holidays) through a documented
  contract; ICS export and import; Google / Outlook sync.

## 9. Frontend

One page, `resources/js/Modules/Calendar/Pages/Index.vue`, full height inside the workspace shell: a toolbar (side
panel switch, Today, previous / next, the period, search, keyboard shortcuts, settings, the view menu), a side panel
(Create, a month navigator, "Search for people", the calendars with show/hide switches) and the view. Components in
`Modules/Calendar/Components`: `TimeGrid` (day, 4 days, week: an all-day strip over a 24-hour grid; drag on empty time
to draw an event, drag an event to move it across time and days, drag its lower edge to resize, drag an all-day bar
sideways; overlapping events share columns; a red line for now), `MonthGrid` (lanes per week, "+ n more", drag to
another day), `YearView`, `ScheduleView`, `MiniMonth` (arrow-key navigable grid), `EventPopover` (details, answers,
"Going?"), `EventEditor`, `ScopeDialog`, `CalendarDialog`, `SettingsDialog`, `CalendarSidebar`. Pure helpers in
`lib/` (`dates`, `layout`, `recurrence`, `describe`, `zones`, `format`) are unit-tested.

Keyboard: `T` today, `J`/`K` next/previous, `D` `X` `W` `M` `Y` `A` for the views, `C` create, `/` search, `?` the list.
Every event is a button; on touch the grid scrolls and a tap opens or creates. Below 1024px the side panel is a
drawer.

Colours: 11 tokens `--event-*` with `--event-ink` in `resources/css/app.css`, both themes; `tests/js/contrast.test.ts`
checks the ink on every colour. An event reads them through `colorVar()` (`--ev`) and `bg-(--ev)`. Strings live in the
`calendar` namespace (`resources/js/Core/i18n/locales/{en,bn}/calendar.ts`); dates, weekdays and months come from
`Intl` in the person's language and numerals. The sidebar entry is `shell.nav.calendar` (icon `calendar-days`).

Demo data: the demo Business and Enterprise plans include the app; there are no demo events.

## 10. Tests

`tests/Feature/Calendar/CalendarTest.php`: tenant isolation of all four tables, cross-workspace 404 on every id route,
not-entitled 404, the primary calendar, UTC storage and per-viewer zones, all-day input, the three scopes for change
and delete and a refused occurrence, privacy (busy by default, private hidden even from the workspace owner, private
events on team calendars, search), guests and answers, reminders and their dedupe key, permissions (reader, workspace
calendars, the primary calendar), preferences, and the trigger and report. `tests/Unit/Calendar/RecurrenceExpanderTest.php`:
every frequency, counts and end dates, daylight saving, far-away windows, refused rules, and the view ranges (the
same cases as the frontend). `tests/js/calendar.test.ts`: ranges, wall-clock and zone arithmetic, layout, presets,
descriptions, colours in both languages.
