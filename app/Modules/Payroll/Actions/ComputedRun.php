<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Calculation\PayMath;
use App\Modules\Payroll\Calculation\PayslipDraft;

/**
 * The outcome of calculating a whole period: payable drafts, the problems that block submission and
 * the notices that do not. Nothing is stored yet; {@see RunManager} persists it.
 */
final readonly class ComputedRun
{
    /**
     * @param  list<array{employee: EmployeeSnapshot, draft: PayslipDraft, assignment_id: string|null, structure_name: string|null}>  $payslips
     * @param  list<array<string, mixed>>  $errors  Block submission (`code`, `employee_id`, `employee_name`).
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public array $payslips,
        public array $errors,
        public array $warnings,
    ) {}

    public function count(): int
    {
        return count($this->payslips);
    }

    /** @return array{gross_amount: int, deduction_amount: int, reimbursement_amount: int, net_amount: int, employer_amount: int} */
    public function totals(): array
    {
        $drafts = array_map(static fn (array $row): PayslipDraft => $row['draft'], $this->payslips);

        return [
            'gross_amount' => PayMath::sum(array_map(static fn (PayslipDraft $d): int => $d->grossAmount, $drafts)),
            'deduction_amount' => PayMath::sum(array_map(static fn (PayslipDraft $d): int => $d->deductionAmount, $drafts)),
            'reimbursement_amount' => PayMath::sum(array_map(static fn (PayslipDraft $d): int => $d->reimbursementAmount, $drafts)),
            'net_amount' => PayMath::sum(array_map(static fn (PayslipDraft $d): int => $d->netAmount, $drafts)),
            'employer_amount' => PayMath::sum(array_map(static fn (PayslipDraft $d): int => $d->employerAmount, $drafts)),
        ];
    }

    /** A hash of everything that decides what the run pays: used to detect changed inputs at approval. */
    public function fingerprint(): string
    {
        $parts = array_map(static fn (array $row): string => $row['draft']->fingerprint(), $this->payslips);
        sort($parts);

        return hash('sha256', json_encode([$parts, $this->errors], JSON_THROW_ON_ERROR));
    }
}
