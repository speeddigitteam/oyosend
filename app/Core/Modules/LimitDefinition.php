<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Billing\Enums\LimitKind;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use Closure;

/**
 * Something a plan can cap: members, messages sent, credits used.
 *
 * A plan sets the maximum per key (`plans.limits`); no entry means unlimited.
 * Ask {@see UsageLimits} before letting a workspace use
 * more of it.
 */
final readonly class LimitDefinition
{
    /**
     * @param  string  $key  Prefixed with the module key: `core.members`, `sms.messages`.
     * @param  LimitKind  $kind  A gauge is counted from what exists; a metered limit from
     *                           what {@see UsageMeter} recorded this period.
     * @param  (Closure(): int)|null  $count  For a gauge: counts what the active workspace has now.
     */
    public function __construct(
        public string $key,
        public LimitKind $kind = LimitKind::Metered,
        public ?Closure $count = null,
    ) {}

    /**
     * Module key, derived from the key's first segment.
     */
    public function module(): string
    {
        return strstr($this->key, '.', true) ?: $this->key;
    }
}
