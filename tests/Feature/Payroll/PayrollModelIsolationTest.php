<?php

declare(strict_types=1);

use App\Modules\Payroll\Enums\ComponentCalc;
use App\Modules\Payroll\Enums\ComponentKind;
use App\Modules\Payroll\Enums\InstallmentStatus;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\ItemType;
use App\Modules\Payroll\Enums\LineKind;
use App\Modules\Payroll\Enums\LoanKind;
use App\Modules\Payroll\Enums\LoanStatus;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Enums\PeriodKind;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RuleBasis;
use App\Modules\Payroll\Enums\RuleCategory;
use App\Modules\Payroll\Enums\RuleMethod;
use App\Modules\Payroll\Enums\RulePayer;
use App\Modules\Payroll\Enums\RunKind;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Models\AutomationActionReceipt;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\LoanInstallment;
use App\Modules\Payroll\Models\NumberSequence;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\PayrollSetting;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\RuleSet;
use App\Modules\Payroll\Models\RuleSlab;
use App\Modules\Payroll\Models\Run;
use App\Modules\Payroll\Models\SalaryAssignment;
use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\StructureComponent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

$employeeId = static fn (): string => (string) Str::ulid();
$structure = static fn (): SalaryStructure => SalaryStructure::query()->create(['name' => 'S'.Str::random(6), 'is_active' => true]);
$profile = static fn (): Profile => Profile::query()->create(['employee_id' => (string) Str::ulid()]);
$period = static fn (): Period => Period::query()->create([
    'name' => 'P'.Str::random(5), 'kind' => PeriodKind::Custom, 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31', 'status' => PeriodStatus::Open,
]);
$run = static fn () => Run::query()->create(['period_id' => $period()->id, 'kind' => RunKind::Regular, 'status' => RunStatus::Draft, 'currency' => 'BDT']);
$payslip = static function () use ($run, $employeeId): Payslip {
    $made = $run();

    return Payslip::query()->create([
        'run_id' => $made->id, 'period_id' => $made->period_id, 'employee_id' => $employeeId(), 'employee_number' => 'EMP-1',
        'employee_name' => 'Iso Test', 'employment_type' => 'full_time', 'joined_on' => '2026-01-01', 'currency' => 'BDT',
        'payment_status' => PaymentStatus::Unpaid,
    ]);
};

it('isolates settings, numbering, structures and rule sets between tenants', function () use ($structure) {
    $this->assertTenantIsolated(PayrollSetting::class, fn () => PayrollSetting::query()->create([]));
    $this->assertTenantIsolated(NumberSequence::class, fn () => NumberSequence::query()->create(['kind' => 'payslip', 'last_number' => 4]));
    $this->assertTenantIsolated(SalaryStructure::class, fn () => $structure());
    $this->assertTenantIsolated(StructureComponent::class, fn () => StructureComponent::query()->create([
        'structure_id' => $structure()->id, 'name' => 'Rent', 'code' => 'RENT', 'kind' => ComponentKind::Earning,
        'calc' => ComponentCalc::Fixed, 'amount' => 100, 'is_taxable' => true, 'is_active' => true,
    ]));
    $this->assertTenantIsolated(RuleSet::class, fn () => RuleSet::query()->create([
        'name' => 'EXAMPLE', 'code' => 'EX'.Str::random(4), 'engine' => 'slab', 'category' => RuleCategory::IncomeTax,
        'payer' => RulePayer::Employee, 'basis' => RuleBasis::TaxableGross, 'method' => RuleMethod::Progressive,
        'effective_from' => '2026-01-01',
    ]));
    $this->assertTenantIsolated(RuleSlab::class, fn () => RuleSlab::query()->create([
        'rule_set_id' => RuleSet::query()->create([
            'name' => 'EXAMPLE', 'code' => 'EX'.Str::random(4), 'engine' => 'slab', 'category' => RuleCategory::IncomeTax,
            'payer' => RulePayer::Employee, 'basis' => RuleBasis::TaxableGross, 'method' => RuleMethod::Progressive,
            'effective_from' => '2026-01-01',
        ])->id,
        'position' => 1, 'from_amount' => 0, 'rate_bps' => 0,
    ]));
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'payroll.item.create', 'idempotency_key' => Str::random(10), 'output' => [],
    ]));
});

it('isolates profiles, salary revisions and pay periods between tenants', function () use ($structure, $profile, $period) {
    $this->assertTenantIsolated(Profile::class, fn () => $profile());
    $this->assertTenantIsolated(SalaryAssignment::class, function () use ($structure, $profile): SalaryAssignment {
        $owner = $profile();

        return SalaryAssignment::query()->create([
            'employee_id' => $owner->employee_id, 'profile_id' => $owner->id, 'structure_id' => $structure()->id,
            'revision' => 1, 'basic_amount' => 1000, 'currency' => 'BDT', 'effective_from' => '2026-01-01',
        ]);
    });
    $this->assertTenantIsolated(Period::class, fn () => $period());
});

it('isolates runs, payslips, lines and payments between tenants', function () use ($run, $payslip) {
    $this->assertTenantIsolated(Run::class, fn () => $run());
    $this->assertTenantIsolated(Payslip::class, fn () => $payslip());
    $this->assertTenantIsolated(PayslipLine::class, fn () => PayslipLine::query()->create([
        'payslip_id' => $payslip()->id, 'kind' => LineKind::Earning, 'code' => 'basic', 'name' => 'Basic salary', 'source' => 'system',
        'amount' => 1, 'is_taxable' => true,
    ]));
    $this->assertTenantIsolated(Payment::class, function () use ($payslip): Payment {
        $slip = $payslip();

        return Payment::query()->create([
            'payslip_id' => $slip->id, 'status' => PaymentStatus::Failed, 'amount' => 0, 'currency' => 'BDT', 'idempotency_key' => Str::random(12),
        ]);
    });
});

it('isolates items, loans and installments between tenants', function () use ($period, $employeeId) {
    $this->assertTenantIsolated(Item::class, fn () => Item::query()->create([
        'employee_id' => $employeeId(), 'period_id' => $period()->id, 'type' => ItemType::Bonus, 'name' => 'Bonus',
        'amount' => 100, 'is_taxable' => true, 'status' => ItemStatus::Pending,
    ]));
    $loan = static fn (): Loan => Loan::query()->create([
        'employee_id' => (string) Str::ulid(), 'kind' => LoanKind::Advance, 'title' => 'Advance', 'principal_amount' => 100,
        'installment_count' => 1, 'first_due_on' => '2026-04-01', 'currency' => 'BDT', 'status' => LoanStatus::Pending,
    ]);
    $this->assertTenantIsolated(Loan::class, fn () => $loan());
    $this->assertTenantIsolated(LoanInstallment::class, function () use ($loan): LoanInstallment {
        $made = $loan();

        return LoanInstallment::query()->create([
            'loan_id' => $made->id, 'employee_id' => $made->employee_id, 'sequence' => 1, 'due_on' => '2026-04-01',
            'amount' => 100, 'status' => InstallmentStatus::Pending,
        ]);
    });
});

it('keeps unique indexes tenant-scoped and one regular run per period', function () use ($period) {
    $tenant = $this->createTenant('pay-unique');

    $this->inTenant($tenant, function () use ($period): void {
        $made = $period();

        Run::query()->create(['period_id' => $made->id, 'kind' => RunKind::Regular, 'status' => RunStatus::Draft, 'regular_marker' => 1, 'currency' => 'BDT']);

        expect(fn () => Run::query()->create(['period_id' => $made->id, 'kind' => RunKind::Regular, 'status' => RunStatus::Draft, 'regular_marker' => 1, 'currency' => 'BDT']))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
