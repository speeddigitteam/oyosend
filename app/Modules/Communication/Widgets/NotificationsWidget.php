<?php

declare(strict_types=1);

namespace App\Modules\Communication\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Modules\Communication\Actions\NotificationPresenter;
use App\Modules\Communication\Models\MemberNotification;

/**
 * The signed-in member's own unread notifications, newest first.
 */
final class NotificationsWidget
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly NotificationPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        $membership = $this->access->membership($user);

        if ($membership === null) {
            return ['unread' => 0, 'items' => []];
        }

        $inbox = MemberNotification::query()
            ->where('membership_id', $membership->id)
            ->whereNull('archived_at');

        return [
            'unread' => (clone $inbox)->whereNull('read_at')->count(),
            'items' => (clone $inbox)->whereNull('read_at')->latest()->limit(5)->get()
                ->map(fn (MemberNotification $notification): array => $this->presenter->present($notification, $user))
                ->all(),
        ];
    }
}
