<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\MarketingChannel;

/**
 * Whether the workspace can send on WhatsApp right now, answered by the communication platform. This app has no
 * connection of its own: `not_configured` is the honest answer until an operator provides credentials and the
 * workspace connects the WhatsApp Cloud API channel in the communication settings.
 */
final class ChannelStatus
{
    public function __construct(private readonly ChannelGateway $gateway) {}

    /** @return array{state: string, reason: string|null, testDouble: bool} */
    public function describe(): array
    {
        $availability = $this->gateway->availability(MarketingChannel::Whatsapp);

        return [
            'state' => $availability->state->value,
            'reason' => $availability->reason,
            'testDouble' => $availability->testDouble,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->gateway->availability(MarketingChannel::Whatsapp)->isAvailable();
    }
}
