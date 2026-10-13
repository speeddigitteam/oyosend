<?php

declare(strict_types=1);

namespace App\Modules\Social\Platforms;

/**
 * What a platform returned for one Page over a range of its own days.
 *
 * `days` maps a platform day (`Y-m-d`) to figure => value. A figure the platform did not return
 * for that day is absent: nothing in here is ever a defaulted zero.
 */
final readonly class PageInsightsSnapshot
{
    public const string RATE_LIMITED = 'rate_limited';

    public const string THROTTLED = 'throttled';

    public const string PERMISSION_MISSING = 'permission_missing';

    public const string TOKEN_INVALID = 'token_invalid';

    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, array<string, int>>  $days  date => figure => value
     * @param  array<string, string>  $sources  figure => the API metric that supplied it
     * @param  list<string>  $notReported  figures no candidate metric answered
     * @param  string|null  $stopped  Why the read stopped early (one of the constants), or null
     * @param  string|null  $errorCode  The platform's error code of the stopping failure, if any
     */
    public function __construct(
        public array $days = [],
        public array $sources = [],
        public array $notReported = [],
        public ?string $stopped = null,
        public ?string $errorCode = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->days === [];
    }
}
