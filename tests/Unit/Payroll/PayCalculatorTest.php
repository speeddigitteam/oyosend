<?php

declare(strict_types=1);

use App\Modules\Payroll\Calculation\CalcSettings;
use App\Modules\Payroll\Calculation\EmployeeInput;
use App\Modules\Payroll\Calculation\PayCalculator;
use App\Modules\Payroll\Calculation\PayslipDraft;
use App\Modules\Payroll\Calculation\RuleDefinition;
use App\Modules\Payroll\Calculation\RuleSetEngines;

/**
 * Pure arithmetic: no database, no HRM. The period is 2026-03-01 .. 2026-03-07 (7 calendar days)
 * unless a test says otherwise; a basic of 70000 makes a day worth exactly 10000.
 */
$calc = static fn (): PayCalculator => new PayCalculator(new RuleSetEngines);

$attendance = static fn (array $overrides = []): array => [
    'from' => '2026-03-01', 'to' => '2026-03-07', 'days_present' => 5, 'days_half' => 0, 'days_absent' => 0,
    'days_on_leave' => 0, 'days_holiday' => 0, 'days_weekly_off' => 2, 'days_incomplete' => 0, 'open_days' => 0,
    'scheduled_minutes' => 2400, 'worked_minutes' => 2400, 'late_minutes' => 0, 'early_leave_minutes' => 0,
    'overtime_minutes' => 0, ...$overrides,
];

$input = static fn (array $overrides = []): EmployeeInput => new EmployeeInput(...[
    'employeeId' => 'emp-1', 'number' => 'EMP-0001', 'name' => 'Rafi Ahmed', 'employmentType' => 'full_time',
    'joinedOn' => '2026-01-05', 'lastWorkingOn' => null, 'basicAmount' => 70_000, 'currency' => 'BDT',
    'assignmentId' => 'asg-1', 'structureName' => 'Standard', 'components' => [], 'attendance' => [], 'leave' => [],
    'items' => [], 'installments' => [], 'rules' => [], ...$overrides,
]);

$line = static fn (PayslipDraft $draft, string $code): ?array => collect($draft->lines)->firstWhere('code', $code);

it('pays the basic salary of a full period', function () use ($calc, $input) {
    $draft = $calc()->calculate($input(), '2026-03-01', '2026-03-07', new CalcSettings);

    expect($draft->basicAmount)->toBe(70_000)->and($draft->grossAmount)->toBe(70_000)
        ->and($draft->deductionAmount)->toBe(0)->and($draft->netAmount)->toBe(70_000)
        ->and($draft->payableDays)->toBe(7)->and($draft->periodDays)->toBe(7)->and($draft->error)->toBeNull();
});

it('prorates a joiner and a leaver by calendar days and takes percentages from the prorated basic', function () use ($calc, $input) {
    $components = [
        ['id' => 'c1', 'code' => 'RENT', 'name' => 'House rent', 'kind' => 'earning', 'calc' => 'percent_basic', 'amount' => null, 'rate_bps' => 4000, 'taxable' => true],
        ['id' => 'c2', 'code' => 'TRAVEL', 'name' => 'Travel', 'kind' => 'earning', 'calc' => 'fixed', 'amount' => 3_500, 'rate_bps' => null, 'taxable' => true],
    ];
    // Joined on the 4th: 4 of 7 days.
    $joiner = $calc()->calculate($input(['joinedOn' => '2026-03-04', 'components' => $components]), '2026-03-01', '2026-03-07', new CalcSettings);

    // basic 70000 x 4 / 7 = 40000; rent 40 % of 40000 = 16000; travel 3500 x 4 / 7 = 2000.
    expect($joiner->payableDays)->toBe(4)->and($joiner->basicAmount)->toBe(40_000)
        ->and($joiner->grossAmount)->toBe(40_000 + 16_000 + 2_000);

    // Last working day the 2nd: 2 of 7 days; 70000 x 2 / 7 = 20000; 3500 x 2 / 7 = 1000.
    $leaver = $calc()->calculate($input(['lastWorkingOn' => '2026-03-02', 'components' => $components]), '2026-03-01', '2026-03-07', new CalcSettings);
    expect($leaver->payableDays)->toBe(2)->and($leaver->basicAmount)->toBe(20_000)->and($leaver->grossAmount)->toBe(20_000 + 8_000 + 1_000);

    // Not employed at all in the period.
    $none = $calc()->calculate($input(['joinedOn' => '2026-04-01']), '2026-03-01', '2026-03-07', new CalcSettings);
    expect($none->payableDays)->toBe(0);
});

it('deducts unpaid leave and absence by units from the day rate, half-up', function () use ($calc, $input, $attendance, $line) {
    $components = [
        ['id' => 'c1', 'code' => 'RENT', 'name' => 'House rent', 'kind' => 'earning', 'calc' => 'percent_basic', 'amount' => null, 'rate_bps' => 4000, 'taxable' => true],
        ['id' => 'c2', 'code' => 'TRAVEL', 'name' => 'Travel', 'kind' => 'earning', 'calc' => 'fixed', 'amount' => 5_000, 'rate_bps' => null, 'taxable' => true],
    ];
    $draft = $calc()->calculate($input([
        'components' => $components,
        'attendance' => $attendance(['days_absent' => 1, 'days_half' => 1]),
        'leave' => [
            ['leave_type_id' => 'l1', 'code' => 'AL', 'name' => 'Annual', 'is_paid' => true, 'units' => 4],
            ['leave_type_id' => 'l2', 'code' => 'UL', 'name' => 'Unpaid', 'is_paid' => false, 'units' => 2],
        ],
    ]), '2026-03-01', '2026-03-07', new CalcSettings);

    // Gross rate base 70000 + 28000 + 5000 = 103000 per 7 days.
    // Unpaid leave: 2 units  -> 103000 x 2 / 14 = 14714.28 -> 14714 (paid leave is never deducted).
    // Absence: 1 absent day (2 units) + 1 half day (1 unit) = 3 units -> 103000 x 3 / 14 = 22071.43 -> 22071.
    expect($line($draft, 'unpaid_leave')['amount'])->toBe(14_714)
        ->and($line($draft, 'absence')['amount'])->toBe(22_071)
        ->and($draft->deductionAmount)->toBe(14_714 + 22_071)
        ->and($draft->grossAmount)->toBe(103_000)
        ->and($draft->netAmount)->toBe(103_000 - 36_785);

    // The same on a basic-only rate base: 70000 x 2 / 14 = 10000.
    $basicOnly = $calc()->calculate($input([
        'components' => $components,
        'leave' => [['leave_type_id' => 'l2', 'code' => 'UL', 'name' => 'Unpaid', 'is_paid' => false, 'units' => 2]],
    ]), '2026-03-01', '2026-03-07', new CalcSettings(dailyRateBase: 'basic'));
    expect($line($basicOnly, 'unpaid_leave')['amount'])->toBe(10_000);

    // A fixed 30-day divisor: 103000 x 2 / (2 x 30) = 3433.33 -> 3433.
    $thirty = $calc()->calculate($input([
        'components' => $components,
        'leave' => [['leave_type_id' => 'l2', 'code' => 'UL', 'name' => 'Unpaid', 'is_paid' => false, 'units' => 2]],
    ]), '2026-03-01', '2026-03-07', new CalcSettings(daysBasis: 'fixed_days', fixedDays: 30));
    expect($line($thirty, 'unpaid_leave')['amount'])->toBe(3_433);
});

it('does not deduct more than the recurring earnings', function () use ($calc, $input, $attendance, $line) {
    $draft = $calc()->calculate($input([
        'attendance' => $attendance(['days_absent' => 9]),
        'leave' => [['leave_type_id' => 'l2', 'code' => 'UL', 'name' => 'Unpaid', 'is_paid' => false, 'units' => 10]],
    ]), '2026-03-01', '2026-03-07', new CalcSettings);

    // 10 units = 5 days = 50000, then 18 units of absence would be 90000: only 20000 is left.
    expect($line($draft, 'unpaid_leave')['amount'])->toBe(50_000)
        ->and($line($draft, 'absence')['amount'])->toBe(20_000)
        ->and($draft->netAmount)->toBe(0)->and($draft->error)->toBeNull();
});

it('pays overtime from minutes with the configured multiplier', function () use ($calc, $input, $attendance, $line) {
    $draft = $calc()->calculate($input(['attendance' => $attendance(['overtime_minutes' => 90])]), '2026-03-01', '2026-03-07', new CalcSettings);

    // 70000 x 90 x 1.5 / (7 days x 480 minutes) = 2812.5 -> 2813 (half-up).
    expect($line($draft, 'overtime')['amount'])->toBe(2_813)->and($draft->grossAmount)->toBe(72_813);

    $double = $calc()->calculate($input(['attendance' => $attendance(['overtime_minutes' => 90])]), '2026-03-01', '2026-03-07', new CalcSettings(overtimeMultiplierBps: 20_000));
    expect($line($double, 'overtime')['amount'])->toBe(3_750);

    $capped = $calc()->calculate($input(['attendance' => $attendance(['overtime_minutes' => 90])]), '2026-03-01', '2026-03-07', new CalcSettings(maxOvertimeMinutes: 60));
    expect($line($capped, 'overtime')['amount'])->toBe(1_875)
        ->and(collect($capped->warnings)->pluck('code')->all())->toContain('overtime_capped');
});

it('adds one-off items, festival bonus as a share of basic, reimbursements outside gross and deductions', function () use ($calc, $input, $line) {
    $draft = $calc()->calculate($input(['items' => [
        ['id' => 'i1', 'type' => 'festival_bonus', 'name' => 'Eid bonus', 'amount' => null, 'basic_bps' => 5000, 'taxable' => true],
        ['id' => 'i2', 'type' => 'allowance', 'name' => 'Phone', 'amount' => 1_000, 'basic_bps' => null, 'taxable' => false],
        ['id' => 'i3', 'type' => 'reimbursement', 'name' => 'Taxi', 'amount' => 2_500, 'basic_bps' => null, 'taxable' => false],
        ['id' => 'i4', 'type' => 'deduction', 'name' => 'Lost badge', 'amount' => 500, 'basic_bps' => null, 'taxable' => false],
    ]]), '2026-03-01', '2026-03-07', new CalcSettings);

    // Festival bonus 50 % of 70000 = 35000. Gross = 70000 + 35000 + 1000. Net = gross + 2500 - 500.
    expect($line($draft, 'festival_bonus')['amount'])->toBe(35_000)
        ->and($draft->grossAmount)->toBe(106_000)
        ->and($draft->reimbursementAmount)->toBe(2_500)
        ->and($draft->deductionAmount)->toBe(500)
        ->and($draft->netAmount)->toBe(106_000 + 2_500 - 500)
        // Taxable: basic + festival bonus; the allowance is flagged non-taxable.
        ->and($draft->taxableAmount)->toBe(105_000)
        ->and($draft->itemIds)->toBe(['i1', 'i2', 'i3', 'i4']);
});

it('evaluates rule sets on the chosen basis and keeps employer contributions out of net pay', function () use ($calc, $input, $line) {
    $slabs = [
        ['position' => 1, 'from' => 0, 'to' => 50_000, 'rate_bps' => 0, 'fixed' => 0],
        ['position' => 2, 'from' => 50_000, 'to' => null, 'rate_bps' => 1000, 'fixed' => 0],
    ];
    $tax = new RuleDefinition('r1', 'EXAMPLE tax', 'TAX', 'slab', 'income_tax', 'employee', 'taxable_gross', 'progressive', false, 12, null, null, $slabs);
    $employer = new RuleDefinition('r2', 'EXAMPLE employer fund', 'ERFUND', 'slab', 'social_contribution', 'employer', 'basic', 'banded', false, 12, null, null, [
        ['position' => 1, 'from' => 0, 'to' => null, 'rate_bps' => 500, 'fixed' => 0],
    ]);
    $contractors = new RuleDefinition('r3', 'EXAMPLE contractor levy', 'LEVY', 'slab', 'levy', 'employee', 'gross', 'banded', false, 12, null, null, [
        ['position' => 1, 'from' => 0, 'to' => null, 'rate_bps' => 1000, 'fixed' => 0],
    ], employmentTypes: ['contract']);

    $draft = $calc()->calculate($input(['rules' => [$tax, $employer, $contractors]]), '2026-03-01', '2026-03-07', new CalcSettings);

    // Tax: (70000 - 50000) x 10 % = 2000. Employer: 70000 x 5 % = 3500. The contractor levy does not apply.
    expect($line($draft, 'TAX')['amount'])->toBe(2_000)
        ->and($line($draft, 'ERFUND')['kind'])->toBe('employer')
        ->and($draft->employerAmount)->toBe(3_500)
        ->and($line($draft, 'LEVY'))->toBeNull()
        ->and($draft->netAmount)->toBe(68_000);
});

it('flags a negative net and refuses to deduct loan installments that do not fit', function () use ($calc, $input) {
    $negative = $calc()->calculate($input(['items' => [
        ['id' => 'i1', 'type' => 'deduction', 'name' => 'Recovery', 'amount' => 80_000, 'basic_bps' => null, 'taxable' => false],
    ]]), '2026-03-01', '2026-03-07', new CalcSettings);
    expect($negative->error)->toBe('negative_net');

    $installments = [
        ['id' => 'n2', 'loan_id' => 'l1', 'label' => 'Advance (2)', 'sequence' => 2, 'due_on' => '2026-03-05', 'amount' => 40_000],
        ['id' => 'n1', 'loan_id' => 'l1', 'label' => 'Advance (1)', 'sequence' => 1, 'due_on' => '2026-02-05', 'amount' => 40_000],
    ];
    $draft = $calc()->calculate($input(['installments' => $installments]), '2026-03-01', '2026-03-07', new CalcSettings);

    // Due first goes first: 40000 fits (net 30000 left), the second 40000 does not and is deferred.
    expect($draft->installmentIds)->toBe(['n1'])
        ->and($draft->netAmount)->toBe(30_000)
        ->and(collect($draft->warnings)->firstWhere('code', 'installment_deferred')['installment_id'])->toBe('n2');
});

it('is deterministic: the same input gives the same fingerprint', function () use ($calc, $input, $attendance) {
    $one = $calc()->calculate($input(['attendance' => $attendance(['overtime_minutes' => 30])]), '2026-03-01', '2026-03-07', new CalcSettings);
    $two = $calc()->calculate($input(['attendance' => $attendance(['overtime_minutes' => 30])]), '2026-03-01', '2026-03-07', new CalcSettings);
    $other = $calc()->calculate($input(['attendance' => $attendance(['overtime_minutes' => 31])]), '2026-03-01', '2026-03-07', new CalcSettings);

    expect($one->fingerprint())->toBe($two->fingerprint())->and($one->fingerprint())->not->toBe($other->fingerprint());
});
