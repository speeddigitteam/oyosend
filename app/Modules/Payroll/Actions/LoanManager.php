<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Enums\InstallmentStatus;
use App\Modules\Payroll\Enums\LoanKind;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loans and salary advances repaid by payroll deductions.
 *
 * The schedule is fixed when the loan is requested: `count` monthly installments that add up to
 * exactly the principal (the remainder of the integer division is spread one minor unit at a time
 * over the first installments). A loan only deducts once a different person approved it.
 */
final class LoanManager
{
    public const int MAX_PRINCIPAL = 1_000_000_000_000;

    public const int MAX_INSTALLMENTS = 60;

    public function __construct(
        private readonly Profiles $profiles,
        private readonly PayrollSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The installment schedule of a loan.
     *
     * @return list<array{sequence: int, due_on: string, amount: int}>
     */
    public static function schedule(int $principal, int $count, string $firstDueOn): array
    {
        $count = max(1, $count);
        $base = intdiv($principal, $count);
        $remainder = $principal % $count;
        $first = CarbonImmutable::parse($firstDueOn, 'UTC');
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'sequence' => $i + 1,
                'due_on' => $first->addMonthsNoOverflow($i)->toDateString(),
                'amount' => $base + ($i < $remainder ? 1 : 0),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $attributes */
    public function request(EmployeeSnapshot $employee, array $attributes, ?TenantMembership $actor = null): Loan
    {
        $principal = (int) $attributes['principal_amount'];
        $count = (int) $attributes['installment_count'];

        if ($principal < 1 || $principal > self::MAX_PRINCIPAL) {
            throw ValidationException::withMessages(['principal_amount' => __('The amount is outside the supported range.')]);
        }

        if ($count < 1 || $count > self::MAX_INSTALLMENTS || $count > $principal) {
            throw ValidationException::withMessages(['installment_count' => __('The number of installments is not valid for this amount.')]);
        }

        return DB::transaction(function () use ($employee, $attributes, $principal, $count, $actor): Loan {
            $profile = $this->profiles->ensure($employee);

            $loan = Loan::query()->create([
                'employee_id' => $employee->id,
                'owner_id' => $profile->owner_id,
                'department_id' => $profile->department_id,
                'branch_id' => $profile->branch_id,
                'kind' => LoanKind::from((string) $attributes['kind']),
                'title' => (string) $attributes['title'],
                'principal_amount' => $principal,
                'installment_count' => $count,
                'first_due_on' => (string) $attributes['first_due_on'],
                'currency' => $this->settings->currency(),
                'status' => LoanStatus::Pending,
                'reason' => $attributes['reason'] ?? null,
                'requested_by_id' => $actor?->id,
            ]);

            foreach (self::schedule($principal, $count, (string) $attributes['first_due_on']) as $row) {
                LoanInstallment::query()->create([...$row, 'loan_id' => $loan->id, 'employee_id' => $employee->id, 'status' => InstallmentStatus::Pending]);
            }

            return $loan;
        });
    }

    /** Approve or reject a pending loan. The approver must not be the requester (unless the workspace allows it). */
    public function decide(Loan $loan, bool $approve, ?string $note, ?TenantMembership $actor): Loan
    {
        return DB::transaction(function () use ($loan, $approve, $note, $actor): Loan {
            /** @var Loan $locked */
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== LoanStatus::Pending) {
                throw ValidationException::withMessages(['loan' => __('This loan has already been decided.')]);
            }

            if ($actor === null) {
                throw ValidationException::withMessages(['loan' => __('A signed-in member must decide a loan.')]);
            }

            if ($this->settings->current()->require_separate_approver && $locked->requested_by_id === $actor->id) {
                throw ValidationException::withMessages(['loan' => __('A loan must be approved by someone other than the person who requested it.')]);
            }

            if (! $approve && ($note === null || trim($note) === '')) {
                throw ValidationException::withMessages(['note' => __('Give a reason for rejecting the loan.')]);
            }

            $locked->update([
                'status' => $approve ? LoanStatus::Active : LoanStatus::Rejected,
                'approved_by_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if (! $approve) {
                $this->closeInstallments($locked);
            }

            $this->audit->record($approve ? 'payroll.loan.approved' : 'payroll.loan.rejected', $locked, ['principal_amount' => $locked->principal_amount]);

            return $locked;
        });
    }

    /** Stop a pending or active loan: the installments not yet deducted are cancelled. */
    public function cancel(Loan $loan, ?string $note = null): Loan
    {
        return DB::transaction(function () use ($loan, $note): Loan {
            /** @var Loan $locked */
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [LoanStatus::Pending, LoanStatus::Active], true)) {
                throw ValidationException::withMessages(['loan' => __('This loan can no longer be cancelled.')]);
            }

            $locked->update(['status' => LoanStatus::Cancelled, 'decision_note' => $note ?? $locked->decision_note, 'decided_at' => now()]);
            $this->closeInstallments($locked);
            $this->audit->record('payroll.loan.cancelled', $locked, ['principal_amount' => $locked->principal_amount]);

            return $locked;
        });
    }

    private function closeInstallments(Loan $loan): void
    {
        LoanInstallment::query()->where('loan_id', $loan->id)->where('status', InstallmentStatus::Pending)
            ->update(['status' => InstallmentStatus::Cancelled]);
    }
}
