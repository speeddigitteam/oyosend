<?php

declare(strict_types=1);

namespace App\Core\Communication;

use App\Core\Communication\Contracts\ChannelNumberDirectory;
use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Data\ChannelNumber;
use App\Core\Communication\Data\ChannelNumberOutcome;
use App\Core\Communication\Data\MarketingChannel;

/** Bound when no communication module is installed: the workspace has no numbers, and it says so. */
final class NullChannelNumbers implements ChannelNumberDirectory, ChannelNumberRegistry
{
    public const string UNAVAILABLE = 'module_unavailable';

    public function numbers(MarketingChannel $channel): array
    {
        return [];
    }

    public function default(MarketingChannel $channel): ?ChannelNumber
    {
        return null;
    }

    public function find(string $numberId): ?ChannelNumber
    {
        return null;
    }

    public function register(MarketingChannel $channel, string $accountId, string $label, ?string $displayNumber = null, bool $makeDefault = false): ChannelNumberOutcome
    {
        return ChannelNumberOutcome::refused(self::UNAVAILABLE);
    }

    public function update(string $numberId, ?string $label = null, ?string $displayNumber = null, ?bool $active = null): ChannelNumberOutcome
    {
        return ChannelNumberOutcome::refused(self::UNAVAILABLE);
    }

    public function makeDefault(string $numberId): ChannelNumberOutcome
    {
        return ChannelNumberOutcome::refused(self::UNAVAILABLE);
    }

    public function remove(string $numberId): ChannelNumberOutcome
    {
        return ChannelNumberOutcome::refused(self::UNAVAILABLE);
    }
}
