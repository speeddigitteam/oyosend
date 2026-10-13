<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http;

use App\Core\Access\AccessChecker;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\ConversationNote;
use App\Modules\WhatsappMarketing\Models\Flow;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Models\QuickReply;
use App\Modules\WhatsappMarketing\Models\Template;
use Illuminate\Http\Request;

/**
 * The shapes this app sends to its pages. Message bodies are untrusted customer text: they are sent as plain
 * strings and the pages render them as text.
 */
final class Presenter
{
    /** Relations a conversation row needs eager-loaded. */
    public const array CONVERSATION_RELATIONS = ['contact:id,first_name,last_name,phone', 'owner.user:id,name'];

    public function __construct(
        private readonly AccessChecker $access,
        private readonly WindowTracker $windows,
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

    /** @return array<string, mixed> */
    public function conversation(Conversation $conversation): array
    {
        $until = $this->windows->serviceWindowUntil($conversation);
        $contactName = $conversation->contact?->fullName();

        return [
            'id' => $conversation->id,
            'phone' => $conversation->phone,
            'displayName' => $contactName ?: ($conversation->profile_name ?: $conversation->phone),
            'profileName' => $conversation->profile_name,
            'contact' => $conversation->contact === null ? null : ['id' => $conversation->contact->id, 'name' => $contactName],
            'status' => $conversation->status->value,
            'owner' => $conversation->owner === null ? null : ['id' => $conversation->owner->id, 'name' => $conversation->owner->user->name],
            'tags' => $conversation->tags ?? [],
            'unread' => $conversation->unread_count,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
            'preview' => $conversation->last_message_preview,
            'lastDirection' => $conversation->last_message_direction,
            'windowOpen' => $until !== null,
            'windowExpiresAt' => $until?->toIso8601String(),
            'botPaused' => $conversation->bot_paused,
        ];
    }

    /** @return array<string, mixed> */
    public function message(Message $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'senderType' => $message->sender_type->value,
            'author' => $message->author?->user->name,
            'type' => $message->type->value,
            'body' => $message->body,
            'templateName' => $message->template_name,
            'status' => $message->status->value,
            'failureCode' => $message->failure_code,
            'attachments' => $message->attachments ?? [],
            'interactive' => $message->interactive,
            'occurredAt' => $message->occurred_at->toIso8601String(),
            'deliveredAt' => $message->delivered_at?->toIso8601String(),
            'readAt' => $message->read_at?->toIso8601String(),
        ];
    }

    /** @return array{id: string, body: string, author: string|null, createdAt: string} */
    public function note(ConversationNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->author?->user->name,
            'createdAt' => $note->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function template(Template $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'language' => $template->language,
            'category' => $template->category->value,
            'status' => $template->status->value,
            'statusSource' => $template->status_source->value,
            'statusReason' => $template->status_reason,
            'statusChangedAt' => $template->status_changed_at?->toIso8601String(),
            'components' => $template->components,
            'body' => $template->body_text,
            'variableCount' => $template->variable_count,
            'header' => $this->component($template, 'HEADER'),
            'footer' => $this->component($template, 'FOOTER'),
            'buttons' => $this->buttons($template),
        ];
    }

    /** @return array<string, mixed> */
    public function quickReply(QuickReply $reply): array
    {
        return [
            'id' => $reply->id,
            'shortcut' => $reply->shortcut,
            'title' => $reply->title,
            'body' => $reply->body,
            'isActive' => $reply->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public function flow(Flow $flow): array
    {
        return [
            'id' => $flow->id,
            'name' => $flow->name,
            'trigger' => $flow->trigger->value,
            'matchType' => $flow->match_type?->value,
            'keywords' => $flow->keywords ?? [],
            'actions' => $flow->actions,
            'priority' => $flow->priority,
            'isActive' => $flow->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public function campaign(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'status' => $campaign->status->value,
            'templateId' => $campaign->template_id,
            'templateName' => $campaign->template_name,
            'templateLanguage' => $campaign->template_language,
            'templateCategory' => $campaign->template_category,
            'templateBody' => $campaign->template_body,
            'listId' => $campaign->list_id,
            'listName' => $campaign->list_name,
            'variables' => $campaign->variable_map,
            'headerMediaType' => $campaign->header_media_type,
            'headerMediaId' => $campaign->header_media_id,
            'buttonParams' => $campaign->button_params ?? [],
            'senderNumberId' => $campaign->sender_number_id,
            'scheduledAt' => $campaign->scheduled_at?->toIso8601String(),
            'startedAt' => $campaign->started_at?->toIso8601String(),
            'completedAt' => $campaign->completed_at?->toIso8601String(),
            'totalRecipients' => $campaign->total_recipients,
            'stopReason' => $campaign->stop_reason,
            'owner' => $campaign->relationLoaded('owner') && $campaign->owner !== null
                ? ['id' => $campaign->owner->id, 'name' => $campaign->owner->user->name]
                : null,
            'createdAt' => $campaign->created_at->toIso8601String(),
        ];
    }

    /**
     * Active agents as assignable members. `id` is the membership id (what assign endpoints take).
     *
     * @return list<array{id: string, agentId: string, name: string, isAvailable: bool}>
     */
    public function agents(): array
    {
        return Agent::query()
            ->where('is_active', true)
            ->with('membership.user:id,name')
            ->get()
            ->filter(static fn (Agent $agent): bool => $agent->membership->status === MembershipStatus::Active)
            ->map(static fn (Agent $agent): array => [
                'id' => $agent->membership_id,
                'agentId' => $agent->id,
                'name' => $agent->membership->user->name,
                'isAvailable' => $agent->is_available,
            ])
            ->sortBy('name')->values()->all();
    }

    /** @return array{id: string, name: string, phone: string|null} */
    public function contact(Contact $contact): array
    {
        return ['id' => $contact->id, 'name' => $contact->fullName(), 'phone' => $contact->phone];
    }

    /** @return array{type: string, text: string}|null */
    private function component(Template $template, string $type): ?array
    {
        foreach ($template->components as $component) {
            if (($component['type'] ?? null) === $type) {
                return ['type' => (string) ($component['format'] ?? 'TEXT'), 'text' => (string) ($component['text'] ?? '')];
            }
        }

        return null;
    }

    /** @return list<array{type: string, text: string, value: string|null}> */
    private function buttons(Template $template): array
    {
        foreach ($template->components as $component) {
            if (($component['type'] ?? null) !== 'BUTTONS') {
                continue;
            }

            return array_values(array_map(static fn (array $button): array => [
                'type' => strtolower((string) ($button['type'] ?? 'quick_reply')),
                'text' => (string) ($button['text'] ?? ''),
                'value' => isset($button['url']) ? (string) $button['url'] : (isset($button['phone_number']) ? (string) $button['phone_number'] : null),
            ], (array) ($component['buttons'] ?? [])));
        }

        return [];
    }
}
