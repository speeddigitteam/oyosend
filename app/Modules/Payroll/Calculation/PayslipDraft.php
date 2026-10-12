<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Calculation;

/**
 * The result of calculating one employee for one period (nothing is stored yet).
 *
 * @phpstan-type Line array{kind: string, code: string, name: string, source: string, source_id: string|null, amount: int, is_taxable: bool, detail: array<string, mixed>|null}
 */
final readonly class PayslipDraft
{
    /**
     * @param  list<Line>  $lines
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<string>  $itemIds  Items applied to this payslip.
     * @param  list<string>  $installmentIds  Loan installments deducted on this payslip.
     * @param  array<string, mixed>  $attendance
     */
    public function __construct(
        public string $employeeId,
        public int $payableDays,
        public int $periodDays,
        public int $basicAmount,
        public int $grossAmount,
        public int $taxableAmount,
        public int $deductionAmount,
        public int $reimbursementAmount,
        public int $netAmount,
        public int $employerAmount,
        public array $lines,
        public array $warnings,
        public array $itemIds,
        public array $installmentIds,
        public array $attendance,
        public ?string $error = null,
    ) {}

    /** A stable string of the figures that decide what is paid, for the approval-time comparison. */
    public function fingerprint(): string
    {
        return json_encode([
            $this->employeeId, $this->payableDays, $this->basicAmount, $this->grossAmount, $this->taxableAmount,
            $this->deductionAmount, $this->reimbursementAmount, $this->netAmount, $this->employerAmount,
            array_map(static fn (array $line): array => [$line['kind'], $line['code'], $line['source_id'], $line['amount']], $this->lines),
            $this->itemIds, $this->installmentIds, $this->error,
        ], JSON_THROW_ON_ERROR);
    }
}
