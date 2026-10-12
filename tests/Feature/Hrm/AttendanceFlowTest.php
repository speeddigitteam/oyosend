<?php

declare(strict_types=1);

use App\Core\Identity\Models\User;
use App\Modules\Hrm\Actions\AttendanceCloser;
use App\Modules\Hrm\Actions\AttendanceManager;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Hrm\Actions\RegularizationManager;
use App\Modules\Hrm\Actions\ShiftManager;
use App\Modules\Hrm\Enums\AttendanceSource;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Events\AttendanceDayClosed;
use App\Modules\Hrm\Jobs\CloseAttendanceDays;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Models\ShiftAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithHrm;

uses(InteractsWithHrm::class);

beforeEach(function () {
    $this->tenant = $this->hrmTenant('hrm-att');
    $this->shift = $this->hrmShift($this->tenant);
    [$this->user, $this->employee, $this->membership] = $this->hrmStaff($this->tenant, [
        'hrm.self.view', 'hrm.attendance.clock', 'hrm.attendance.view', 'hrm.attendance.regularize',
    ], ['hrm.attendance.view' => 'own']);
});

it('records clock in and out from the server clock and ignores any client time', function () {
    // Monday 2026-03-02 in Dhaka.
    $this->hrmAt('2026-03-02 09:07:41');
    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->hrmUrl($this->tenant, '/attendance/clock-in'), ['clock_in_at' => '2020-01-01 00:00', 'note' => 'Office'])
        ->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $entry = AttendanceEntry::query()->sole();

        // 09:07:41 Dhaka is 03:07:41 UTC: the posted time was not used.
        expect($entry->clock_in_at->toDateTimeString())->toBe('2026-03-02 03:07:41')
            ->and($entry->work_date->toDateString())->toBe('2026-03-02')
            ->and($entry->source)->toBe(AttendanceSource::Clock)
            ->and($entry->open_marker)->toBe(1);
    });

    // A second clock-in while one is open is refused.
    $this->post($this->hrmUrl($this->tenant, '/attendance/clock-in'))->assertSessionHasErrors('clock');

    $this->hrmAt('2026-03-02 18:03:10');
    $this->post($this->hrmUrl($this->tenant, '/attendance/clock-out'), ['clock_out_at' => '1999-01-01 00:00'])->assertRedirect();
    $this->post($this->hrmUrl($this->tenant, '/attendance/clock-out'))->assertSessionHasErrors('clock');

    $this->inTenant($this->tenant, function (): void {
        $day = AttendanceDay::query()->sole();

        // 09:07 .. 18:03 is 536 minutes, minus the 60 minute break of one continuous entry.
        expect($day->status)->toBe(DayStatus::Present)
            ->and($day->worked_minutes)->toBe(476)
            ->and($day->late_minutes)->toBe(0)
            ->and($day->overtime_minutes)->toBe(3)
            ->and($day->scheduled_minutes)->toBe(480)
            ->and($day->is_closed)->toBeFalse()
            ->and(AttendanceEntry::query()->sole()->open_marker)->toBeNull();
    });
});

it('lets the database allow at most one open entry per employee', function () {
    $this->hrmAt('2026-03-02 09:00:00');
    $this->inTenant($this->tenant, function (): void {
        app(AttendanceManager::class)->clockIn($this->employee);

        expect(fn () => AttendanceEntry::query()->create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-03-02', 'clock_in_at' => now(), 'open_marker' => 1,
            'source' => 'manual',
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('refuses overlapping, future and inverted manual entries', function () {
    $this->hrmAt('2026-03-03 12:00:00');

    $this->inTenant($this->tenant, function (): void {
        $manager = app(AttendanceManager::class);
        $manager->record($this->employee, '2026-03-02 09:00', '2026-03-02 13:00', 'Missed punch', null);

        expect(fn () => $manager->record($this->employee, '2026-03-02 12:00', '2026-03-02 14:00', null, null))->toThrow(ValidationException::class)
            ->and(fn () => $manager->record($this->employee, '2026-03-02 15:00', '2026-03-02 14:00', null, null))->toThrow(ValidationException::class)
            ->and(fn () => $manager->record($this->employee, '2026-03-03 11:00', '2026-03-03 23:00', null, null))->toThrow(ValidationException::class)
            ->and(fn () => $manager->record($this->employee, '2026-03-01 09:00', '2026-03-02 12:00', null, null))->toThrow(ValidationException::class);

        $manager->record($this->employee, '2026-03-02 14:00', '2026-03-02 18:00', null, null);

        $day = AttendanceDay::query()->where('work_date', '2026-03-02')->sole();
        expect($day->worked_minutes)->toBe(480)->and($day->entries_count)->toBe(2);
    });
});

it('closes finished days once, with weekly off, holiday and absent statuses and one event per day', function () {
    Event::fake([AttendanceDayClosed::class]);
    $this->inTenant($this->tenant, fn () => Holiday::query()->create(['name' => 'Language Day', 'holiday_on' => '2026-02-21', 'type' => 'public']));
    $idle = $this->hire($this->tenant, ['first_name' => 'Idle', 'joined_on' => '2026-02-19']);

    // Thursday 2026-02-19 is a normal day; Friday and Saturday are the default weekly offs;
    // the holiday is a Saturday too, so move it to a weekday.
    $this->inTenant($this->tenant, fn () => Holiday::query()->where('holiday_on', '2026-02-21')->update(['holiday_on' => '2026-02-23']));

    $this->hrmAt('2026-02-19 09:00:00');
    $this->inTenant($this->tenant, fn () => app(AttendanceManager::class)->clockIn($this->employee));
    $this->hrmAt('2026-02-19 17:30:00');
    $this->inTenant($this->tenant, fn () => app(AttendanceManager::class)->clockOut($this->employee));

    // Run on the morning of Tuesday 2026-02-24.
    $this->hrmAt('2026-02-24 00:30:00');
    $closed = $this->inTenant($this->tenant, fn (): int => app(AttendanceCloser::class)->run());
    $again = $this->inTenant($this->tenant, fn (): int => app(AttendanceCloser::class)->run());

    expect($again)->toBe(0)->and($closed)->toBeGreaterThan(0);

    $this->inTenant($this->tenant, function () use ($idle): void {
        $status = fn (string $employeeId, string $date): string => AttendanceDay::query()
            ->where('employee_id', $employeeId)->where('work_date', $date)->sole()->status->value;

        expect($status($this->employee->id, '2026-02-19'))->toBe('present')
            ->and($status($idle->id, '2026-02-19'))->toBe('absent')
            ->and($status($idle->id, '2026-02-20'))->toBe('weekly_off')
            ->and($status($idle->id, '2026-02-21'))->toBe('weekly_off')
            ->and($status($idle->id, '2026-02-22'))->toBe('absent')
            ->and($status($idle->id, '2026-02-23'))->toBe('holiday')
            ->and(AttendanceDay::query()->where('employee_id', $idle->id)->where('is_closed', false)->count())->toBe(0)
            ->and(AttendanceDay::query()->where('employee_id', $idle->id)->count())->toBe(5);
    });

    // Closing again posted nothing: one event per employee-day, none on the second run.
    Event::assertDispatchedTimes(AttendanceDayClosed::class, $closed);
});

it('is safe to run the scheduled job twice and through the queue job', function () {
    Event::fake([AttendanceDayClosed::class]);
    $this->hrmAt('2026-03-04 00:10:00');

    $this->inTenant($this->tenant, function (): void {
        app()->call([new CloseAttendanceDays, 'handle']);
        $first = AttendanceDay::query()->count();
        app()->call([new CloseAttendanceDays, 'handle']);

        expect(AttendanceDay::query()->count())->toBe($first)->and($first)->toBeGreaterThan(0)
            ->and(AttendanceDay::query()->where('revision', '>', 1)->count())->toBe(0);
    });
});

it('does not close a day while someone is still clocked in on an overnight shift', function () {
    $this->inTenant($this->tenant, function (): void {
        app(ShiftManager::class)->save($this->shift, ['name' => 'Day', 'starts_at' => '09:00', 'ends_at' => '18:00', 'is_default' => false]);
    });
    $night = $this->hrmShift($this->tenant, ['name' => 'Night', 'starts_at' => '22:00', 'ends_at' => '06:00', 'break_minutes' => 0, 'is_default' => true]);

    $this->hrmAt('2026-03-02 22:05:00');
    $this->inTenant($this->tenant, fn () => app(AttendanceManager::class)->clockIn($this->employee));

    // 00:30 the next day: the day of the 2nd is still open for this employee.
    $this->hrmAt('2026-03-03 00:30:00');
    $this->inTenant($this->tenant, function (): void {
        app(AttendanceCloser::class)->closeEmployee($this->employee, '2026-03-02');
        expect(AttendanceDay::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-02')->sole()->is_closed)->toBeFalse();
    });

    // A clock-in after midnight belongs to the 2nd's work date as well.
    $this->hrmAt('2026-03-03 06:20:00');
    $this->inTenant($this->tenant, function (): void {
        app(AttendanceManager::class)->clockOut($this->employee);
        $day = AttendanceDay::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-02')->sole();

        expect($day->worked_minutes)->toBe(495)->and($day->late_minutes)->toBe(0)->and($day->overtime_minutes)->toBe(20);
    });

    expect($night->crossesMidnight())->toBeTrue();
});

it('shows approved leave on the closed day and recomputes closed days when leave is approved later', function () {
    $type = $this->hrmLeaveType($this->tenant, ['approval_flow' => 'hr_only', 'tracks_balance' => false]);
    $this->hrmAt('2026-03-04 00:30:00');

    $this->inTenant($this->tenant, function () use ($type): void {
        app(AttendanceCloser::class)->closeEmployee($this->employee, '2026-03-03');
        $day = AttendanceDay::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-03')->sole();
        expect($day->status)->toBe(DayStatus::Absent)->and($day->revision)->toBe(1);

        $request = app(LeaveManager::class)->submit($this->employee, [
            'leave_type_id' => $type->id, 'starts_on' => '2026-03-03', 'ends_on' => '2026-03-03',
        ]);

        $owner = User::query()->find($this->ownerOf($this->tenant)->id);
        $ownerMembership = $this->membershipOf($this->tenant, $owner);
        $request->load('employee', 'leaveType');
        app(LeaveManager::class)->decide($request, $owner, true, null, $ownerMembership);

        $day = $day->refresh();
        expect($day->status)->toBe(DayStatus::OnLeave)->and($day->leave_units)->toBe(2)->and($day->revision)->toBe(2);
    });
});

it('assigns shifts without overlap and shortens the earlier assignment', function () {
    $evening = $this->hrmShift($this->tenant, ['name' => 'Evening', 'starts_at' => '14:00', 'ends_at' => '22:00', 'is_default' => false]);

    $this->inTenant($this->tenant, function () use ($evening): void {
        $manager = app(ShiftManager::class);
        $first = $manager->assign($this->employee, $this->shift, '2026-03-01', null);
        $second = $manager->assign($this->employee, $evening, '2026-03-16', null);

        expect($first->refresh()->ends_on->toDateString())->toBe('2026-03-15')
            ->and($second->ends_on)->toBeNull()
            ->and(fn () => $manager->assign($this->employee, $this->shift, '2026-03-10', '2026-03-20'))->toThrow(ValidationException::class)
            ->and(ShiftAssignment::query()->count())->toBe(2);
    });
});

it('approves a regularization into a recorded entry and refuses to decide your own', function () {
    $this->hrmAt('2026-03-04 10:00:00');
    [$managerUser, $managerEmployee] = $this->hrmStaff($this->tenant, [
        'hrm.self.view', 'hrm.attendance.view', 'hrm.attendance.approve',
    ], ['hrm.attendance.view' => 'own', 'hrm.attendance.approve' => 'own'], ['first_name' => 'Boss']);
    $this->inTenant($this->tenant, fn () => app(EmployeeManager::class)->update($this->employee, ['manager_id' => $managerEmployee->id]));

    $this->inTenant($this->tenant, function (): void {
        $this->correction = app(RegularizationManager::class)->request($this->employee, '2026-03-03', '2026-03-03 09:05', '2026-03-03 18:10', 'Forgot to punch', $this->membership);
        expect($this->correction->status)->toBe(RegularizationStatus::Pending)
            ->and(fn () => app(RegularizationManager::class)->request($this->employee, '2026-03-03', '2026-03-03 09:00', '2026-03-03 10:00', 'Again', $this->membership))
            ->toThrow(ValidationException::class);
    });

    // The employee cannot decide their own correction.
    $this->actingAsMember($this->tenant, $this->user)
        ->post($this->hrmUrl($this->tenant, "/attendance/corrections/{$this->correction->id}/decision"), ['decision' => 'approve'])
        ->assertForbidden();

    // Their reporting manager can, even though the record scope of `approve` is only `own`.
    $this->actingAsMember($this->tenant, $managerUser)
        ->post($this->hrmUrl($this->tenant, "/attendance/corrections/{$this->correction->id}/decision"), ['decision' => 'approve'])
        ->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        expect($this->correction->refresh()->status)->toBe(RegularizationStatus::Approved);
        $day = AttendanceDay::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-03')->sole();

        // 09:05 .. 18:10 is 545 minutes, less the 60 minute break of one continuous entry.
        expect($day->worked_minutes)->toBe(485)
            ->and($day->late_minutes)->toBe(0)
            ->and(AttendanceEntry::query()->where('employee_id', $this->employee->id)->sole()->source)->toBe(AttendanceSource::Regularization)
            ->and(Regularization::query()->where('status', RegularizationStatus::Pending)->count())->toBe(0);
    });
});
