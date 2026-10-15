<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Inbox;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Communication\Contracts\InboxReplies;
use App\Core\Communication\Contracts\InboxSource;
use App\Core\Communication\Inbox\InboxCursor;
use App\Core\Communication\Inbox\InboxItem;
use App\Core\Communication\Inbox\InboxMessage;
use App\Core\Communication\Inbox\InboxQuery;
use App\Core\Communication\Inbox\InboxSourceDefinition;
use App\Core\Communication\Inbox\InboxState;
use App\Core\Communication\Inbox\InboxThread;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Modules\Social\Comments\Actions\CommentActions;
use App\Modules\Social\Comments\CommentGateway;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Http\Controllers\AudienceCommentController;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Enums\AccountStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Facebook Page comments in the unified inbox (ADR 0030). One item is one conversation: a top-level comment someone
 * wrote on a connected Page's post, with the replies under it. Comments are narrowed exactly as on this app's
 * comments page (the viewer's scope of `social.comments.view`, never the Page's own comments).
 *
 * A thread is "awaiting an answer" while one of its audience comments is neither handled nor deleted (the comments
 * page's "open"); the item's unread count is how many. A reply written in the inbox answers the newest such comment,
 * or the top-level comment when none awaits, through {@see CommentActions::reply()}; nothing here calls the platform.
 */
final class CommentInboxSource implements InboxReplies, InboxSource
{
    /** The source key; the app's realtime signals name it too (ADR 0035). */
    public const string KEY = 'social.comments';

    private const string PLATFORM = 'facebook';

    private const string TABLE = 'social_audience_comments';

    /**
     * The top-level comment a row belongs to. Facebook threads are two levels deep; a reply the Page wrote to a reply
     * is a third, so the thread is found through the row's parent as well.
     */
    private const string THREAD = 'COALESCE(parents.parent_id, social_audience_comments.parent_id, social_audience_comments.id)';

    /** {@see THREAD} for a reply, whose `parent_id` is never null. */
    private const string REPLY_THREAD = 'COALESCE(parents.parent_id, social_audience_comments.parent_id)';

    private const string WRITTEN_AT = 'COALESCE(social_audience_comments.commented_at, social_audience_comments.received_at)';

    /** Levels of replies read under a top-level comment, and rows per level, as on the comments page. */
    private const int DEPTH = 5;

    private const int PER_LEVEL = 200;

    public function __construct(
        private readonly RecordAccess $records,
        private readonly AccessChecker $checker,
        private readonly CommentActions $actions,
        private readonly CommentGateway $gateway,
    ) {}

    public function definition(): InboxSourceDefinition
    {
        return new InboxSourceDefinition(key: self::KEY, module: 'social', channel: self::PLATFORM, label: 'Facebook comments', permission: AudienceCommentController::VIEW);
    }

    public function items(InboxQuery $query, ?InboxCursor $after = null): array
    {
        $threads = $this->filtered($query)
            ->where(function (Builder $states) use ($query): void {
                foreach ($query->states as $state) {
                    $states->orWhere(fn (Builder $one) => $this->inState($one, $state));
                }
            })
            ->when($after !== null, fn (Builder $older) => $older->where(fn (Builder $cursor) => $cursor
                ->where('thread_activity.last_at', '<', $after?->at)
                ->orWhere(fn (Builder $same) => $same->where('thread_activity.last_at', $after?->at)->where(self::TABLE.'.id', '<', $after?->id))))
            ->with(['account:id,name', 'owner.user:id,name'])
            ->orderByDesc('thread_activity.last_at')
            ->orderByDesc(self::TABLE.'.id')
            ->limit($query->limit + 1)
            ->get();
        $latest = $this->latestTexts($threads->modelKeys());

        return $threads->map(function (AudienceComment $root) use ($latest): InboxItem {
            $awaiting = (int) $root->getAttribute('awaiting');
            $preview = $latest[$root->id] ?? $this->text($root);

            return new InboxItem(
                id: $root->id,
                title: $this->title($root),
                preview: $preview === null ? null : mb_substr($preview, 0, 140),
                at: $this->lastActivity($root),
                state: $awaiting === 0 ? InboxState::Closed : ($root->owner_id === null ? InboxState::Unassigned : InboxState::Active),
                url: route('social.comments.index', ['comment' => $root->id]),
                ownerName: $root->owner?->user->name,
                unread: $awaiting,
            );
        })->values()->all();
    }

    public function counts(InboxQuery $query): array
    {
        // One pass over the threads, as the comments page counts its filters.
        $row = $this->filtered($query)->toBase()->cloneWithout(['columns'])
            ->selectRaw('SUM(CASE WHEN thread_activity.awaiting > 0 AND social_audience_comments.owner_id IS NULL THEN 1 ELSE 0 END) AS unassigned_count')
            ->selectRaw('SUM(CASE WHEN thread_activity.awaiting > 0 AND social_audience_comments.owner_id IS NOT NULL THEN 1 ELSE 0 END) AS active_count')
            ->selectRaw('SUM(CASE WHEN thread_activity.awaiting = 0 THEN 1 ELSE 0 END) AS closed_count')
            ->first();

        return [
            InboxState::Unassigned->value => (int) ($row->unassigned_count ?? 0),
            InboxState::Active->value => (int) ($row->active_count ?? 0),
            InboxState::Closed->value => (int) ($row->closed_count ?? 0),
        ];
    }

    public function thread(string $id, User $viewer, int $limit = self::DEFAULT_LIMIT): ?InboxThread
    {
        $root = $this->root($id, $viewer);

        if ($root === null) {
            return null;
        }

        $comments = $this->conversation($root);
        $target = $this->target($root, $comments);
        $replyable = $this->records->allows($viewer, AudienceCommentController::REPLY, $target);
        $blocked = $replyable ? $this->replyBlockedReason($target) : null;
        $total = $comments->count();

        return new InboxThread(
            sourceKey: $this->definition()->key,
            id: $root->id,
            title: $this->title($root),
            channel: self::PLATFORM,
            contactId: null,
            url: route('social.comments.index', ['comment' => $root->id]),
            canReply: $replyable && $blocked === null,
            replyBlockedReason: $blocked,
            messages: $comments->slice(max(0, $total - $limit))->map(fn (AudienceComment $comment): InboxMessage => $this->message($comment))->values()->all(),
            hasOlder: $total > $limit,
            subtitle: $this->postTitle($root),
        );
    }

    public function reply(string $id, string $text, User $viewer): InboxMessage
    {
        $root = $this->root($id, $viewer) ?? throw new NotFoundHttpException;
        $target = $this->target($root, $this->conversation($root));

        // The same record scope as this app's own reply route, on the comment that is answered.
        if (! $this->records->allows($viewer, AudienceCommentController::REPLY, $target)) {
            throw new NotFoundHttpException;
        }

        $membership = $this->checker->membership($viewer) ?? throw new AccessDeniedHttpException;
        $target->loadMissing(['account', 'post']);

        try {
            $reply = $this->actions->reply($target, $text, $membership);
        } catch (CommentActionFailed $refused) {
            // Written for a person, never carrying a token or a request (CommentActionFailed).
            throw ValidationException::withMessages(['text' => $refused->getMessage()]);
        } catch (ValidationException $refused) {
            throw ValidationException::withMessages(['text' => array_values(array_map('strval', Arr::flatten($refused->errors())))]);
        }

        return $this->message($reply);
    }

    public function replyPermission(): string
    {
        return AudienceCommentController::REPLY;
    }

    /** The top-level comment, when the viewer may see it on the comments page. */
    private function root(string $id, User $viewer): ?AudienceComment
    {
        return $this->records->constrain(AudienceComment::query(), $viewer, AudienceCommentController::VIEW)
            ->where('platform', self::PLATFORM)
            ->where('is_from_page', false)
            ->whereNull('parent_id')
            ->with(['account', 'post:id,title,body'])
            ->find($id);
    }

    /**
     * The top-level comment and every reply under it, oldest first, read as the comments page reads a thread.
     *
     * @return Collection<int, AudienceComment>
     */
    private function conversation(AudienceComment $root): Collection
    {
        $all = new Collection([$root]);
        $frontier = [$root->id];

        for ($depth = 0; $depth < self::DEPTH && $frontier !== []; $depth++) {
            $children = AudienceComment::query()->with('account')->whereIn('parent_id', $frontier)->orderBy('commented_at')->limit(self::PER_LEVEL)->get();
            $all = $all->merge($children);
            $frontier = $children->modelKeys();
        }

        return $all->sortBy(fn (AudienceComment $comment): string => $this->writtenAt($comment)->format('Y-m-d H:i:s.u').$comment->id)->values();
    }

    /**
     * The comment a reply from the inbox answers: the newest audience comment still awaiting an answer, else the
     * newest one that was not deleted, else the top-level comment.
     *
     * @param  Collection<int, AudienceComment>  $comments
     */
    private function target(AudienceComment $root, Collection $comments): AudienceComment
    {
        $audience = $comments->reverse()->filter(static fn (AudienceComment $comment): bool => ! $comment->is_from_page && $comment->remote_state !== RemoteState::Deleted);

        return $audience->first(static fn (AudienceComment $comment): bool => $comment->handled_at === null)
            ?? $audience->first()
            ?? $root;
    }

    /** Why a reply would be refused now. Shown only: {@see CommentActions::reply()} decides again. */
    private function replyBlockedReason(AudienceComment $target): ?string
    {
        if ($target->remote_state === RemoteState::Deleted) {
            return __('This comment was deleted.');
        }

        if (! $this->gateway->available($target->account)) {
            return __('Comments are not available for this platform on this installation.');
        }

        if ($target->account->status !== AccountStatus::Connected) {
            return __('This account must be connected again before comments can be used.');
        }

        return null;
    }

    /** @return Builder<AudienceComment> */
    private function filtered(InboxQuery $query): Builder
    {
        return $this->threads($query->user)
            // "Unread" is the item's own count: comments of the thread still awaiting an answer.
            ->when($query->unread, fn (Builder $awaiting) => $awaiting->where('thread_activity.awaiting', '>', 0))
            ->when($query->owner === 'me', fn (Builder $mine) => $mine->where(self::TABLE.'.owner_id', $query->membershipId))
            ->when($query->owner === 'unassigned', fn (Builder $nobody) => $nobody->whereNull(self::TABLE.'.owner_id'))
            ->when($query->search !== '', fn (Builder $found) => $found->where(fn (Builder $match) => $match
                ->where(self::TABLE.'.author_name', 'like', Like::contains($query->search))
                ->orWhereIn(self::TABLE.'.id', $this->rows()
                    ->where(self::TABLE.'.message', 'like', Like::contains($query->search))
                    ->selectRaw(self::THREAD))));
    }

    /**
     * Top-level audience comments the viewer may see, each with its thread's last activity and the number of its
     * comments awaiting an answer.
     *
     * @return Builder<AudienceComment>
     */
    private function threads(User $viewer): Builder
    {
        $activity = $this->rows()
            ->selectRaw(self::THREAD.' AS thread_id')
            ->selectRaw('MAX('.self::WRITTEN_AT.') AS last_at')
            ->selectRaw('SUM(CASE WHEN social_audience_comments.is_from_page = 0 AND social_audience_comments.handled_at IS NULL AND social_audience_comments.remote_state <> ? THEN 1 ELSE 0 END) AS awaiting', [RemoteState::Deleted->value])
            ->groupByRaw(self::THREAD);

        return $this->records->constrain(AudienceComment::query(), $viewer, AudienceCommentController::VIEW)
            ->joinSub($activity, 'thread_activity', 'thread_activity.thread_id', '=', self::TABLE.'.id')
            ->select([self::TABLE.'.*', 'thread_activity.last_at', 'thread_activity.awaiting'])
            ->where(self::TABLE.'.platform', self::PLATFORM)
            ->where(self::TABLE.'.is_from_page', false)
            ->whereNull(self::TABLE.'.parent_id');
    }

    /**
     * The workspace's comments with their parent row, so {@see THREAD} can name each row's top-level comment.
     *
     * @return Builder<AudienceComment>
     */
    private function rows(): Builder
    {
        return AudienceComment::query()->leftJoin(self::TABLE.' as parents', function (JoinClause $join): void {
            $join->on('parents.id', '=', self::TABLE.'.parent_id')->on('parents.tenant_id', '=', self::TABLE.'.tenant_id');
        });
    }

    /**
     * @param  Builder<AudienceComment>  $query
     * @return Builder<AudienceComment>
     */
    private function inState(Builder $query, InboxState $state): Builder
    {
        return match ($state) {
            InboxState::Unassigned => $query->where('thread_activity.awaiting', '>', 0)->whereNull(self::TABLE.'.owner_id'),
            InboxState::Active => $query->where('thread_activity.awaiting', '>', 0)->whereNotNull(self::TABLE.'.owner_id'),
            InboxState::Closed => $query->where('thread_activity.awaiting', 0),
        };
    }

    /**
     * The newest reply text of each thread that has replies (the caller falls back to the top-level comment's own).
     * Every lookup is by `parent_id`, so it stays on the `social_audience_comments_thread` index.
     *
     * @param  list<string>  $rootIds
     * @return array<string, string|null>
     */
    private function latestTexts(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $replies = AudienceComment::query()->whereIn('parent_id', $rootIds)->pluck('id')->all();
        $ranked = $this->rows()
            ->whereIn(self::TABLE.'.parent_id', [...$rootIds, ...$replies])
            ->select([self::TABLE.'.message', self::TABLE.'.has_attachment'])
            ->selectRaw(self::REPLY_THREAD.' AS thread_id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.self::REPLY_THREAD.' ORDER BY '.self::WRITTEN_AT.' DESC, social_audience_comments.id DESC) AS position');

        $texts = [];

        // The derived table is the tenant-scoped query above; nothing outside it is read.
        foreach (DB::query()->fromSub($ranked, 'ranked')->where('position', 1)->get(['thread_id', 'message', 'has_attachment']) as $row) {
            $message = is_string($row->message) && $row->message !== '' ? $row->message : null;
            $texts[(string) $row->thread_id] = $message ?? ((bool) $row->has_attachment ? __('Attachment') : null);
        }

        return $texts;
    }

    private function message(AudienceComment $comment): InboxMessage
    {
        return new InboxMessage(
            id: $comment->id,
            direction: $comment->is_from_page ? InboxMessage::OUT : InboxMessage::IN,
            text: $comment->message === null || $comment->message === '' ? null : $comment->message,
            at: $this->writtenAt($comment),
            authorName: $comment->author_name,
            status: match ($comment->remote_state) {
                RemoteState::Hidden => 'hidden',
                RemoteState::Deleted => 'deleted',
                RemoteState::Visible => null,
            },
            attachmentLabel: $comment->has_attachment ? __('Attachment') : null,
        );
    }

    private function title(AudienceComment $root): string
    {
        return __(':name on :page', ['name' => $root->author_name ?? __('A Facebook user'), 'page' => $root->account->name]);
    }

    private function postTitle(AudienceComment $root): ?string
    {
        $post = $root->post;
        $title = $post === null ? '' : (string) ($post->title ?? Str::limit((string) $post->body, 80));

        return $title === '' ? null : $title;
    }

    private function text(AudienceComment $comment): ?string
    {
        if ($comment->message !== null && $comment->message !== '') {
            return $comment->message;
        }

        return $comment->has_attachment ? __('Attachment') : null;
    }

    private function writtenAt(AudienceComment $comment): CarbonImmutable
    {
        return CarbonImmutable::instance($comment->commented_at ?? $comment->received_at)->utc();
    }

    private function lastActivity(AudienceComment $root): CarbonImmutable
    {
        $at = $root->getAttribute('last_at');

        return is_string($at) ? CarbonImmutable::parse($at, (string) config('app.timezone'))->utc() : $this->writtenAt($root);
    }
}
