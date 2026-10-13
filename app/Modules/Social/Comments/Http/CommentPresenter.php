<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Http;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Comments\Actions\CommentSources;
use App\Modules\Social\Comments\Actions\RuleManager;
use App\Modules\Social\Comments\CommentGateway;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentNote;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Support\Str;

/** Shapes comments, rules and Page connections for the Inertia pages. Text is passed on as plain strings only. */
final class CommentPresenter
{
    public function __construct(
        private readonly SocialPlatforms $platforms,
        private readonly CommentGateway $gateway,
        private readonly CommentSources $sources,
        private readonly RuleManager $rules,
    ) {}

    /** @return array<string, mixed> */
    public function item(AudienceComment $comment): array
    {
        $assignee = $comment->owner;
        $post = $comment->post;

        return [
            'id' => $comment->id,
            'accountId' => $comment->account_id,
            'accountName' => $comment->account->name,
            'platform' => $comment->platform,
            'platformLabel' => $this->platforms->find($comment->platform)?->label() ?? Str::headline($comment->platform),
            'authorName' => $comment->author_name,
            'isFromPage' => $comment->is_from_page,
            'message' => $comment->message,
            'commentedAt' => $comment->commented_at?->toIso8601String(),
            'unread' => $comment->read_at === null && $comment->handled_at === null && ! $comment->is_from_page,
            'replied' => $comment->replied_at !== null,
            'repliedAt' => $comment->replied_at?->toIso8601String(),
            'replyCount' => $comment->reply_count,
            'hidden' => $comment->remote_state === RemoteState::Hidden,
            'deleted' => $comment->remote_state === RemoteState::Deleted,
            'needsAttention' => $comment->needs_attention,
            'attentionReason' => $this->attention($comment->attention_reason),
            'handled' => $comment->handled_at !== null,
            'tags' => $comment->tags ?? [],
            'assignee' => $assignee === null ? null : ['id' => $assignee->id, 'name' => $assignee->user->name],
            'post' => $post === null ? null : ['id' => $post->id, 'title' => $post->title ?? Str::limit((string) $post->body, 80)],
            'externalPostId' => $comment->external_post_id,
            'permalink' => $comment->permalink,
            'likeCount' => $comment->like_count,
            'likedByPage' => $comment->liked_by_page,
            'hasAttachment' => $comment->has_attachment,
            'sentiment' => $comment->sentiment,
            'isReply' => $comment->parent_id !== null,
            'parentId' => $comment->parent_id,
            'lastError' => $comment->last_action_error,
            'source' => $comment->source,
        ];
    }

    /** @return array{id: string, body: string, at: string|null, author: array{id: string, name: string}|null} */
    public function note(CommentNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'at' => $note->created_at?->toIso8601String(),
            'author' => $note->author === null ? null : ['id' => $note->author->id, 'name' => $note->author->user->name],
        ];
    }

    /** @return array<string, mixed> */
    public function rule(CommentRule $rule, bool $withStanding = true): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'enabled' => $rule->is_enabled,
            'matchMode' => $rule->match_mode,
            'matchType' => $rule->match_type,
            'keywords' => $rule->keywords,
            'accountId' => $rule->account_id,
            'postId' => $rule->post_id,
            'campaignId' => $rule->campaign_id,
            'actions' => $rule->actions,
            'replyTemplates' => $rule->reply_templates ?? [],
            'hourlyCap' => $rule->hourly_cap,
            'cooldownMinutes' => $rule->cooldown_minutes,
            'quietStart' => $rule->quiet_start,
            'quietEnd' => $rule->quiet_end,
            'priority' => $rule->priority,
            'matchedCount' => $rule->matched_count,
            'lastMatchedAt' => $rule->last_matched_at?->toIso8601String(),
            'actor' => $rule->actor === null ? null : ['id' => $rule->actor->id, 'name' => $rule->actor->user->name],
            // Why the rule would currently do nothing: its member lost a permission, left, ...
            'standing' => $withStanding ? $this->rules->standing($rule) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function source(SocialAccount $account, ?CommentSource $source): array
    {
        $platform = $this->platforms->find($account->platform);

        return [
            'accountId' => $account->id,
            'name' => $account->name,
            'platform' => $account->platform,
            'platformLabel' => $platform?->label() ?? Str::headline($account->platform),
            'avatarUrl' => $account->avatar_url,
            'supported' => $this->sources->supports($account),
            'connected' => $account->status->value === 'connected',
            'enabled' => $source !== null && $source->is_enabled,
            'owned' => $source !== null && $source->is_enabled && $this->sources->owns($account),
            'webhookState' => $source !== null ? $source->webhook_state : CommentSource::NONE,
            'lastEventAt' => $source?->last_event_at?->toIso8601String(),
            'lastPolledAt' => $source?->last_polled_at?->toIso8601String(),
            'errorCode' => $source?->last_error_code,
            'privateReplies' => $this->gateway->available($account)
                && ($this->platformSupportsPrivate($account)),
        ];
    }

    /** @return array{id: string, name: string} */
    public function member(TenantMembership $membership): array
    {
        return ['id' => $membership->id, 'name' => $membership->user->name];
    }

    private function platformSupportsPrivate(SocialAccount $account): bool
    {
        return $this->gateway->provider($account)->supportsPrivateReplies($account->scopes);
    }

    private function attention(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return str_starts_with($reason, 'rule:') ? 'rule' : $reason;
    }
}
