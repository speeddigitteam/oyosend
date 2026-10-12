<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Automation\ActionReceiptStore;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Illuminate\Validation\ValidationException;

/** Adds a tag to a conversation. Adding a tag it already has changes nothing. */
final class TagConversationAction implements ActionDefinition
{
    public function __construct(
        private readonly ConversationManager $conversations,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'whatsapp_marketing.conversation.add_tag';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.conversation.add_tag';
    }

    public static function module(): string
    {
        return 'whatsapp_marketing';
    }

    public static function requiredPermission(): string
    {
        return 'whatsapp_marketing.conversations.manage';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['conversation_id', 'tag'],
            'properties' => ['conversation_id' => ['type' => 'string'], 'tag' => ['type' => 'string', 'maxLength' => 30]],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['conversation_id', 'tags'],
            'properties' => ['conversation_id' => ['type' => 'string'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $conversation = Conversation::query()->find((string) ($input['conversation_id'] ?? ''))
                ?? throw ValidationException::withMessages(['conversation_id' => __('Conversation not found.')]);
            $conversation = $this->conversations->addTag($conversation, (string) ($input['tag'] ?? ''));

            return ['conversation_id' => $conversation->id, 'tags' => $conversation->tags ?? []];
        });
    }
}
