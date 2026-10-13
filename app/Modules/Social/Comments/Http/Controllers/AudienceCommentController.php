<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Social\Comments\Actions\CommentActions;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Enums\InboxStatus;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Http\CommentPresenter;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Http\Controllers\SocialSupport;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audience comments inbox. Every record is narrowed to the viewer's scope of the permission each action
 * needs (a comment outside it answers 404), and the platform is only ever called through {@see CommentActions}.
 */
final class AudienceCommentController
{
    public const string VIEW = 'social.comments.view';

    public const string REPLY = 'social.comments.reply';

    public const string MODERATE = 'social.comments.moderate';

    public const string RULES = 'social.comments.manage_rules';

    private const array BULK = [
        'read' => self::VIEW,
        'handled' => self::REPLY,
        'reopen' => self::REPLY,
        'hide' => self::MODERATE,
        'unhide' => self::MODERATE,
        'assign' => self::MODERATE,
        'unassign' => self::MODERATE,
        'tag' => self::MODERATE,
    ];

    public function __construct(
        private readonly RecordAccess $records,
        private readonly CommentActions $actions,
        private readonly CommentPresenter $presenter,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InboxStatus::class)],
            'account_id' => ['nullable', 'string', 'size:26'],
            'post_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'size:26'],
        ]);
        $status = InboxStatus::tryFrom((string) ($filters['status'] ?? '')) ?? InboxStatus::Open;
        $membership = $this->support->membership($actor);

        $scoped = fn (): Builder => $this->records->constrain(AudienceComment::query(), $actor, self::VIEW)->where('is_from_page', false);

        $query = $scoped()
            ->with(['account', 'post', 'owner.user'])
            ->when($filters['account_id'] ?? null, fn (Builder $q, string $id) => $q->where('account_id', $id))
            ->when($filters['post_id'] ?? null, fn (Builder $q, string $id) => $q->where('post_id', $id))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(function (Builder $match) use ($term): void {
                $match->where('message', 'like', Like::contains($term))->orWhere('author_name', 'like', Like::contains($term));
            }));
        $this->filter($query, $status, $membership);

        $comments = $query->orderByDesc('commented_at')->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn (AudienceComment $comment): array => $this->presenter->item($comment));

        $selected = isset($filters['comment']) ? $this->thread($actor, $filters['comment']) : null;

        return Inertia::render('Modules/Social/Pages/Comments/Index', [
            'comments' => $comments,
            'filters' => [
                'status' => $status->value,
                'account_id' => $filters['account_id'] ?? null,
                'post_id' => $filters['post_id'] ?? null,
                'q' => $filters['q'] ?? null,
            ],
            'counts' => $this->counts($scoped(), $membership),
            'thread' => $selected,
            'accounts' => SocialAccount::query()->where('status', '!=', AccountStatus::Disconnected->value)
                ->whereIn('id', CommentSource::query()->select('account_id'))->orderBy('name')->get()
                ->map(fn (SocialAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'platform' => $account->platform])->values()->all(),
            'posts' => $this->postOptions($scoped()),
            'members' => $actor->can(self::MODERATE) ? $this->members() : [],
            'can' => [
                'reply' => $actor->can(self::REPLY),
                'moderate' => $actor->can(self::MODERATE),
                'rules' => $actor->can(self::RULES),
            ],
            'limits' => [
                'reply' => (int) config('social_comments.limits.reply_max', 2000),
                'note' => (int) config('social_comments.limits.note_max', 2000),
                'tag' => (int) config('social_comments.limits.tag_max', 40),
                'bulk' => (int) config('social_comments.limits.bulk_max', 50),
            ],
            'hasSources' => CommentSource::query()->where('is_enabled', true)->exists(),
        ]);
    }

    public function read(Request $request, string $comment): RedirectResponse
    {
        $this->actions->markRead($this->target($request, $comment, self::VIEW), $this->support->membership($this->support->actor($request)));

        return back();
    }

    public function reply(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:'.(int) config('social_comments.limits.reply_max', 2000) * 2]]);

        return $this->attempt(function () use ($request, $comment, $data): void {
            $this->actions->reply($this->target($request, $comment, self::REPLY), (string) $data['message'], $this->support->membership($this->support->actor($request)));
            Toast::success(__('Reply sent.'));
        });
    }

    public function like(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['liked' => ['required', 'boolean']]);

        return $this->attempt(function () use ($request, $comment, $data): void {
            $this->actions->setLiked($this->target($request, $comment, self::REPLY), (bool) $data['liked'], $this->support->membership($this->support->actor($request)));
        });
    }

    public function hidden(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        return $this->attempt(function () use ($request, $comment, $data): void {
            $this->actions->setHidden($this->target($request, $comment, self::MODERATE), (bool) $data['hidden'], $this->support->membership($this->support->actor($request)));
            Toast::success($data['hidden'] ? __('Comment hidden.') : __('Comment is visible again.'));
        });
    }

    public function destroy(Request $request, string $comment): RedirectResponse
    {
        return $this->attempt(function () use ($request, $comment): void {
            $this->actions->delete($this->target($request, $comment, self::MODERATE), $this->support->membership($this->support->actor($request)));
            Toast::success(__('Comment deleted.'));
        });
    }

    public function handled(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['handled' => ['required', 'boolean']]);
        $this->actions->markHandled($this->target($request, $comment, self::REPLY), (bool) $data['handled'], $this->support->membership($this->support->actor($request)));

        return back();
    }

    public function assign(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['membership_id' => ['nullable', 'string', 'size:26']]);
        $record = $this->target($request, $comment, self::MODERATE);
        $to = isset($data['membership_id']) ? $this->activeMember($data['membership_id']) : null;
        $this->actions->assign($record, $to, $this->support->membership($this->support->actor($request)));
        Toast::success($to === null ? __('Comment unassigned.') : __('Comment assigned.'));

        return back();
    }

    public function note(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.(int) config('social_comments.limits.note_max', 2000) * 2]]);
        $this->actions->addNote($this->target($request, $comment, self::REPLY), $this->support->membership($this->support->actor($request)), (string) $data['body']);
        Toast::success(__('Note added.'));

        return back();
    }

    public function tag(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['tag' => ['required', 'string', 'max:'.(int) config('social_comments.limits.tag_max', 40) * 2]]);
        $this->actions->addTag($this->target($request, $comment, self::MODERATE), (string) $data['tag'], $this->support->membership($this->support->actor($request)));

        return back();
    }

    public function untag(Request $request, string $comment): RedirectResponse
    {
        $data = $request->validate(['tag' => ['required', 'string', 'max:200']]);
        $this->actions->removeTag($this->target($request, $comment, self::MODERATE), (string) $data['tag'], $this->support->membership($this->support->actor($request)));

        return back();
    }

    public function bulk(Request $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $max = (int) config('social_comments.limits.bulk_max', 50);
        $data = $request->validate([
            'operation' => ['required', Rule::in(array_keys(self::BULK))],
            'ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'ids.*' => ['string', 'size:26', 'distinct'],
            'membership_id' => ['nullable', 'string', 'size:26'],
            'tag' => ['nullable', 'string', 'max:200'],
        ]);
        $operation = (string) $data['operation'];
        $permission = self::BULK[$operation];
        abort_unless($actor->can($permission), 403);

        $by = $this->support->membership($actor);
        $to = $operation === 'assign' ? $this->activeMember((string) ($data['membership_id'] ?? '')) : null;

        if ($operation === 'tag' && trim((string) ($data['tag'] ?? '')) === '') {
            throw ValidationException::withMessages(['tag' => __('Write the tag to add.')]);
        }

        // Only records the viewer's scope reaches are touched; the rest are not disclosed.
        $comments = $this->records->constrain(AudienceComment::query(), $actor, $permission)->with('account')->whereIn('id', $data['ids'])->get();
        $done = 0;
        $failed = 0;

        foreach ($comments as $comment) {
            try {
                match ($operation) {
                    'read' => $this->actions->markRead($comment, $by),
                    'handled' => $this->actions->markHandled($comment, true, $by),
                    'reopen' => $this->actions->markHandled($comment, false, $by),
                    'hide' => $this->actions->setHidden($comment, true, $by),
                    'unhide' => $this->actions->setHidden($comment, false, $by),
                    'assign' => $this->actions->assign($comment, $to, $by),
                    'unassign' => $this->actions->assign($comment, null, $by),
                    'tag' => $this->actions->addTag($comment, (string) $data['tag'], $by),
                    default => null,
                };
                $done++;
            } catch (CommentActionFailed) {
                $failed++;
            }
        }

        $failed === 0
            ? Toast::success(__(':count comments updated.', ['count' => $done]))
            : Toast::warning(__(':done comments updated, :failed could not be changed.', ['done' => $done, 'failed' => $failed]));

        return back();
    }

    /** Run a platform-facing action and turn a refusal into a message instead of an error page. */
    private function attempt(Closure $action): RedirectResponse
    {
        try {
            $action();
        } catch (CommentActionFailed $failure) {
            Toast::error($failure->getMessage());
        }

        return back();
    }

    private function target(Request $request, string $id, string $permission): AudienceComment
    {
        $comment = AudienceComment::query()->with(['account', 'post'])->findOrFail($id);
        $this->records->authorize($this->support->actor($request), $permission, $comment);

        return $comment;
    }

    private function activeMember(string $id): TenantMembership
    {
        return TenantMembership::query()->with('user')->where('status', MembershipStatus::Active->value)->find($id)
            ?? throw ValidationException::withMessages(['membership_id' => __('Choose an active team member.')]);
    }

    /** @return list<array{id: string, name: string}> */
    private function members(): array
    {
        return TenantMembership::query()->with('user:id,name')->where('status', MembershipStatus::Active->value)->get()
            ->map(fn (TenantMembership $membership): array => $this->presenter->member($membership))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * The selected comment's whole conversation and its internal notes.
     *
     * @return array<string, mixed>|null
     */
    private function thread(User $actor, string $id): ?array
    {
        $comment = $this->records->constrain(AudienceComment::query(), $actor, self::VIEW)->with(['account', 'post', 'owner.user'])->find($id);

        if ($comment === null) {
            return null;
        }

        $root = $comment;

        for ($depth = 0; $depth < 5 && $root->parent_id !== null; $depth++) {
            $parent = AudienceComment::query()->with(['account', 'post', 'owner.user'])->find($root->parent_id);

            if ($parent === null) {
                break;
            }

            $root = $parent;
        }

        $all = collect([$root]);
        $frontier = [$root->id];

        for ($depth = 0; $depth < 5 && $frontier !== []; $depth++) {
            $children = AudienceComment::query()->with(['account', 'post', 'owner.user'])->whereIn('parent_id', $frontier)->orderBy('commented_at')->limit(200)->get();
            $all = $all->merge($children);
            $frontier = $children->pluck('id')->all();
        }

        return [
            'focus' => $comment->id,
            'root' => $root->id,
            'comments' => $all->unique('id')->sortBy('commented_at')->values()->map(fn (AudienceComment $item): array => $this->presenter->item($item))->all(),
            'notes' => $comment->notes()->with('author.user:id,name')->latest()->limit(50)->get()->map(fn ($note): array => $this->presenter->note($note))->all(),
            'providerAvailable' => true,
        ];
    }

    /** @param Builder<AudienceComment> $query */
    private function filter(Builder $query, InboxStatus $status, TenantMembership $membership): void
    {
        $notDeleted = RemoteState::Deleted->value;

        match ($status) {
            InboxStatus::Open => $query->whereNull('handled_at')->where('remote_state', '!=', $notDeleted),
            InboxStatus::Unread => $query->whereNull('read_at')->whereNull('handled_at')->where('remote_state', '!=', $notDeleted),
            InboxStatus::Replied => $query->whereNotNull('replied_at'),
            InboxStatus::Hidden => $query->where('remote_state', RemoteState::Hidden->value),
            InboxStatus::NeedsAttention => $query->where('needs_attention', true)->where('remote_state', '!=', $notDeleted),
            InboxStatus::Handled => $query->whereNotNull('handled_at'),
            InboxStatus::Mine => $query->where('owner_id', $membership->id)->whereNull('handled_at'),
        };
    }

    /**
     * @param  Builder<AudienceComment>  $base
     * @return array<string, int>
     */
    private function counts(Builder $base, TenantMembership $membership): array
    {
        $row = $base->toBase()->selectRaw(
            'SUM(CASE WHEN handled_at IS NULL AND remote_state != ? THEN 1 ELSE 0 END) as open_count, '
            .'SUM(CASE WHEN read_at IS NULL AND handled_at IS NULL AND remote_state != ? THEN 1 ELSE 0 END) as unread_count, '
            .'SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied_count, '
            .'SUM(CASE WHEN remote_state = ? THEN 1 ELSE 0 END) as hidden_count, '
            .'SUM(CASE WHEN needs_attention = 1 AND remote_state != ? THEN 1 ELSE 0 END) as attention_count, '
            .'SUM(CASE WHEN handled_at IS NOT NULL THEN 1 ELSE 0 END) as handled_count, '
            .'SUM(CASE WHEN owner_id = ? AND handled_at IS NULL THEN 1 ELSE 0 END) as mine_count',
            [RemoteState::Deleted->value, RemoteState::Deleted->value, RemoteState::Hidden->value, RemoteState::Deleted->value, $membership->id],
        )->first();

        return [
            InboxStatus::Open->value => (int) ($row->open_count ?? 0),
            InboxStatus::Unread->value => (int) ($row->unread_count ?? 0),
            InboxStatus::Replied->value => (int) ($row->replied_count ?? 0),
            InboxStatus::Hidden->value => (int) ($row->hidden_count ?? 0),
            InboxStatus::NeedsAttention->value => (int) ($row->attention_count ?? 0),
            InboxStatus::Handled->value => (int) ($row->handled_count ?? 0),
            InboxStatus::Mine->value => (int) ($row->mine_count ?? 0),
        ];
    }

    /**
     * @param  Builder<AudienceComment>  $base
     * @return list<array{id: string, title: string}>
     */
    private function postOptions(Builder $base): array
    {
        $ids = $base->toBase()->whereNotNull('post_id')->select('post_id')->distinct()->limit(50)->pluck('post_id')->all();

        return Post::query()->whereIn('id', $ids)->orderByDesc('updated_at')->get(['id', 'title', 'body'])
            ->map(fn (Post $post): array => ['id' => $post->id, 'title' => (string) ($post->title ?? Str::limit((string) $post->body, 60))])->all();
    }
}
