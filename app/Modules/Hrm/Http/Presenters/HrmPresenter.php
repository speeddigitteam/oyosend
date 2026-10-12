<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Presenters;

use App\Modules\Hrm\Models\AttendanceDay;
use App\Modules\Hrm\Models\AttendanceEntry;
use App\Modules\Hrm\Models\Designation;
use App\Modules\Hrm\Models\EmergencyContact;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAsset;
use App\Modules\Hrm\Models\EmployeeDocument;
use App\Modules\Hrm\Models\EmploymentHistory;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\LeaveApproval;
use App\Modules\Hrm\Models\LeavePolicy;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Regularization;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Models\ShiftAssignment;

/** The array shapes HRM pages receive. Keys are camelCase; dates are `Y-m-d`, instants ISO 8601 UTC. */
final class HrmPresenter
{
    /**
     * List/summary shape of an employee. Expects `department`, `branch`, `designation`, `manager` and
     * `owner.user` to be eager loaded.
     *
     * @return array<string, mixed>
     */
    public static function employeeRow(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'number' => $employee->employee_number,
            'name' => $employee->fullName(),
            'firstName' => $employee->first_name,
            'lastName' => $employee->last_name,
            'status' => $employee->status->value,
            'employmentType' => $employee->employment_type->value,
            'designation' => $employee->designation === null ? null : ['id' => $employee->designation->id, 'name' => $employee->designation->name],
            'department' => $employee->department === null ? null : ['id' => $employee->department->id, 'name' => $employee->department->name],
            'branch' => $employee->branch === null ? null : ['id' => $employee->branch->id, 'name' => $employee->branch->name],
            'managerId' => $employee->manager_id,
            'managerName' => $employee->manager?->fullName(),
            'joinedOn' => $employee->joined_on->toDateString(),
            'terminatedOn' => $employee->terminated_on?->toDateString(),
            'workEmail' => $employee->work_email,
            'phone' => $employee->phone,
            'hasAccount' => $employee->owner_id !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function designation(Designation $designation): array
    {
        return [
            'id' => $designation->id,
            'name' => $designation->name,
            'code' => $designation->code,
            'description' => $designation->description,
            'isActive' => $designation->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function emergencyContact(EmergencyContact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'relationship' => $contact->relationship,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'address' => $contact->address,
            'isPrimary' => $contact->is_primary,
        ];
    }

    /** @return array<string, mixed> */
    public static function document(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'title' => $document->title,
            'originalName' => $document->original_name,
            'mimeType' => $document->mime_type,
            'size' => $document->size,
            'expiresOn' => $document->expires_on?->toDateString(),
            'uploadedAt' => $document->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function asset(EmployeeAsset $asset, bool $withNotes = true): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'assetTag' => $asset->asset_tag,
            'category' => $asset->category,
            'assignedOn' => $asset->assigned_on->toDateString(),
            'returnedOn' => $asset->returned_on?->toDateString(),
            'returnCondition' => $asset->return_condition,
            'notes' => $withNotes ? $asset->notes : null,
            'isHeld' => $asset->isHeld(),
        ];
    }

    /** @return array<string, mixed> */
    public static function history(EmploymentHistory $entry, bool $withCost = true, bool $withReasons = true): array
    {
        $changes = $entry->changes;

        // Cost rate is guarded by hrm.personal.view; the ledger must not hand it to people without it.
        if (! $withCost) {
            $changes = array_diff_key($changes, ['cost_rate_amount' => true, 'cost_currency' => true]);
        }

        // Reasons and free-text notes are personal data: guarded by hrm.personal.view (or the person themselves).
        if (! $withReasons) {
            $changes = array_diff_key($changes, ['status_reason' => true, 'termination_reason' => true, 'notes' => true]);
        }

        return [
            'id' => $entry->id,
            'event' => $entry->event->value,
            'effectiveOn' => $entry->effective_on->toDateString(),
            'changes' => $changes,
            'reason' => $withReasons ? $entry->reason : null,
            'recordedAt' => $entry->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function holiday(Holiday $holiday): array
    {
        return ['id' => $holiday->id, 'name' => $holiday->name, 'date' => $holiday->holiday_on->toDateString(), 'type' => $holiday->type->value];
    }

    /** @return array<string, mixed> */
    public static function shift(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'startsAt' => $shift->starts_at,
            'endsAt' => $shift->ends_at,
            'crossesMidnight' => $shift->crossesMidnight(),
            'breakMinutes' => $shift->break_minutes,
            'lateGraceMinutes' => $shift->late_grace_minutes,
            'earlyGraceMinutes' => $shift->early_grace_minutes,
            'overtimeThresholdMinutes' => $shift->overtime_threshold_minutes,
            'halfDayMinutes' => $shift->half_day_minutes,
            'scheduledMinutes' => $shift->scheduledMinutes(),
            'weeklyOffDays' => $shift->weekly_off_days,
            'isDefault' => $shift->is_default,
            'isActive' => $shift->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function shiftAssignment(ShiftAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'employeeId' => $assignment->employee_id,
            'shiftId' => $assignment->shift_id,
            'shiftName' => $assignment->shift->name ?? null,
            'startsOn' => $assignment->starts_on->toDateString(),
            'endsOn' => $assignment->ends_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function day(AttendanceDay $day): array
    {
        return [
            'id' => $day->id,
            'date' => $day->work_date->toDateString(),
            'status' => $day->status->value,
            'scheduledMinutes' => $day->scheduled_minutes,
            'workedMinutes' => $day->worked_minutes,
            'lateMinutes' => $day->late_minutes,
            'earlyLeaveMinutes' => $day->early_leave_minutes,
            'overtimeMinutes' => $day->overtime_minutes,
            'firstInAt' => $day->first_in_at?->toIso8601String(),
            'lastOutAt' => $day->last_out_at?->toIso8601String(),
            'entriesCount' => $day->entries_count,
            'leaveUnits' => $day->leave_units,
            'isClosed' => $day->is_closed,
            'revision' => $day->revision,
        ];
    }

    /** @return array<string, mixed> */
    public static function entry(AttendanceEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->work_date->toDateString(),
            'clockInAt' => $entry->clock_in_at->toIso8601String(),
            'clockOutAt' => $entry->clock_out_at?->toIso8601String(),
            'source' => $entry->source->value,
            'note' => $entry->note,
        ];
    }

    /**
     * Expects `employee` loaded.
     *
     * @return array<string, mixed>
     */
    public static function correction(Regularization $correction): array
    {
        return [
            'id' => $correction->id,
            'employee' => ['id' => $correction->employee_id, 'name' => $correction->employee->fullName(), 'number' => $correction->employee->employee_number],
            'date' => $correction->work_date->toDateString(),
            'clockInAt' => $correction->clock_in_at->toIso8601String(),
            'clockOutAt' => $correction->clock_out_at->toIso8601String(),
            'reason' => $correction->reason,
            'status' => $correction->status->value,
            'decisionNote' => $correction->decision_note,
            'decidedAt' => $correction->decided_at?->toIso8601String(),
            'requestedAt' => $correction->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function leaveType(LeaveType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'isPaid' => $type->is_paid,
            'tracksBalance' => $type->tracks_balance,
            'allowsHalfDay' => $type->allows_half_day,
            'requiresReason' => $type->requires_reason,
            'maxConsecutiveDays' => $type->max_consecutive_days,
            'minNoticeDays' => $type->min_notice_days,
            'approvalFlow' => $type->approval_flow->value,
            'isActive' => $type->is_active,
            'sortOrder' => $type->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    public static function leavePolicy(LeavePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'leaveTypeId' => $policy->leave_type_id,
            'name' => $policy->name,
            'employmentTypes' => $policy->employment_types,
            'accrual' => $policy->accrual->value,
            'annualUnits' => $policy->annual_units,
            'carryForwardMaxUnits' => $policy->carry_forward_max_units,
            'maxBalanceUnits' => $policy->max_balance_units,
            'maxUnitsPerRequest' => $policy->max_units_per_request,
            'sandwichRule' => $policy->sandwich_rule,
            'minServiceDays' => $policy->min_service_days,
            'isActive' => $policy->is_active,
        ];
    }

    /**
     * Expects `employee`, `leaveType` and (for the timeline) `approvals` loaded.
     *
     * @return array<string, mixed>
     */
    public static function leaveRequest(LeaveRequest $request, bool $withApprovals = false): array
    {
        return [
            'id' => $request->id,
            'employee' => ['id' => $request->employee_id, 'name' => $request->employee->fullName(), 'number' => $request->employee->employee_number],
            'leaveType' => ['id' => $request->leave_type_id, 'name' => $request->leaveType->name, 'code' => $request->leaveType->code, 'isPaid' => $request->leaveType->is_paid],
            'startsOn' => $request->starts_on->toDateString(),
            'endsOn' => $request->ends_on->toDateString(),
            'startSession' => $request->start_session->value,
            'endSession' => $request->end_session->value,
            'units' => $request->units,
            'days' => $request->days,
            'leaveYear' => $request->leave_year,
            'status' => $request->status->value,
            'reason' => $request->reason,
            'requestedAt' => $request->created_at?->toIso8601String(),
            'decidedAt' => $request->decided_at?->toIso8601String(),
            'cancelledAt' => $request->cancelled_at?->toIso8601String(),
            'cancellationReason' => $request->cancellation_reason,
            'approvals' => $withApprovals
                ? $request->approvals->map(static fn (LeaveApproval $approval): array => [
                    'step' => $approval->step->value,
                    'decision' => $approval->decision,
                    'note' => $approval->note,
                    'decidedAt' => $approval->decided_at->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }
}
