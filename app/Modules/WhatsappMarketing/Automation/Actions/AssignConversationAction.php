<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\WhatsappMarketing\Actions\AgentManager;
use App\Modules\WhatsappMarketing\Actions\AutoAssigner;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Automation\ActionReceiptStore;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Illuminate\Validation\ValidationException;

/** Assigns a conversation to a named agent, or to the available agent with the fewest open conversations. */
final class AssignConversationAction implements ActionDefinition
{
    public function __construct(
        private readonly ConversationManager $conversations,
        private readonly AgentManager $agents,
        private readonly AutoAssigner $assigner,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'whatsapp_marketing.conversation.assign';
    }

    public static function label(): string
    {
        return 'automation.actions.whatsapp_marketing.conversation.assign';
    }

    public static function module(): string
    {
        return 'whatsapp_marketing';
    }

    public static function requiredPermission(): string
    {
        return 'whatsapp_marketing.conversations.assign';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['conversation_id'],
            'properties' => [
                'conversation_id' => ['type' => 'string'],
                'assignee_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['conversation_id', 'owner_id'],
            'properties' => ['conversation_id' => ['type' => 'string'], 'owner_id' => ['type' => ['string', 'null']]],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $conversation = Conversation::query()->find((string) ($input['conversation_id'] ?? ''))
                ?? throw ValidationException::withMessages(['conversation_id' => __('Conversation not found.')]);
            $assigneeId = $input['assignee_id'] ?? null;

            $membership = is_string($assigneeId) && $assigneeId !== ''
                ? ($this->agents->activeMembership($assigneeId) ?? throw ValidationException::withMessages(['assignee_id' => __('Choose an enrolled, active agent.')]))
                : $this->assigner->pick();

            if ($membership !== null) {
                $conversation = $this->conversations->assign($conversation, $membership, 'automation');
            }

            return ['conversation_id' => $conversation->id, 'owner_id' => $conversation->owner_id];
        });
    }
}
