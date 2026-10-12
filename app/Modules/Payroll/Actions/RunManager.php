<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Payroll\Enums\InstallmentStatus;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Events\RunApproved;
use App\Modules\Payroll\Events\RunReversed;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RangeException;

/**
 * The payroll run state machine.
 *
 *   draft --submit--> submitted --approve (a different member)--> approved (locked)
 *            ^------------ send back / recalculate ----------------+
 *
 * Preparing a regular run moves its period to `processing`; approving it moves the period to
 * `approved`. Nothing about an approved run is ever edited again: a correction is an adjustment
 * run (pending one-off items) and an undo is a reversal run, both of which go through the same
 * submit / approve steps. Money-moving is out of scope here: payment is only *recorded*
 * ({@see PaymentManager}).
 */
final class RunManager
{
    public const string LIMIT = 'payroll.payslips';

    public function __construct(
        private readonly RunComputation $computation,
        private readonly PayrollSettings $settings,
        private readonly NumberAllocator $numbers,
        private readonly Profiles $profiles,
        private readonly AuditLogger $audit,
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
    ) {}

    /** Calculate (or recalculate) the regular run of an open / processing period as a draft. */
    public function prepare(Period $period, ?TenantMembership $actor = null): Run
    {
        return DB::transaction(function () use ($period, $actor): Run {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PeriodStatus::Open, PeriodStatus::Processing], true)) {
                throw ValidationException::withMessages(['period' => __('This period has already been processed.')]);
            }

            $existing = Run::query()->where('period_id', $locked->id)->where('regular_marker', 1)->lockForUpdate()->first();
            $computed = $this->computation->regular($locked);

            $run = $existing ?? new Run([
                'period_id' => $locked->id,
                'kind' => RunKind::Regular,
                'regular_marker' => 1,
            ]);

            $this->persist($run, $computed, $actor);
            $locked->update(['status' => PeriodStatus::Processing]);
            $this->audit->record('payroll.run.prepared', $run, $this->summary($run), subjectLabel: $locked->name);

            return $run->refresh();
        });
    }

    /** Draft an adjustment run from the items still pending for an approved period. */
    public function prepareAdjustment(Period $period, ?TenantMembership $actor = null): Run
    {
        return DB::transaction(function () use ($period, $actor): Run {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PeriodStatus::Approved, PeriodStatus::Paid], true)) {
                throw ValidationException::withMessages(['period' => __('Adjustment runs are only available for approved or paid periods.')]);
            }

            $computed = $this->computation->adjustment($locked);

            if ($computed->count() === 0 && $computed->errors === []) {
                throw ValidationException::withMessages(['period' => __('There are no pending items to pay for this period.')]);
            }

            $run = Run::query()
                ->where('period_id', $locked->id)->where('kind', RunKind::Adjustment)
                ->whereIn('status', [RunStatus::Draft, RunStatus::Submitted])->lockForUpdate()->first()
                ?? new Run(['period_id' => $locked->id, 'kind' => RunKind::Adjustment]);

            $this->persist($run, $computed, $actor);
            $this->audit->record('payroll.run.prepared', $run, $this->summary($run), subjectLabel: $locked->name);

            return $run->refresh();
        });
    }

    /** Recalculate a draft or submitted run from today's inputs (a submitted run returns to draft). */
    public function recalculate(Run $run, ?TenantMembership $actor = null): Run
    {
        $run->loadMissing('period');

        return match ($run->kind) {
            RunKind::Regular => $this->prepare($run->period, $actor),
            RunKind::Adjustment => $this->prepareAdjustment($run->period, $actor),
            RunKind::Reversal => throw ValidationException::withMessages(['run' => __('A reversal run cannot be recalculated.')]),
        };
    }

    public function submit(Run $run, ?TenantMembership $actor): Run
    {
        return DB::transaction(function () use ($run, $actor): Run {
            $locked = $this->lock($run);

            if ($locked->kind === RunKind::Reversal || $locked->status !== RunStatus::Draft) {
                throw ValidationException::withMessages(['run' => __('Only a draft run can be submitted.')]);
            }

            if ($actor === null) {
                throw ValidationException::withMessages(['run' => __('A signed-in member must submit the run.')]);
            }

            if (($locked->errors ?? []) !== []) {
                throw ValidationException::withMessages(['run' => __('Resolve the problems listed on the run, then recalculate.')]);
            }

            if ($locked->employee_count < 1) {
                throw ValidationException::withMessages(['run' => __('The run has no payslips.')]);
            }

            $this->limits->ensure(self::LIMIT, $locked->employee_count, 'run');
            $locked->update(['status' => RunStatus::Submitted, 'submitted_by_id' => $actor->id, 'submitted_at' => now()]);
            $this->audit->record('payroll.run.submitted', $locked, $this->summary($locked));

            return $locked;
        });
    }

    /** The approver returns a submitted run to the preparer. */
    public function sendBack(Run $run, ?TenantMembership $actor, string $note): Run
    {
        return DB::transaction(function () use ($run, $actor, $note): Run {
            $locked = $this->lock($run);

            if ($locked->kind === RunKind::Reversal || $locked->status !== RunStatus::Submitted) {
                throw ValidationException::withMessages(['run' => __('Only a submitted run can be sent back.')]);
            }

            $locked->update(['status' => RunStatus::Draft, 'submitted_by_id' => null, 'submitted_at' => null, 'note' => $note]);
            $this->audit->record('payroll.run.returned', $locked, [...$this->summary($locked), 'note' => $note, 'by' => $actor?->id]);

            return $locked;
        });
    }

    /** Throw away a draft run (or a reversal that nobody approved yet). */
    public function discard(Run $run, ?TenantMembership $actor = null): void
    {
        DB::transaction(function () use ($run, $actor): void {
            $locked = $this->lock($run);
            $discardable = $locked->status === RunStatus::Draft
                || ($locked->kind === RunKind::Reversal && $locked->status === RunStatus::Submitted);

            if (! $discardable || $locked->isLocked()) {
                throw ValidationException::withMessages(['run' => __('Only a run that has not been approved can be discarded.')]);
            }

            $this->clearPayslips($locked);
            $period = Period::query()->whereKey($locked->period_id)->lockForUpdate()->firstOrFail();
            $summary = $this->summary($locked);
            $locked->delete();

            if ($locked->kind === RunKind::Regular && $period->status === PeriodStatus::Processing) {
                $period->update(['status' => PeriodStatus::Open]);
            }

            $this->audit->record('payroll.run.discarded', $period, [...$summary, 'by' => $actor?->id], subjectLabel: $period->name);
        });
    }

    /** Approve a submitted run: numbers the payslips, locks everything, consumes the inputs. */
    public function approve(Run $run, ?TenantMembership $actor): Run
    {
        return DB::transaction(function () use ($run, $actor): Run {
            $locked = $this->lock($run);
            /** @var Period $period */
            $period = Period::query()->whereKey($locked->period_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RunStatus::Submitted) {
                throw ValidationException::withMessages(['run' => __('Only a submitted run can be approved.')]);
            }

            if ($actor === null) {
                throw ValidationException::withMessages(['run' => __('A signed-in member must approve the run.')]);
            }

            $this->assertSeparateApprover($locked, $actor);

            match ($locked->kind) {
                RunKind::Regular => $this->guardPeriod($period, [PeriodStatus::Processing]),
                RunKind::Adjustment => $this->guardPeriod($period, [PeriodStatus::Approved, PeriodStatus::Paid]),
                RunKind::Reversal => $this->guardPeriod($period, [PeriodStatus::Processing, PeriodStatus::Approved, PeriodStatus::Paid]),
            };

            if ($locked->kind !== RunKind::Reversal) {
                $fresh = $locked->kind === RunKind::Regular
                    ? $this->computation->regular($period)
                    : $this->computation->adjustment($period);

                if ($fresh->fingerprint() !== $locked->input_fingerprint) {
                    throw ValidationException::withMessages(['run' => __('The inputs changed since this run was calculated. Recalculate it and submit it again.')]);
                }

                $this->limits->ensure(self::LIMIT, $locked->employee_count, 'run');
                $this->meter->record(self::LIMIT, max(1, $locked->employee_count), 'payroll.run:'.$locked->id, ['run_id' => $locked->id]);
            }

            $payslips = Payslip::query()->where('run_id', $locked->id)->orderBy('employee_name')->orderBy('id')->get();
            $now = now();

            foreach ($payslips as $payslip) {
                $payslip->forceFill(['number' => $this->numbers->next(NumberAllocator::PAYSLIP), 'locked_at' => $now])->save();
            }

            if ($locked->kind === RunKind::Reversal) {
                $this->reopenInputsOfReversed($locked);
            } else {
                $this->consumeInputs($payslips);
            }

            $locked->forceFill([
                'number' => $this->numbers->next(NumberAllocator::RUN),
                'status' => RunStatus::Approved,
                'approved_by_id' => $actor->id,
                'approved_at' => $now,
                'locked_at' => $now,
            ])->save();

            if ($locked->kind === RunKind::Regular) {
                $period->update(['status' => PeriodStatus::Approved]);
            }

            $this->audit->record($locked->kind === RunKind::Reversal ? 'payroll.run.reversed' : 'payroll.run.approved', $locked, $this->summary($locked), subjectLabel: $period->name);

            $payload = [
                'run_id' => $locked->id,
                'run_number' => $locked->number,
                'period_id' => $period->id,
                'kind' => $locked->kind->value,
                'payslip_count' => $locked->employee_count,
                'gross_amount' => $locked->gross_amount,
                'net_amount' => $locked->net_amount,
                'currency' => $locked->currency,
            ];

            if ($locked->kind === RunKind::Reversal) {
                RunReversed::dispatch([...$payload, 'reverses_run_id' => $locked->reverses_run_id]);
            } else {
                RunApproved::dispatch($payload);
            }

            return $locked;
        });
    }

    /**
     * Undo an approved run with a reversal run: a copy of its payslips marked as reversals,
     * waiting for a different member's approval. The original is never edited.
     */
    public function reverse(Run $original, ?TenantMembership $actor, string $reason): Run
    {
        return DB::transaction(function () use ($original, $actor, $reason): Run {
            $source = $this->lock($original);
            $period = Period::query()->whereKey($source->period_id)->lockForUpdate()->firstOrFail();

            if ($source->status !== RunStatus::Approved || $source->kind === RunKind::Reversal) {
                throw ValidationException::withMessages(['run' => __('Only an approved payroll run can be reversed.')]);
            }

            if ($period->status === PeriodStatus::Closed) {
                throw ValidationException::withMessages(['run' => __('This pay period is closed.')]);
            }

            if (Run::query()->where('reverses_run_id', $source->id)->exists()) {
                throw ValidationException::withMessages(['run' => __('This run has already been reversed or has a reversal in progress.')]);
            }

            $reversal = Run::query()->create([
                'period_id' => $source->period_id,
                'kind' => RunKind::Reversal,
                'status' => RunStatus::Submitted,
                'reverses_run_id' => $source->id,
                'prepared_by_id' => $actor?->id,
                'submitted_by_id' => $actor?->id,
                'submitted_at' => now(),
                'currency' => $source->currency,
                'employee_count' => $source->employee_count,
                'gross_amount' => $source->gross_amount,
                'deduction_amount' => $source->deduction_amount,
                'reimbursement_amount' => $source->reimbursement_amount,
                'net_amount' => $source->net_amount,
                'employer_amount' => $source->employer_amount,
                'note' => $reason,
            ]);

            $originals = Payslip::query()->where('run_id', $source->id)->with('lines')->get();

            foreach ($originals as $payslip) {
                $copy = Payslip::query()->create([
                    ...$payslip->only([
                        'period_id', 'employee_id', 'owner_id', 'department_id', 'branch_id', 'employee_number', 'employee_name',
                        'designation_name', 'department_name', 'employment_type', 'joined_on', 'currency', 'assignment_id',
                        'structure_name', 'payable_days', 'period_days', 'basic_amount', 'gross_amount', 'taxable_amount',
                        'deduction_amount', 'reimbursement_amount', 'net_amount', 'employer_amount', 'attendance',
                    ]),
                    'run_id' => $reversal->id,
                    'is_reversal' => true,
                    'reverses_payslip_id' => $payslip->id,
                    'payment_status' => PaymentStatus::Unpaid,
                    'warnings' => null,
                ]);

                foreach ($payslip->lines as $line) {
                    PayslipLine::query()->create([
                        ...$line->only(['kind', 'code', 'name', 'source', 'source_id', 'amount', 'is_taxable', 'sort_order', 'detail']),
                        'payslip_id' => $copy->id,
                    ]);
                }
            }

            $this->audit->record('payroll.run.reversal_requested', $reversal, [
                'reverses_run_id' => $source->id,
                'reverses_run_number' => $source->number,
                'reason' => $reason,
                'net_amount' => $source->net_amount,
            ], subjectLabel: $period->name);

            return $reversal;
        });
    }

    private function assertSeparateApprover(Run $run, TenantMembership $approver): void
    {
        if (! $this->settings->current()->require_separate_approver) {
            return;
        }

        if ($approver->id === $run->prepared_by_id || $approver->id === $run->submitted_by_id) {
            throw ValidationException::withMessages([
                'run' => __('A payroll run must be approved by someone other than the person who prepared or submitted it.'),
            ]);
        }
    }

    /** @param list<PeriodStatus> $allowed */
    private function guardPeriod(Period $period, array $allowed): void
    {
        if (! in_array($period->status, $allowed, true)) {
            throw ValidationException::withMessages(['period' => __('The pay period is not in a state that allows this.')]);
        }
    }

    private function lock(Run $run): Run
    {
        /** @var Run $locked */
        $locked = Run::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /** Write a computation into a draft run: its payslips, lines and totals. */
    private function persist(Run $run, ComputedRun $computed, ?TenantMembership $actor): void
    {
        if ($run->exists && ($run->isLocked() || $run->status === RunStatus::Approved)) {
            throw ValidationException::withMessages(['run' => __('A locked payroll run cannot be changed.')]);
        }

        try {
            $totals = $computed->totals();
        } catch (RangeException) {
            throw ValidationException::withMessages(['run' => __('The run totals are outside the supported range. Review the amounts and recalculate.')]);
        }

        $currency = $this->settings->currency();

        $run->fill([
            'status' => RunStatus::Draft,
            'currency' => $currency,
            'employee_count' => $computed->count(),
            ...$totals,
            'input_fingerprint' => $computed->fingerprint(),
            'warnings' => $computed->warnings === [] ? null : $computed->warnings,
            'errors' => $computed->errors === [] ? null : $computed->errors,
            // The first preparer stays on record; a recalculation (or a system actor) never replaces or wipes it.
            'prepared_by_id' => $run->prepared_by_id ?? $actor?->id,
            'submitted_by_id' => null,
            'submitted_at' => null,
            'note' => null,
        ])->save();

        $this->clearPayslips($run);

        foreach ($computed->payslips as $row) {
            $employee = $row['employee'];
            $draft = $row['draft'];
            $profile = $this->profiles->ensure($employee);

            $payslip = Payslip::query()->create([
                'run_id' => $run->id,
                'period_id' => $run->period_id,
                'employee_id' => $employee->id,
                'owner_id' => $profile->owner_id,
                'department_id' => $profile->department_id,
                'branch_id' => $profile->branch_id,
                'employee_number' => $employee->number,
                'employee_name' => $employee->name,
                'designation_name' => $employee->designationName,
                'department_name' => $employee->departmentName,
                'employment_type' => $employee->employmentType,
                'joined_on' => $employee->joinedOn,
                'currency' => $currency,
                'assignment_id' => $row['assignment_id'],
                'structure_name' => $row['structure_name'],
                'payable_days' => $draft->payableDays,
                'period_days' => $draft->periodDays,
                'basic_amount' => $draft->basicAmount,
                'gross_amount' => $draft->grossAmount,
                'taxable_amount' => $draft->taxableAmount,
                'deduction_amount' => $draft->deductionAmount,
                'reimbursement_amount' => $draft->reimbursementAmount,
                'net_amount' => $draft->netAmount,
                'employer_amount' => $draft->employerAmount,
                'attendance' => $draft->attendance === [] ? null : $draft->attendance,
                'warnings' => $draft->warnings === [] ? null : $draft->warnings,
                'payment_status' => PaymentStatus::Unpaid,
            ]);

            foreach ($draft->lines as $index => $line) {
                PayslipLine::query()->create([...$line, 'payslip_id' => $payslip->id, 'sort_order' => $index]);
            }
        }
    }

    /** Remove the (unlocked) payslips of a draft run. */
    private function clearPayslips(Run $run): void
    {
        $ids = Payslip::query()->where('run_id', $run->id)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        if (Payslip::query()->whereIn('id', $ids)->whereNotNull('locked_at')->exists()) {
            throw ValidationException::withMessages(['run' => __('A locked payroll run cannot be changed.')]);
        }

        PayslipLine::query()->whereIn('payslip_id', $ids)->delete();
        Payslip::query()->whereIn('id', $ids)->delete();
    }

    /**
     * Mark the items and installments a run paid as consumed, in the approving transaction.
     *
     * @param  iterable<Payslip>  $payslips
     */
    private function consumeInputs(iterable $payslips): void
    {
        $loanIds = [];

        foreach ($payslips as $payslip) {
            $lines = PayslipLine::query()->where('payslip_id', $payslip->id)->whereIn('source', ['item', 'loan'])->get();
            $itemIds = $lines->where('source', 'item')->pluck('source_id')->filter()->all();
            $installmentIds = $lines->where('source', 'loan')->pluck('source_id')->filter()->all();

            if ($itemIds !== []) {
                $updated = Item::query()->whereKey($itemIds)->where('status', ItemStatus::Pending)
                    ->update(['status' => ItemStatus::Included, 'payslip_id' => $payslip->id]);

                if ($updated !== count($itemIds)) {
                    throw ValidationException::withMessages(['run' => __('The inputs changed since this run was calculated. Recalculate it and submit it again.')]);
                }
            }

            if ($installmentIds !== []) {
                $updated = LoanInstallment::query()->whereKey($installmentIds)->where('status', InstallmentStatus::Pending)
                    ->update(['status' => InstallmentStatus::Deducted, 'payslip_id' => $payslip->id]);

                if ($updated !== count($installmentIds)) {
                    throw ValidationException::withMessages(['run' => __('The inputs changed since this run was calculated. Recalculate it and submit it again.')]);
                }

                $loanIds = [...$loanIds, ...LoanInstallment::query()->whereKey($installmentIds)->pluck('loan_id')->all()];
            }
        }

        foreach (array_unique($loanIds) as $loanId) {
            $remaining = LoanInstallment::query()->where('loan_id', $loanId)->where('status', InstallmentStatus::Pending)->exists();

            if (! $remaining) {
                Loan::query()->whereKey($loanId)->where('status', LoanStatus::Active)->update(['status' => LoanStatus::Completed]);
            }
        }
    }

    /** A reversal frees what the reversed run had consumed so it can be paid again. */
    private function reopenInputsOfReversed(Run $reversal): void
    {
        $payslipIds = Payslip::query()->where('run_id', $reversal->reverses_run_id)->pluck('id')->all();

        if ($payslipIds === []) {
            return;
        }

        Item::query()->whereIn('payslip_id', $payslipIds)->where('status', ItemStatus::Included)
            ->update(['status' => ItemStatus::Pending, 'payslip_id' => null]);

        $loanIds = LoanInstallment::query()->whereIn('payslip_id', $payslipIds)->where('status', InstallmentStatus::Deducted)->pluck('loan_id')->unique()->all();
        LoanInstallment::query()->whereIn('payslip_id', $payslipIds)->where('status', InstallmentStatus::Deducted)
            ->update(['status' => InstallmentStatus::Pending, 'payslip_id' => null]);
        Loan::query()->whereKey($loanIds)->where('status', LoanStatus::Completed)->update(['status' => LoanStatus::Active]);
    }

    /** @return array<string, mixed> */
    private function summary(Run $run): array
    {
        return [
            'period_id' => $run->period_id,
            'kind' => $run->kind->value,
            'number' => $run->number,
            'employee_count' => $run->employee_count,
            'gross_amount' => $run->gross_amount,
            'net_amount' => $run->net_amount,
        ];
    }
}
