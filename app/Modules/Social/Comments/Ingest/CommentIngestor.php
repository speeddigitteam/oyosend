<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Ingest;

use App\Core\Communication\Contracts\InboxSignals;
use App\Modules\Social\Comments\Actions\CommentSettings;
use App\Modules\Social\Comments\Data\RemoteComment;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Events\CommentReceived;
use App\Modules\Social\Comments\Inbox\CommentInboxSource;
use App\Modules\Social\Comments\Jobs\EvaluateCommentRules;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Support\UntrustedText;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single door into the inbox. Webhook deliveries, polls and our own replies all come through
 * {@see self::store()}: text is cleaned, the comment is deduplicated by the platform's id (a replayed delivery or
 * an overlapping poll changes nothing), and only a genuinely new comment announces itself and starts the rules.
 */
final class CommentIngestor
{
    public function __construct(
        private readonly CommentSettings $settings,
        private readonly InboxSignals $signals,
    ) {}

    /** @return array{0: AudienceComment, 1: bool} The comment and whether it was new. */
    public function store(SocialAccount $account, RemoteComment $remote, string $source): array
    {
        $message = UntrustedText::clean($remote->message, AudienceComment::MESSAGE_MAX);
        $existing = $this->find($account, $remote->id);

        if ($existing !== null) {
            return [$this->refresh($existing, $remote, $message), false];
        }

        $isFromPage = $remote->authorId !== null && $remote->authorId === $account->external_id;
        $parent = $remote->parentId === null ? null : $this->find($account, $remote->parentId);

        $comment = new AudienceComment([
            'account_id' => $account->id,
            'post_id' => $this->postFor($account, $remote->postId),
            'platform' => $account->platform,
            'external_id' => $remote->id,
            'external_post_id' => $remote->postId,
            'parent_external_id' => $remote->parentId,
            'parent_id' => $parent?->id,
            'author_external_id' => $remote->authorId,
            'author_name' => $remote->authorName === null ? null : UntrustedText::line($remote->authorName, 190),
            'message' => $message === '' ? null : $message,
            'has_attachment' => $remote->hasAttachment,
            'is_from_page' => $isFromPage,
            'like_count' => $remote->likeCount,
            'permalink' => $remote->permalink,
            'remote_state' => $remote->isHidden ? RemoteState::Hidden : RemoteState::Visible,
            'source' => $source,
            'commented_at' => $remote->createdAt ?? CarbonImmutable::now(),
            'received_at' => now(),
            // A comment by the Page itself needs nobody's attention.
            'handled_at' => $isFromPage ? now() : null,
        ]);
        // Incoming audience comments belong to nobody until a person (or a rule) is given them.
        $comment->keepUnowned();

        try {
            DB::transaction(function () use ($comment, $parent, $isFromPage): void {
                $comment->save();

                if ($isFromPage && $parent !== null) {
                    // The Page answered natively on the platform: the thread is answered.
                    $parent->forceFill(['replied_at' => $comment->commented_at, 'reply_count' => $parent->reply_count + 1])->save();
                }
            });
        } catch (UniqueConstraintViolationException) {
            $found = $this->find($account, $remote->id);

            if ($found === null) {
                throw new \RuntimeException('Comment disappeared after a duplicate key.');
            }

            return [$found, false];
        }

        // The inbox lists a thread by its top-level comment, at most two levels up (CommentInboxSource::THREAD).
        $this->signals->changed(CommentInboxSource::KEY, $parent === null ? $comment->id : ($parent->parent_id ?? $parent->id));

        if (! $isFromPage) {
            event(CommentReceived::from($comment));
            $this->startRules($comment);
        }

        return [$comment, true];
    }

    /** The platform says the comment is gone. */
    public function markDeleted(SocialAccount $account, string $externalId): void
    {
        AudienceComment::query()->where('account_id', $account->id)->where('external_id', $externalId)
            ->update(['remote_state' => RemoteState::Deleted->value, 'needs_attention' => false]);
        $this->announce($account, $externalId);
    }

    public function markHidden(SocialAccount $account, string $externalId, bool $hidden): void
    {
        AudienceComment::query()->where('account_id', $account->id)->where('external_id', $externalId)
            ->where('remote_state', '!=', RemoteState::Deleted->value)
            ->update(['remote_state' => ($hidden ? RemoteState::Hidden : RemoteState::Visible)->value]);
        $this->announce($account, $externalId);
    }

    /** Tell open inbox pages that the comment's thread changed (ADR 0035). */
    public function threadChanged(AudienceComment $comment): void
    {
        $root = $comment->parent_id === null
            ? $comment->id
            : (AudienceComment::query()->whereKey($comment->parent_id)->value('parent_id') ?? $comment->parent_id);

        $this->signals->changed(CommentInboxSource::KEY, (string) $root);
    }

    private function announce(SocialAccount $account, string $externalId): void
    {
        $comment = $this->find($account, $externalId);

        if ($comment !== null) {
            $this->threadChanged($comment);
        }
    }

    private function find(SocialAccount $account, string $externalId): ?AudienceComment
    {
        return AudienceComment::query()->where('account_id', $account->id)->where('external_id', $externalId)->first();
    }

    private function refresh(AudienceComment $comment, RemoteComment $remote, string $message): AudienceComment
    {
        $changes = [];

        if ($message !== '' && $message !== $comment->message) {
            $changes['message'] = $message;
        }

        if ($remote->likeCount !== $comment->like_count && $remote->likeCount >= 0) {
            $changes['like_count'] = $remote->likeCount;
        }

        if ($comment->remote_state !== RemoteState::Deleted && $remote->isHidden !== ($comment->remote_state === RemoteState::Hidden)) {
            $changes['remote_state'] = ($remote->isHidden ? RemoteState::Hidden : RemoteState::Visible)->value;
        }

        if ($changes !== []) {
            $comment->forceFill($changes)->save();
        }

        return $comment;
    }

    private function postFor(SocialAccount $account, ?string $externalPostId): ?string
    {
        if ($externalPostId === null) {
            return null;
        }

        $id = PostTarget::query()->where('account_id', $account->id)->where('external_id', $externalPostId)->value('post_id');

        return is_string($id) ? $id : null;
    }

    private function startRules(AudienceComment $comment): void
    {
        if ($this->settings->automationPaused() || ! CommentRule::query()->where('is_enabled', true)->exists()) {
            return;
        }

        EvaluateCommentRules::dispatch($comment->id);
    }
}
