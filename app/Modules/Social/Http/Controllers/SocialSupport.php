<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Category;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\SocialPlatforms;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Shared helpers of the Social controllers: the actor, their membership and form option lists. */
final class SocialSupport
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly SocialSettings $settings,
        private readonly SocialPlatforms $platforms,
        private readonly SocialPresenter $presenter,
        private readonly AccountAllowList $allowList,
    ) {}

    public function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }

    /** A post the actor may act on with `$permission`; 404 outside their scope. */
    public function post(User $actor, string $id, string $permission): Post
    {
        $post = Post::query()->findOrFail($id);
        $this->records->authorize($actor, $permission, $post);

        // The account allow-list narrows what the permission alone would allow.
        $ability = match ($permission) {
            'social.posts.edit', 'social.posts.delete', 'social.posts.schedule' => AccountAllowList::PUBLISH,
            'social.posts.approve' => AccountAllowList::APPROVE,
            default => null,
        };

        if ($ability !== null) {
            $this->allowList->assert($actor, $ability, $post->targets()->pluck('account_id')->all());
        }

        return $post;
    }

    /**
     * Refuse accounts outside the actor's allow-list for `$ability` (publish, approve, analytics).
     *
     * @param  list<string>  $accountIds
     */
    public function assertAccounts(User $actor, string $ability, array $accountIds): void
    {
        $this->allowList->assert($actor, $ability, $accountIds);
    }

    /**
     * Local date-time (workspace timezone) from the form into UTC, or null.
     */
    public function utc(?string $local): ?CarbonImmutable
    {
        return $local === null || $local === '' ? null : $this->settings->toUtc($local);
    }

    /**
     * Everything the post form needs.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return [
            'accounts' => SocialAccount::query()->where('status', '!=', 'disconnected')->orderBy('platform')->orderBy('name')->get()
                ->filter(fn (SocialAccount $account): bool => $this->mayPublishTo($account->id))->values()
                ->map(fn (SocialAccount $account): array => $this->presenter->accountOption($account))->all(),
            'campaigns' => Campaign::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Campaign $campaign): array => ['id' => $campaign->id, 'name' => $campaign->name])->all(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->all(),
            'hashtagSets' => HashtagSet::query()->orderBy('name')->get()
                ->map(fn (HashtagSet $set): array => ['id' => $set->id, 'name' => $set->name, 'tags' => $set->tags])->all(),
            'media' => Media::query()->latest()->limit(200)->get()
                ->map(fn (Media $media): array => $this->presenter->media($media))->all(),
            'approvalRequired' => $this->settings->current()->require_approval,
            'timezone' => $this->settings->timezone(),
            'platforms' => $this->presenter->platforms(),
            'limits' => ['maxMedia' => (int) config('social.media.max_per_post', 10), 'maxBody' => 5000],
        ];
    }

    /** Whether the signed-in member may publish to the account (the post form lists only those). */
    private function mayPublishTo(string $accountId): bool
    {
        $user = auth('web')->user();

        return ! $user instanceof User || $this->allowList->allows($user, AccountAllowList::PUBLISH, $accountId);
    }

    public function platforms(): SocialPlatforms
    {
        return $this->platforms;
    }
}
