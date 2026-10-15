<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Http;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Http\Controllers\SocialSupport;
use App\Modules\Social\Messages\Actions\ConversationAccess;
use App\Modules\Social\Messages\Actions\DirectMessages;
use App\Modules\Social\Messages\Actions\MessagingSetup;
use App\Modules\Social\Messages\Inbox\DirectMessageInboxSource;
use App\Modules\Social\Messages\MessagingAvailability;
use App\Modules\Social\Messages\Models\DirectConversation;
use App\Modules\Social\Messages\Models\DirectMessage;
use App\Modules\Social\Messages\Models\MessagingSetting;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Social "Messages" page: Messenger and Instagram direct messages of connected Pages, a thread with a composer,
 * and the per-Page capture switch (ADR 0031). Every conversation is narrowed by {@see ConversationAccess}; one outside
 * the member's reach answers 404. A reply goes through {@see DirectMessages::reply()}, the same action the unified inbox
 * uses. Message text is untrusted and is rendered only as text.
 */
final class DirectMessageController
{
    private const int THREAD_MESSAGES = 100;

    public function __construct(
        private readonly ConversationAccess $access,
        private readonly DirectMessages $messages,
        private readonly MessagingSetup $setup,
        private readonly MessagingAvailability $availability,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'platform' => ['nullable', Rule::in(DirectConversation::PLATFORMS)],
            'status' => ['nullable', Rule::in([DirectConversation::OPEN, DirectConversation::CLOSED, 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'conversation' => ['nullable', 'string', 'size:26'],
        ]);
        $status = (string) ($filters['status'] ?? DirectConversation::OPEN);

        $thread = null;

        if (isset($filters['conversation'])) {
            // A deep link to a conversation the member may not open is a 404. Opening changes nothing: the page posts
            // `social.messages.read` while the member looks at it, so a crafted link cannot mark anything read.
            $conversation = $this->access->visible($actor)->with(['account', 'owner.user:id,name'])->find($filters['conversation']) ?? abort(404);
            $thread = $this->thread($actor, $conversation);
        }

        $conversations = $this->access->visible($actor, ConversationAccess::VIEW, $filters['platform'] ?? null)
            ->whereNotNull('last_message_at')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(fn (Builder $match) => $match
                ->where('participant_name', 'like', Like::contains($term))
                ->orWhere('participant_id', 'like', Like::contains($term))
                ->orWhere('last_message_preview', 'like', Like::contains($term))))
            ->with(['account:id,name', 'owner.user:id,name'])
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (DirectConversation $conversation): array => $this->row($conversation));

        $canReply = $actor->can(ConversationAccess::REPLY);
        $problem = $this->availability->operatorProblem();

        return Inertia::render('Modules/Social/Pages/Messages/Index', [
            'availability' => [
                'problem' => $problem,
                'platforms' => MessagingAvailability::platforms(),
                'instagram' => MessagingAvailability::instagramEnabled(),
                'windowHours' => MessagingAvailability::windowHours(),
                'messengerScopes' => MessagingAvailability::messengerScopes(),
                'instagramScopes' => MessagingAvailability::instagramScopes(),
                'webhookUrl' => 'https://'.config('platform.domain').'/social/webhooks/facebook',
            ],
            'pages' => $this->pages(),
            'conversations' => $conversations,
            'filters' => [
                'platform' => $filters['platform'] ?? null,
                'status' => $status,
                'q' => $filters['q'] ?? null,
            ],
            'thread' => $thread,
            'members' => $canReply ? $this->members() : [],
            'can' => [
                'reply' => $canReply,
                'manage' => $actor->can('social.accounts.manage'),
            ],
        ]);
    }

    /** The member read the open conversation: its unread count is cleared. */
    public function read(Request $request, string $conversation): RedirectResponse
    {
        $this->messages->markRead($this->target($this->support->actor($request), $conversation, ConversationAccess::VIEW));

        return back();
    }

    public function reply(Request $request, string $conversation): RedirectResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:4096']]);
        $actor = $this->support->actor($request);
        $this->messages->reply($this->target($actor, $conversation, ConversationAccess::REPLY), (string) $data['text'], $this->support->membership($actor));
        Toast::success(__('Message sent.'));

        return back();
    }

    public function status(Request $request, string $conversation): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([DirectConversation::OPEN, DirectConversation::CLOSED])]]);
        $actor = $this->support->actor($request);
        $this->messages->setStatus($this->target($actor, $conversation, ConversationAccess::REPLY), (string) $data['status'], $this->support->membership($actor));

        return back();
    }

    public function assign(Request $request, string $conversation): RedirectResponse
    {
        $data = $request->validate(['membership_id' => ['nullable', 'string', 'size:26']]);
        $actor = $this->support->actor($request);
        $record = $this->target($actor, $conversation, ConversationAccess::REPLY);
        $to = isset($data['membership_id'])
            ? TenantMembership::query()->where('status', MembershipStatus::Active->value)->find($data['membership_id'])
                ?? throw ValidationException::withMessages(['membership_id' => __('Choose an active team member.')])
            : null;
        $this->messages->assign($record, $to, $this->support->membership($actor));
        Toast::success($to === null ? __('Conversation unassigned.') : __('Conversation assigned.'));

        return back();
    }

    public function enable(Request $request, string $account): RedirectResponse
    {
        $setting = $this->setup->enable(SocialAccount::query()->findOrFail($account));
        Toast::success($setting->instagram_state === MessagingSetting::ON
            ? __('Messages are on for this Page and its Instagram account.')
            : __('Messages are on for this Page.'));

        return back();
    }

    public function disable(Request $request, string $account): RedirectResponse
    {
        $this->setup->disable(SocialAccount::query()->findOrFail($account));
        Toast::success(__('Messages are off for this Page. Conversations already received are kept.'));

        return back();
    }

    /** A conversation the actor may act on with the permission; 404 outside their reach. */
    private function target(User $actor, string $id, string $permission): DirectConversation
    {
        $conversation = $this->access->visible($actor)->with('account')->find($id) ?? abort(404);
        abort_unless($this->access->allows($actor, $permission, $conversation), 404);

        return $conversation;
    }

    /** @return array<string, mixed> */
    private function row(DirectConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'platform' => $conversation->platform,
            'title' => $conversation->displayName(),
            'accountName' => $conversation->account->name,
            'preview' => $conversation->last_message_preview,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'unread' => $conversation->unread_count,
            'status' => $conversation->status,
            'ownerName' => $conversation->owner?->user?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function thread(User $actor, DirectConversation $conversation): array
    {
        $rows = DirectMessage::query()->where('conversation_id', $conversation->id)->with('sender.user:id,name')
            ->orderByDesc('sent_at')->orderByDesc('id')->limit(self::THREAD_MESSAGES + 1)->get();
        $replyable = $this->access->allows($actor, ConversationAccess::REPLY, $conversation);
        $blocked = $replyable ? $this->messages->blockedReason($conversation) : null;

        return [
            ...$this->row($conversation),
            'owner' => $conversation->owner === null ? null : ['id' => $conversation->owner->id, 'name' => $conversation->owner->user->name],
            'messages' => $rows->take(self::THREAD_MESSAGES)->reverse()->values()->map(fn (DirectMessage $message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'text' => $message->text,
                'at' => $message->sent_at->toIso8601String(),
                'authorName' => $message->direction === DirectMessage::OUT ? ($message->sender->user->name ?? $conversation->account->name) : null,
                'status' => $message->status,
                'attachment' => DirectMessageInboxSource::attachmentLabel($message->attachment_type),
            ])->all(),
            'hasOlder' => $rows->count() > self::THREAD_MESSAGES,
            'canReply' => $replyable && $blocked === null,
            'replyable' => $replyable,
            'blockedReason' => $blocked,
            'windowEndsAt' => $this->messages->windowEndsAt($conversation)?->toIso8601String(),
            'textMax' => MessagingAvailability::textMax($conversation->platform),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pages(): array
    {
        $accounts = SocialAccount::query()->where('platform', 'facebook')->where('status', '!=', AccountStatus::Disconnected->value)->orderBy('name')->get();
        $settings = MessagingSetting::query()->whereIn('social_account_id', $accounts->modelKeys())->get()->keyBy('social_account_id');

        return $accounts->map(function (SocialAccount $account) use ($settings): array {
            $setting = $settings->get($account->id);

            return [
                'id' => $account->id,
                'name' => $account->name,
                'status' => $account->status->value,
                'enabled' => $setting->is_enabled ?? false,
                'state' => $setting->state ?? MessagingSetting::OFF,
                'instagramState' => $setting->instagram_state ?? MessagingSetting::OFF,
                'missingScopes' => $setting->missing_scopes ?? [],
                'lastError' => $setting?->last_error,
                'lastEventAt' => $setting?->last_event_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return list<array{id: string, name: string}> */
    private function members(): array
    {
        return TenantMembership::query()->with('user:id,name')->where('status', MembershipStatus::Active->value)->get()
            ->map(static fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => (string) $membership->user->name])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }
}
