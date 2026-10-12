<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Automation\ActionReceiptStore;
use App\Modules\Support\Enums\MessageKind;
use App\Modules\Support\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Adds an internal note. It deliberately cannot send a public reply: nothing a workflow does may speak to
 * a customer on an agent's behalf. The idempotency key is the note's unique source key.
 */
final class AddInternalNoteAction implements ActionDefinition
{
    public function __construct(
        private readonly TicketManager $tickets,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'support.ticket.add_note';
    }

    public static function label(): string
    {
        return 'automation.actions.support.ticket.add_note';
    }

    public static function module(): string
    {
        return 'support';
    }

    public static function requiredPermission(): string
    {
        return 'support.tickets.reply';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ticket_id', 'body'],
            'properties' => [
                'ticket_id' => ['type' => 'string'],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ticket_id', 'message_id'],
            'properties' => ['ticket_id' => ['type' => 'string'], 'message_id' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $ticket = Ticket::query()->find((string) ($input['ticket_id'] ?? ''))
                ?? throw ValidationException::withMessages(['ticket_id' => __('Ticket not found.')]);
            $message = $this->tickets->addMessage(
                $ticket,
                MessageKind::InternalNote,
                (string) ($input['body'] ?? ''),
                null,
                [],
                null,
                'automation:'.self::key().':'.$idempotencyKey,
                __('Automation'),
            );

            return ['ticket_id' => $ticket->id, 'message_id' => $message->id];
        });
    }
}
