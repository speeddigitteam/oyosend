<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Payroll\Actions\RunManager;
use App\Modules\Payroll\Automation\Actions\CreatePayItemAction;
use App\Modules\Payroll\Automation\Actions\PrepareRunAction;
use App\Modules\Payroll\Models\AutomationActionReceipt;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\Profile;
use App\Modules\Payroll\Models\Run;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithPayroll;

uses(InteractsWithPayroll::class);

beforeEach(function () {
    $this->tenant = $this->payrollTenant('pay-auto');
    $this->hrmShift($this->tenant);
    $this->hrmAt('2026-03-09 10:00:00');
    $this->employee = $this->hire($this->tenant, ['first_name' => 'Rafi', 'joined_on' => '2026-03-01']);
    $this->structure = $this->payStructure($this->tenant);
    $this->paySalary($this->tenant, $this->employee, $this->structure, 70_000);
    $this->period = $this->payPeriod($this->tenant);
});

it('registers payroll triggers and actions with the automation registry', function () {
    $registry = app(AutomationRegistry::class);

    expect($registry->trigger('payroll.run.approved'))->not->toBeNull()
        ->and($registry->trigger('payroll.payslip.paid'))->not->toBeNull()
        ->and($registry->trigger('payroll.run.reversed'))->not->toBeNull()
        ->and($registry->action('payroll.item.create'))->not->toBeNull()
        ->and($registry->action('payroll.run.prepare_draft'))->not->toBeNull()
        // Nothing that moves money or approves is exposed as an action.
        ->and($registry->action('payroll.run.approve'))->toBeNull()
        ->and($registry->action('payroll.payment.record'))->toBeNull();
});

it('creates a pending pay item once per idempotency key', function () {
    $this->inTenant($this->tenant, function (): void {
        $action = app(CreatePayItemAction::class);
        $input = ['employee_id' => $this->employee->id, 'period_id' => $this->period->id, 'type' => 'festival_bonus', 'name' => 'Eid bonus', 'amount' => 25_000];

        $first = $action->execute($input, 'run-1:step-1');
        $retry = $action->execute($input, 'run-1:step-1');
        $second = $action->execute($input, 'run-2:step-1');

        expect($retry)->toBe($first)
            ->and($second['item_id'])->not->toBe($first['item_id'])
            ->and(Item::query()->count())->toBe(2)
            ->and(Item::query()->find($first['item_id'])->status->value)->toBe('pending')
            ->and(AutomationActionReceipt::query()->count())->toBe(2)
            ->and(fn () => $action->execute([...$input, 'employee_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'], 'run-3:step-1'))->toThrow(ValidationException::class);
    });
});

it('prepares a draft run once per key and never submits or approves it', function () {
    $this->closeDays($this->tenant, $this->employee, '2026-03-07');

    $this->inTenant($this->tenant, function (): void {
        $action = app(PrepareRunAction::class);
        $first = $action->execute(['period_id' => $this->period->id], 'wf-1:step-1');
        $retry = $action->execute(['period_id' => $this->period->id], 'wf-1:step-1');

        expect($retry)->toBe($first)
            ->and($first['status'])->toBe('draft')
            ->and($first['employee_count'])->toBe(1)
            ->and(Run::query()->sole()->status->value)->toBe('draft')
            ->and(Run::query()->sole()->prepared_by_id)->toBeNull();

        // Whoever approves it later is, by definition, a different person from the (absent) preparer.
        $run = Run::query()->sole();
        $owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
        expect(app(RunManager::class)->submit($run, $owner)->status->value)->toBe('submitted');
    });
});

it('keeps payroll placement and the account link current when HRM announces a change', function () {
    $this->closeDays($this->tenant, $this->employee, '2026-03-07');
    $owner = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    [, $approver] = $this->hrmMember($this->tenant, ['payroll.runs.approve']);

    $slip = $this->inTenant($this->tenant, function () use ($owner, $approver): Payslip {
        $run = app(RunManager::class)->prepare($this->period, $owner);
        $run = app(RunManager::class)->submit($run, $owner);
        app(RunManager::class)->approve($run, $approver);

        return Payslip::query()->where('run_id', $run->id)->sole();
    });
    expect($slip->owner_id)->toBeNull();

    [, $membership] = $this->hrmMember($this->tenant, ['payroll.payslips.view'], ['payroll.payslips.view' => 'own']);
    $this->inTenant($this->tenant, fn () => app(EmployeeManager::class)->update($this->employee, ['membership_id' => $membership->id]));

    $this->inTenant($this->tenant, function () use ($slip, $membership): void {
        // The person can now reach their own payslip; its money columns did not move.
        expect(Payslip::query()->find($slip->id)->owner_id)->toBe($membership->id)
            ->and(Payslip::query()->find($slip->id)->net_amount)->toBe($slip->net_amount)
            ->and(Profile::query()->where('employee_id', $this->employee->id)->sole()->owner_id)->toBe($membership->id);
    });
});
