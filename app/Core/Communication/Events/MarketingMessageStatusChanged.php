<?php

declare(strict_types=1);

namespace App\Core\Communication\Events;

use App\Core\Communication\Data\DeliveryState;
use App\Core\Events\TenantDomainEvent;

/**
 * A message sent through `ChannelGateway` reached a new state.
 *
 * Raised by the communication module (after commit) for `sent`, `delivered`, `failed`, `bounced`, `blocked`
 * (suppressed or consent lost after queueing) and `cancelled`. Marketing apps listen to this class instead of
 * importing the module's own events, and ignore events whose `module` is not their own key.
 *
 * Payload: `message_id`, `module`, `campaign_id|null`, `recipient_id|null`, `contact_id|null`, `channel`,
 * `state` ({@see DeliveryState} value), `failure_code|null`.
 */
final class MarketingMessageStatusChanged extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'communication.marketing.message_status_changed';
    }

    public function payload(): array
    {
        return $this->data;
    }

    public function messageId(): string
    {
        return (string) $this->data['message_id'];
    }

    public function module(): string
    {
        return (string) $this->data['module'];
    }

    public function state(): DeliveryState
    {
        return DeliveryState::from((string) $this->data['state']);
    }
}
