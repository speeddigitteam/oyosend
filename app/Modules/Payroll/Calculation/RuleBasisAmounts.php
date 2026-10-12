<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

/** The period amounts a rule can be based on, in minor units. */
final readonly class RuleBasisAmounts
{
    public function __construct(
        public int $basic,
        public int $gross,
        public int $taxableGross,
        public string $currency,
        public string $from,
        public string $to,
    ) {}
}
