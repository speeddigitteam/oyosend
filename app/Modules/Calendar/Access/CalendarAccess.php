<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Access;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Calendar\Enums\CalendarVisibility;
use App\Modules\Calendar\Enums\EventVisibility;
use App\Modules\Calendar\Models\Attendee;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who sees and changes which calendar.
 *
 * Calendars are private by person. A personal calendar is fully visible to its owner only; a teammate sees it when
 * they follow the owner and the owner chose `team` (details) or `busy` (busy blocks), never `private`. Workspace
 * calendars (no owner) are visible to everyone holding `calendar.events.view`. A guest sees the event they were
 * invited to, whatever its calendar. A role's record scope does not widen any of this: a manager with an `all`
 * scope elsewhere still cannot read a colleague's private calendar.
 */
final readonly class CalendarAccess
{
    public const string VIEW = 'calendar.events.view';

    public const string MANAGE = 'calendar.events.manage';

    public const string SHARED = 'calendar.shared.manage';

    public const string FULL = 'full';

    public const string BUSY = 'busy';

    public function __construct(private AccessChecker $access) {}

    public function membership(User $user): TenantMembership
    {
        return $this->access->membership($user) ?? abort(403);
    }

    public function canEdit(User $user, TenantMembership $me, Calendar $calendar): bool
    {
        return $calendar->isShared()
            ? $user->can(self::SHARED)
            : $calendar->owner_id === $me->id && $user->can(self::MANAGE);
    }

    /** `full`, `busy` or null (not visible) for a calendar, before any event-level privacy. */
    public function detailLevel(TenantMembership $me, Calendar $calendar): ?string
    {
        if ($calendar->isShared() || $calendar->owner_id === $me->id) {
            return self::FULL;
        }

        return match ($calendar->visibility) {
            CalendarVisibility::Team => self::FULL,
            CalendarVisibility::Busy => self::BUSY,
            CalendarVisibility::Private => null,
        };
    }

    /** What a member may see of one event (pass its series root), or null when it stays hidden. */
    public function eventDetail(TenantMembership $me, CalendarEvent $root, Calendar $calendar, bool $isGuest): ?string
    {
        if ($isGuest || $calendar->owner_id === $me->id) {
            return self::FULL;
        }

        $level = $this->detailLevel($me, $calendar);

        if ($level === self::FULL && $root->visibility === EventVisibility::Private && ! $calendar->isShared()) {
            return self::BUSY;
        }

        return $level;
    }

    /**
     * The calendars on a member's page: their own, the workspace's, and those of the teammates they follow that are
     * not private.
     *
     * @param  list<string>  $followed  Membership ids.
     * @return Collection<int, Calendar>
     */
    public function visibleCalendars(TenantMembership $me, array $followed): Collection
    {
        $followed = $this->activeMemberIds(array_values(array_diff($followed, [$me->id])));

        return Calendar::query()
            ->where(fn (Builder $query) => $query
                ->where('owner_id', $me->id)
                ->orWhereNull('owner_id')
                ->orWhere(fn (Builder $other) => $other
                    ->whereIn('owner_id', $followed === [] ? [''] : $followed)
                    ->where('visibility', '!=', CalendarVisibility::Private->value)))
            ->with('owner.user:id,name')
            ->orderByDesc('is_primary')->orderBy('name')
            ->get();
    }

    /**
     * Series roots (and single events) the member is a guest of.
     *
     * @return list<string>
     */
    public function invitedEventIds(TenantMembership $me): array
    {
        return Attendee::query()->where('membership_id', $me->id)->pluck('event_id')->all();
    }

    /** Whether the member may see an event at all (pass the row a route bound; exceptions resolve to their series). */
    public function canSee(TenantMembership $me, CalendarEvent $event): bool
    {
        $root = $event->isException() ? $event->loadMissing('series')->series : $event;

        if ($root === null) {
            return false;
        }

        $isGuest = Attendee::query()->where('event_id', $root->id)->where('membership_id', $me->id)->exists();

        return $this->eventDetail($me, $root, $root->loadMissing('calendar')->calendar, $isGuest) !== null;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function activeMemberIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TenantMembership::query()->whereIn('id', $ids)->where('status', MembershipStatus::Active)->pluck('id')->all();
    }
}
