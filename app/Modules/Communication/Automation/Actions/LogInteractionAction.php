<?php

declare(strict_types=1);

namespace App\Modules\Communication\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Communication\Data\InteractionEntry;
use App\Modules\Communication\Actions\TimelineRecorder;
use App\Modules\Communication\Automation\ActionReceiptStore;

/** Appends a note to a contact's or company's timeline. */
final class LogInteractionAction implements ActionDefinition
{
    public function __construct(
        private readonly TimelineRecorder $timeline,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'communication.interaction.log';
    }

    public static function label(): string
    {
        return 'automation.actions.communication.interaction.log';
    }

    public static function module(): string
    {
        return 'communication';
    }

    public static function requiredPermission(): string
    {
        return 'communication.timeline.log';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['summary'],
            'properties' => [
                'contact_id' => ['type' => ['string', 'null']],
                'company_id' => ['type' => ['string', 'null']],
                'type' => ['type' => 'string', 'enum' => ['note', 'call', 'meeting'], 'default' => 'note'],
                'summary' => ['type' => 'string', 'maxLength' => 200],
                'body' => ['type' => ['string', 'null'], 'maxLength' => 5000],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['status'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['logged', 'rejected']],
                'interaction_id' => ['type' => ['string', 'null']],
            ],
        ];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input, $idempotencyKey): array {
            $type = (string) ($input['type'] ?? 'note');
            $summary = trim((string) ($input['summary'] ?? ''));
            $contactId = isset($input['contact_id']) ? (string) $input['contact_id'] : null;
            $companyId = isset($input['company_id']) ? (string) $input['company_id'] : null;

            if (! in_array($type, ['note', 'call', 'meeting'], true) || $summary === '' || mb_strlen($summary) > 200
                || ($contactId === null && $companyId === null)) {
                return ['status' => 'rejected', 'interaction_id' => null];
            }

            $interaction = $this->timeline->write(new InteractionEntry(
                type: $type,
                summary: $summary,
                contactId: $contactId,
                companyId: $companyId,
                body: isset($input['body']) ? mb_substr((string) $input['body'], 0, 5000) : null,
                sourceModule: 'automation',
                dedupeKey: 'automation:'.$idempotencyKey,
            ));

            return ['status' => $interaction === null ? 'rejected' : 'logged', 'interaction_id' => $interaction?->id];
        });
    }
}
