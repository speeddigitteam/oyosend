<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\ChannelNumberOutcome;
use App\Core\Communication\Data\MarketingChannel;

/**
 * Add, rename, disable, re-default and remove the workspace's business numbers (ADR 0022).
 *
 * The caller authorizes (the communication app's settings permission, `communication.settings.manage`): this
 * contract only enforces data rules. Every change is audited; a number is claimed platform-wide, so registering
 * a number another workspace holds is refused with `account_in_use`. The connection (provider and credential
 * reference) must already exist: numbers share it.
 */
interface ChannelNumberRegistry
{
    /**
     * @param  string  $accountId  The provider's id of the number (WhatsApp `phone_number_id`, 5 to 32 digits).
     * @param  string  $label  1 to 60 characters, unique among the workspace's numbers.
     * @param  bool  $makeDefault  The first number is always the default.
     */
    public function register(MarketingChannel $channel, string $accountId, string $label, ?string $displayNumber = null, bool $makeDefault = false): ChannelNumberOutcome;

    /** Change the label, display number and/or active flag; null leaves a value unchanged. */
    public function update(string $numberId, ?string $label = null, ?string $displayNumber = null, ?bool $active = null): ChannelNumberOutcome;

    public function makeDefault(string $numberId): ChannelNumberOutcome;

    /**
     * Refused (`in_use`) once any message was sent or queued from the number (disable it instead: history stays
     * attributable), and (`is_default`) for the default number while others exist (make another the default first).
     */
    public function remove(string $numberId): ChannelNumberOutcome;
}
