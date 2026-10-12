<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Automation\Triggers;

use App\Core\Automation\Contracts\TriggerDefinition;
use App\Modules\EmailMarketing\Events\SubscriberSubscribed;

final class SubscriberSubscribedTrigger implements TriggerDefinition
{
    public static function key(): string
    {
        return 'email_marketing.subscriber.subscribed';
    }

    public static function label(): string
    {
        return 'automation.triggers.email_marketing.subscriber.subscribed';
    }

    public static function module(): string
    {
        return 'email_marketing';
    }

    public static function event(): string
    {
        return SubscriberSubscribed::class;
    }

    public static function requiredPermission(): string
    {
        return 'email_marketing.subscribers.view';
    }

    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['subscription_id', 'list_id', 'contact_id'],
            'properties' => [
                'subscription_id' => ['type' => 'string'],
                'list_id' => ['type' => 'string'],
                'contact_id' => ['type' => 'string'],
                'source' => ['type' => 'string'],
            ],
        ];
    }
}
