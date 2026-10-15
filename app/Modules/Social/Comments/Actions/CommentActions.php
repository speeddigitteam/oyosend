<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Comments\CommentGateway;
use App\Modules\Social\Comments\Contracts\CommentProvider;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Data\RemoteComment;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Ingest\CommentIngestor;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentNote;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Support\UntrustedText;
use App\Modules\Social\Platforms\AccountCredentials;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that can be done to an audience comment, by a person or by a rule. Callers authorise first (a
 * controller with the permission and the record scope, the rule engine with its member's live permissions);
 * this class does the work: the platform call outside any transaction, then the local change and its audit entry
 * together. A refused call leaves the comment unchanged except for a short error code and an attention flag.
 */
final class CommentActions
{
    public function __construct(
        private readonly CommentGateway $gateway,
        private readonly CommentIngestor $ingestor,
        private readonly AuditLogger $audit,
    ) {}

    /** Reply publicly as the Page. Returns the stored reply. */
    public function reply(AudienceComment $comment, string $text, ?TenantMembership $by, ?CommentRule $rule = null): AudienceComment
    {
        $text = $this->text($text, (int) config('social_comments.limits.reply_max', 2000), 'message');
        $this->assertActionable($comment, ownCommentAllowed: false);
        $account = $comment->account;

        $replyId = $this->remote($comment, fn ($provider, $credentials): string => $provider->reply($credentials, $comment->external_id, $text));

        [$reply] = $this->ingestor->store($account, new RemoteComment(
            id: $replyId,
            postId: $comment->external_post_id,
            parentId: $comment->external_id,
            authorId: $account->external_id,
            authorName: $account->name,
            message: $text,
            createdAt: CarbonImmutable::now(),
        ), 'reply');

        DB::transaction(function () use ($comment, $by, $rule, $reply): void {
            $comment->refresh();
            $comment->forceFill([
                'replied_at' => $comment->replied_at ?? now(),
                'handled_at' => $comment->handled_at ?? now(),
                'handled_by' => $comment->handled_at === null ? $by?->id : $comment->handled_by,
                'needs_attention' => false,
                'attention_reason' => null,
                'last_action_error' => null,
            ])->save();
            $this->audit->record('social.comment.replied', $comment, $this->context($by, $rule, ['reply_id' => $reply->id]));
        });
        $this->ingestor->threadChanged($comment);

        return $reply;
    }

    /** Send one private (Messenger) reply to the commenter, where the platform offers it. */
    public function privateReply(AudienceComment $comment, string $text, ?TenantMembership $by, ?CommentRule $rule = null): void
    {
        $text = $this->text($text, (int) config('social_comments.limits.reply_max', 2000), 'message');
        $this->assertActionable($comment, ownCommentAllowed: false);

        $provider = $this->gateway->provider($comment->account);

        if (! $provider->supportsPrivateReplies($comment->account->scopes)) {
            throw new CommentActionFailed(__('Private replies are not available for this account.'), CommentActionFailed::NOT_ALLOWED, 'private_replies_unavailable');
        }

        $this->remote($comment, fn ($provider, $credentials) => $provider->privateReply($credentials, $comment->external_id, $text));

        DB::transaction(function () use ($comment, $by, $rule): void {
            $comment->forceFill(['replied_at' => $comment->replied_at ?? now(), 'last_action_error' => null])->save();
            $this->audit->record('social.comment.private_replied', $comment, $this->context($by, $rule));
        });
    }

    public function setHidden(AudienceComment $comment, bool $hidden, ?TenantMembership $by, ?CommentRule $rule = null): void
    {
        $this->assertActionable($comment, ownCommentAllowed: false);

        if (($comment->remote_state === RemoteState::Hidden) === $hidden) {
            return;
        }

        $this->remote($comment, fn ($provider, $credentials) => $provider->setHidden($credentials, $comment->external_id, $hidden));

        DB::transaction(function () use ($comment, $hidden, $by, $rule): void {
            $comment->forceFill(['remote_state' => $hidden ? RemoteState::Hidden : RemoteState::Visible, 'last_action_error' => null])->save();
            $this->audit->record($hidden ? 'social.comment.hidden' : 'social.comment.unhidden', $comment, $this->context($by, $rule));
        });
        $this->ingestor->threadChanged($comment);
    }

    public function delete(AudienceComment $comment, ?TenantMembership $by): void
    {
        if ($comment->remote_state === RemoteState::Deleted) {
            return;
        }

        try {
            $this->remote($comment, fn ($provider, $credentials) => $provider->delete($credentials, $comment->external_id));
        } catch (CommentActionFailed $failure) {
            // Already gone on the platform: the goal is reached.
            if ($failure->kind !== CommentActionFailed::GONE) {
                throw $failure;
            }
        }

        DB::transaction(function () use ($comment, $by): void {
            $comment->forceFill(['remote_state' => RemoteState::Deleted, 'needs_attention' => false, 'handled_at' => $comment->handled_at ?? now(), 'last_action_error' => null])->save();
            $this->audit->record('social.comment.removed', $comment, $this->context($by, null));
        });
        $this->ingestor->threadChanged($comment);
    }

    public function setLiked(AudienceComment $comment, bool $liked, ?TenantMembership $by): void
    {
        $this->assertActionable($comment, ownCommentAllowed: false);

        if ($comment->liked_by_page === $liked) {
            return;
        }

        $this->remote($comment, fn ($provider, $credentials) => $provider->setLiked($credentials, $comment->external_id, $liked));

        DB::transaction(function () use ($comment, $liked, $by): void {
            $comment->forceFill([
                'liked_by_page' => $liked,
                'like_count' => max(0, $comment->like_count + ($liked ? 1 : -1)),
                'last_action_error' => null,
            ])->save();
            $this->audit->record($liked ? 'social.comment.liked' : 'social.comment.unliked', $comment, $this->context($by, null));
        });
    }

    public function markRead(AudienceComment $comment, ?TenantMembership $by): void
    {
        if ($comment->read_at !== null) {
            return;
        }

        $comment->forceFill(['read_at' => now(), 'read_by' => $by?->id])->save();
    }

    public function markHandled(AudienceComment $comment, bool $handled, ?TenantMembership $by): void
    {
        if (($comment->handled_at !== null) === $handled) {
            return;
        }

        DB::transaction(function () use ($comment, $handled, $by): void {
            $comment->forceFill([
                'handled_at' => $handled ? now() : null,
                'handled_by' => $handled ? $by?->id : null,
                'needs_attention' => $handled ? false : $comment->needs_attention,
                'read_at' => $comment->read_at ?? ($handled ? now() : null),
            ])->save();
            $this->audit->record($handled ? 'social.comment.handled' : 'social.comment.reopened', $comment, $this->context($by, null));
        });
        $this->ingestor->threadChanged($comment);
    }

    /** Assign to a member (their department and branch come with it) or, with null, to nobody. */
    public function assign(AudienceComment $comment, ?TenantMembership $to, ?TenantMembership $by, ?CommentRule $rule = null): void
    {
        DB::transaction(function () use ($comment, $to, $by, $rule): void {
            $comment->assignOwner($to)->save();
            $this->audit->record('social.comment.assigned', $comment, $this->context($by, $rule, ['assignee_id' => $to?->id]));
        });
        $this->ingestor->threadChanged($comment);
    }

    public function addNote(AudienceComment $comment, ?TenantMembership $by, string $body): CommentNote
    {
        $body = $this->text($body, (int) config('social_comments.limits.note_max', 2000), 'body');

        return DB::transaction(function () use ($comment, $by, $body): CommentNote {
            $note = CommentNote::query()->create(['comment_id' => $comment->id, 'author_id' => $by?->id, 'body' => $body]);
            $this->audit->record('social.comment.note_added', $comment, ['note_id' => $note->id]);

            return $note;
        });
    }

    public function addTag(AudienceComment $comment, string $tag, ?TenantMembership $by, ?CommentRule $rule = null): void
    {
        $tag = UntrustedText::line($tag, (int) config('social_comments.limits.tag_max', 40));
        $tags = $comment->tags ?? [];

        if ($tag === '' || in_array($tag, $tags, true) || count($tags) >= (int) config('social_comments.limits.tags_max', 10)) {
            return;
        }

        DB::transaction(function () use ($comment, $tag, $tags, $by, $rule): void {
            $comment->forceFill(['tags' => [...$tags, $tag]])->save();
            $this->audit->record('social.comment.tagged', $comment, $this->context($by, $rule, ['tag' => $tag]));
        });
    }

    public function removeTag(AudienceComment $comment, string $tag, ?TenantMembership $by): void
    {
        $tags = $comment->tags ?? [];

        if (! in_array($tag, $tags, true)) {
            return;
        }

        DB::transaction(function () use ($comment, $tag, $tags, $by): void {
            $comment->forceFill(['tags' => array_values(array_diff($tags, [$tag]))])->save();
            $this->audit->record('social.comment.untagged', $comment, $this->context($by, null, ['tag' => $tag]));
        });
    }

    /** Put the comment in front of a person (a rule's "send to review"). */
    public function flagAttention(AudienceComment $comment, string $reason, ?TenantMembership $by = null, ?CommentRule $rule = null): void
    {
        if ($comment->needs_attention && $comment->attention_reason === $reason) {
            return;
        }

        DB::transaction(function () use ($comment, $reason, $by, $rule): void {
            $comment->forceFill([
                'needs_attention' => true,
                'attention_reason' => mb_substr($reason, 0, 64),
                // A flagged comment is open again.
                'handled_at' => null,
                'handled_by' => null,
            ])->save();
            $this->audit->record('social.comment.flagged', $comment, $this->context($by, $rule, ['reason' => mb_substr($reason, 0, 64)]));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(CommentProvider, AccountCredentials): T  $call
     * @return T
     */
    private function remote(AudienceComment $comment, Closure $call): mixed
    {
        try {
            return $this->gateway->call($comment->account, $call);
        } catch (CommentActionFailed $failure) {
            if ($failure->kind === CommentActionFailed::GONE) {
                $comment->forceFill(['remote_state' => RemoteState::Deleted, 'last_action_error' => $failure->errorCode])->save();
            } elseif (! $failure->isTemporary()) {
                $comment->forceFill(['last_action_error' => mb_substr($failure->errorCode, 0, 64), 'needs_attention' => true, 'attention_reason' => 'action_failed'])->save();
            }

            throw $failure;
        }
    }

    private function assertActionable(AudienceComment $comment, bool $ownCommentAllowed): void
    {
        if ($comment->remote_state === RemoteState::Deleted) {
            throw new CommentActionFailed(__('This comment was deleted.'), CommentActionFailed::GONE, 'comment_gone');
        }

        if ($comment->is_from_page && ! $ownCommentAllowed) {
            throw new CommentActionFailed(__('This is the Page\'s own comment.'), CommentActionFailed::NOT_ALLOWED, 'own_comment');
        }
    }

    private function text(string $value, int $max, string $field): string
    {
        $clean = UntrustedText::clean($value, $max + 1);

        if ($clean === '' || mb_strlen($clean) > $max) {
            throw ValidationException::withMessages([$field => __('Write between 1 and :max characters.', ['max' => $max])]);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(?TenantMembership $by, ?CommentRule $rule, array $extra = []): array
    {
        return [
            ...$extra,
            'automatic' => $rule !== null,
            'rule_id' => $rule?->id,
            'membership_id' => $by !== null ? $by->id : $rule?->actor_id,
        ];
    }
}
