<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/** Whether a platform can be used right now, and the honest reason when it cannot. */
final readonly class PlatformAvailability
{
    public const string NOT_CONFIGURED = 'not_configured';

    public const string NOT_IMPLEMENTED = 'not_implemented';

    public function __construct(
        public bool $available,
        public ?string $code = null,
        public ?string $reason = null,
    ) {}

    public static function available(): self
    {
        return new self(true);
    }

    public static function unavailable(string $code, string $reason): self
    {
        return new self(false, $code, $reason);
    }
}
