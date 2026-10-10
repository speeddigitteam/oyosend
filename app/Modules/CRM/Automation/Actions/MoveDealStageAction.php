<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Modules\CRM\Actions\DealManager;
use App\Modules\CRM\Automation\ActionReceiptStore;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\PipelineStage;

final class MoveDealStageAction implements ActionDefinition
{
    public function __construct(
        private readonly DealManager $deals,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'crm.deal.move_stage';
    }

    public static function label(): string
    {
        return 'automation.actions.crm.deal.move_stage';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function requiredPermission(): string
    {
        return 'crm.deals.edit';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['deal_id', 'stage_id'], 'properties' => ['deal_id' => ['type' => 'string'], 'stage_id' => ['type' => 'string'], 'note' => ['type' => ['string', 'null'], 'maxLength' => 500]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['deal_id', 'stage_id', 'status'], 'properties' => ['deal_id' => ['type' => 'string'], 'stage_id' => ['type' => 'string'], 'status' => ['type' => 'string']]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $deal = Deal::query()->findOrFail($input['deal_id']);
            $stage = PipelineStage::query()->findOrFail($input['stage_id']);
            $this->deals->move($deal, $stage, note: $input['note'] ?? null);

            return ['deal_id' => $deal->id, 'stage_id' => $stage->id, 'status' => $deal->refresh()->status->value];
        });
    }
}
