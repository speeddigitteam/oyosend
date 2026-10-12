<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;

/** Shared shape of the WhatsApp Business triggers: no configuration; ids and scalars as output, never text. */
abstract class WhatsappTrigger implements TriggerDefinition
{
    public static function label(): string
    {
        return 'automation.triggers.'.static::key();
    }

    public static function module(): string
    {
        return 'whatsapp_marketing';
    }

    public static function requiredPermission(): string
    {
        return 'whatsapp_marketing.conversations.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }
}
