<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Ai;

use App\Core\Ai\Contracts\AiTool;
use App\Core\Ai\ToolContext;
use App\Core\Ai\ToolOutput;
use App\Modules\WhatsappMarketing\Actions\ConversationAccess;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Message;
use App\Modules\WhatsappMarketing\Support\Permissions;

/**
 * Lets the AI app read one WhatsApp conversation (the latest messages, as text) for a member who may see it, so
 * an agent can ask for a reply draft. READ-ONLY: it sends nothing and changes nothing; the agent reviews the draft
 * and sends it like any reply. Internal notes are not included. The workspace must enable the
 * `whatsapp_conversations` data scope in its AI settings first (new scopes are opt-in, ADR 0018).
 */
final class ConversationReadTool implements AiTool
{
    public const int MESSAGES = 20;

    public function __construct(private readonly ConversationAccess $access) {}

    public static function name(): string
    {
        return 'whatsapp_conversation_get';
    }

    public static function dataScope(): string
    {
        return 'whatsapp_conversations';
    }

    public static function permission(): string
    {
        return Permissions::CONVERSATIONS_VIEW;
    }

    public static function description(): string
    {
        return 'Read one WhatsApp conversation by id: the customer name, status, tags and the latest messages (oldest first) with who sent each.';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id'],
            'properties' => ['id' => ['type' => 'string', 'description' => 'WhatsApp conversation id.']],
            'additionalProperties' => false,
        ];
    }

    public function execute(ToolContext $context, array $input): ToolOutput
    {
        $conversation = $this->access->constrain(Conversation::query(), $context->user, self::permission())
            ->with('contact:id,first_name,last_name')
            ->findOrFail((string) ($input['id'] ?? ''));

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('body')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(self::MESSAGES)
            ->get(['id', 'direction', 'sender_type', 'body', 'occurred_at'])
            ->reverse()->values();

        return new ToolOutput([
            'id' => $conversation->id,
            'customer' => $conversation->contact?->fullName() ?: $conversation->profile_name,
            'status' => $conversation->status->value,
            'tags' => $conversation->tags ?? [],
            'messages' => $messages->map(static fn (Message $message): array => [
                'from' => $message->direction->value === 'inbound' ? 'customer' : $message->sender_type->value,
                'text' => mb_substr((string) $message->body, 0, 1000),
                'at' => $message->occurred_at->toIso8601String(),
            ])->all(),
        ], [$conversation->id]);
    }
}
