<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

/**
 * A rule set as the calculator sees it: plain data, detached from Eloquent.
 */
final readonly class RuleDefinition
{
    /**
     * @param  string  $payer  `employee` (a deduction) or `employer` (an employer cost, informational).
     * @param  string  $basis  `taxable_gross`, `gross` or `basic`.
     * @param  string  $method  `progressive` (marginal bands) or `banded` (the matching band's rate on the whole base).
     * @param  list<array{position: int, from: int, to: int|null, rate_bps: int, fixed: int}>  $slabs
     * @param  array<string, mixed>  $parameters  Engine specific settings.
     * @param  list<string>|null  $employmentTypes  Null applies to everybody.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $engine,
        public string $category,
        public string $payer,
        public string $basis,
        public string $method,
        public bool $annualize,
        public int $periodsPerYear,
        public ?int $minAmount,
        public ?int $maxAmount,
        public array $slabs,
        public array $parameters = [],
        public ?array $employmentTypes = null,
        public int $version = 1,
    ) {}
}
