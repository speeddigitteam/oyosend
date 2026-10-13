<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\SocialAccount;

/**
 * The same checks a save/schedule would run, for a post that is still being composed: nothing is
 * stored. The composer shows the per-account result live, so people see why an account or a file
 * does not fit before they try to schedule.
 */
final class PostPreflight
{
    public function __construct(private readonly PostContentRules $rules) {}

    /**
     * @param  list<array{account_id: string, body_override: string|null}>  $targets
     * @param  list<string>  $mediaIds
     * @return list<array<string, mixed>>
     */
    public function previews(PostType $type, ?string $body, ?string $link, ?string $hashtagSetId, array $targets, array $mediaIds): array
    {
        $accounts = SocialAccount::query()
            ->whereIn('id', array_map(static fn (array $target): string => $target['account_id'], $targets))
            ->get()->keyBy('id');
        $media = Media::query()->whereIn('id', $mediaIds)->get()->keyBy('id');
        $set = $hashtagSetId === null ? null : HashtagSet::query()->find($hashtagSetId);

        // A transient, unsaved post: tenant-scoped lookups above mean another workspace's ids are simply absent.
        $post = new Post(['body' => $body, 'link_url' => $link, 'post_type' => $type, 'hashtags' => $set?->tags]);
        $post->setRelation('targets', collect($targets)
            ->filter(fn (array $target): bool => $accounts->has($target['account_id']))
            ->map(function (array $target) use ($accounts): PostTarget {
                $row = new PostTarget(['account_id' => $target['account_id'], 'body_override' => $target['body_override']]);
                $row->forceFill(['id' => 'preview-'.$target['account_id']]);
                $row->setRelation('account', $accounts->get($target['account_id']));

                return $row;
            })->values());
        $post->setRelation('mediaLinks', collect($mediaIds)
            ->filter(fn (string $id): bool => $media->has($id))
            ->map(function (string $id, int $position) use ($media): PostMedia {
                $link = new PostMedia(['media_id' => $id, 'position' => $position]);
                $link->setRelation('media', $media->get($id));

                return $link;
            })->values());

        return $this->rules->previews($post);
    }
}
