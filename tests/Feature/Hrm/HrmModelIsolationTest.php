<?php

declare(strict_types=1);

use App\Modules\Hrm\Enums\AttendanceSource;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Enums\DocumentType;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\HistoryEvent;
use App\Modules\Hrm\Enums\HolidayType;
use App\Modules\Hrm\Enums\LeaveAccrual;
use App\Modules\Hrm\Enums\LeaveApprovalFlow;
use App\Modules\Hrm\Enums\LeaveSession;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\AutomationActionReceipt;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\EmergencyContact;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAsset;
use App\Modules\Hrm\Models\EmployeeDocument;
use App\Modules\Hrm\Models\EmploymentHistory;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HrmSetting;
use App\Modules\Hrm\Models\LeaveApproval;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\NumberSequence;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShiftAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

$employee = fn (): Employee => Employee::query()->create([
    'employee_number' => 'EMP-'.Str::upper(Str::random(6)),
    'first_name' => 'Iso',
    'employment_type' => EmploymentType::FullTime,
    'status' => EmployeeStatus::Active,
    'joined_on' => '2026-01-01',
]);
$shift = fn (): Shift => Shift::query()->create(['name' => 'S'.Str::random(5), 'starts_at' => '09:00', 'ends_at' => '17:00']);
$type = fn (): LeaveType => LeaveType::query()->create([
    'name' => 'T'.Str::random(5), 'code' => Str::upper(Str::random(5)), 'approval_flow' => LeaveApprovalFlow::HrOnly,
]);

it('isolates settings, sequences and the catalogue between tenants', function () use ($shift, $type) {
    $this->assertTenantIsolated(HrmSetting::class, fn () => HrmSetting::query()->create(['weekly_off_days' => [5]]));
    $this->assertTenantIsolated(NumberSequence::class, fn () => NumberSequence::query()->create(['kind' => 'employee', 'last_number' => 3]));
    $this->assertTenantIsolated(Designation::class, fn () => Designation::query()->create(['name' => 'Analyst']));
    $this->assertTenantIsolated(Holiday::class, fn () => Holiday::query()->create(['name' => 'Day', 'holiday_on' => '2026-02-21', 'type' => HolidayType::Public]));
    $this->assertTenantIsolated(Shift::class, fn () => $shift());
    $this->assertTenantIsolated(LeaveType::class, fn () => $type());
    $this->assertTenantIsolated(AutomationActionReceipt::class, fn () => AutomationActionReceipt::query()->create([
        'action' => 'hrm.leave.grant_balance', 'idempotency_key' => Str::random(10), 'output' => [],
    ]));
});

it('isolates employees and everything an employee owns between tenants', function () use ($employee) {
    $this->assertTenantIsolated(Employee::class, fn () => $employee());
    $this->assertTenantIsolated(EmploymentHistory::class, fn () => EmploymentHistory::query()->create([
        'employee_id' => $employee()->id, 'event' => HistoryEvent::Joined, 'effective_on' => '2026-01-01', 'changes' => [],
    ]));
    $this->assertTenantIsolated(EmergencyContact::class, fn () => EmergencyContact::query()->create([
        'employee_id' => $employee()->id, 'name' => 'Mum', 'relationship' => 'Mother', 'phone' => '01700000000',
    ]));
    $this->assertTenantIsolated(EmployeeDocument::class, fn () => EmployeeDocument::query()->create([
        'employee_id' => $employee()->id, 'type' => DocumentType::Contract, 'title' => 'Contract', 'original_name' => 'c.pdf',
        'path' => 'hrm/x/c.pdf', 'mime_type' => 'application/pdf', 'size' => 10,
    ]));
    $this->assertTenantIsolated(EmployeeAsset::class, fn () => EmployeeAsset::query()->create([
        'employee_id' => $employee()->id, 'name' => 'Laptop', 'assigned_on' => '2026-01-02',
    ]));
});

it('isolates attendance and shift assignment between tenants', function () use ($employee, $shift) {
    $this->assertTenantIsolated(ShiftAssignment::class, fn () => ShiftAssignment::query()->create([
        'employee_id' => $employee()->id, 'shift_id' => $shift()->id, 'starts_on' => '2026-01-01',
    ]));
    $this->assertTenantIsolated(AttendanceEntry::class, fn () => AttendanceEntry::query()->create([
        'employee_id' => $employee()->id, 'work_date' => '2026-01-05', 'clock_in_at' => '2026-01-05 03:00:00',
        'clock_out_at' => '2026-01-05 11:00:00', 'source' => AttendanceSource::Manual,
    ]));
    $this->assertTenantIsolated(AttendanceDay::class, fn () => AttendanceDay::query()->create([
        'employee_id' => $employee()->id, 'work_date' => '2026-01-05', 'status' => DayStatus::Present,
    ]));
    $this->assertTenantIsolated(Regularization::class, fn () => Regularization::query()->create([
        'employee_id' => $employee()->id, 'work_date' => '2026-01-05', 'clock_in_at' => '2026-01-05 03:00:00',
        'clock_out_at' => '2026-01-05 11:00:00', 'reason' => 'x', 'status' => RegularizationStatus::Pending,
    ]));
});

it('isolates leave policies, requests, approvals and the ledger between tenants', function () use ($employee, $type) {
    $request = fn (): LeaveRequest => LeaveRequest::query()->create([
        'employee_id' => $employee()->id, 'leave_type_id' => $type()->id, 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-02',
        'start_session' => LeaveSession::Full, 'end_session' => LeaveSession::Full, 'units' => 2, 'days' => ['2026-03-02' => 2],
        'leave_year' => 2026, 'status' => LeaveStatus::PendingHr,
    ]);

    $this->assertTenantIsolated(LeavePolicy::class, fn () => LeavePolicy::query()->create([
        'leave_type_id' => $type()->id, 'name' => 'Std', 'accrual' => LeaveAccrual::Annual, 'annual_units' => 20,
    ]));
    $this->assertTenantIsolated(LeaveRequest::class, fn () => $request());
    $this->assertTenantIsolated(LeaveApproval::class, fn () => LeaveApproval::query()->create([
        'leave_request_id' => $request()->id, 'step' => 'hr', 'decision' => 'approved', 'decided_at' => now(),
    ]));
    $this->assertTenantIsolated(LeaveLedgerEntry::class, fn () => LeaveLedgerEntry::query()->create([
        'employee_id' => $employee()->id, 'leave_type_id' => $type()->id, 'leave_year' => 2026, 'units' => 5,
        'kind' => LedgerKind::Adjustment, 'effective_on' => '2026-03-02', 'idempotency_key' => Str::random(20),
    ]));
});

it('allows the same employee number, shift and leave code in two workspaces but never twice in one', function () {
    $a = $this->createTenant();
    $b = $this->createTenant();
    $make = fn () => Employee::query()->create([
        'employee_number' => 'EMP-0001', 'first_name' => 'Same', 'employment_type' => EmploymentType::FullTime,
        'status' => EmployeeStatus::Active, 'joined_on' => '2026-01-01',
    ]);

    $this->inTenant($a, fn () => $make());
    $this->inTenant($b, fn () => $make());

    expect(fn () => $this->inTenant($a, fn () => $make()))->toThrow(UniqueConstraintViolationException::class);

    $code = fn () => LeaveType::query()->create(['name' => 'Annual', 'code' => 'AL', 'approval_flow' => LeaveApprovalFlow::HrOnly]);
    $this->inTenant($a, fn () => $code());
    $this->inTenant($b, fn () => $code());
    expect(fn () => $this->inTenant($a, fn () => LeaveType::query()->create(['name' => 'Other', 'code' => 'AL', 'approval_flow' => LeaveApprovalFlow::HrOnly])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps an immutable history and ledger even for direct model writes', function () use ($employee, $type) {
    $tenant = $this->createTenant();

    $this->inTenant($tenant, function () use ($employee, $type): void {
        $history = EmploymentHistory::query()->create(['employee_id' => $employee()->id, 'event' => HistoryEvent::Joined, 'effective_on' => '2026-01-01', 'changes' => []]);
        $ledger = LeaveLedgerEntry::query()->create([
            'employee_id' => $employee()->id, 'leave_type_id' => $type()->id, 'leave_year' => 2026, 'units' => 5,
            'kind' => LedgerKind::Accrual, 'effective_on' => '2026-01-01', 'idempotency_key' => 'k-'.Str::random(8),
        ]);

        expect(fn () => $history->update(['reason' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $history->delete())->toThrow(LogicException::class)
            ->and(fn () => $ledger->update(['units' => 6]))->toThrow(LogicException::class)
            ->and(fn () => $ledger->delete())->toThrow(LogicException::class);
    });
});
