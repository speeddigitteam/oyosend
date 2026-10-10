<?php

declare(strict_types=1);

namespace App\Modules\CRM\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\CRM\Events\DealWon;

final class DealWonTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'crm.deal.won';
    }

    public static function label(): string
    {
        return 'automation.triggers.crm.deal.won';
    }

    public static function module(): string
    {
        return 'crm';
    }

    public static function event(): string
    {
        return DealWon::class;
    }

    public static function requiredPermission(): string
    {
        return 'crm.deals.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['minimum_amount' => ['type' => 'integer', 'minimum' => 0]], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['deal_id', 'amount', 'currency'], 'properties' => ['deal_id' => ['type' => 'string'], 'amount' => ['type' => 'integer'], 'currency' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']]]];
    }
}
