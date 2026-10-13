<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Widgets;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Models\AudienceComment;

/**
 * Dashboard widget, shown with `social.comments.view`: comments still waiting for a person, how many of those were
 * flagged for attention and how many have not been opened. Counts follow the viewer's own / department / branch / all scope.
 */
final class SocialCommentsWidget
{
    public function __construct(private readonly RecordAccess $records) {}

    /** @return array<string, int> */
    public function __invoke(User $user): array
    {
        $open = fn () => $this->records->constrain(AudienceComment::query(), $user, 'social.comments.view')
            ->where('is_from_page', false)->whereNull('handled_at')->where('remote_state', '!=', RemoteState::Deleted->value);

        return [
            'unhandled' => $open()->count(),
            'unread' => $open()->whereNull('read_at')->count(),
            'needsAttention' => $open()->where('needs_attention', true)->count(),
        ];
    }
}
