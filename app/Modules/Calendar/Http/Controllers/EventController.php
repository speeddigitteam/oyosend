<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http\Controllers;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\Actions\EventManager;
use App\Modules\Calendar\Enums\AttendeeResponse;
use App\Modules\Calendar\Http\EventInput;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes, moves and removes events, and records a guest's answer. An event the member may not see is a
 * 404 (as if it did not exist); one they see but may not change is a 403.
 */
final class EventController
{
    public function __construct(
        private readonly CalendarAccess $access,
        private readonly EventManager $events,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        $changes = EventInput::changes($request);
        $calendar = $this->writableCalendar($user, $me, (string) $changes->attributes['calendar_id']);
        $this->events->create($calendar, $changes, $me);
        Toast::success(__('Event saved.'));

        return back();
    }

    public function update(Request $request, CalendarEvent $event): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        $this->authorizeChange($user, $me, $event);
        $changes = EventInput::changes($request);
        [$scope, $occurrence] = EventInput::target($request);

        if ($changes->attributes['calendar_id'] !== $event->calendar_id) {
            $this->writableCalendar($user, $me, (string) $changes->attributes['calendar_id']);
        }

        $this->events->update($event, $changes, $scope, $occurrence);
        Toast::success(__('Event saved.'));

        return back();
    }

    public function move(Request $request, CalendarEvent $event): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        $this->authorizeChange($user, $me, $event);
        $changes = EventInput::move($request, $event->is_all_day, $event->timezone);
        [$scope, $occurrence] = EventInput::target($request);
        $this->events->update($event, $changes, $scope, $occurrence);

        return back();
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        $this->authorizeChange($user, $me, $event);
        [$scope, $occurrence] = EventInput::target($request);
        $this->events->delete($event, $scope, $occurrence);
        Toast::success(__('Event deleted.'));

        return back();
    }

    public function respond(Request $request, CalendarEvent $event): RedirectResponse
    {
        [, $me] = $this->actor($request);
        $input = $request->validate(['response' => ['required', Rule::enum(AttendeeResponse::class)->except([AttendeeResponse::NeedsAction])]]);
        $root = $event->isException() ? ($event->loadMissing('series')->series ?? abort(404)) : $event;
        $this->events->respond($root, $me, AttendeeResponse::from($input['response']));

        return back();
    }

    /** @return array{0: User, 1: TenantMembership} */
    private function actor(Request $request): array
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);

        return [$user, $this->access->membership($user)];
    }

    private function authorizeChange(User $user, TenantMembership $me, CalendarEvent $event): void
    {
        abort_unless($this->access->canSee($me, $event), 404);
        abort_unless($this->access->canEdit($user, $me, $event->loadMissing('calendar')->calendar), 403);
    }

    private function writableCalendar(User $user, TenantMembership $me, string $id): Calendar
    {
        $calendar = Calendar::query()->find($id);

        if ($calendar === null || $this->access->detailLevel($me, $calendar) === null || ! $this->access->canEdit($user, $me, $calendar)) {
            throw ValidationException::withMessages(['calendar_id' => __('Choose a calendar you can add events to.')]);
        }

        return $calendar;
    }
}
