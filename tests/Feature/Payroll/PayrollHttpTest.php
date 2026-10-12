<?php

declare(strict_types=1);

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Payroll\Actions\ItemManager;
use App\Modules\Payroll\Actions\LoanManager;
use App\Modules\Payroll\Actions\PayrollSettings;
use App\Modules\Payroll\Actions\RuleSetManager;
use App\Modules\Payroll\Actions\RunManager;
use App\Modules\Payroll\Actions\SalaryManager;
use App\Modules\Payroll\Enums\PaymentStatus;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Loan;
use App\Modules\Payroll\Models\Payment;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\RuleSet;
use App\Modules\Payroll\Models\Run;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPayroll;

uses(InteractsWithPayroll::class);

/*
 * One workspace with two employees who can sign in (Amina and Bashir), each with a salary, and one
 * approved run for 2026-03-01 .. 2026-03-07. Amina's account number is stored; Bashir's name starts
 * with a spreadsheet formula character on purpose.
 */
beforeEach(function () {
    $this->tenant = $this->payrollTenant('pay-http');
    $this->hrmShift($this->tenant);
    $this->hrmAt('2026-03-09 10:00:00');

    $self = ['payroll.payslips.view'];
    [$this->aminaUser, $this->amina, $this->aminaMembership] = $this->hrmStaff($this->tenant, $self, ['payroll.payslips.view' => 'own'], ['first_name' => 'Amina', 'last_name' => 'Khan', 'joined_on' => '2026-03-01']);
    [$this->bashirUser, $this->bashir, $this->bashirMembership] = $this->hrmStaff($this->tenant, $self, ['payroll.payslips.view' => 'own'], ['first_name' => '=Bashir', 'last_name' => 'Rahman', 'joined_on' => '2026-03-01']);

    $this->structure = $this->payStructure($this->tenant, [['name' => 'Transport', 'code' => 'TRAVEL', 'calc' => 'fixed', 'amount' => 2000]]);
    $this->paySalary($this->tenant, $this->amina, $this->structure, 50_000);
    $this->paySalary($this->tenant, $this->bashir, $this->structure, 60_000);
    $this->period = $this->payPeriod($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        app(PayrollSettings::class)->update(['deduct_absence' => false]);
        $profile = Profile::query()->where('employee_id', $this->amina->id)->firstOrFail();
        app(SalaryManager::class)->updateAccount($profile, [
            'payment_method' => 'bank', 'account_holder' => 'Amina Khan', 'bank_name' => 'Example Bank', 'account_number' => '0012345678901',
        ]);
    });

    $this->closeDays($this->tenant, $this->amina, '2026-03-07');
    $this->closeDays($this->tenant, $this->bashir, '2026-03-07');

    $this->ownerMembership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    [$this->approverUser, $this->approver] = $this->hrmMember($this->tenant, [
        'payroll.runs.view', 'payroll.runs.approve', 'payroll.payments.record', 'payroll.payments.export', 'payroll.reports.view',
    ]);
    [$this->preparerUser, $this->preparer] = $this->hrmMember($this->tenant, ['payroll.runs.view', 'payroll.runs.prepare', 'payroll.periods.view']);

    $this->approved = function (): Run {
        return $this->inTenant($this->tenant, function (): Run {
            $run = app(RunManager::class)->prepare($this->period, $this->ownerMembership);
            $run = app(RunManager::class)->submit($run, $this->ownerMembership);

            return app(RunManager::class)->approve($run, $this->approver);
        });
    };
});

it('answers 404 on every payroll page when the workspace is not entitled to the app', function () {
    $plain = $this->hrmTenant('pay-plain');
    $this->actingAsOwner($plain);

    foreach (['/payslips', '/runs', '/periods', '/salaries', '/items', '/loans', '/rules', '/structures', '/reports', '/settings'] as $path) {
        $this->get($this->payUrl($plain, $path))->assertNotFound();
    }
});

it('requires the permission of each area and nothing is granted by owning an employee record', function () {
    $this->actingAsMember($this->tenant, $this->aminaUser);

    foreach (['/runs', '/periods', '/salaries', '/items', '/loans', '/rules', '/structures', '/reports', '/settings'] as $path) {
        $this->get($this->payUrl($this->tenant, $path))->assertForbidden();
    }

    $this->get($this->payUrl($this->tenant, '/payslips'))->assertOk();
    $this->post($this->payUrl($this->tenant, '/periods'), ['kind' => 'monthly', 'month' => '2026-04'])->assertForbidden();
});

it('shows an employee exactly their own payslips and nobody else', function () {
    $run = ($this->approved)();
    [$aminaSlip, $bashirSlip] = $this->inTenant($this->tenant, fn () => [
        Payslip::query()->where('run_id', $run->id)->where('employee_id', $this->amina->id)->sole(),
        Payslip::query()->where('run_id', $run->id)->where('employee_id', $this->bashir->id)->sole(),
    ]);

    $this->actingAsMember($this->tenant, $this->aminaUser);
    $this->get($this->payUrl($this->tenant, '/payslips'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Payroll/Pages/Payslips/Index')
            ->has('payslips.data', 1)
            ->where('payslips.data.0.employeeName', 'Amina Khan')
            ->where('payslips.data.0.netAmount', 52_000)
            ->where('payslips.data.0.number', 'PSL-000002'));

    $this->get($this->payUrl($this->tenant, "/payslips/{$aminaSlip->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Payroll/Pages/Payslips/Show')
            ->where('payslip.netAmount', 52_000)
            ->where('abilities.recordPayment', false)
            ->has('lines', 2));

    $document = $this->get($this->payUrl($this->tenant, "/payslips/{$aminaSlip->id}/document?download=1"));
    $document->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('Amina Khan')
        ->assertSee('PSL-000002')
        ->assertDontSee('Bashir');
    expect($document->headers->get('Content-Disposition'))->toContain('attachment; filename="payslip-PSL-000002.html"')
        ->and($document->headers->get('Cache-Control'))->toContain('no-store');

    // Another employee's payslip does not exist for her, in any form.
    $this->get($this->payUrl($this->tenant, "/payslips/{$bashirSlip->id}"))->assertNotFound();
    $this->get($this->payUrl($this->tenant, "/payslips/{$bashirSlip->id}/document"))->assertNotFound();
});

it('keeps a draft payslip away from employees until the run is approved', function () {
    $slip = $this->inTenant($this->tenant, function (): Payslip {
        $run = app(RunManager::class)->prepare($this->period, $this->ownerMembership);

        return Payslip::query()->where('run_id', $run->id)->where('employee_id', $this->amina->id)->sole();
    });

    $this->actingAsMember($this->tenant, $this->aminaUser);
    $this->get($this->payUrl($this->tenant, '/payslips'))->assertInertia(fn (AssertableInertia $page) => $page->has('payslips.data', 0));
    $this->get($this->payUrl($this->tenant, "/payslips/{$slip->id}"))->assertNotFound();
    $this->get($this->payUrl($this->tenant, "/payslips/{$slip->id}/document"))->assertNotFound();
});

it('hides salaries behind the scoped salary permission', function () {
    [$hrUser] = $this->hrmMember($this->tenant, ['payroll.salaries.view'], ['payroll.salaries.view' => 'own']);
    $profiles = $this->inTenant($this->tenant, fn () => Profile::query()->get()->keyBy('employee_id'));

    // Amina holds salaries.view with the own scope: her profile only.
    [$staffUser] = $this->hrmStaff($this->tenant, ['payroll.salaries.view'], ['payroll.salaries.view' => 'own'], ['first_name' => 'Chitra', 'joined_on' => '2026-03-01']);
    $this->actingAsMember($this->tenant, $hrUser);
    $this->get($this->payUrl($this->tenant, '/salaries'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('profiles.data', 0));

    $this->actingAsOwner($this->tenant);
    $this->get($this->payUrl($this->tenant, '/salaries'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Payroll/Pages/Salaries/Index')
            ->has('profiles.data', 2)
            ->where('canManage', true));
    $this->get($this->payUrl($this->tenant, "/salaries/{$profiles[$this->amina->id]->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('account.accountNumberMasked', '*********8901')
            ->where('account.hasAccountNumber', true)
            ->has('assignments', 1));

    // The scoped member cannot open a profile outside their scope.
    $this->actingAsMember($this->tenant, $hrUser);
    $this->get($this->payUrl($this->tenant, "/salaries/{$profiles[$this->amina->id]->id}"))->assertNotFound();
    expect($staffUser)->not->toBeNull();
});

it('enforces four-eyes approval over HTTP and refuses a self-approval', function () {
    $this->actingAsMember($this->tenant, $this->preparerUser);
    $this->post($this->payUrl($this->tenant, "/periods/{$this->period->id}/runs"))->assertRedirect();
    $run = $this->inTenant($this->tenant, fn () => Run::query()->sole());

    $this->get($this->payUrl($this->tenant, "/runs/{$run->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Payroll/Pages/Runs/Show')
            ->where('run.status', 'draft')
            ->where('run.employeeCount', 2)
            ->where('abilities.submit', true)
            ->where('abilities.approve', false)
            ->has('payslips.data', 2));

    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/submit"))->assertRedirect();
    // The preparer's permission set cannot approve at all.
    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/approve"))->assertForbidden();

    // A person who holds both permissions still cannot approve what they prepared.
    [$bothUser] = $this->hrmMember($this->tenant, ['payroll.runs.view', 'payroll.runs.prepare', 'payroll.runs.approve']);
    $this->actingAsMember($this->tenant, $bothUser);
    $this->inTenant($this->tenant, fn () => app(RunManager::class)->sendBack($run->fresh(), $this->approver, 'Back'));
    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/recalculate"))->assertRedirect();
    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/submit"))->assertRedirect();
    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/approve"))->assertSessionHasErrors('run');
    expect($this->inTenant($this->tenant, fn () => Run::query()->sole()->status->value))->toBe('submitted');

    $this->actingAsMember($this->tenant, $this->approverUser);
    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/approve"))->assertRedirect();
    expect($this->inTenant($this->tenant, fn () => Run::query()->sole()->status->value))->toBe('approved');
});

it('records payments over HTTP exactly once per idempotency key', function () {
    $run = ($this->approved)();
    $slip = $this->inTenant($this->tenant, fn () => Payslip::query()->where('run_id', $run->id)->where('employee_id', $this->amina->id)->sole());
    $payload = ['status' => 'paid', 'method' => 'bank', 'reference' => 'TRX-9', 'paid_on' => '2026-03-09', 'idempotency_key' => 'idem-key-0001'];

    $this->actingAsMember($this->tenant, $this->approverUser);
    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), $payload)->assertRedirect();
    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), $payload)->assertRedirect();

    $this->inTenant($this->tenant, function () use ($slip): void {
        expect(Payment::query()->count())->toBe(1)
            ->and($slip->fresh()->payment_status)->toBe(PaymentStatus::Paid)
            ->and($slip->fresh()->payment_reference)->toBe('TRX-9');
    });

    // A paid payslip cannot be marked failed afterwards, and validation rejects a missing method.
    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), ['status' => 'failed', 'idempotency_key' => 'idem-key-0002'])->assertSessionHasErrors('payslip');
    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), ['status' => 'paid', 'idempotency_key' => 'idem-key-0003'])->assertSessionHasErrors(['method', 'paid_on']);

    // Without the permission the route is closed.
    $this->actingAsMember($this->tenant, $this->preparerUser);
    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), $payload)->assertForbidden();
});

it('refuses payment and the payment sheet once an approved reversal has cancelled the run', function () {
    $run = ($this->approved)();
    $reversal = $this->inTenant($this->tenant, fn () => app(RunManager::class)->reverse($run, $this->ownerMembership, 'Wrong data'));
    $slip = $this->inTenant($this->tenant, fn () => Payslip::query()->where('run_id', $run->id)->where('employee_id', $this->amina->id)->sole());
    $this->actingAsMember($this->tenant, $this->approverUser);

    // While the reversal is only pending the run is still payable.
    $this->get($this->payUrl($this->tenant, "/runs/{$run->id}"))->assertInertia(fn (AssertableInertia $page) => $page->where('abilities.recordPayments', true));

    $this->inTenant($this->tenant, fn () => app(RunManager::class)->approve($reversal, $this->approver));
    $reversalSlip = $this->inTenant($this->tenant, fn () => Payslip::query()->where('run_id', $reversal->id)->where('employee_id', $this->amina->id)->sole());

    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), ['status' => 'paid', 'method' => 'cash', 'paid_on' => '2026-03-09', 'idempotency_key' => 'idem-rev-0001'])->assertSessionHasErrors('payslip');
    $this->post($this->payUrl($this->tenant, "/payslips/{$reversalSlip->id}/payment"), ['status' => 'paid', 'method' => 'cash', 'paid_on' => '2026-03-09', 'idempotency_key' => 'idem-rev-0002'])->assertSessionHasErrors('payslip');
    $this->post($this->payUrl($this->tenant, "/runs/{$run->id}/payments"), ['status' => 'paid', 'method' => 'cash', 'paid_on' => '2026-03-09', 'idempotency_key' => 'idem-rev-0003'])->assertSessionHasErrors('run');
    $this->get($this->payUrl($this->tenant, "/runs/{$run->id}/payment-sheet"))->assertSessionHasErrors('run');

    $this->get($this->payUrl($this->tenant, "/runs/{$run->id}"))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('abilities.recordPayments', false)->where('abilities.exportPaymentSheet', false));

    $this->inTenant($this->tenant, function () use ($slip): void {
        expect(Payment::query()->count())->toBe(0)->and($slip->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
    });
});

it('exports csv that cannot run formulas and a payment sheet with the stored account', function () {
    $run = ($this->approved)();
    $this->actingAsMember($this->tenant, $this->approverUser);

    $register = $this->get($this->payUrl($this->tenant, '/reports/export?report=register&from=2026-01-01&to=2026-12-31'));
    $register->assertOk();
    expect($register->headers->get('Content-Type'))->toContain('text/csv')
        ->and($register->headers->get('Content-Disposition'))->toContain('attachment');

    $body = $register->getContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        // The employee named "=Bashir Rahman" is written as text, never as a formula.
        ->and($body)->toContain("'=Bashir Rahman")
        ->and($body)->not->toContain('"=Bashir')
        ->and($body)->not->toContain(',=Bashir')
        ->and($body)->toContain('Amina Khan')
        ->and($body)->toContain('520.00');

    $sheet = $this->get($this->payUrl($this->tenant, "/runs/{$run->id}/payment-sheet"));
    $sheet->assertOk();
    $csv = $sheet->getContent();

    expect($csv)->toContain('0012345678901')->and($csv)->toContain('Example Bank')->and($csv)->toContain('520.00');

    // The paid ones leave the sheet.
    $slip = $this->inTenant($this->tenant, fn () => Payslip::query()->where('run_id', $run->id)->where('employee_id', $this->amina->id)->sole());
    $this->post($this->payUrl($this->tenant, "/payslips/{$slip->id}/payment"), ['status' => 'paid', 'method' => 'cash', 'paid_on' => '2026-03-09', 'idempotency_key' => 'idem-key-9999'])->assertRedirect();
    expect($this->get($this->payUrl($this->tenant, "/runs/{$run->id}/payment-sheet"))->getContent())->not->toContain('0012345678901');

    // Reports and the sheet are workspace-wide permissions.
    $this->actingAsMember($this->tenant, $this->aminaUser);
    $this->get($this->payUrl($this->tenant, '/reports/export'))->assertForbidden();
    $this->get($this->payUrl($this->tenant, "/runs/{$run->id}/payment-sheet"))->assertForbidden();
});

it('returns 404 for every payroll record addressed from another workspace', function () {
    $run = ($this->approved)();

    $ids = $this->inTenant($this->tenant, function () use ($run): array {
        $snapshot = app(EmployeeDirectory::class)->find($this->amina->id);
        $item = app(ItemManager::class)->create($snapshot, $this->period, ['type' => 'bonus', 'name' => 'Bonus', 'amount' => 100]);
        $loan = app(LoanManager::class)->request($snapshot, ['kind' => 'loan', 'title' => 'L', 'principal_amount' => 1000, 'installment_count' => 2, 'first_due_on' => '2026-04-01']);
        $rule = app(RuleSetManager::class)->save(null, [
            'name' => 'EXAMPLE', 'code' => 'EX1', 'engine' => 'slab', 'category' => 'levy', 'payer' => 'employee', 'basis' => 'gross',
            'method' => 'banded', 'annualize' => false, 'periods_per_year' => 12, 'effective_from' => '2026-01-01', 'is_active' => true,
            'slabs' => [['from' => 0, 'to' => null, 'rate_bps' => 100]],
        ]);

        return [
            'payslip' => Payslip::query()->where('run_id', $run->id)->firstOrFail()->id,
            'profile' => Profile::query()->firstOrFail()->id,
            'item' => $item->id,
            'loan' => $loan->id,
            'rule' => $rule->id,
        ];
    });
    $intruder = $this->createTenant('pay-intruder');
    $this->entitle($intruder, 'hrm', 'payroll');

    $this->assertNotFoundFromOtherTenant('GET', "/payroll/runs/{$run->id}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/periods/{$this->period->id}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/payslips/{$ids['payslip']}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/payslips/{$ids['payslip']}/document", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/structures/{$this->structure->id}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/salaries/{$ids['profile']}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/loans/{$ids['loan']}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/rules/{$ids['rule']}", $this->tenant, intruder: $intruder);
    $this->assertNotFoundFromOtherTenant('PUT', "/payroll/items/{$ids['item']}", $this->tenant, ['type' => 'bonus', 'name' => 'X', 'amount' => 5], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/payroll/loans/{$ids['loan']}/cancel", $this->tenant, [], $intruder);
    $this->assertNotFoundFromOtherTenant('POST', "/payroll/payslips/{$ids['payslip']}/payment", $this->tenant, [
        'status' => 'failed', 'idempotency_key' => 'idem-isolation-1',
    ], $intruder);
    $this->assertNotFoundFromOtherTenant('GET', "/payroll/runs/{$run->id}/payment-sheet", $this->tenant, intruder: $intruder);
});

it('creates periods, items, loans and rule sets through validated forms', function () {
    $this->actingAsOwner($this->tenant);

    $this->post($this->payUrl($this->tenant, '/periods'), ['kind' => 'monthly', 'month' => '2026-04'])->assertRedirect();
    $this->post($this->payUrl($this->tenant, '/periods'), ['kind' => 'custom', 'starts_on' => '2026-04-10', 'ends_on' => '2026-04-20'])->assertSessionHasErrors('starts_on');

    $this->post($this->payUrl($this->tenant, '/items'), [
        'employee_id' => $this->amina->id, 'period_id' => $this->period->id, 'type' => 'festival_bonus', 'name' => 'Eid', 'basic_bps' => 10_000,
    ])->assertRedirect();
    $this->post($this->payUrl($this->tenant, '/items'), [
        'employee_id' => $this->amina->id, 'period_id' => $this->period->id, 'type' => 'bonus', 'name' => 'Both', 'amount' => 5, 'basic_bps' => 5,
    ])->assertSessionHasErrors('amount');
    $this->post($this->payUrl($this->tenant, '/items'), [
        'employee_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'period_id' => $this->period->id, 'type' => 'bonus', 'name' => 'Ghost', 'amount' => 5,
    ])->assertSessionHasErrors('employee_id');

    $this->post($this->payUrl($this->tenant, '/loans'), [
        'employee_id' => $this->bashir->id, 'kind' => 'loan', 'title' => 'Bike', 'principal_amount' => 90_000, 'installment_count' => 3, 'first_due_on' => '2026-04-05',
    ])->assertRedirect();

    $this->post($this->payUrl($this->tenant, '/rules'), [
        'name' => 'EXAMPLE levy', 'code' => 'EXLEVY', 'engine' => 'slab', 'category' => 'levy', 'payer' => 'employer', 'basis' => 'basic',
        'method' => 'banded', 'annualize' => false, 'periods_per_year' => 12, 'effective_from' => '2026-01-01', 'is_active' => true,
        'slabs' => [['from' => 0, 'to' => 1000, 'rate_bps' => 0], ['from' => 1000, 'to' => null, 'rate_bps' => 250]],
    ])->assertRedirect();
    // A gap between slabs is refused.
    $this->post($this->payUrl($this->tenant, '/rules'), [
        'name' => 'EXAMPLE bad', 'code' => 'EXBAD', 'engine' => 'slab', 'category' => 'levy', 'payer' => 'employee', 'basis' => 'gross',
        'method' => 'banded', 'annualize' => false, 'periods_per_year' => 12, 'effective_from' => '2026-01-01', 'is_active' => true,
        'slabs' => [['from' => 0, 'to' => 1000, 'rate_bps' => 0], ['from' => 2000, 'to' => null, 'rate_bps' => 250]],
    ])->assertSessionHasErrors('slabs');

    $this->inTenant($this->tenant, function (): void {
        expect(Item::query()->count())->toBe(1)->and(Item::query()->sole()->basic_bps)->toBe(10_000)
            ->and(Loan::query()->sole()->installments)->toHaveCount(3)
            ->and(RuleSet::query()->count())->toBe(1)->and(RuleSet::query()->sole()->slabs)->toHaveCount(2);
    });
});

it('refuses rule set amounts beyond the supported range', function () {
    $this->actingAsOwner($this->tenant);
    $base = [
        'name' => 'EXAMPLE big', 'code' => 'EXBIG', 'engine' => 'slab', 'category' => 'levy', 'payer' => 'employee', 'basis' => 'gross',
        'method' => 'banded', 'annualize' => false, 'periods_per_year' => 12, 'effective_from' => '2026-01-01', 'is_active' => true,
    ];

    $this->post($this->payUrl($this->tenant, '/rules'), [...$base, 'slabs' => [['from' => 0, 'to' => null, 'rate_bps' => 0, 'fixed' => 1_000_000_000_001]]])
        ->assertSessionHasErrors('slabs.0.fixed');
    $this->post($this->payUrl($this->tenant, '/rules'), [...$base, 'min_amount' => 1_000_000_000_001, 'max_amount' => 9_000_000_000_000_000, 'slabs' => [['from' => 0, 'to' => null, 'rate_bps' => 0]]])
        ->assertSessionHasErrors(['min_amount', 'max_amount']);
});
