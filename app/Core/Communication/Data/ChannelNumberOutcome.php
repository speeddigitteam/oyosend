<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

use App\Core\Communication\Contracts\ChannelNumberRegistry;

/**
 * The answer of a {@see ChannelNumberRegistry} change.
 *
 * `reason` when not ok: `module_unavailable`, `not_connected` (no connection for the channel yet),
 * `unsupported_channel`, `provider_not_configured`, `invalid_account_id`, `invalid_label`, `label_in_use`,
 * `account_in_use` (already registered here or by another workspace), `not_found`, `in_use`, `is_default`,
 * `inactive` (cannot become the default).
 */
final readonly class ChannelNumberOutcome
{
    public function __construct(
        public bool $ok,
        public ?string $reason = null,
        public ?ChannelNumber $number = null,
    ) {}

    public static function ok(ChannelNumber $number): self
    {
        return new self(true, null, $number);
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }
}
