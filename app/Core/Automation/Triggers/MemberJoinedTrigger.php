<?php

declare(strict_types=1);

namespace App\Core\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Core\Identity\Events\MemberJoined;

/**
 * "Someone joined the workspace" - the first trigger registered against the
 * automation contract. It describes the trigger; nothing executes workflows
 * until the engine is built (Phase 10).
 */
final class MemberJoinedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'core.member.joined';
    }

    public static function label(): string
    {
        return 'automation.triggers.core.member.joined';
    }

    public static function module(): string
    {
        return 'core';
    }

    public static function event(): string
    {
        return MemberJoined::class;
    }

    public static function requiredPermission(): string
    {
        return 'core.members.view';
    }

    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role' => [
                    'type' => 'string',
                    'description' => 'Only run when the person joins with this role. Empty means any role.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['membership_id', 'user_id', 'name', 'email', 'role'],
            'properties' => [
                'membership_id' => ['type' => 'string'],
                'user_id' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'role' => ['type' => 'string'],
            ],
        ];
    }
}
