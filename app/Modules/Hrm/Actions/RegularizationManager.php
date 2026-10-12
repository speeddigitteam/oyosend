<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Hrm\Enums\AttendanceSource;
use App\Modules\Hrm\Enums\RegularizationStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Support\HrmPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attendance corrections. An employee asks for a day to be corrected with the times
 * they worked; an approver (the reporting manager, or anyone holding
 * `hrm.attendance.approve` over the record) approves or rejects. Approval records an
 * attendance entry of source `regularization` and recomputes the day.
 */
final class RegularizationManager
{
    /** How many days back a correction may be requested. */
    public const int WINDOW_DAYS = 31;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HrmSettings $settings,
        private readonly AttendanceManager $attendance,
        private readonly EmployeeResolver $resolver,
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
    ) {}

    public function request(Employee $employee, string $workDate, string $clockIn, string $clockOut, string $reason, ?TenantMembership $actor = null): Regularization
    {
        $timezone = $this->settings->timezone();
        $today = $this->settings->today();

        if ($workDate > $today->toDateString() || $workDate < $today->subDays(self::WINDOW_DAYS)->toDateString()) {
            throw ValidationException::withMessages(['work_date' => __('A correction can only be requested for the last :days days.', ['days' => self::WINDOW_DAYS])]);
        }

        try {
            $in = CarbonImmutable::parse($clockIn, $timezone)->utc()->startOfMinute();
            $out = CarbonImmutable::parse($clockOut, $timezone)->utc()->startOfMinute();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['clock_in_at' => __('The date and time are not valid.')]);
        }

        if ($out <= $in) {
            throw ValidationException::withMessages(['clock_out_at' => __('The clock-out must be after the clock-in.')]);
        }

        if ($out > CarbonImmutable::now('UTC')) {
            throw ValidationException::withMessages(['clock_out_at' => __('Attendance cannot be recorded in the future.')]);
        }

        if (intdiv($out->getTimestamp() - $in->getTimestamp(), 60) > AttendanceManager::MAX_ENTRY_MINUTES) {
            throw ValidationException::withMessages(['clock_out_at' => __('An entry cannot be longer than 24 hours.')]);
        }

        return DB::transaction(function () use ($employee, $workDate, $in, $out, $reason, $actor): Regularization {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $pending = Regularization::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $workDate)
                ->where('status', RegularizationStatus::Pending)
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages(['work_date' => __('There is already a pending correction for this day.')]);
            }

            $regularization = (new Regularization([
                'work_date' => $workDate,
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'reason' => trim($reason),
                'status' => RegularizationStatus::Pending,
                'requested_by' => $actor?->id,
            ]))->placeFor($employee);
            $regularization->save();

            return $regularization;
        });
    }

    /** Which requests the person may decide: scope over the record, or being the reporting manager. */
    public function mayDecide(User $actor, Regularization $regularization): bool
    {
        if ($this->resolver->isSelf($actor, $regularization->employee)) {
            return false;
        }

        // Whoever filed the request (for example HR on someone's behalf) cannot also approve it.
        if ($regularization->requested_by !== null && $regularization->requested_by === $this->access->membership($actor)?->id) {
            return false;
        }

        return $this->resolver->mayActOn($actor, HrmPermissions::ATTENDANCE_APPROVE, $regularization->employee, managerCounts: true)
            || $this->records->allows($actor, HrmPermissions::ATTENDANCE_APPROVE, $regularization);
    }

    public function decide(Regularization $regularization, User $actor, bool $approve, ?string $note, ?TenantMembership $membership = null): Regularization
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! $approve && $note === null) {
            throw ValidationException::withMessages(['note' => __('Say why the correction is rejected.')]);
        }

        return DB::transaction(function () use ($regularization, $actor, $approve, $note, $membership): Regularization {
            /** @var Regularization $locked */
            $locked = Regularization::query()->with('employee')->whereKey($regularization->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RegularizationStatus::Pending) {
                throw ValidationException::withMessages(['status' => __('This correction has already been decided.')]);
            }

            if (! $this->mayDecide($actor, $locked)) {
                abort(404);
            }

            if ($approve) {
                $this->attendance->recordInstants(
                    $locked->employee,
                    $locked->clock_in_at->toImmutable(),
                    $locked->clock_out_at->toImmutable(),
                    'Regularization: '.$locked->reason,
                    $membership,
                    AttendanceSource::Regularization,
                );
            }

            $locked->update([
                'status' => $approve ? RegularizationStatus::Approved : RegularizationStatus::Rejected,
                'decided_by' => $membership?->id,
                'decided_at' => CarbonImmutable::now('UTC'),
                'decision_note' => $note,
            ]);
            $this->audit->record($approve ? 'hrm.regularization.approved' : 'hrm.regularization.rejected', $locked, [
                'work_date' => $locked->work_date->toDateString(),
                'note' => $note,
            ]);

            return $locked;
        });
    }

    public function cancel(Regularization $regularization, User $actor): Regularization
    {
        return DB::transaction(function () use ($regularization, $actor): Regularization {
            /** @var Regularization $locked */
            $locked = Regularization::query()->with('employee')->whereKey($regularization->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RegularizationStatus::Pending) {
                throw ValidationException::withMessages(['status' => __('This correction has already been decided.')]);
            }

            if (! $this->resolver->isSelf($actor, $locked->employee)
                && ! $this->records->allows($actor, HrmPermissions::ATTENDANCE_MANAGE, $locked)) {
                abort(404);
            }

            $locked->update(['status' => RegularizationStatus::Cancelled, 'decided_at' => CarbonImmutable::now('UTC')]);
            $this->audit->record('hrm.regularization.cancelled', $locked, ['work_date' => $locked->work_date->toDateString()]);

            return $locked;
        });
    }
}
