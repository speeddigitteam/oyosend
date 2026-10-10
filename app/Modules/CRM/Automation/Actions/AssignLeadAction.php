<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Actions;

use App\Core\Automation\Contracts\ActionDefinition;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Actions\LeadManager;
use App\Modules\CRM\Automation\ActionReceiptStore;
use App\Modules\CRM\Models\Lead;

final class AssignLeadAction implements ActionDefinition
{
    public function __construct(
        private readonly LeadManager $leads,
        private readonly ActionReceiptStore $receipts,
    ) {}

    public static function key(): string
    {
        return 'crm.lead.assign';
    }

    public static function label(): string
    {
        return 'automation.actions.crm.lead.assign';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function requiredPermission(): string
    {
        return 'crm.leads.assign';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['lead_id', 'owner_id'], 'properties' => ['lead_id' => ['type' => 'string'], 'owner_id' => ['type' => ['string', 'null']]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['lead_id'], 'properties' => ['lead_id' => ['type' => 'string'], 'owner_id' => ['type' => ['string', 'null']]]];
    }

    public function execute(array $input, string $idempotencyKey): array
    {
        return $this->receipts->run(self::key(), $idempotencyKey, function () use ($input): array {
            $lead = Lead::query()->findOrFail($input['lead_id']);
            $owner = ($input['owner_id'] ?? null) === null ? null : TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['owner_id']);
            $this->leads->assign($lead, $owner);

            return ['lead_id' => $lead->id, 'owner_id' => $owner?->id];
        });
    }
}
