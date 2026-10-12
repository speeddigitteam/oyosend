<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Organization\Models\Department;
use App\Modules\Hrm\Actions\AttendanceManager;
use App\Modules\Hrm\Actions\EmployeeRecords;
use App\Modules\Hrm\Actions\LeaveAccruals;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Hrm\Actions\RegularizationManager;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\EmergencyContact;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAsset;
use App\Modules\Hrm\Models\EmployeeDocument;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Regularization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InteractsWithHrm;

uses(InteractsWithHrm::class);

beforeEach(function () {
    Storage::fake((string) config('tenancy.filesystem.disk', 'local'));
    $this->tenant = $this->hrmTenant('hrm-http');
});

it('answers 404 for every HRM page when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('hrm-plain');
    $this->actingAsOwner($plain);

    foreach (['/me', '/employees', '/attendance', '/leave', '/holidays', '/reports', '/settings'] as $path) {
        $this->get($this->tenantUrl($plain, '/hrm'.$path))->assertNotFound();
    }
});

it('runs the HR administrator workflow as the workspace owner', function () {
    $this->actingAsOwner($this->tenant);
    $department = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Finance']));

    // Settings: designations and numbering.
    $this->put($this->hrmUrl($this->tenant, '/settings'), [
        'employee_prefix' => 'ACM-', 'employee_padding' => 3, 'weekly_off_days' => [5], 'leave_year_start_month' => 7, 'work_day_minutes' => 480,
    ])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, '/settings/designations'), ['name' => 'Accountant', 'is_active' => true])->assertRedirect();
    $designation = $this->inTenant($this->tenant, fn () => Designation::query()->sole());

    $this->get($this->hrmUrl($this->tenant, '/settings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Hrm/Pages/Settings/Index')
            ->where('settings.employeePrefix', 'ACM-')
            ->where('settings.weeklyOffDays', [5])
            ->where('settings.leaveYearStartMonth', 7)
            ->has('designations', 1));

    $this->get($this->hrmUrl($this->tenant, '/employees/create'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Modules/Hrm/Pages/Employees/Create')->has('form.departments', 1)->has('form.designations', 1));

    $this->post($this->hrmUrl($this->tenant, '/employees'), [
        'first_name' => 'Nusrat', 'last_name' => 'Jahan', 'employment_type' => 'full_time', 'joined_on' => '2026-02-01',
        'department_id' => $department->id, 'designation_id' => $designation->id, 'work_email' => 'nusrat@example.test',
        'national_id' => '1990123456789', 'cost_rate_amount' => 25000, 'status' => 'probation',
    ])->assertRedirect();
    $employee = $this->inTenant($this->tenant, fn () => Employee::query()->sole());

    expect($employee->employee_number)->toBe('ACM-001')
        ->and($employee->owner_id)->toBeNull()
        ->and($employee->cost_currency)->toBe('BDT');

    $this->get($this->hrmUrl($this->tenant, "/employees/{$employee->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Hrm/Pages/Employees/Show')
            ->where('employee.number', 'ACM-001')
            ->where('employee.status', 'probation')
            ->where('employee.department.name', 'Finance')
            ->where('employee.personal.nationalId', '1990123456789')
            ->where('employee.costRateAmount', 25000)
            ->where('abilities.edit', true)
            ->where('abilities.changeStatus', true)
            ->where('statusOptions', ['active', 'on_leave', 'suspended', 'terminated'])
            ->has('history', 1));

    $this->get($this->hrmUrl($this->tenant, '/employees?q=nusr&status=probation'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 1)->where('canCreate', true));
    $this->get($this->hrmUrl($this->tenant, '/employees?q=zzz'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 0));

    // Records of the employee.
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/emergency-contacts"), ['name' => 'Karim', 'relationship' => 'Brother', 'phone' => '01711111111'])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/assets"), ['name' => 'Laptop', 'asset_tag' => 'LT-77'])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/assets"), ['name' => 'Other laptop', 'asset_tag' => 'LT-77'])->assertSessionHasErrors('asset_tag');
    $asset = $this->inTenant($this->tenant, fn () => EmployeeAsset::query()->sole());
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/assets/{$asset->id}/return"), ['return_condition' => 'Good'])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/documents"), [
        'file' => UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'), 'type' => 'contract', 'title' => 'Employment contract',
    ])->assertRedirect();

    $this->inTenant($this->tenant, function () use ($employee): void {
        $contact = EmergencyContact::query()->sole();
        $document = EmployeeDocument::query()->sole();

        expect($contact->is_primary)->toBeTrue()
            ->and($document->path)->toStartWith("hrm/{$employee->id}/documents/")
            ->and(EmployeeAsset::query()->sole()->isHeld())->toBeFalse();
    });

    // Status changes: a termination needs a type and a reason.
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/status"), ['status' => 'terminated'])->assertSessionHasErrors();
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/status"), ['status' => 'active', 'effective_on' => '2026-05-01'])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/status"), [
        'status' => 'terminated', 'termination_type' => 'resignation', 'reason' => 'Relocating', 'effective_on' => '2026-06-30',
    ])->assertRedirect();

    $this->get($this->hrmUrl($this->tenant, "/employees/{$employee->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.status', 'terminated')
            ->where('employee.terminationType', 'resignation')
            ->where('statusOptions', ['active']));

    // Holidays, reports and the leave setup pages.
    $this->post($this->hrmUrl($this->tenant, '/holidays'), ['name' => 'Eid', 'holiday_on' => '2026-03-20', 'type' => 'public'])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, '/holidays'), ['name' => 'Eid again', 'holiday_on' => '2026-03-20', 'type' => 'public'])->assertSessionHasErrors('holiday_on');
    $this->get($this->hrmUrl($this->tenant, '/holidays?year=2026'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('holidays', 1)->where('canManage', true));

    $this->post($this->hrmUrl($this->tenant, '/settings/leave-types'), [
        'name' => 'Annual', 'code' => 'al', 'is_paid' => true, 'tracks_balance' => true, 'allows_half_day' => true,
        'requires_reason' => false, 'min_notice_days' => 0, 'approval_flow' => 'manager_then_hr', 'is_active' => true,
    ])->assertRedirect();
    $type = $this->inTenant($this->tenant, fn () => LeaveType::query()->sole());
    expect($type->code)->toBe('AL');
    $this->post($this->hrmUrl($this->tenant, '/settings/leave-policies'), [
        'leave_type_id' => $type->id, 'name' => 'Standard', 'accrual' => 'monthly', 'annual_units' => 36,
        'carry_forward_max_units' => 10, 'sandwich_rule' => false, 'min_service_days' => 0, 'is_active' => true,
    ])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, '/settings/shifts'), [
        'name' => 'Office', 'starts_at' => '09:00', 'ends_at' => '17:30', 'break_minutes' => 30, 'late_grace_minutes' => 5,
        'early_grace_minutes' => 0, 'overtime_threshold_minutes' => 15, 'is_default' => true, 'is_active' => true,
    ])->assertRedirect();

    $this->get($this->hrmUrl($this->tenant, '/reports?from=2026-01-01&to=2026-03-31'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Hrm/Pages/Reports/Index')
            ->where('headcount.total', 0)
            ->where('headcount.joined', 1)
            ->where('headcount.left', 0)
            ->has('leave.by_type', 1));
});

it('lets an employee see and edit only their own record: the own scope', function () {
    [$userA, $a] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.employees.view', 'hrm.employees.manage'], [
        'hrm.employees.view' => 'own', 'hrm.employees.manage' => 'own',
    ], ['first_name' => 'Alpha']);
    [, $b] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.employees.view'], ['hrm.employees.view' => 'own'], ['first_name' => 'Beta']);
    $boss = $this->hire($this->tenant, ['first_name' => 'Boss']);

    $this->actingAsMember($this->tenant, $userA);
    $this->get($this->hrmUrl($this->tenant, '/employees'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $a->id));
    $this->get($this->hrmUrl($this->tenant, "/employees/{$b->id}"))->assertNotFound();
    $this->put($this->hrmUrl($this->tenant, "/employees/{$b->id}"), ['first_name' => 'Hack', 'employment_type' => 'full_time'])->assertNotFound();

    // Own record: contact details change, employment fields silently do not.
    $this->put($this->hrmUrl($this->tenant, "/employees/{$a->id}"), [
        'first_name' => 'Alpha', 'employment_type' => 'contract', 'phone' => '01999999999', 'manager_id' => $boss->id,
        'cost_rate_amount' => 999999, 'cost_currency' => 'BDT',
    ])->assertRedirect();

    $this->inTenant($this->tenant, function () use ($a): void {
        $fresh = $a->refresh();
        expect($fresh->phone)->toBe('01999999999')
            ->and($fresh->employment_type->value)->toBe('full_time')
            ->and($fresh->manager_id)->toBeNull()
            ->and($fresh->cost_rate_amount)->toBeNull();
    });

    // Nobody changes their own status.
    $this->post($this->hrmUrl($this->tenant, "/employees/{$a->id}/status"), ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
});

it('narrows by department and hides personal data without hrm.personal.view', function () {
    $sales = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Sales']));
    $ops = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Ops']));

    [$lead, $leadEmployee, $leadMembership] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.employees.view'], ['hrm.employees.view' => 'department'], [
        'first_name' => 'Lead', 'department_id' => $sales->id,
    ]);
    $this->inTenant($this->tenant, fn () => $leadMembership->update(['department_id' => $sales->id]));
    $teammate = $this->hire($this->tenant, ['first_name' => 'Mate', 'department_id' => $sales->id, 'national_id' => 'NID-1', 'date_of_birth' => '1990-01-01']);
    $stranger = $this->hire($this->tenant, ['first_name' => 'Stranger', 'department_id' => $ops->id]);

    $this->actingAsMember($this->tenant, $lead);
    $this->get($this->hrmUrl($this->tenant, '/employees'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('employees.data', 2));
    $this->get($this->hrmUrl($this->tenant, "/employees/{$stranger->id}"))->assertNotFound();

    // Visible record, but the personal block is withheld: they hold hrm.employees.view only.
    $this->get($this->hrmUrl($this->tenant, "/employees/{$teammate->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('employee.personal', null)
            ->where('employee.costRateAmount', null)
            ->where('emergencyContacts', [])
            ->where('abilities.viewPersonal', false));

    // The same colleague with the personal-data permission sees it.
    [$hr] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view', 'hrm.personal.view'], ['hrm.employees.view' => 'all', 'hrm.personal.view' => 'all']);
    $this->actingAsMember($this->tenant, $hr)->get($this->hrmUrl($this->tenant, "/employees/{$teammate->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('employee.personal.nationalId', 'NID-1')->where('employee.personal.dateOfBirth', '1990-01-01'));
});

it('serves private documents only to the employee and holders of the personal-data permission', function () {
    [$staff, $employee] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.employees.view'], ['hrm.employees.view' => 'all'], ['first_name' => 'Docs']);
    [$viewer] = $this->hrmMember($this->tenant, ['hrm.employees.view'], ['hrm.employees.view' => 'all']);
    [$hr] = $this->hrmMember($this->tenant, ['hrm.employees.view', 'hrm.personal.view'], ['hrm.employees.view' => 'all', 'hrm.personal.view' => 'all']);

    $document = $this->inTenant($this->tenant, fn () => app(EmployeeRecords::class)->addDocument(
        $employee, UploadedFile::fake()->createWithContent('offer.txt', 'secret offer'), ['type' => 'offer', 'title' => 'Offer'],
    ));
    $url = $this->hrmUrl($this->tenant, "/employees/{$employee->id}/documents/{$document->id}");

    $this->actingAsMember($this->tenant, $viewer)->get($url)->assertNotFound();
    $this->actingAsMember($this->tenant, $hr)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAsMember($this->tenant, $staff)->get($url)->assertOk();
});

it('lets employees clock in and read their month through self-service', function () {
    [$user, $employee] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.attendance.clock', 'hrm.leave.request'], [], ['first_name' => 'Self']);
    $this->hrmShift($this->tenant);
    $this->hrmAt('2026-03-02 09:00:00');

    $this->actingAsMember($this->tenant, $user)->get($this->hrmUrl($this->tenant, '/me'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Hrm/Pages/Me/Show')
            ->where('hasProfile', true)
            ->where('employee.id', $employee->id)
            ->where('clock.clockedIn', false)
            ->where('clock.isWorkingDay', true)
            ->where('abilities.clock', true)
            ->where('abilities.requestLeave', true)
            ->where('abilities.viewLeave', false));

    $this->post($this->hrmUrl($this->tenant, '/attendance/clock-in'))->assertRedirect();
    $this->get($this->hrmUrl($this->tenant, '/me'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('clock.clockedIn', true)->where('clock.day.status', 'incomplete'));

    // No attendance permission, no clock.
    [$nobody] = $this->hrmStaff($this->tenant, ['hrm.self.view'], [], ['first_name' => 'No clock']);
    $this->actingAsMember($this->tenant, $nobody)->post($this->hrmUrl($this->tenant, '/attendance/clock-in'))->assertForbidden();

    // A signed-in member with no employee record gets an empty self page, not an error.
    $plain = $this->addMember($this->tenant, $this->createRole($this->tenant, 'Self only', ['hrm.self.view']));
    $this->actingAsMember($this->tenant, $plain)->get($this->hrmUrl($this->tenant, '/me'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasProfile', false));
});

it('refuses HR actions without the matching permission', function () {
    [$user, $employee] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.employees.view'], ['hrm.employees.view' => 'all'], ['first_name' => 'Viewer']);
    $this->actingAsMember($this->tenant, $user);

    $this->post($this->hrmUrl($this->tenant, '/employees'), ['first_name' => 'X', 'employment_type' => 'full_time', 'joined_on' => '2026-01-01'])->assertForbidden();
    $this->put($this->hrmUrl($this->tenant, "/employees/{$employee->id}"), ['first_name' => 'X', 'employment_type' => 'full_time'])->assertForbidden();
    $this->post($this->hrmUrl($this->tenant, "/employees/{$employee->id}/status"), ['status' => 'suspended', 'reason' => 'x'])->assertForbidden();
    $this->get($this->hrmUrl($this->tenant, '/settings'))->assertForbidden();
    $this->get($this->hrmUrl($this->tenant, '/reports'))->assertForbidden();
    $this->post($this->hrmUrl($this->tenant, '/holidays'), ['name' => 'X', 'holiday_on' => '2026-01-01', 'type' => 'public'])->assertForbidden();
    $this->post($this->hrmUrl($this->tenant, '/attendance/entries'), ['employee_id' => $employee->id, 'clock_in_at' => '2026-01-01 09:00', 'clock_out_at' => '2026-01-01 17:00'])->assertForbidden();
    $this->post($this->hrmUrl($this->tenant, '/leave/adjustments'), ['employee_id' => $employee->id, 'leave_type_id' => str_repeat('0', 26), 'units' => 2, 'note' => 'x'])->assertForbidden();
});

it('answers 404 for every id route from another workspace', function () {
    $employee = $this->hire($this->tenant);
    $type = $this->hrmLeaveType($this->tenant);
    $this->hrmPolicy($this->tenant, $type);
    $shift = $this->hrmShift($this->tenant);
    $this->hrmAt('2026-03-03 12:00:00');

    $ids = $this->inTenant($this->tenant, function () use ($employee, $type): array {
        app(LeaveAccruals::class)->accrueEmployee($employee);
        $contact = app(EmployeeRecords::class)->addContact($employee, ['name' => 'C', 'relationship' => 'Friend', 'phone' => '017']);
        $asset = app(EmployeeRecords::class)->assignAsset($employee, ['name' => 'Phone']);
        $document = app(EmployeeRecords::class)->addDocument($employee, UploadedFile::fake()->createWithContent('a.txt', 'x'), ['type' => 'other', 'title' => 'A']);
        $entry = app(AttendanceManager::class)->record($employee, '2026-03-02 09:00', '2026-03-02 17:00', null, null);
        $correction = app(RegularizationManager::class)->request($employee, '2026-03-02', '2026-03-02 09:00', '2026-03-02 18:00', 'x');
        $request = app(LeaveManager::class)->submit($employee, ['leave_type_id' => $type->id, 'starts_on' => '2026-03-15', 'ends_on' => '2026-03-15']);
        $holiday = Holiday::query()->create(['name' => 'H', 'holiday_on' => '2026-04-01', 'type' => 'public']);
        $designation = Designation::query()->create(['name' => 'D']);
        $policy = LeavePolicy::query()->firstOrFail();

        return compact('contact', 'asset', 'document', 'entry', 'correction', 'request', 'holiday', 'designation', 'policy');
    });
    $id = fn (string $key): string => $ids[$key]->id;
    $intruder = $this->hrmTenant('hrm-intruder');
    $check = fn (string $method, string $path, array $data = []) => $this->assertNotFoundFromOtherTenant($method, $path, $this->tenant, $data, $intruder);

    $check('GET', "/hrm/employees/{$employee->id}");
    $check('GET', "/hrm/attendance/employees/{$employee->id}");
    $check('POST', "/hrm/employees/{$employee->id}/status", ['status' => 'suspended', 'reason' => 'x']);
    $check('PUT', "/hrm/employees/{$employee->id}", ['first_name' => 'X', 'employment_type' => 'full_time']);
    $check('PUT', "/hrm/employees/{$employee->id}/emergency-contacts/{$id('contact')}", ['name' => 'N', 'relationship' => 'R', 'phone' => '1']);
    $check('POST', "/hrm/employees/{$employee->id}/assets/{$id('asset')}/return");
    $check('GET', "/hrm/employees/{$employee->id}/documents/{$id('document')}");
    $check('DELETE', "/hrm/attendance/entries/{$id('entry')}");
    $check('POST', "/hrm/attendance/corrections/{$id('correction')}/decision", ['decision' => 'approve']);
    $check('POST', "/hrm/attendance/corrections/{$id('correction')}/cancel");
    $check('GET', "/hrm/leave/requests/{$id('request')}");
    $check('POST', "/hrm/leave/requests/{$id('request')}/decision", ['decision' => 'approve']);
    $check('POST', "/hrm/leave/requests/{$id('request')}/cancel");
    $check('PUT', "/hrm/holidays/{$id('holiday')}", ['name' => 'H2', 'holiday_on' => '2026-04-02', 'type' => 'public']);
    $check('PUT', "/hrm/settings/designations/{$id('designation')}", ['name' => 'D2', 'is_active' => true]);
    $check('PUT', "/hrm/settings/shifts/{$shift->id}", [
        'name' => 'S2', 'starts_at' => '08:00', 'ends_at' => '16:00', 'break_minutes' => 0, 'late_grace_minutes' => 0,
        'early_grace_minutes' => 0, 'overtime_threshold_minutes' => 0, 'is_default' => false, 'is_active' => true,
    ]);
    $check('PUT', "/hrm/settings/leave-types/{$type->id}", [
        'name' => 'T2', 'code' => 'T2', 'is_paid' => true, 'tracks_balance' => true, 'allows_half_day' => true, 'requires_reason' => false,
        'min_notice_days' => 0, 'approval_flow' => 'hr_only', 'is_active' => true,
    ]);
    $check('PUT', "/hrm/settings/leave-policies/{$id('policy')}", [
        'leave_type_id' => $type->id, 'name' => 'P2', 'accrual' => 'annual', 'annual_units' => 2, 'carry_forward_max_units' => 0,
        'sandwich_rule' => false, 'min_service_days' => 0, 'is_active' => true,
    ]);
});

it('refuses nested records that belong to another employee of the same workspace', function () {
    $one = $this->hire($this->tenant, ['first_name' => 'One']);
    $two = $this->hire($this->tenant, ['first_name' => 'Two']);
    $contact = $this->inTenant($this->tenant, fn () => app(EmployeeRecords::class)->addContact($one, ['name' => 'C', 'relationship' => 'Friend', 'phone' => '017']));

    $this->actingAsOwner($this->tenant)
        ->put($this->hrmUrl($this->tenant, "/employees/{$two->id}/emergency-contacts/{$contact->id}"), ['name' => 'N', 'relationship' => 'R', 'phone' => '1'])
        ->assertNotFound();
    $this->delete($this->hrmUrl($this->tenant, "/employees/{$two->id}/emergency-contacts/{$contact->id}"))->assertNotFound();
});

it('records and removes manual attendance as HR and keeps it out of reach for own-scope people', function () {
    $employee = $this->hire($this->tenant, ['first_name' => 'Punch']);
    $this->hrmShift($this->tenant);
    $this->hrmAt('2026-03-04 12:00:00');

    $this->actingAsOwner($this->tenant)
        ->post($this->hrmUrl($this->tenant, '/attendance/entries'), [
            'employee_id' => $employee->id, 'clock_in_at' => '2026-03-03 09:00', 'clock_out_at' => '2026-03-03 17:30', 'note' => 'Device down',
        ])->assertRedirect();

    $entry = $this->inTenant($this->tenant, fn () => AttendanceEntry::query()->sole());
    $this->get($this->hrmUrl($this->tenant, "/attendance/employees/{$employee->id}?month=2026-03"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Hrm/Pages/Attendance/Employee')
            ->where('month', '2026-03')
            ->has('days', 1)
            ->where('days.0.workedMinutes', 450)
            ->where('totals.workedMinutes', 450));

    $this->get($this->hrmUrl($this->tenant, '/attendance?date=2026-03-03'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows.data', 1)->where('rows.data.0.day.status', 'present'));

    $this->delete($this->hrmUrl($this->tenant, "/attendance/entries/{$entry->id}"))->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(AttendanceEntry::query()->count())->toBe(0));

    // A person with own-scope attendance management cannot edit their own attendance.
    [$self, $selfEmployee] = $this->hrmStaff($this->tenant, ['hrm.attendance.view', 'hrm.attendance.manage'], [
        'hrm.attendance.view' => 'own', 'hrm.attendance.manage' => 'own',
    ], ['first_name' => 'Cheat']);
    $this->actingAsMember($this->tenant, $self)
        ->post($this->hrmUrl($this->tenant, '/attendance/entries'), [
            'employee_id' => $selfEmployee->id, 'clock_in_at' => '2026-03-03 09:00', 'clock_out_at' => '2026-03-03 17:00',
        ])->assertForbidden();
    $this->inTenant($this->tenant, fn () => expect(Regularization::query()->count())->toBe(0));
});

it('keeps cost-rate changes out of the employment history for people without hrm.personal.view', function () {
    [$viewer] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view'], ['hrm.employees.view' => 'all']);
    $employee = $this->hire($this->tenant, ['first_name' => 'Costed', 'cost_rate_amount' => 25000, 'cost_currency' => 'BDT']);

    $this->inTenant($this->tenant, fn () => $employee->history()->create([
        'event' => 'updated',
        'effective_on' => '2026-03-01',
        'changes' => [
            'cost_rate_amount' => ['from' => 25000, 'to' => 30000],
            'cost_currency' => ['from' => 'BDT', 'to' => 'BDT'],
            'department_id' => ['from' => null, 'to' => 'x'],
        ],
    ]));

    $changes = fn (AssertableInertia $page): array => collect($page->toArray()['props']['history'])->pluck('changes')->all();

    $this->actingAsMember($this->tenant, $viewer)->get($this->hrmUrl($this->tenant, "/employees/{$employee->id}"))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($changes): void {
            $all = json_encode($changes($page));

            expect($all)->not->toContain('cost_rate_amount')->and($all)->not->toContain('cost_currency')->and($all)->toContain('department_id');
        });

    [$hr] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view', 'hrm.personal.view'], ['hrm.employees.view' => 'all', 'hrm.personal.view' => 'all']);
    $this->actingAsMember($this->tenant, $hr)->get($this->hrmUrl($this->tenant, "/employees/{$employee->id}"))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(json_encode($changes($page)))->toContain('cost_rate_amount'));
});

it('keeps termination reasons, history reasons and asset notes behind hrm.personal.view', function () {
    [$viewer] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view'], ['hrm.employees.view' => 'all']);
    [$hr] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view', 'hrm.personal.view'], ['hrm.employees.view' => 'all', 'hrm.personal.view' => 'all']);
    $employee = $this->hire($this->tenant, ['first_name' => 'Sensitive']);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $employee->forceFill(['status_reason' => 'Medical leave details', 'termination_reason' => 'Misconduct details'])->save();
        $employee->history()->create(['event' => 'status_changed', 'effective_on' => '2026-03-01', 'changes' => ['status_reason' => ['from' => null, 'to' => 'Medical leave details']], 'reason' => 'Private reason']);
        app(EmployeeRecords::class)->assignAsset($employee, ['name' => 'Laptop', 'notes' => 'Has a private note']);
    });

    $this->actingAsMember($this->tenant, $viewer)->get($this->hrmUrl($this->tenant, "/employees/{$employee->id}"))->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = json_encode($page->toArray()['props']);

            expect($props)->not->toContain('Medical leave details')->and($props)->not->toContain('Misconduct details')
                ->and($props)->not->toContain('Private reason')->and($props)->not->toContain('private note')
                ->and($props)->toContain('Laptop');
        });

    $this->actingAsMember($this->tenant, $hr)->get($this->hrmUrl($this->tenant, "/employees/{$employee->id}"))->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = json_encode($page->toArray()['props']);

            expect($props)->toContain('Medical leave details')->and($props)->toContain('Misconduct details')
                ->and($props)->toContain('Private reason')->and($props)->toContain('private note');
        });
});

it('narrows the manager and member pickers of the employee form to the actor scope', function () {
    $sales = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Sales']));
    $ops = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Ops']));

    [$creator, $creatorMembership] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view', 'hrm.employees.create'], ['hrm.employees.view' => 'department']);
    $this->inTenant($this->tenant, fn () => $creatorMembership->update(['department_id' => $sales->id]));
    [, $salesMember] = $this->hrmMember($this->tenant, ['hrm.self.view']);
    [, $opsMember] = $this->hrmMember($this->tenant, ['hrm.self.view']);
    $this->inTenant($this->tenant, function () use ($salesMember, $opsMember, $sales, $ops): void {
        $salesMember->update(['department_id' => $sales->id]);
        $opsMember->update(['department_id' => $ops->id]);
    });
    $this->hire($this->tenant, ['first_name' => 'Sales', 'last_name' => 'Person', 'department_id' => $sales->id]);
    $this->hire($this->tenant, ['first_name' => 'Ops', 'last_name' => 'Person', 'department_id' => $ops->id]);

    $this->actingAsMember($this->tenant, $creator)->get($this->hrmUrl($this->tenant, '/employees/create'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($salesMember, $opsMember): void {
            $form = $page->toArray()['props']['form'];
            $names = collect($form['managers'])->pluck('name')->all();
            $ids = collect($form['members'])->pluck('id')->all();

            expect($names)->toContain('Sales Person')->and($names)->not->toContain('Ops Person')
                ->and($ids)->toContain($salesMember->id)->and($ids)->not->toContain($opsMember->id)
                ->and(collect($form['members'])->pluck('email')->unique()->all())->toBe(['']);
        });

    // Workspace-wide access to employees and personal data sees everyone, with emails.
    [$hr] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.employees.view', 'hrm.employees.create', 'hrm.personal.view'], ['hrm.employees.view' => 'all', 'hrm.personal.view' => 'all']);
    $this->actingAsMember($this->tenant, $hr)->get($this->hrmUrl($this->tenant, '/employees/create'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($opsMember): void {
            $form = $page->toArray()['props']['form'];

            expect(collect($form['members'])->pluck('id')->all())->toContain($opsMember->id)
                ->and(collect($form['members'])->firstWhere('id', $opsMember->id)['email'])->not->toBe('')
                ->and(collect($form['managers'])->pluck('name')->all())->toContain('Ops Person');
        });
});

it('writes no personal or cost fields of an employee to the audit trail', function () {
    $employee = $this->hire($this->tenant, [
        'first_name' => 'Audited', 'date_of_birth' => '1990-05-05', 'personal_email' => 'private@example.test', 'phone' => '01711111111',
        'address' => '12 Secret Road', 'city' => 'Dhaka', 'cost_rate_amount' => 55555, 'notes' => 'confidential note', 'national_id' => 'NID-999',
    ]);
    $this->inTenant($this->tenant, fn () => $employee->forceFill(['notes' => 'changed note', 'address' => 'New address', 'cost_rate_amount' => 66666])->save());

    $logs = $this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'like', 'hrm.employee.%')->get());
    $blob = json_encode($logs->pluck('properties')->all());

    expect($logs)->not->toBeEmpty();

    foreach (['1990-05-05', 'private@example.test', '01711111111', 'Secret Road', 'New address', '55555', '66666', 'confidential note', 'changed note', 'NID-999'] as $secret) {
        expect($blob)->not->toContain($secret);
    }
});

it('refuses to let whoever filed an attendance correction also decide it', function () {
    $this->hrmAt('2026-03-05 12:00:00');
    $this->hrmShift($this->tenant);
    $employee = $this->hire($this->tenant, ['first_name' => 'Requested']);
    [$hrUser, $hrMembership] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.attendance.approve', 'hrm.attendance.view'], ['hrm.attendance.approve' => 'all', 'hrm.attendance.view' => 'all']);
    [$otherUser] = $this->hrmMember($this->tenant, ['hrm.self.view', 'hrm.attendance.approve', 'hrm.attendance.view'], ['hrm.attendance.approve' => 'all', 'hrm.attendance.view' => 'all']);

    $this->inTenant($this->tenant, function () use ($employee, $hrUser, $hrMembership, $otherUser): void {
        $manager = app(RegularizationManager::class);
        $correction = $manager->request($employee, '2026-03-03', '2026-03-03 09:00', '2026-03-03 18:00', 'Filed by HR', $hrMembership);

        expect($manager->mayDecide($hrUser, $correction))->toBeFalse()
            ->and($manager->mayDecide($otherUser, $correction))->toBeTrue()
            ->and(fn () => $manager->decide($correction, $hrUser, true, null, $hrMembership))->toThrow(NotFoundHttpException::class);
    });
});
