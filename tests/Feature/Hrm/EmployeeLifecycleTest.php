<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Core\Organization\Models\Department;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\HrmSettings;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\HistoryEvent;
use App\Modules\Hrm\Events\EmployeeCreated;
use App\Modules\Hrm\Events\EmployeeTerminated;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmploymentHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->tenant = $this->createTenant('hrm-life', ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
    $this->entitle($this->tenant, 'hrm');
    $this->employees = fn (): EmployeeManager => app(EmployeeManager::class);
    $this->hire = fn (array $overrides = []): Employee => $this->inTenant($this->tenant, fn (): Employee => app(EmployeeManager::class)->create([
        'first_name' => 'Rafi',
        'last_name' => 'Ahmed',
        'employment_type' => EmploymentType::FullTime->value,
        'joined_on' => '2026-01-05',
        ...$overrides,
    ]));
});

it('numbers employees in a gap-free sequence with the configured prefix and padding', function () {
    $this->inTenant($this->tenant, fn () => app(HrmSettings::class)->update(['employee_prefix' => 'EMP-', 'employee_padding' => 3]));

    $first = ($this->hire)();
    $second = ($this->hire)(['first_name' => 'Nila']);

    // A failed creation hands its number back.
    expect(fn () => ($this->hire)(['first_name' => 'Broken', 'department_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']))
        ->toThrow(ValidationException::class);

    $third = ($this->hire)(['first_name' => 'Sami']);

    expect($first->employee_number)->toBe('EMP-001')
        ->and($second->employee_number)->toBe('EMP-002')
        ->and($third->employee_number)->toBe('EMP-003');

    $this->inTenant($this->tenant, fn () => app(HrmSettings::class)->update(['employee_prefix' => 'ACME/', 'employee_padding' => 5]));

    expect(($this->hire)(['first_name' => 'Tuli'])->employee_number)->toBe('ACME/00004');
});

it('keeps numbering independent per workspace', function () {
    $other = $this->createTenant('hrm-other');
    $this->entitle($other, 'hrm');

    $mine = ($this->hire)();
    $theirs = $this->inTenant($other, fn () => app(EmployeeManager::class)->create([
        'first_name' => 'Zed', 'employment_type' => 'full_time', 'joined_on' => '2026-01-05',
    ]));

    expect($mine->employee_number)->toBe('EMP-0001')->and($theirs->employee_number)->toBe('EMP-0001');
});

it('writes an immutable employment history for every change that matters', function () {
    $department = $this->inTenant($this->tenant, fn () => Department::factory()->create(['name' => 'Ops']));
    $designation = $this->inTenant($this->tenant, fn () => Designation::query()->create(['name' => 'Analyst']));
    $employee = ($this->hire)();

    $this->inTenant($this->tenant, function () use ($employee, $department, $designation): void {
        app(EmployeeManager::class)->update($employee, ['department_id' => $department->id, 'designation_id' => $designation->id, 'first_name' => 'Rafi', 'phone' => '01700000000']);
        app(EmployeeManager::class)->update($employee, ['phone' => '01800000000']);

        $history = EmploymentHistory::query()->where('employee_id', $employee->id)->orderBy('created_at')->orderBy('id')->get();

        expect($history)->toHaveCount(2)
            ->and($history[0]->event)->toBe(HistoryEvent::Joined)
            ->and($history[1]->event)->toBe(HistoryEvent::Updated)
            ->and($history[1]->changes['department_id']['to'])->toBe($department->id)
            ->and($history[1]->changes['designation_id']['to'])->toBe($designation->id);

        // Rows can be neither edited nor removed.
        expect(fn () => $history[0]->update(['reason' => 'tampered']))->toThrow(LogicException::class);
        expect(fn () => $history[0]->delete())->toThrow(LogicException::class);
    });
});

it('walks the lifecycle: probation, active, suspended, terminated, reinstated', function () {
    Event::fake([EmployeeCreated::class, EmployeeTerminated::class]);
    $employee = ($this->hire)(['status' => 'probation']);
    $manager = fn (): EmployeeManager => app(EmployeeManager::class);

    $this->inTenant($this->tenant, function () use ($employee, $manager): void {
        $manager()->changeStatus($employee, EmployeeStatus::Active, ['effective_on' => '2026-04-05']);
        expect($employee->refresh()->confirmed_on->toDateString())->toBe('2026-04-05');

        // A suspension needs a reason; a terminated person cannot jump to suspended.
        expect(fn () => $manager()->changeStatus($employee, EmployeeStatus::Suspended, []))->toThrow(ValidationException::class);
        $manager()->changeStatus($employee, EmployeeStatus::Suspended, ['reason' => 'Investigation']);
        $manager()->changeStatus($employee, EmployeeStatus::Terminated, [
            'reason' => 'Left for another role', 'termination_type' => 'resignation', 'effective_on' => '2026-06-30', 'last_working_on' => '2026-06-28',
        ]);

        $terminated = $employee->refresh();
        expect($terminated->status)->toBe(EmployeeStatus::Terminated)
            ->and($terminated->terminated_on->toDateString())->toBe('2026-06-30')
            ->and($terminated->last_working_on->toDateString())->toBe('2026-06-28')
            ->and(fn () => $manager()->changeStatus($employee, EmployeeStatus::Suspended, ['reason' => 'x']))->toThrow(ValidationException::class);

        $manager()->changeStatus($employee, EmployeeStatus::Active, ['effective_on' => '2026-08-01']);
        expect($employee->refresh()->terminated_on)->toBeNull()
            ->and($employee->status)->toBe(EmployeeStatus::Active);

        $events = EmploymentHistory::query()->where('employee_id', $employee->id)->orderBy('created_at')->orderBy('id')->pluck('event')->map->value->all();
        expect($events)->toBe(['joined', 'status_changed', 'status_changed', 'terminated', 'reinstated']);
    });

    Event::assertDispatched(EmployeeCreated::class, fn (EmployeeCreated $event): bool => $event->payload()['number'] === 'EMP-0001');
    Event::assertDispatched(EmployeeTerminated::class, fn (EmployeeTerminated $event): bool => $event->payload()['termination_type'] === 'resignation');
});

it('refuses a reporting loop and a manager from another workspace', function () {
    $boss = ($this->hire)(['first_name' => 'Boss']);
    $lead = ($this->hire)(['first_name' => 'Lead', 'manager_id' => $boss->id]);
    $dev = ($this->hire)(['first_name' => 'Dev', 'manager_id' => $lead->id]);

    $this->inTenant($this->tenant, function () use ($boss, $dev): void {
        $manager = app(EmployeeManager::class);

        expect(fn () => $manager->update($boss, ['manager_id' => $dev->id]))->toThrow(ValidationException::class)
            ->and(fn () => $manager->update($boss, ['manager_id' => $boss->id]))->toThrow(ValidationException::class);

        $manager->update($dev, ['manager_id' => $boss->id]);
        expect($dev->refresh()->manager_id)->toBe($boss->id);
    });

    $outsider = $this->createTenant('hrm-outsider');
    $this->entitle($outsider, 'hrm');
    $foreign = $this->inTenant($outsider, fn () => app(EmployeeManager::class)->create([
        'first_name' => 'Foreign', 'employment_type' => 'contract', 'joined_on' => '2026-01-05',
    ]));

    $this->inTenant($this->tenant, fn () => expect(fn () => app(EmployeeManager::class)->update($lead, ['manager_id' => $foreign->id]))
        ->toThrow(ValidationException::class));
});

it('moves direct reports up when their manager leaves', function () {
    $boss = ($this->hire)(['first_name' => 'Boss']);
    $lead = ($this->hire)(['first_name' => 'Lead', 'manager_id' => $boss->id]);
    $dev = ($this->hire)(['first_name' => 'Dev', 'manager_id' => $lead->id]);

    $this->inTenant($this->tenant, function () use ($lead, $dev, $boss): void {
        app(EmployeeManager::class)->changeStatus($lead, EmployeeStatus::Terminated, [
            'reason' => 'Redundancy', 'termination_type' => 'redundancy', 'effective_on' => '2026-03-01',
        ]);

        expect($dev->refresh()->manager_id)->toBe($boss->id);
    });
});

it('links an account to one employee only and makes it the record owner', function () {
    $user = $this->addMember($this->tenant);
    $membership = $this->membershipOf($this->tenant, $user);
    $employee = ($this->hire)(['membership_id' => $membership->id]);
    $another = ($this->hire)(['first_name' => 'Other']);

    expect($employee->owner_id)->toBe($membership->id);

    $this->inTenant($this->tenant, fn () => expect(fn () => app(EmployeeManager::class)->update($another, ['membership_id' => $membership->id]))
        ->toThrow(ValidationException::class));
});

it('enforces the plan limit on counted employees and frees a place on termination', function () {
    $plan = $this->createFreePlan(['key' => 'two-staff', 'modules' => ['hrm'], 'limits' => ['hrm.employees' => 2]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $first = ($this->hire)(['first_name' => 'A']);
    ($this->hire)(['first_name' => 'B']);

    $this->inTenant($this->tenant, function (): void {
        expect(app(UsageLimits::class)->used('hrm.employees'))->toBe(2)
            ->and(app(UsageLimits::class)->remaining('hrm.employees'))->toBe(0);
    });

    expect(fn () => ($this->hire)(['first_name' => 'C']))->toThrow(ValidationException::class);

    $this->inTenant($this->tenant, function () use ($first): void {
        app(EmployeeManager::class)->changeStatus($first, EmployeeStatus::Terminated, ['reason' => 'x', 'termination_type' => 'other', 'effective_on' => '2026-02-01']);
        expect(app(UsageLimits::class)->used('hrm.employees'))->toBe(1);
    });

    expect(($this->hire)(['first_name' => 'C'])->employee_number)->toBe('EMP-0003');

    // Reinstating the leaver would exceed the plan again.
    $this->inTenant($this->tenant, fn () => expect(fn () => app(EmployeeManager::class)->changeStatus($first, EmployeeStatus::Active, ['effective_on' => '2026-03-01']))
        ->toThrow(ValidationException::class));
});

it('derives dates from the workspace timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-10 20:30:00', 'UTC')); // 02:30 on the 11th in Dhaka

    $this->inTenant($this->tenant, fn () => expect(app(HrmSettings::class)->today()->toDateString())->toBe('2026-05-11'));
});
