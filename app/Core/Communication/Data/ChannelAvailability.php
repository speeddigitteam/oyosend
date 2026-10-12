<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** Whether the workspace can send on a channel right now. */
final readonly class ChannelAvailability
{
    /**
     * @param  string|null  $reason  `module_unavailable`, `channel_disabled` or `provider_not_configured`
     *                               when not available.
     * @param  bool  $testDouble  True when the connected provider is an in-memory double (testing/local only):
     *                            never present such a channel as a real one.
     */
    public function __construct(
        public MarketingChannel $channel,
        public ChannelState $state,
        public ?string $reason = null,
        public bool $testDouble = false,
    ) {}

    public function isAvailable(): bool
    {
        return $this->state === ChannelState::Available;
    }
}
