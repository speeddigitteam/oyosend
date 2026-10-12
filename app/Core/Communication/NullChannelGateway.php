<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\ChannelGateway;
use App\Core\Communication\Data\ChannelAvailability;
use App\Core\Communication\Data\ChannelState;
use App\Core\Communication\Data\DeliveryReport;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Communication\Data\MarketingMessage;
use App\Core\Communication\Data\SendReceipt;
use InvalidArgumentException;

/** Bound when no communication module is installed: nothing can be sent, and it says so. */
final class NullChannelGateway implements ChannelGateway
{
    public const string UNAVAILABLE = 'module_unavailable';

    public function availability(MarketingChannel $channel): ChannelAvailability
    {
        return new ChannelAvailability($channel, ChannelState::NotConfigured, self::UNAVAILABLE);
    }

    public function send(MarketingMessage $message): SendReceipt
    {
        return SendReceipt::unavailable(self::UNAVAILABLE);
    }

    public function statusFor(string $messageId): ?DeliveryReport
    {
        return null;
    }

    public function statusForMany(array $messageIds): array
    {
        if (count($messageIds) > self::MAX_BATCH) {
            throw new InvalidArgumentException('At most '.self::MAX_BATCH.' messages per call.');
        }

        return [];
    }
}
