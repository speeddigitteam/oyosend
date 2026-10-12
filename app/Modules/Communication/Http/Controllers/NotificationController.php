<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Communication\Actions\NotificationPreferences;
use App\Modules\Communication\Actions\NotificationPresenter;
use App\Modules\Communication\Models\MemberNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member's own notifications. Every query is narrowed to the signed-in member's membership; another
 * member's notification is a 404, never a 403.
 */
final class NotificationController
{
    public const string VIEW = 'communication.notifications.view';

    public function __construct(
        private readonly CommunicationControllerSupport $support,
        private readonly NotificationPresenter $presenter,
        private readonly NotificationPreferences $preferences,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $filters = $request->validate([
            'filter' => ['nullable', 'in:all,unread,archived'],
            'category' => ['nullable', 'string', 'max:40'],
        ]);
        $filter = $filters['filter'] ?? 'all';
        $category = $filters['category'] ?? null;

        $own = MemberNotification::query()->where('membership_id', $membership->id);

        $notifications = (clone $own)
            ->when($filter === 'archived', fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (MemberNotification $notification): array => $this->presenter->present($notification, $actor));

        return Inertia::render('Modules/Communication/Pages/Notifications/Index', [
            'notifications' => $notifications,
            'filters' => ['filter' => $filter, 'category' => $category],
            'unreadCount' => (clone $own)->whereNull('archived_at')->whereNull('read_at')->count(),
            'categories' => $this->preferences->categories(),
        ]);
    }

    /** For the header bell: the unread count and the latest few unread items. */
    public function summary(Request $request): JsonResponse
    {
        $actor = $this->support->actor($request);
        $membership = $this->support->membership($actor);
        $unread = MemberNotification::query()
            ->where('membership_id', $membership->id)->whereNull('archived_at')->whereNull('read_at');

        return response()->json([
            'unread' => (clone $unread)->count(),
            'items' => (clone $unread)->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()
                ->map(fn (MemberNotification $notification): array => $this->presenter->present($notification, $actor))
                ->all(),
        ]);
    }

    public function read(Request $request, MemberNotification $notification): RedirectResponse
    {
        $this->owned($request, $notification)->forceFill(['read_at' => $notification->read_at ?? CarbonImmutable::now()])->save();

        return back();
    }

    public function unread(Request $request, MemberNotification $notification): RedirectResponse
    {
        $this->owned($request, $notification)->forceFill(['read_at' => null])->save();

        return back();
    }

    public function archive(Request $request, MemberNotification $notification): RedirectResponse
    {
        $this->owned($request, $notification)->forceFill([
            'archived_at' => $notification->archived_at ?? CarbonImmutable::now(),
            'read_at' => $notification->read_at ?? CarbonImmutable::now(),
        ])->save();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $membership = $this->support->membership($this->support->actor($request));

        MemberNotification::query()
            ->where('membership_id', $membership->id)
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now()]);
        Toast::success(__('All notifications marked as read.'));

        return back();
    }

    private function owned(Request $request, MemberNotification $notification): MemberNotification
    {
        $membership = $this->support->membership($this->support->actor($request));
        abort_unless($notification->membership_id === $membership->id, 404);

        return $notification;
    }
}
