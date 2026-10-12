<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Communication\Models\MemberNotification;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Turns a stored notification into what a screen shows: translated in the viewer's language, with a link that
 * is dropped when its route no longer exists.
 */
final class NotificationPresenter
{
    /**
     * @return array{id: string, category: string, severity: string, title: string, body: string|null, titleKey: string, bodyKey: string|null, params: array<string, scalar|null>, url: string|null, read: bool, archived: bool, createdAt: string}
     */
    public function present(MemberNotification $notification, User $viewer): array
    {
        $locale = $viewer->locale ?: app()->getLocale();
        $params = $notification->params ?? [];

        return [
            'id' => $notification->id,
            'category' => $notification->category,
            'severity' => $notification->severity,
            'title' => (string) __($notification->title_key, $params, $locale),
            'body' => $notification->body_key === null ? null : (string) __($notification->body_key, $params, $locale),
            'titleKey' => $notification->title_key,
            'bodyKey' => $notification->body_key,
            'params' => $params,
            'url' => $this->url($notification),
            'read' => $notification->read_at !== null,
            'archived' => $notification->archived_at !== null,
            'createdAt' => $notification->created_at->toIso8601String(),
        ];
    }

    private function url(MemberNotification $notification): ?string
    {
        $name = $notification->link_route;

        if ($name === null || ! Route::has($name)) {
            return null;
        }

        try {
            return route($name, $notification->link_params ?? [], false);
        } catch (Throwable) {
            return null;
        }
    }
}
