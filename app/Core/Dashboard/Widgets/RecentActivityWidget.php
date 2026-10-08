<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Widgets;

use App\Core\Audit\Models\AuditLog;
use App\Core\Identity\Models\User;

final class RecentActivityWidget
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        return [
            'entries' => AuditLog::query()
                ->latest('created_at')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (AuditLog $entry): array => [
                    'id' => $entry->id,
                    'event' => $entry->event,
                    'actor' => $entry->actor_label,
                    'subject' => $entry->subject_label,
                    'createdAt' => $entry->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
