<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

/** What a rule engine returned: the amount and how it got there (kept on the payslip line). */
final readonly class RuleOutcome
{
    /** @param array<string, mixed> $detail */
    public function __construct(public int $amount, public array $detail = []) {}
}
