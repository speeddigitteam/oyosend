<?php

declare(strict_types=1);

use App\Core\Organization\Models\Department;
use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Core\Workforce\Contracts\EmployeeTimeSummary;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Hrm\Actions\AttendanceCloser;
use App\Modules\Hrm\Actions\AttendanceManager;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\LeaveAccruals;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Hrm\Automation\Actions\AssignManagerAction;
use App\Modules\Hrm\Automation\Actions\GrantLeaveBalanceAction;
use App\Modules\Hrm\Models\AutomationActionReceipt;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithHrm;

uses(InteractsWithHrm::class);

beforeEach(function () {
    $this->tenant = $this->hrmTenant('hrm-contract');
    $this->hrmAt('2026-03-02 10:00:00');
});

it('exposes employees to other modules as plain snapshots, scoped to the workspace', function () {
    $department = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Ops']));
    $designation = $this->inTenant($this->tenant, fn () => Designation::query()->create(['name' => 'Lead']));
    [, $boss, $membership] = $this->hrmStaff($this->tenant, ['hrm.self.view'], [], [
        'first_name' => 'Boss', 'last_name' => 'Lady', 'department_id' => $department->id, 'designation_id' => $designation->id,
        'cost_rate_amount' => 15000, 'work_email' => 'boss@example.test',
    ]);
    $report = $this->hire($this->tenant, ['first_name' => 'Rep', 'manager_id' => $boss->id]);
    $other = $this->hrmTenant('hrm-contract-other');
    $foreign = $this->hire($other, ['first_name' => 'Foreign']);

    $this->inTenant($this->tenant, function () use ($boss, $report, $membership, $foreign): void {
        $directory = app(EmployeeDirectory::class);
        $snapshot = $directory->find($boss->id);

        expect($snapshot)->toBeInstanceOf(EmployeeSnapshot::class)
            ->and($snapshot->name)->toBe('Boss Lady')
            ->and($snapshot->number)->toBe('EMP-0001')
            ->and($snapshot->membershipId)->toBe($membership->id)
            ->and($snapshot->departmentName)->toBe('Ops')
            ->and($snapshot->designationName)->toBe('Lead')
            ->and($snapshot->costRateAmount)->toBe(15000)
            ->and($snapshot->costCurrency)->toBe('BDT')
            ->and($snapshot->status)->toBe('active')
            ->and($snapshot->toArray())->not->toHaveKeys(['national_id', 'date_of_birth', 'address'])
            ->and($directory->findByMembership($membership->id)->id)->toBe($boss->id)
            ->and($directory->directReports($boss->id))->toHaveCount(1)
            ->and($directory->managerChain($report->id)[0]->id)->toBe($boss->id)
            ->and(array_keys($directory->findMany([$boss->id, $report->id, $foreign->id])))->toEqualCanonicalizing([$boss->id, $report->id])
            ->and($directory->find($foreign->id))->toBeNull()
            ->and($directory->list(['status' => ['active'], 'department_id' => $boss->department_id]))->toHaveCount(1)
            ->and($directory->list(['active_on' => '2025-12-31']))->toHaveCount(0)
            ->and($directory->list(['active_on' => '2026-03-02']))->toHaveCount(2);

        $page = $directory->list([], 1);
        expect($page)->toHaveCount(1)->and($directory->list([], 5, $page[0]->id))->toHaveCount(1);
    });
});

it('summarizes closed attendance and approved leave per employee and period', function () {
    $this->hrmShift($this->tenant);
    $type = $this->hrmLeaveType($this->tenant, ['approval_flow' => 'hr_only']);
    $unpaid = $this->hrmLeaveType($this->tenant, ['name' => 'Unpaid', 'code' => 'UL', 'is_paid' => false, 'tracks_balance' => false, 'approval_flow' => 'hr_only']);
    $this->hrmPolicy($this->tenant, $type);
    $employee = $this->hire($this->tenant);
    $owner = $this->ownerOf($this->tenant);
    $ownerMembership = $this->membershipOf($this->tenant, $owner);
    $this->hrmAt('2026-03-05 00:30:00');

    $this->inTenant($this->tenant, function () use ($employee, $type, $unpaid, $owner, $ownerMembership): void {
        $attendance = app(AttendanceManager::class);
        app(LeaveAccruals::class)->accrueEmployee($employee);
        $attendance->record($employee, '2026-03-02 09:00', '2026-03-02 18:20', null, null);

        foreach ([[$type, '2026-03-03', '2026-03-03'], [$unpaid, '2026-03-04', '2026-03-04']] as [$leaveType, $from, $to]) {
            $request = app(LeaveManager::class)->submit($employee, ['leave_type_id' => $leaveType->id, 'starts_on' => $from, 'ends_on' => $to]);
            $request->load(['employee', 'leaveType']);
            app(LeaveManager::class)->decide($request, $owner, true, null, $ownerMembership);
        }
    });

    $this->inTenant($this->tenant, function () use ($employee): void {
        app(AttendanceCloser::class)->closeEmployee($employee, '2026-03-04');
        $time = app(EmployeeTimeSummary::class);
        $summary = $time->attendance($employee->id, '2026-03-02', '2026-03-04');

        expect($summary)->toMatchArray([
            'days_present' => 1, 'days_on_leave' => 2, 'open_days' => 0, 'days_absent' => 0,
            'worked_minutes' => 500, 'overtime_minutes' => 20, 'late_minutes' => 0, 'scheduled_minutes' => 1440,
        ]);

        $usage = collect($time->leaveUsage($employee->id, '2026-03-01', '2026-03-31'))->keyBy('code');
        expect($usage['AL']['units'])->toBe(2)->and($usage['AL']['is_paid'])->toBeTrue()
            ->and($usage['UL']['units'])->toBe(2)->and($usage['UL']['is_paid'])->toBeFalse()
            ->and($time->leaveUsage($employee->id, '2026-03-04', '2026-03-31')[0]['code'])->toBe('UL')
            ->and(fn () => $time->attendance($employee->id, '2026-03-05', '2026-03-01'))->toThrow(InvalidArgumentException::class);
    });
});

it('executes the grant-balance automation action once per idempotency key', function () {
    $type = $this->hrmLeaveType($this->tenant);
    $employee = $this->hire($this->tenant);

    $this->inTenant($this->tenant, function () use ($type, $employee): void {
        $action = app(GrantLeaveBalanceAction::class);
        $input = ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'units' => 6, 'note' => 'Anniversary'];

        $first = $action->execute($input, 'run-1:step-1');
        $retry = $action->execute($input, 'run-1:step-1');
        $second = $action->execute($input, 'run-2:step-1');

        expect($retry)->toBe($first)
            ->and($first['balance_units'])->toBe(6)
            ->and($second['balance_units'])->toBe(12)
            ->and(LeaveLedgerEntry::query()->where('employee_id', $employee->id)->count())->toBe(2)
            ->and(AutomationActionReceipt::query()->where('action', 'hrm.leave.grant_balance')->count())->toBe(2)
            ->and(fn () => $action->execute([...$input, 'units' => 0], 'run-3'))->toThrow(ValidationException::class)
            ->and(AutomationActionReceipt::query()->where('idempotency_key', 'run-3')->count())->toBe(0);
    });
});

it('executes the assign-manager automation action durably and refuses loops', function () {
    $boss = $this->hire($this->tenant, ['first_name' => 'Boss']);
    $dev = $this->hire($this->tenant, ['first_name' => 'Dev']);

    $this->inTenant($this->tenant, function () use ($boss, $dev): void {
        $action = app(AssignManagerAction::class);
        $first = $action->execute(['employee_id' => $dev->id, 'manager_id' => $boss->id], 'k1');
        $retry = $action->execute(['employee_id' => $dev->id, 'manager_id' => null], 'k1');

        // The stored JSON may reorder keys: compare the content, not the key order.
        expect($first)->toEqual(['employee_id' => $dev->id, 'manager_id' => $boss->id])
            ->and($retry)->toEqual($first)
            ->and($dev->refresh()->manager_id)->toBe($boss->id)
            ->and(fn () => $action->execute(['employee_id' => $boss->id, 'manager_id' => $dev->id], 'k2'))->toThrow(ValidationException::class)
            ->and(app(EmployeeManager::class)->managerWouldCycle($boss->id, $dev->id))->toBeTrue();
    });
});
