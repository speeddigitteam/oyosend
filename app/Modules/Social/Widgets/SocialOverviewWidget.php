<?php

declare(strict_types=1);

namespace App\Modules\Social\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\LeadAds\LeadAdsFigures;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Carbon;

/**
 * Dashboard widget, shown with `social.posts.view`: what is queued, what needs a decision and
 * which accounts need attention. Post counts follow the viewer's own / department / branch / all scope.
 */
final class SocialOverviewWidget
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function __invoke(User $user): array
    {
        $posts = fn () => $this->records->constrain(Post::query(), $user, 'social.posts.view');
        $next = $posts()->where('status', PostStatus::Scheduled->value)->where('scheduled_for', '>=', now())->min('scheduled_for');

        return [
            'scheduled' => $posts()->where('status', PostStatus::Scheduled->value)->count(),
            'awaitingApproval' => $posts()->where('status', PostStatus::PendingApproval->value)->count(),
            'failed' => $posts()->whereIn('status', [PostStatus::Failed->value, PostStatus::PartiallyFailed->value])->count(),
            'accountsNeedingAttention' => $this->access->allows($user, 'social.accounts.view')
                ? SocialAccount::query()->where('status', AccountStatus::NeedsReauthorization->value)->count()
                : 0,
            'nextScheduledFor' => is_string($next) ? Carbon::parse($next)->toIso8601String() : null,
            ...LeadAdsFigures::forUser($this->access, $user),
        ];
    }
}
