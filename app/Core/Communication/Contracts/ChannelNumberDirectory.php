<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\ChannelNumber;
use App\Core\Communication\Data\MarketingChannel;

/**
 * The workspace's own business numbers on a channel, read-only (ADR 0022).
 *
 * One connection (provider + credential reference) can own several numbers. Every number was claimed
 * platform-wide, so no number can be used by two workspaces. A single-number workspace has exactly one number,
 * and it is the default.
 *
 * Without the communication app, when the workspace is not entitled or when the channel has no numbers, every
 * method answers empty/null. Call inside the active tenant context.
 */
interface ChannelNumberDirectory
{
    /**
     * All numbers of the workspace on the channel, default first, then by label. Disabled numbers are included
     * (`isActive` false); only active numbers can be sent from.
     *
     * @return list<ChannelNumber>
     */
    public function numbers(MarketingChannel $channel): array;

    /** The number a message goes out from when the caller names none. Null when the channel has no active number. */
    public function default(MarketingChannel $channel): ?ChannelNumber;

    /** One number of THIS workspace by its platform id; null for any other id. */
    public function find(string $numberId): ?ChannelNumber;
}
