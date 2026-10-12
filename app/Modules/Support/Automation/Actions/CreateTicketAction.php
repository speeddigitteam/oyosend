<?php

declare(strict_types=1);

namespace App\Modules\Support\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\Support\Actions\TicketManager;
use App\Modules\Support\Automation\ActionReceiptStore;
use App\Modules\Support\Enums\TicketChannel;

/**
 * Opens a ticket. The idempotency key becomes the ticket's unique source key, so a retried step never opens a
 * second ticket (and never burns a number or plan allowance).
 */
final class CreateTicketAction implements ActionDefinition
{
    public function __construct(
        private readonly TicketManager $tickets,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'support.ticket.create';
    }

    public static function label(): string
    {
        return 'automation.actions.support.ticket.create';
    }

    public static function module(): string
    {
        return 'support';
    }

    public static function requiredPermission(): string
    {
        return 'support.tickets.create';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['subject', 'body'],
            'properties' => [
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20000],
                'priority' => ['type' => ['string', 'null'], 'enum' => ['low', 'normal', 'high', 'urgent', null]],
                'category_id' => ['type' => ['string', 'null']],
                'queue_id' => ['type' => ['string', 'null']],
                'contact_id' => ['type' => ['string', 'null']],
                'company_id' => ['type' => ['string', 'null']],
                'requester_name' => ['type' => ['string', 'null'], 'maxLength' => 160],
                'requester_email' => ['type' => ['string', 'null'], 'maxLength' => 190],
                'tags' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 60]],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ticket_id', 'number'],
            'properties' => ['ticket_id' => ['type' => 'string'], 'number' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $ticket = $this->tickets->create(
                array_filter($input, static fn (mixed $value): bool => $value !== null),
                null,
                TicketChannel::Api,
                'automation:'.self::key().':'.$idempotencyKey,
            );

            return ['ticket_id' => $ticket->id, 'number' => $ticket->number, 'status' => $ticket->status->value];
        });
    }
}
