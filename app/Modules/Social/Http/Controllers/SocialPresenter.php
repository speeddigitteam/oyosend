<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Modules\Social\Actions\PostContentRules;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\Media;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns models into the arrays the Inertia pages receive. Nothing secret passes through here:
 * an account is presented without its tokens, media without its storage path.
 */
final class SocialPresenter
{
    public function __construct(
        private readonly SocialPlatforms $platforms,
        private readonly PostContentRules $rules,
        private readonly SocialSettings $settings,
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    /** @return list<array<string, mixed>> */
    public function platforms(): array
    {
        $out = [];

        foreach ($this->platforms->all() as $platform) {
            $availability = $platform->availability();
            $out[] = [
                'key' => $platform->key(),
                'label' => $platform->label(),
                'available' => $availability->available,
                'unavailableCode' => $availability->code,
                'reason' => $availability->reason,
                'capabilities' => $platform->capabilities()->toArray(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function account(SocialAccount $account): array
    {
        $platform = $this->platforms->find($account->platform);

        return [
            'id' => $account->id,
            'platform' => $account->platform,
            'platformLabel' => $platform?->label() ?? $account->platform,
            'name' => $account->name,
            'handle' => $account->handle,
            'avatarUrl' => $account->avatar_url,
            'type' => $account->account_type,
            'status' => $account->status->value,
            'statusReason' => $account->status_reason,
            'needsReauthorization' => $account->status === AccountStatus::NeedsReauthorization,
            'platformAvailable' => $platform?->availability()->available ?? false,
            'capabilities' => $platform?->capabilities()->toArray() ?? [],
            'connectedAt' => $account->connected_at?->toIso8601String(),
            'lastUsedAt' => $account->last_used_at?->toIso8601String(),
            'tokenExpiresAt' => $account->token_expires_at?->toIso8601String(),
            'canRefresh' => $account->refresh_token !== null,
        ];
    }

    /** @return array<string, mixed> */
    public function accountOption(SocialAccount $account): array
    {
        $full = $this->account($account);

        return [
            'id' => $full['id'],
            'platform' => $full['platform'],
            'platformLabel' => $full['platformLabel'],
            'name' => $full['name'],
            'handle' => $full['handle'],
            'status' => $full['status'],
            'usable' => $account->isUsable() && $full['platformAvailable'] && ($full['capabilities']['publish'] ?? false),
            'capabilities' => $full['capabilities'],
        ];
    }

    /** @return array<string, mixed> */
    public function media(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->name,
            'kind' => $media->kind->value,
            'mime' => $media->mime,
            'sizeBytes' => $media->size_bytes,
            'width' => $media->width,
            'height' => $media->height,
            'altText' => $media->alt_text,
            'url' => route('social.media.file', ['media' => $media->id]),
            'createdAt' => $media->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Post $post): array
    {
        $post->loadMissing(['targets.account', 'campaign', 'category']);
        $at = $post->scheduled_for ?? $post->published_at;

        return [
            'id' => $post->id,
            'title' => $post->title,
            'excerpt' => Str::limit(trim((string) $post->body), 160),
            'status' => $post->status->value,
            'scheduledFor' => $post->scheduled_for?->toIso8601String(),
            'publishedAt' => $post->published_at?->toIso8601String(),
            'at' => $at?->toIso8601String(),
            'campaign' => $post->campaign === null ? null : ['id' => $post->campaign->id, 'name' => $post->campaign->name],
            'category' => $post->category === null ? null : ['id' => $post->category->id, 'name' => $post->category->name],
            'targets' => $post->targets->map(fn (PostTarget $target): array => [
                'id' => $target->id,
                'accountId' => $target->account_id,
                'platform' => $target->account->platform,
                'accountName' => $target->account->name,
                'status' => $target->status->value,
            ])->values()->all(),
            'createdAt' => $post->created_at?->toIso8601String(),
        ];
    }

    /**
     * Full post for the detail and edit pages.
     *
     * @return array<string, mixed>
     */
    public function detail(Post $post, User $actor): array
    {
        $post->load([
            'targets.account', 'targets.attemptLog', 'mediaLinks.media', 'comments.author.user:id,name',
            'campaign', 'category', 'owner.user:id,name',
        ]);
        $requiresApproval = $this->settings->current()->require_approval;
        $status = $post->status;
        $can = fn (string $permission): bool => $this->records->allows($actor, $permission, $post);
        $hasPublishing = $post->targets->contains(fn (PostTarget $t): bool => $t->status === TargetStatus::Publishing);
        $membershipId = $this->access->membership($actor)?->id;

        return [
            ...$this->summary($post),
            'body' => $post->body,
            'linkUrl' => $post->link_url,
            'hashtags' => $post->hashtags ?? [],
            'hashtagSetId' => $post->hashtag_set_id,
            'scheduledForLocal' => $post->scheduled_for?->copy()->setTimezone($this->settings->timezone())->format('Y-m-d\TH:i'),
            'owner' => $post->owner === null ? null : ['id' => $post->owner->id, 'name' => $post->owner->user->name],
            'submittedAt' => $post->submitted_at?->toIso8601String(),
            'approvedAt' => $post->approved_at?->toIso8601String(),
            'rejectedAt' => $post->rejected_at?->toIso8601String(),
            'rejectionReason' => $post->rejection_reason,
            'media' => $post->mediaLinks->map(fn (PostMedia $link): array => $this->media($link->media))->values()->all(),
            'targets' => $post->targets->map(fn (PostTarget $target): array => [
                'id' => $target->id,
                'accountId' => $target->account_id,
                'platform' => $target->account->platform,
                'platformLabel' => $this->platforms->find($target->account->platform)?->label() ?? $target->account->platform,
                'accountName' => $target->account->name,
                'accountStatus' => $target->account->status->value,
                'bodyOverride' => $target->body_override,
                'status' => $target->status->value,
                'attempts' => $target->attempts,
                'nextAttemptAt' => $target->next_attempt_at?->toIso8601String(),
                'publishedAt' => $target->published_at?->toIso8601String(),
                'externalUrl' => $target->external_url,
                'lastError' => $target->last_error,
                'lastErrorCode' => $target->last_error_code,
                'needsManualCheck' => $target->needs_manual_check,
                'canRetry' => $target->status === TargetStatus::Failed && $target->account->status === AccountStatus::Connected,
                'attemptLog' => $target->attemptLog->map(fn ($attempt): array => [
                    'attempt' => $attempt->attempt,
                    'outcome' => $attempt->outcome->value,
                    'errorCode' => $attempt->error_code,
                    'message' => $attempt->message,
                    'at' => $attempt->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'comments' => $post->comments->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'author' => $comment->author === null ? null : ['id' => $comment->author->id, 'name' => $comment->author->user->name],
                'at' => $comment->created_at?->toIso8601String(),
                'mine' => $comment->author_id !== null && $comment->author_id === $membershipId,
            ])->values()->all(),
            'previews' => $this->rules->previews($post),
            'approval' => [
                'required' => $requiresApproval,
                'submittedBy' => $post->submitted_by,
                'approvedBy' => $post->approved_by,
                'mineToApprove' => $membershipId !== null && $post->submitted_by !== $membershipId && $post->owner_id !== $membershipId,
            ],
            'abilities' => [
                'edit' => $status->isEditable() && $can('social.posts.edit'),
                'delete' => $status->isDeletable() && $can('social.posts.delete'),
                'submit' => $status === PostStatus::Draft && $can('social.posts.schedule'),
                'approve' => $status === PostStatus::PendingApproval && $actor->can('social.posts.approve')
                    && $membershipId !== null && $post->submitted_by !== $membershipId && $post->owner_id !== $membershipId,
                'reject' => $status === PostStatus::PendingApproval && $actor->can('social.posts.approve'),
                'schedule' => $can('social.posts.schedule') && (
                    $requiresApproval ? $status === PostStatus::Approved : in_array($status, [PostStatus::Draft, PostStatus::Approved], true)
                ),
                'reschedule' => $status === PostStatus::Scheduled && ! $hasPublishing && $can('social.posts.schedule'),
                'unschedule' => $status->canReturnToDraft() && ! $hasPublishing && $can('social.posts.schedule'),
                'retry' => $can('social.posts.schedule'),
                'comment' => $actor->can('social.posts.comment'),
            ],
            'now' => Carbon::now()->toIso8601String(),
        ];
    }
}
