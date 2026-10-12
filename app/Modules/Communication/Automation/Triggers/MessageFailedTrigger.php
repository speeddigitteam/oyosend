<?php

declare(strict_types=1);

namespace App\Modules\Communication\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\Communication\Events\MessageFailed;

final class MessageFailedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'communication.message.failed';
    }

    public static function label(): string
    {
        return 'automation.triggers.communication.message.failed';
    }

    public static function module(): string
    {
        return 'communication';
    }

    public static function event(): string
    {
        return MessageFailed::class;
    }

    public static function requiredPermission(): string
    {
        return 'communication.messages.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return ['type' => 'object', 'required' => ['message_id', 'channel'], 'properties' => ['message_id' => ['type' => 'string'], 'channel' => ['type' => 'string'], 'purpose' => ['type' => 'string'], 'status' => ['type' => 'string'], 'contact_id' => ['type' => ['string', 'null']], 'company_id' => ['type' => ['string', 'null']], 'source' => ['type' => ['string', 'null']], 'failure_code' => ['type' => ['string', 'null']]]];
    }
}
