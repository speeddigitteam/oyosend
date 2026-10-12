<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Hrm\Enums\AttendanceSource;
use App\Modules\Hrm\Enums\DayStatus;
use App\Modules\Hrm\Events\AttendanceDayClosed;
use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\AttendanceMath;
use App\Modules\Hrm\Support\WorkCalendars;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clock-ins and outs, manual entries and the computed daily record.
 *
 * Server time is the only clock: `clockIn()` and `clockOut()` take no timestamp. A person
 * with an entry still open cannot start another one (and the database enforces it with a
 * unique `open_marker`). Manual and regularized entries are entered in workspace local
 * time, must lie in the past, must not overlap another entry and are audited.
 */
final class AttendanceManager
{
    /** An entry cannot span more than this many minutes. */
    public const int MAX_ENTRY_MINUTES = 1440;

    /** After a night shift ends, a clock-in still belongs to the previous work date for this long. */
    private const int NIGHT_SHIFT_GRACE_MINUTES = 120;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HrmSettings $settings,
        private readonly WorkCalendars $calendars,
        private readonly LeaveDays $leaveDays,
    ) {}

    public function clockIn(Employee $employee, ?string $note = null, ?TenantMembership $actor = null): AttendanceEntry
    {
        $now = CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($employee, $note, $actor, $now): AttendanceEntry {
            $locked = $this->lockWorking($employee);

            if (AttendanceEntry::query()->where('employee_id', $locked->id)->whereNull('clock_out_at')->exists()) {
                throw ValidationException::withMessages(['clock' => __('You are already clocked in. Clock out first, or ask for a correction.')]);
            }

            $date = $this->workDateFor($locked, $now);

            if ($date < $locked->joined_on->toDateString()) {
                throw ValidationException::withMessages(['clock' => __('You cannot clock in before the joining date.')]);
            }

            $entry = (new AttendanceEntry([
                'work_date' => $date,
                'clock_in_at' => $now,
                'open_marker' => 1,
                'source' => AttendanceSource::Clock,
                'note' => $note,
                'created_by' => $actor?->id,
            ]))->placeFor($locked);
            $entry->save();

            $this->recompute($locked, $date);

            return $entry;
        });
    }

    public function clockOut(Employee $employee, ?TenantMembership $actor = null): AttendanceEntry
    {
        $now = CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($employee, $now): AttendanceEntry {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            /** @var AttendanceEntry|null $entry */
            $entry = AttendanceEntry::query()->where('employee_id', $locked->id)->whereNull('clock_out_at')->lockForUpdate()->first();

            if ($entry === null) {
                throw ValidationException::withMessages(['clock' => __('You are not clocked in.')]);
            }

            if (intdiv($now->getTimestamp() - $entry->clock_in_at->getTimestamp(), 60) > self::MAX_ENTRY_MINUTES) {
                throw ValidationException::withMessages(['clock' => __('This entry has been open for more than a day. Ask for a correction.')]);
            }

            $entry->update(['clock_out_at' => $now, 'open_marker' => null]);
            $this->recompute($locked, $entry->work_date->toDateString());

            return $entry;
        });
    }

    /**
     * Record a finished interval entered by a person (HR, or an approved regularization).
     *
     * @param  string  $clockIn  local `Y-m-d H:i` (or ISO 8601 with an offset)
     */
    public function record(
        Employee $employee,
        string $clockIn,
        string $clockOut,
        ?string $note,
        ?TenantMembership $actor,
        AttendanceSource $source = AttendanceSource::Manual,
    ): AttendanceEntry {
        $timezone = $this->settings->timezone();
        $in = $this->parseLocal($clockIn, $timezone, 'clock_in_at');
        $out = $this->parseLocal($clockOut, $timezone, 'clock_out_at');

        return $this->recordInstants($employee, $in, $out, $note, $actor, $source);
    }

    public function recordInstants(
        Employee $employee,
        CarbonImmutable $in,
        CarbonImmutable $out,
        ?string $note,
        ?TenantMembership $actor,
        AttendanceSource $source,
    ): AttendanceEntry {
        $in = $in->utc()->startOfMinute();
        $out = $out->utc()->startOfMinute();
        $now = CarbonImmutable::now('UTC');

        if ($out <= $in) {
            throw ValidationException::withMessages(['clock_out_at' => __('The clock-out must be after the clock-in.')]);
        }

        if ($out > $now) {
            throw ValidationException::withMessages(['clock_out_at' => __('Attendance cannot be recorded in the future.')]);
        }

        if (intdiv($out->getTimestamp() - $in->getTimestamp(), 60) > self::MAX_ENTRY_MINUTES) {
            throw ValidationException::withMessages(['clock_out_at' => __('An entry cannot be longer than 24 hours.')]);
        }

        return DB::transaction(function () use ($employee, $in, $out, $note, $actor, $source): AttendanceEntry {
            /** @var Employee $locked */
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $date = $this->workDateFor($locked, $in);

            if ($date < $locked->joined_on->toDateString()) {
                throw ValidationException::withMessages(['clock_in_at' => __('The date is before the joining date.')]);
            }

            $overlap = AttendanceEntry::query()
                ->where('employee_id', $locked->id)
                ->where('clock_in_at', '<', $out)
                ->where(fn ($query) => $query->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $in))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['clock_in_at' => __('This overlaps another attendance entry.')]);
            }

            $entry = (new AttendanceEntry([
                'work_date' => $date,
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                'open_marker' => null,
                'source' => $source,
                'note' => $note,
                'created_by' => $actor?->id,
            ]))->placeFor($locked);
            $entry->save();

            $this->audit->record('hrm.attendance.entry_recorded', $locked, [
                'work_date' => $date,
                'source' => $source->value,
                'minutes' => intdiv($out->getTimestamp() - $in->getTimestamp(), 60),
            ]);

            $this->recompute($locked, $date);

            return $entry;
        });
    }

    public function deleteEntry(AttendanceEntry $entry, ?TenantMembership $actor = null): void
    {
        DB::transaction(function () use ($entry): void {
            /** @var Employee $employee */
            $employee = Employee::query()->whereKey($entry->employee_id)->lockForUpdate()->firstOrFail();
            $date = $entry->work_date->toDateString();
            $this->audit->record('hrm.attendance.entry_deleted', $employee, [
                'work_date' => $date,
                'source' => $entry->source->value,
                'clock_in_at' => $entry->clock_in_at->toIso8601String(),
                'clock_out_at' => $entry->clock_out_at?->toIso8601String(),
            ]);
            $entry->delete();
            $this->recompute($employee, $date);
        });
    }

    /**
     * Compute (and store) the day from its entries, the shift, holidays and approved leave.
     * Pass `$close` to finalize the day; an already closed day is recomputed and its
     * `revision` increases, which is announced again.
     */
    public function recompute(Employee $employee, string $date, bool $close = false): AttendanceDay
    {
        $result = $this->evaluate($employee, $date);

        $existing = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date)->first();
        $wasClosed = $existing !== null && $existing->is_closed;
        $previousRevision = $existing === null ? 0 : $existing->revision;
        $previousClosedAt = $existing === null ? null : $existing->closed_at;

        $attributes = [
            'shift_id' => $result['shift_id'],
            'status' => $result['status'],
            'scheduled_minutes' => $result['scheduled_minutes'],
            'worked_minutes' => $result['worked_minutes'],
            'late_minutes' => $result['late_minutes'],
            'early_leave_minutes' => $result['early_leave_minutes'],
            'overtime_minutes' => $result['overtime_minutes'],
            'first_in_at' => $result['first_in_at'],
            'last_out_at' => $result['last_out_at'],
            'entries_count' => $result['entries_count'],
            'leave_units' => $result['leave_units'],
        ];

        $closing = $close || $wasClosed;

        if ($closing) {
            $attributes['is_closed'] = true;
            $attributes['closed_at'] = $wasClosed ? $previousClosedAt : CarbonImmutable::now('UTC');
            $attributes['revision'] = $wasClosed ? $previousRevision + 1 : 1;
        }

        if ($existing === null) {
            $day = new AttendanceDay($attributes);
            $day->setAttribute('work_date', $date);
            $day->placeFor($employee);

            try {
                $day->save();
            } catch (UniqueConstraintViolationException) {
                // Another worker created the day between the read and the insert.
                $day = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date)->firstOrFail();
                $day->fill($attributes)->save();
            }
        } else {
            $day = $existing;
            $day->fill($attributes)->save();
        }

        if ($closing) {
            AttendanceDayClosed::dispatch([
                'attendance_day_id' => $day->id,
                'employee_id' => $employee->id,
                'membership_id' => $employee->owner_id,
                'work_date' => $date,
                'status' => $day->status->value,
                'scheduled_minutes' => $day->scheduled_minutes,
                'worked_minutes' => $day->worked_minutes,
                'late_minutes' => $day->late_minutes,
                'early_leave_minutes' => $day->early_leave_minutes,
                'overtime_minutes' => $day->overtime_minutes,
                'leave_units' => $day->leave_units,
                'revision' => $day->revision,
            ]);
        }

        return $day;
    }

    /** Close a day once. Returns null when it was already closed. */
    public function closeDay(Employee $employee, string $date): ?AttendanceDay
    {
        return DB::transaction(function () use ($employee, $date): ?AttendanceDay {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $closed = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date)->where('is_closed', true)->exists();

            if ($closed) {
                return null;
            }

            // Someone still clocked in (an overnight shift): leave the day for a later run. An entry
            // forgotten for more than a day closes as incomplete.
            $stillWorking = AttendanceEntry::query()
                ->where('employee_id', $employee->id)
                ->where('work_date', $date)
                ->whereNull('clock_out_at')
                ->where('clock_in_at', '>', CarbonImmutable::now('UTC')->subMinutes(self::MAX_ENTRY_MINUTES))
                ->exists();

            if ($stillWorking) {
                return null;
            }

            return $this->recompute($employee, $date, close: true);
        });
    }

    /** Recompute the already closed days in a range (after leave or holidays changed). */
    public function refreshClosedDays(Employee $employee, string $from, string $to): int
    {
        $dates = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->where('is_closed', true)
            ->whereBetween('work_date', [$from, $to])
            ->pluck('work_date');

        foreach ($dates as $date) {
            $this->recompute($employee, $date->toDateString());
        }

        return $dates->count();
    }

    /**
     * The local work date an instant belongs to: its local calendar date, or the previous
     * one when it falls in the tail of the previous day's overnight shift.
     */
    public function workDateFor(Employee $employee, CarbonImmutable $instant): string
    {
        $timezone = $this->settings->timezone();
        $local = $instant->setTimezone($timezone);
        $today = $local->toDateString();
        $yesterday = $local->subDay()->toDateString();

        $calendar = $this->calendars->forEmployee($employee->id, $yesterday, $today);
        $shift = $calendar->shiftOn($yesterday);

        if ($shift !== null && $shift->crossesMidnight() && ! $calendar->isWeeklyOff($yesterday) && ! $calendar->isHoliday($yesterday)) {
            $end = AttendanceMath::shiftEnd($yesterday, $timezone, $shift)->addMinutes(self::NIGHT_SHIFT_GRACE_MINUTES);

            if ($instant <= $end) {
                return $yesterday;
            }
        }

        return $today;
    }

    private function lockWorking(Employee $employee): Employee
    {
        /** @var Employee $locked */
        $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->isWorking()) {
            throw ValidationException::withMessages(['clock' => __('Attendance can only be recorded for active employees.')]);
        }

        return $locked;
    }

    /**
     * @return array{
     *     shift_id: string|null, status: DayStatus, scheduled_minutes: int, worked_minutes: int, late_minutes: int,
     *     early_leave_minutes: int, overtime_minutes: int, first_in_at: CarbonImmutable|null,
     *     last_out_at: CarbonImmutable|null, entries_count: int, leave_units: int
     * }
     */
    private function evaluate(Employee $employee, string $date): array
    {
        $entries = AttendanceEntry::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->orderBy('clock_in_at')
            ->get()
            ->map(static fn (AttendanceEntry $entry): array => [
                'in' => $entry->clock_in_at->toImmutable(),
                'out' => $entry->clock_out_at?->toImmutable(),
            ])
            ->all();

        $calendar = $this->calendars->forEmployee($employee->id, $date, $date);
        $shift = $calendar->shiftOn($date);
        $leaveUnits = $this->leaveDays->approved($employee->id, $date, $date)[$date] ?? 0;

        $math = AttendanceMath::evaluate(
            $date,
            $this->settings->timezone(),
            $shift,
            $entries,
            $calendar->isHoliday($date),
            $calendar->isWeeklyOff($date),
            $leaveUnits,
            $calendar->fallbackMinutes,
        );

        return [
            'shift_id' => $shift?->id,
            'status' => $math['status'],
            'scheduled_minutes' => $math['scheduled_minutes'],
            'worked_minutes' => $math['worked_minutes'],
            'late_minutes' => $math['late_minutes'],
            'early_leave_minutes' => $math['early_leave_minutes'],
            'overtime_minutes' => $math['overtime_minutes'],
            'first_in_at' => $math['first_in_at'],
            'last_out_at' => $math['last_out_at'],
            'entries_count' => $math['entries_count'],
            'leave_units' => $leaveUnits,
        ];
    }

    private function parseLocal(string $value, string $timezone, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('The date and time are not valid.')]);
        }
    }
}
