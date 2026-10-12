<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Automation\ActionReceiptStore;
use App\Modules\Support\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a ticket to a named agent, or hands it to a queue's next round-robin agent. The receipt makes a
 * retried step return the first result, so a round-robin pointer is never advanced twice for one step.
 */
final class AssignTicketAction implements ActionDefinition
{
    public function __construct(
        private readonly TicketManager $tickets,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'support.ticket.assign';
    }

    public static function label(): string
    {
        return 'automation.actions.support.ticket.assign';
    }

    public static function module(): string
    {
        return 'support';
    }

    public static function requiredPermission(): string
    {
        return 'support.tickets.assign';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ticket_id'],
            'properties' => [
                'ticket_id' => ['type' => 'string'],
                'assignee_id' => ['type' => ['string', 'null']],
                'queue_id' => ['type' => ['string', 'null']],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ticket_id', 'owner_id'],
            'properties' => ['ticket_id' => ['type' => 'string'], 'owner_id' => ['type' => ['string', 'null']], 'queue_id' => ['type' => ['string', 'null']]],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $ticket = Ticket::query()->find((string) ($input['ticket_id'] ?? ''))
                ?? throw ValidationException::withMessages(['ticket_id' => __('Ticket not found.')]);
            $assigneeId = $input['assignee_id'] ?? null;

            if ($assigneeId !== null && $assigneeId !== '') {
                $assignee = TenantMembership::query()->find((string) $assigneeId)
                    ?? throw ValidationException::withMessages(['assignee_id' => __('Choose an active support agent.')]);
                $ticket = $this->tickets->assign($ticket, $assignee, null);
            } else {
                $ticket = $this->tickets->autoAssign($ticket, $input['queue_id'] ?? null, null);
            }

            return ['ticket_id' => $ticket->id, 'owner_id' => $ticket->owner_id, 'queue_id' => $ticket->queue_id];
        });
    }
}
