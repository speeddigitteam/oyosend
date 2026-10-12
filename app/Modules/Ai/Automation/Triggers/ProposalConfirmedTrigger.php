<?php

declare(strict_types=1);

namespace App\Modules\Ai\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Ai\Events\ProposalConfirmed;

final class ProposalConfirmedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'ai.proposal.confirmed';
    }

    public static function label(): string
    {
        return 'automation.triggers.ai.proposal.confirmed';
    }

    public static function module(): string
    {
        return 'ai';
    }

    public static function event(): string
    {
        return ProposalConfirmed::class;
    }

    public static function requiredPermission(): string
    {
        return 'ai.assistant.use';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['proposal_id', 'tool', 'conversation_id', 'membership_id'],
            'properties' => [
                'proposal_id' => ['type' => 'string'],
                'tool' => ['type' => 'string'],
                'conversation_id' => ['type' => 'string'],
                'membership_id' => ['type' => 'string'],
            ],
        ];
    }
}
