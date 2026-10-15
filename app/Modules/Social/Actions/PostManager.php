<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Category;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Support\HashtagNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits and deletes posts, their per-account variants and their media. Review and
 * publishing states are in {@see PostWorkflow}; a change to reviewed content sends the post
 * back to draft so an approval always covers exactly what is published.
 */
final class PostManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TargetOptions $options,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  title, body, link_url, campaign_id, category_id, hashtag_set_id, scheduled_for (UTC Carbon|null)
     * @param  list<array{account_id: string, body_override?: string|null, options?: array<string, mixed>|null}>  $targets  `options` absent leaves a target's options as they are
     * @param  list<string>  $mediaIds
     */
    public function create(array $attributes, array $targets, array $mediaIds, ?TenantMembership $owner = null): Post
    {
        $prepared = $this->prepare($attributes);

        return DB::transaction(function () use ($prepared, $targets, $mediaIds, $owner): Post {
            $post = new Post($prepared);
            $post->status = PostStatus::Draft;
            $post->assignOwner($owner);
            $post->save();

            $this->syncTargets($post, $targets);
            $this->syncMedia($post, $mediaIds);

            return $post->load(['targets.account', 'mediaLinks.media']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{account_id: string, body_override?: string|null, options?: array<string, mixed>|null}>|null  $targets  null leaves the accounts as they are
     * @param  list<string>|null  $mediaIds  null leaves the media as it is
     */
    public function update(Post $post, array $attributes, ?array $targets, ?array $mediaIds): Post
    {
        $prepared = $this->prepare($attributes);

        return DB::transaction(function () use ($post, $prepared, $targets, $mediaIds): Post {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->id);

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages(['post' => __('This post can no longer be edited.')]);
            }

            $before = $locked->status;
            $locked->fill($prepared);

            if ($before !== PostStatus::Draft) {
                // The content changed after it was reviewed or queued: it must be reviewed again.
                $locked->fill([
                    'status' => PostStatus::Draft,
                    'submitted_by' => null, 'submitted_at' => null,
                    'approved_by' => null, 'approved_at' => null,
                    'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
                ]);
            }

            $locked->save();

            if ($targets !== null) {
                $this->syncTargets($locked, $targets);
            }

            if ($mediaIds !== null) {
                $this->syncMedia($locked, $mediaIds);
            }

            if ($before !== PostStatus::Draft) {
                $locked->targets()->where('status', TargetStatus::Pending->value)->update(['next_attempt_at' => null]);
                $this->audit->record('social.post.returned_to_draft', $locked, ['from' => $before->value, 'reason' => 'content_changed']);
            }

            return $locked->load(['targets.account', 'mediaLinks.media']);
        });
    }

    public function delete(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->id);

            if (! $locked->status->isDeletable()) {
                throw ValidationException::withMessages(['post' => __('Only a draft, awaiting, approved or failed post can be deleted.')]);
            }

            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepare(array $attributes): array
    {
        $prepared = [
            'title' => $attributes['title'] ?? null,
            'body' => $attributes['body'] ?? null,
            'link_url' => $attributes['link_url'] ?? null,
            'scheduled_for' => $attributes['scheduled_for'] ?? null,
            'campaign_id' => $this->resolve(Campaign::class, $attributes['campaign_id'] ?? null, 'campaign_id')?->getKey(),
            'category_id' => $this->resolve(Category::class, $attributes['category_id'] ?? null, 'category_id')?->getKey(),
            'hashtag_set_id' => null,
            'hashtags' => null,
        ];

        /** @var HashtagSet|null $set */
        $set = $this->resolve(HashtagSet::class, $attributes['hashtag_set_id'] ?? null, 'hashtag_set_id');

        if ($set !== null) {
            $prepared['hashtag_set_id'] = $set->id;
            $prepared['hashtags'] = $set->tags;
        }

        $extra = array_values(array_filter((array) ($attributes['extra_hashtags'] ?? []), 'is_string'));

        if ($extra !== []) {
            // AI-suggested tags the person kept: merged into the snapshot, de-duplicated, at most 30.
            $prepared['hashtags'] = HashtagNormalizer::normalize([...((array) $prepared['hashtags']), ...$extra]);
        }

        if (! empty($attributes['ai_assisted'])) {
            // Sticky: editing never clears the marker, so an approver always sees it.
            $prepared['ai_assisted'] = true;
        }

        if (isset($attributes['post_type'])) {
            // Validated by the request; an unknown value falls back to a plain post.
            $prepared['post_type'] = (PostType::tryFrom((string) $attributes['post_type']) ?? PostType::Post)->value;
        }

        if ($prepared['scheduled_for'] instanceof \DateTimeInterface) {
            $prepared['scheduled_for'] = Carbon::instance($prepared['scheduled_for'])->utc();
        }

        return $prepared;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function resolve(string $class, mixed $id, string $field): ?Model
    {
        if ($id === null || $id === '') {
            return null;
        }

        // Tenant-scoped: an id from another workspace is simply not found.
        return $class::query()->find($id)
            ?? throw ValidationException::withMessages([$field => __('The selected option is not available.')]);
    }

    /**
     * @param  list<array{account_id: string, body_override?: string|null, options?: array<string, mixed>|null}>  $targets
     */
    private function syncTargets(Post $post, array $targets): void
    {
        $wanted = [];
        /** @var array<string, array<string, mixed>|null> $chosen options sent per account; an absent key keeps the stored ones */
        $chosen = [];

        foreach ($targets as $target) {
            $wanted[$target['account_id']] = $target['body_override'] ?? null;

            if (array_key_exists('options', $target)) {
                $chosen[$target['account_id']] = $target['options'];
            }
        }

        $accounts = SocialAccount::query()->whereIn('id', array_keys($wanted))->get()->keyBy('id');

        foreach (array_keys($wanted) as $accountId) {
            $account = $accounts->get($accountId);

            if ($account === null || $account->status === AccountStatus::Disconnected) {
                throw ValidationException::withMessages(['targets' => __('One of the selected accounts is not available.')]);
            }
        }

        $existing = $post->targets()->get()->keyBy('account_id');

        foreach ($existing as $accountId => $target) {
            if (! array_key_exists($accountId, $wanted) && $target->status !== TargetStatus::Published) {
                $target->delete();
            }
        }

        foreach ($wanted as $accountId => $override) {
            $override = $override === null || trim($override) === '' ? null : $override;
            $current = $existing->get($accountId);
            $options = array_key_exists($accountId, $chosen)
                ? ['options' => $this->options->forAccount($accounts[$accountId], $chosen[$accountId])]
                : [];

            if ($current === null) {
                PostTarget::query()->create([
                    'post_id' => $post->id,
                    'account_id' => $accountId,
                    'body_override' => $override,
                    ...$options,
                    'status' => TargetStatus::Pending,
                    'idempotency_key' => sha1($post->id.':'.$accountId),
                ]);
            } elseif ($current->status !== TargetStatus::Published) {
                $current->update(['body_override' => $override, ...$options]);
            }
        }
    }

    /** @param list<string> $mediaIds */
    private function syncMedia(Post $post, array $mediaIds): void
    {
        $mediaIds = array_values(array_unique($mediaIds));
        $max = (int) config('social.media.max_per_post', 10);

        if (count($mediaIds) > $max) {
            throw ValidationException::withMessages(['media' => __('A post can have at most :max media items.', ['max' => $max])]);
        }

        $found = Media::query()->whereIn('id', $mediaIds)->pluck('id')->all();

        if (count($found) !== count($mediaIds)) {
            throw ValidationException::withMessages(['media' => __('One of the selected media files is not available.')]);
        }

        PostMedia::query()->where('post_id', $post->id)->whereNotIn('media_id', $mediaIds)->delete();

        foreach ($mediaIds as $position => $mediaId) {
            PostMedia::query()->updateOrCreate(['post_id' => $post->id, 'media_id' => $mediaId], ['position' => $position]);
        }
    }
}
