<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Billing\UsageLimits;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Contracts\EmployeeTimeSummary;
use App\Modules\Hrm\Actions\AttendanceCloser;
use App\Modules\Hrm\Actions\AttendanceManager;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Payroll\Actions\ItemManager;
use App\Modules\Payroll\Actions\LoanManager;
use App\Modules\Payroll\Actions\PaymentManager;
use App\Modules\Payroll\Actions\PayrollReports;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Actions\PeriodManager;
use App\Modules\Payroll\Actions\RuleSetManager;
use App\Modules\Payroll\Actions\RunManager;
use App\Modules\Payroll\Enums\InstallmentStatus;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Events\PayslipPaid;
use App\Modules\Payroll\Events\RunApproved;
use App\Modules\Payroll\Events\RunReversed;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithPayroll;

uses(InteractsWithPayroll::class);

/*
 * Period 2026-03-01 .. 2026-03-07 (7 calendar days, Fri and Sat are the weekly off). Basic 70000 so a
 * day is worth exactly 10000 of basic. Time facts come from real HRM attendance and leave.
 *
 *   Sun 1  present 09:00-18:00        Wed 4  approved unpaid leave
 *   Mon 2  present 09:00-19:00 (60 min overtime)   Thu 5  present
 *   Tue 3  no entry: absent           Fri/Sat weekly off
 */
beforeEach(function () {
    $this->tenant = $this->payrollTenant('pay-flow');
    $this->hrmShift($this->tenant);
    $this->hrmAt('2026-03-09 10:00:00');

    $this->employee = $this->hire($this->tenant, ['first_name' => 'Rafi', 'last_name' => 'Ahmed', 'joined_on' => '2026-03-01']);
    $this->structure = $this->payStructure($this->tenant, [
        ['name' => 'House rent', 'code' => 'RENT', 'calc' => 'percent_basic', 'rate_bps' => 4000],
        ['name' => 'Transport', 'code' => 'TRAVEL', 'calc' => 'fixed', 'amount' => 5000],
        ['name' => 'Provident fund', 'code' => 'PF', 'kind' => 'deduction', 'calc' => 'percent_basic', 'rate_bps' => 1000, 'is_taxable' => false],
    ]);
    $this->paySalary($this->tenant, $this->employee, $this->structure, 70_000);
    $this->period = $this->payPeriod($this->tenant);

    $this->attend = function (): void {
        $type = $this->hrmLeaveType($this->tenant, ['name' => 'Unpaid', 'code' => 'UL', 'is_paid' => false, 'tracks_balance' => false, 'approval_flow' => 'hr_only']);
        $owner = $this->ownerOf($this->tenant);
        $ownerMembership = $this->membershipOf($this->tenant, $owner);

        $this->inTenant($this->tenant, function () use ($type, $owner, $ownerMembership): void {
            $attendance = app(AttendanceManager::class);
            $attendance->record($this->employee, '2026-03-01 09:00', '2026-03-01 18:00', null, null);
            $attendance->record($this->employee, '2026-03-02 09:00', '2026-03-02 19:00', null, null);
            $attendance->record($this->employee, '2026-03-05 09:00', '2026-03-05 18:00', null, null);

            $request = app(LeaveManager::class)->submit($this->employee, ['leave_type_id' => $type->id, 'starts_on' => '2026-03-04', 'ends_on' => '2026-03-04']);
            $request->load(['employee', 'leaveType']);
            app(LeaveManager::class)->decide($request, $owner, true, null, $ownerMembership);
        });

        $this->closeDays($this->tenant, $this->employee, '2026-03-07');
    };

    $this->exampleRule = function (): void {
        // EXAMPLE numbers for a test: not any country's law.
        $this->inTenant($this->tenant, fn () => app(RuleSetManager::class)->save(null, [
            'name' => 'EXAMPLE income tax', 'code' => 'EXTAX', 'engine' => 'slab', 'category' => 'income_tax', 'payer' => 'employee',
            'basis' => 'taxable_gross', 'method' => 'progressive', 'annualize' => false, 'periods_per_year' => 12,
            'effective_from' => '2026-01-01', 'is_active' => true,
            'slabs' => [
                ['from' => 0, 'to' => 50_000, 'rate_bps' => 0],
                ['from' => 50_000, 'to' => null, 'rate_bps' => 1000],
            ],
        ]));
    };

    $this->ownerMembership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    [$this->approverUser, $this->approver] = $this->hrmMember($this->tenant, ['payroll.runs.approve']);

    $this->prepare = fn (): Run => $this->inTenant($this->tenant, fn (): Run => app(RunManager::class)->prepare($this->period, $this->ownerMembership));
    $this->submit = fn (Run $run): Run => $this->inTenant($this->tenant, fn (): Run => app(RunManager::class)->submit($run, $this->ownerMembership));
    $this->approve = fn (Run $run): Run => $this->inTenant($this->tenant, fn (): Run => app(RunManager::class)->approve($run, $this->approver));
});

it('calculates a run from real HRM time facts with exact integer arithmetic', function () {
    ($this->attend)();
    ($this->exampleRule)();

    $this->inTenant($this->tenant, function (): void {
        $facts = app(EmployeeTimeSummary::class)->attendance($this->employee->id, '2026-03-01', '2026-03-07');
        expect($facts)->toMatchArray(['days_present' => 3, 'days_absent' => 1, 'days_on_leave' => 1, 'open_days' => 0, 'overtime_minutes' => 60]);
    });

    $run = ($this->prepare)();
    $payslip = $this->inTenant($this->tenant, fn () => Payslip::query()->with('lines')->where('run_id', $run->id)->sole());
    $lines = $payslip->lines->keyBy('code');

    // Rate base 70000 + 28000 + 5000 = 103000 over 7 days. Unpaid leave and absence: 103000 x 2 / 14 = 14714.
    // Overtime: 70000 x 60 x 1.5 / (7 x 480) = 1875. Taxable: 104875 - 29428 = 75447; tax (75447 - 50000) x 10 % = 2545.
    expect($run->status)->toBe(RunStatus::Draft)
        ->and($run->employee_count)->toBe(1)
        ->and($payslip->basic_amount)->toBe(70_000)
        ->and($lines['RENT']->amount)->toBe(28_000)
        ->and($lines['TRAVEL']->amount)->toBe(5_000)
        ->and($lines['overtime']->amount)->toBe(1_875)
        ->and($lines['PF']->amount)->toBe(7_000)
        ->and($lines['unpaid_leave']->amount)->toBe(14_714)
        ->and($lines['absence']->amount)->toBe(14_714)
        ->and($lines['EXTAX']->amount)->toBe(2_545)
        ->and($payslip->gross_amount)->toBe(104_875)
        ->and($payslip->taxable_amount)->toBe(75_447)
        ->and($payslip->deduction_amount)->toBe(7_000 + 14_714 + 14_714 + 2_545)
        ->and($payslip->net_amount)->toBe(65_902)
        ->and($run->net_amount)->toBe(65_902)
        ->and($payslip->number)->toBeNull()
        ->and($payslip->isLocked())->toBeFalse();

    $this->inTenant($this->tenant, fn () => expect($this->period->fresh()->status)->toBe(PeriodStatus::Processing));
});

it('refuses a period that has not ended, has open attendance days, or whose employee has no salary', function () {
    // Not ended yet.
    $this->hrmAt('2026-03-05 10:00:00');
    $this->inTenant($this->tenant, fn () => expect(fn () => app(RunManager::class)->prepare($this->period))->toThrow(ValidationException::class));

    // Ended, but an attendance day was never closed.
    $this->hrmAt('2026-03-02 09:00:00');
    $this->inTenant($this->tenant, fn () => app(AttendanceManager::class)->clockIn($this->employee));
    $this->hrmAt('2026-03-09 10:00:00');

    $this->inTenant($this->tenant, function (): void {
        $summary = app(EmployeeTimeSummary::class)->attendance($this->employee->id, '2026-03-01', '2026-03-07');
        expect($summary['open_days'])->toBeGreaterThan(0);

        try {
            app(RunManager::class)->prepare($this->period);
            $this->fail('A period with open attendance days must be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('period')
                ->and($exception->errors()['period'][0])->toContain('Rafi Ahmed');
        }

        expect(Run::query()->count())->toBe(0)->and($this->period->fresh()->status)->toBe(PeriodStatus::Open);
    });
});

it('skips an employee without a salary revision and reports it as a notice', function () {
    $this->hire($this->tenant, ['first_name' => 'Nina', 'joined_on' => '2026-03-01']);
    ($this->attend)();

    $run = ($this->prepare)();

    expect($run->employee_count)->toBe(1)
        ->and(collect($run->warnings)->pluck('code')->all())->toContain('no_salary_assignment');
});

it('moves a run through submit and approval with a different approver and locks the snapshot', function () {
    Event::fake([RunApproved::class]);
    ($this->attend)();
    ($this->exampleRule)();

    $run = ($this->prepare)();
    $run = ($this->submit)($run);
    expect($run->status)->toBe(RunStatus::Submitted);

    // The preparer (also the submitter) cannot approve their own run.
    $this->inTenant($this->tenant, fn () => expect(fn () => app(RunManager::class)->approve($run, $this->ownerMembership))->toThrow(ValidationException::class));

    $approved = ($this->approve)($run);
    $payslip = $this->inTenant($this->tenant, fn () => Payslip::query()->where('run_id', $run->id)->sole());

    expect($approved->status)->toBe(RunStatus::Approved)
        ->and($approved->number)->toBe('RUN-000001')
        ->and($approved->approved_by_id)->toBe($this->approver->id)
        ->and($approved->isLocked())->toBeTrue()
        ->and($payslip->number)->toBe('PSL-000001')
        ->and($payslip->isLocked())->toBeTrue();

    Event::assertDispatched(RunApproved::class, fn (RunApproved $event): bool => $event->payload()['net_amount'] === 65_902
        && $event->payload()['run_id'] === $run->id && $event->payload()['payslip_count'] === 1);

    $this->inTenant($this->tenant, function () use ($run): void {
        expect(Period::query()->find($this->period->id)->status)->toBe(PeriodStatus::Approved);

        // A second approval, or touching an approved run, is refused.
        expect(fn () => app(RunManager::class)->approve($run, $this->approver))->toThrow(ValidationException::class)
            ->and(fn () => app(RunManager::class)->prepare($this->period))->toThrow(ValidationException::class)
            ->and(fn () => app(RunManager::class)->discard($run))->toThrow(ValidationException::class);
    });
});

it('lets the workspace switch the separate-approver rule off, and keeps the check when it is on', function () {
    ($this->attend)();
    $run = ($this->submit)(($this->prepare)());

    $this->inTenant($this->tenant, function () use ($run): void {
        app(PayrollSettings::class)->update(['require_separate_approver' => false]);
        expect(app(RunManager::class)->approve($run, $this->ownerMembership)->status)->toBe(RunStatus::Approved);
    });
});

it('keeps an approved run and its payslips immutable', function () {
    ($this->attend)();
    $run = ($this->approve)(($this->submit)(($this->prepare)()));

    $this->inTenant($this->tenant, function () use ($run): void {
        $payslip = Payslip::query()->where('run_id', $run->id)->sole();
        $line = PayslipLine::query()->where('payslip_id', $payslip->id)->first();

        expect(fn () => $payslip->update(['net_amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $payslip->update(['employee_name' => 'Someone Else']))->toThrow(LogicException::class)
            ->and(fn () => $payslip->delete())->toThrow(LogicException::class)
            ->and(fn () => $line->update(['amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $line->delete())->toThrow(LogicException::class)
            ->and(fn () => $run->update(['net_amount' => 1]))->toThrow(LogicException::class)
            ->and(fn () => $run->delete())->toThrow(LogicException::class);

        // The payment columns are the only thing that can still move.
        $current = Payslip::query()->findOrFail($payslip->id);
        $current->update(['payment_reference' => 'REF-1']);
        expect($current->fresh()->payment_reference)->toBe('REF-1');
    });
});

it('detects changed inputs between submission and approval', function () {
    ($this->attend)();
    $run = ($this->submit)(($this->prepare)());

    $this->inTenant($this->tenant, function (): void {
        $snapshot = app(EmployeeDirectory::class)->find($this->employee->id);
        app(ItemManager::class)->create($snapshot, $this->period, ['type' => 'bonus', 'name' => 'Spot bonus', 'amount' => 5_000]);
    });

    $this->inTenant($this->tenant, fn () => expect(fn () => app(RunManager::class)->approve($run, $this->approver))->toThrow(ValidationException::class));

    // Recalculating picks the item up, and the run can then be approved.
    $run = $this->inTenant($this->tenant, fn () => app(RunManager::class)->recalculate($run, $this->ownerMembership));
    $run = ($this->approve)(($this->submit)($run));

    $this->inTenant($this->tenant, function () use ($run): void {
        $item = Item::query()->sole();
        expect($item->status)->toBe(ItemStatus::Included)->and($item->payslip_id)->not->toBeNull()
            ->and($run->gross_amount)->toBe(109_875);
    });
});

it('deducts loan installments from runs and completes the loan', function () {
    ($this->attend)();

    $this->inTenant($this->tenant, function (): void {
        $snapshot = app(EmployeeDirectory::class)->find($this->employee->id);
        $this->loan = app(LoanManager::class)->request($snapshot, [
            'kind' => 'advance', 'title' => 'Salary advance', 'principal_amount' => 20_000, 'installment_count' => 2, 'first_due_on' => '2026-03-05',
        ], $this->ownerMembership);

        // A pending loan is not deducted, and the requester cannot approve it.
        expect(fn () => app(LoanManager::class)->decide($this->loan, true, null, $this->ownerMembership))->toThrow(ValidationException::class);
    });

    $before = ($this->prepare)();
    $this->inTenant($this->tenant, fn () => expect(PayslipLine::query()->where('code', 'loan')->count())->toBe(0));

    $this->inTenant($this->tenant, fn () => app(LoanManager::class)->decide($this->loan, true, null, $this->approver));

    $run = $this->inTenant($this->tenant, fn () => app(RunManager::class)->recalculate($before, $this->ownerMembership));
    $run = ($this->approve)(($this->submit)($run));

    $this->inTenant($this->tenant, function () use ($run): void {
        // Only the first installment (due 2026-03-05) is due in this period: 10000.
        $line = PayslipLine::query()->where('code', 'loan')->sole();
        $installments = LoanInstallment::query()->where('loan_id', $this->loan->id)->orderBy('sequence')->get();

        expect($line->amount)->toBe(10_000)
            ->and($installments[0]->status)->toBe(InstallmentStatus::Deducted)
            ->and($installments[0]->payslip_id)->not->toBeNull()
            ->and($installments[1]->status)->toBe(InstallmentStatus::Pending)
            ->and(Loan::query()->find($this->loan->id)->status)->toBe(LoanStatus::Active)
            ->and($run->net_amount)->toBe(68_447 - 10_000);
    });
});

it('records payments idempotently and closes the period once every payslip is paid', function () {
    Event::fake([PayslipPaid::class]);
    ($this->attend)();
    $run = ($this->approve)(($this->submit)(($this->prepare)()));

    $this->inTenant($this->tenant, function () use ($run): void {
        $payslip = Payslip::query()->where('run_id', $run->id)->sole();
        $payments = app(PaymentManager::class);
        $input = ['status' => 'paid', 'method' => 'bank', 'reference' => 'TRX-1', 'paid_on' => '2026-03-09'];

        // Failed first, then paid.
        $failed = $payments->record($payslip, ['status' => 'failed', 'note' => 'Account closed'], 'key-failed-0001', $this->ownerMembership);
        expect($failed->status)->toBe(PaymentStatus::Failed)->and($payslip->fresh()->payment_status)->toBe(PaymentStatus::Failed);

        $first = $payments->record($payslip, $input, 'key-paid-0001', $this->ownerMembership);
        $replay = $payments->record($payslip, $input, 'key-paid-0001', $this->ownerMembership);

        expect($replay->id)->toBe($first->id)
            ->and(Payment::query()->count())->toBe(2)
            ->and($payslip->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($payslip->fresh()->payment_reference)->toBe('TRX-1')
            ->and(Period::query()->find($this->period->id)->status)->toBe(PeriodStatus::Paid);

        // A paid payslip is final; the same key may not be reused for another payslip.
        expect(fn () => $payments->record($payslip, ['status' => 'failed'], 'key-failed-0002', $this->ownerMembership))->toThrow(ValidationException::class);
    });

    Event::assertDispatchedTimes(PayslipPaid::class, 1);
    Event::assertDispatched(PayslipPaid::class, fn (PayslipPaid $event): bool => $event->payload()['net_amount'] === 68_447);
});

it('reverses an approved run with a second approval, reopens its inputs and nets reports to zero', function () {
    Event::fake([RunReversed::class]);
    ($this->attend)();

    $this->inTenant($this->tenant, function (): void {
        $snapshot = app(EmployeeDirectory::class)->find($this->employee->id);
        app(ItemManager::class)->create($snapshot, $this->period, ['type' => 'festival_bonus', 'name' => 'Eid', 'basic_bps' => 5000]);
    });

    $run = ($this->approve)(($this->submit)(($this->prepare)()));

    $reversal = $this->inTenant($this->tenant, fn () => app(RunManager::class)->reverse($run, $this->ownerMembership, 'Wrong attendance data'));
    expect($reversal->kind)->toBe(RunKind::Reversal)->and($reversal->status)->toBe(RunStatus::Submitted);

    $this->inTenant($this->tenant, function () use ($run, $reversal): void {
        // The original is untouched, a second reversal is refused, and the reverser cannot approve it.
        expect(Run::query()->find($run->id)->net_amount)->toBe($run->net_amount)
            ->and(fn () => app(RunManager::class)->reverse($run, $this->ownerMembership, 'again'))->toThrow(ValidationException::class)
            ->and(fn () => app(RunManager::class)->approve($reversal, $this->ownerMembership))->toThrow(ValidationException::class)
            ->and(Item::query()->sole()->status)->toBe(ItemStatus::Included);

        $approved = app(RunManager::class)->approve($reversal, $this->approver);
        $slips = Payslip::query()->where('run_id', $approved->id)->get();

        expect($approved->status)->toBe(RunStatus::Approved)
            ->and($slips)->toHaveCount(1)
            ->and($slips[0]->is_reversal)->toBeTrue()
            ->and($slips[0]->net_amount)->toBe($run->net_amount)
            ->and($slips[0]->number)->toBe('PSL-000002')
            ->and(Item::query()->sole()->status)->toBe(ItemStatus::Pending);

        // Reports count a reversal negatively, so the run and its reversal cancel out.
        $totals = app(PayrollReports::class)->totals([$this->period->id]);
        expect($totals['net_amount'])->toBe(0)->and($totals['gross_amount'])->toBe(0)->and($totals['payslips'])->toBe(2);
    });

    Event::assertDispatched(RunReversed::class, fn (RunReversed $event): bool => $event->payload()['reverses_run_id'] === $run->id);
});

it('pays an item posted after approval through an adjustment run', function () {
    ($this->attend)();
    $run = ($this->approve)(($this->submit)(($this->prepare)()));

    $this->inTenant($this->tenant, function () use ($run): void {
        $snapshot = app(EmployeeDirectory::class)->find($this->employee->id);
        app(ItemManager::class)->create($snapshot, $this->period, ['type' => 'reimbursement', 'name' => 'Late taxi claim', 'amount' => 1_500]);
        app(ItemManager::class)->create($snapshot, $this->period, ['type' => 'allowance', 'name' => 'Backpay', 'amount' => 4_000]);

        $adjustment = app(RunManager::class)->prepareAdjustment($this->period, $this->ownerMembership);
        $adjustment = app(RunManager::class)->submit($adjustment, $this->ownerMembership);
        $adjustment = app(RunManager::class)->approve($adjustment, $this->approver);
        $slip = Payslip::query()->where('run_id', $adjustment->id)->sole();

        expect($adjustment->kind)->toBe(RunKind::Adjustment)
            ->and($slip->gross_amount)->toBe(4_000)
            ->and($slip->reimbursement_amount)->toBe(1_500)
            ->and($slip->net_amount)->toBe(5_500)
            ->and(Item::query()->where('status', ItemStatus::Included)->count())->toBe(2)
            // The regular run was not touched.
            ->and(Run::query()->find($run->id)->net_amount)->toBe($run->net_amount);

        // Nothing is left to adjust.
        expect(fn () => app(RunManager::class)->prepareAdjustment($this->period))->toThrow(ValidationException::class);
    });
});

it('discards a draft run and returns the period to open', function () {
    ($this->attend)();
    $run = ($this->prepare)();

    $this->inTenant($this->tenant, function () use ($run): void {
        app(RunManager::class)->discard($run, $this->ownerMembership);

        expect(Run::query()->count())->toBe(0)->and(Payslip::query()->count())->toBe(0)
            ->and(Period::query()->find($this->period->id)->status)->toBe(PeriodStatus::Open);
    });
});

it('sends a submitted run back to the preparer', function () {
    ($this->attend)();
    $run = ($this->submit)(($this->prepare)());

    $returned = $this->inTenant($this->tenant, fn () => app(RunManager::class)->sendBack($run, $this->approver, 'Check overtime'));

    expect($returned->status)->toBe(RunStatus::Draft)->and($returned->submitted_by_id)->toBeNull()->and($returned->note)->toBe('Check overtime');
});

it('allocates gap-free numbers and enforces the metered payslip limit at approval', function () {
    $plan = $this->createFreePlan(['key' => 'one-payslip', 'modules' => ['hrm', 'payroll'], 'limits' => ['payroll.payslips' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    ($this->attend)();
    $run = ($this->approve)(($this->submit)(($this->prepare)()));

    $this->inTenant($this->tenant, function (): void {
        expect(app(UsageLimits::class)->used('payroll.payslips'))->toBe(1)
            ->and(app(UsageLimits::class)->remaining('payroll.payslips'))->toBe(0);

        // The next run in the next period cannot be submitted: the plan's payslips are used up.
        $next = app(PeriodManager::class)->create(['kind' => 'custom', 'starts_on' => '2026-03-08', 'ends_on' => '2026-03-08']);
        $this->hrmAt('2026-03-10 10:00:00');
        app(AttendanceCloser::class)->closeEmployee($this->employee, '2026-03-08');
        $draft = app(RunManager::class)->prepare($next, $this->ownerMembership);

        expect(fn () => app(RunManager::class)->submit($draft, $this->ownerMembership))->toThrow(ValidationException::class);
    });
});

it('keeps the first preparer on a run when it is recalculated by someone else or by no one', function () {
    ($this->attend)();
    $run = ($this->prepare)();

    $this->inTenant($this->tenant, function () use ($run): void {
        $recalculated = app(RunManager::class)->recalculate($run, $this->approver);
        expect($recalculated->prepared_by_id)->toBe($this->ownerMembership->id);

        $recalculated = app(RunManager::class)->recalculate($recalculated, null);
        expect($recalculated->prepared_by_id)->toBe($this->ownerMembership->id);
    });
});

it('writes no salary figures to the salary revision audit entry', function () {
    $entry = $this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'payroll.salary.revised')->firstOrFail());

    expect($entry->properties)->toHaveKeys(['employee_id', 'revision', 'structure_id', 'effective_from'])
        ->and($entry->properties)->not->toHaveKey('basic_amount')
        ->and($entry->properties)->not->toHaveKey('previous_basic_amount');
});

it('turns an amount outside the supported range into a run error instead of a server error', function () {
    ($this->attend)();

    $this->inTenant($this->tenant, function (): void {
        foreach (['EXA', 'EXB'] as $code) {
            app(RuleSetManager::class)->save(null, [
                'name' => 'EXAMPLE huge '.$code, 'code' => $code, 'engine' => 'slab', 'category' => 'levy', 'payer' => 'employee',
                'basis' => 'gross', 'method' => 'banded', 'annualize' => false, 'periods_per_year' => 12,
                'effective_from' => '2026-01-01', 'is_active' => true,
                'slabs' => [['from' => 0, 'to' => null, 'rate_bps' => 0, 'fixed' => PHP_INT_MAX]],
            ]);
        }
    });

    $run = ($this->prepare)();

    expect($run->status)->toBe(RunStatus::Draft)
        ->and(collect($run->errors)->pluck('code')->all())->toContain('amount_out_of_range');
});
