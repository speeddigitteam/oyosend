<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Http\Controllers;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\Actions\CalendarManager;
use App\Modules\Calendar\Actions\Preferences;
use App\Modules\Calendar\Enums\CalendarVisibility;
use App\Modules\Calendar\Enums\EventColor;
use App\Modules\Calendar\Models\Calendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The calendars in the sidebar: create, change and remove them, show or hide them, and follow teammates. */
final class CalendarsController
{
    /** Teammates one member may follow at most. */
    public const int MAX_FOLLOWED = 50;

    public function __construct(
        private readonly CalendarAccess $access,
        private readonly CalendarManager $calendars,
        private readonly Preferences $preferences,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        $input = $this->validated($request) + $request->validate(['shared' => ['sometimes', 'boolean']]);
        $shared = (bool) ($input['shared'] ?? false);
        abort_if($shared && ! $user->can(CalendarAccess::SHARED), 403);
        unset($input['shared']);

        $this->calendars->create($input, $shared ? null : $me);
        Toast::success(__('Calendar created.'));

        return back();
    }

    public function update(Request $request, Calendar $calendar): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        abort_if($this->access->detailLevel($me, $calendar) === null, 404);
        abort_unless($this->access->canEdit($user, $me, $calendar), 403);

        $this->calendars->update($calendar, $this->validated($request));
        Toast::success(__('Calendar saved.'));

        return back();
    }

    public function destroy(Request $request, Calendar $calendar): RedirectResponse
    {
        [$user, $me] = $this->actor($request);
        abort_if($this->access->detailLevel($me, $calendar) === null, 404);
        abort_unless($this->access->canEdit($user, $me, $calendar), 403);

        $this->calendars->delete($calendar);
        Toast::success(__('Calendar deleted.'));

        return back();
    }

    /** Show or hide a calendar on the member's own page. */
    public function toggle(Request $request, Calendar $calendar): RedirectResponse
    {
        [, $me] = $this->actor($request);
        abort_if($this->access->detailLevel($me, $calendar) === null, 404);
        $visible = (bool) $request->validate(['visible' => ['required', 'boolean']])['visible'];

        $preference = $this->preferences->for($me);
        $hidden = array_values(array_diff($preference->hidden_calendar_ids ?? [], [$calendar->id]));

        if (! $visible) {
            $hidden[] = $calendar->id;
        }

        $this->preferences->save($me, ['hidden_calendar_ids' => array_slice($hidden, -200)]);

        return back();
    }

    /** Add a teammate's calendars (those they let the team see) to the member's page. */
    public function follow(Request $request): RedirectResponse
    {
        [, $me] = $this->actor($request);
        $id = (string) $request->validate(['member_id' => ['required', 'string', 'size:26']])['member_id'];
        $member = TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($id);
        abort_if($member->id === $me->id, 422);

        $preference = $this->preferences->for($me);
        $followed = array_values(array_unique([...($preference->followed_member_ids ?? []), $member->id]));
        $this->preferences->save($me, ['followed_member_ids' => array_slice($followed, -self::MAX_FOLLOWED)]);

        return back();
    }

    public function unfollow(Request $request, TenantMembership $membership): RedirectResponse
    {
        [, $me] = $this->actor($request);
        $preference = $this->preferences->for($me);
        $this->preferences->save($me, ['followed_member_ids' => array_values(array_diff($preference->followed_member_ids ?? [], [$membership->id]))]);

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', Rule::enum(EventColor::class)],
            'visibility' => ['sometimes', Rule::enum(CalendarVisibility::class)],
        ]);
        $input['name'] = trim($input['name']);
        $input['description'] = trim((string) ($input['description'] ?? '')) ?: null;

        return $input;
    }

    /** @return array{0: User, 1: TenantMembership} */
    private function actor(Request $request): array
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 401);

        return [$user, $this->access->membership($user)];
    }
}
