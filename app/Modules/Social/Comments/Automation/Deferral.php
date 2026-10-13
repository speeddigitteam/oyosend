<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use Carbon\CarbonImmutable;

/** A comment the rules could not finish yet (quiet hours, a cap, a temporary platform refusal): look again at `until`. */
final readonly class Deferral
{
    public function __construct(
        public CarbonImmutable $until,
        public string $reason,
        /** Quiet hours are expected and do not count against the retry budget. */
        public bool $counts = true,
    ) {}
}
