<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Hrm\Enums\ApprovalStep;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\LeaveApprovalFlow;
use App\Modules\Hrm\Enums\LeaveSession;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Events\LeaveApproved;
use App\Modules\Hrm\Events\LeaveRequested;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveApproval;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\HrmPermissions;
use App\Modules\Hrm\Support\LeaveCalculator;
use App\Modules\Hrm\Support\LeaveUnits;
use App\Modules\Hrm\Support\WorkCalendars;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The leave request workflow.
 *
 * A request reserves its units in the ledger when it is submitted (so pending requests
 * count against the balance and an over-balance request is refused), is decided in one or
 * two steps depending on the leave type's flow, and gives its units back when rejected
 * or cancelled. Overlapping requests are refused at half-day precision.
 */
final class LeaveManager
{
    /** How far back a request may start. */
    private const int BACKDATE_DAYS = 31;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly HrmSettings $settings,
        private readonly WorkCalendars $calendars,
        private readonly LeavePolicies $policies,
        private readonly LeaveBalances $balances,
        private readonly EmployeeResolver $resolver,
        private readonly AttendanceManager $attendance,
    ) {}

    /**
     * @param  array{leave_type_id: string, starts_on: string, ends_on: string, start_session?: string|null, end_session?: string|null, reason?: string|null}  $input
     */
    public function submit(Employee $employee, array $input, ?TenantMembership $actor = null): LeaveRequest
    {
        $request = DB::transaction(function () use ($employee, $input, $actor): LeaveRequest {
            /** @var Employee $locked */
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isWorking()) {
                throw ValidationException::withMessages(['employee_id' => __('Leave can only be requested for active employees.')]);
            }

            $type = LeaveType::query()->find($input['leave_type_id']);

            if ($type === null || ! $type->is_active) {
                throw ValidationException::withMessages(['leave_type_id' => __('The selected leave type is not available.')]);
            }

            $startSession = LeaveSession::tryFrom((string) ($input['start_session'] ?? 'full')) ?? LeaveSession::Full;
            $endSession = LeaveSession::tryFrom((string) ($input['end_session'] ?? 'full')) ?? LeaveSession::Full;
            $from = $input['starts_on'];
            $to = $input['ends_on'];
            $today = $this->settings->today();

            if ($to < $from) {
                throw ValidationException::withMessages(['ends_on' => __('The end date cannot be before the start date.')]);
            }

            if ($from < $locked->joined_on->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => __('Leave cannot start before the joining date.')]);
            }

            if ($from < $today->subDays(self::BACKDATE_DAYS)->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => __('Leave cannot start more than a month in the past.')]);
            }

            if ($type->min_notice_days > 0 && $from < $today->addDays($type->min_notice_days)->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => __('This leave type needs more notice.')]);
            }

            if (! $type->allows_half_day && ($startSession !== LeaveSession::Full || $endSession !== LeaveSession::Full)) {
                throw ValidationException::withMessages(['start_session' => __('This leave type does not allow half days.')]);
            }

            $reason = isset($input['reason']) ? trim((string) $input['reason']) : null;
            $reason = $reason === '' ? null : $reason;

            if ($type->requires_reason && $reason === null) {
                throw ValidationException::withMessages(['reason' => __('A reason is required for this leave type.')]);
            }

            $span = CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) + 1;

            if ($type->max_consecutive_days !== null && $span > $type->max_consecutive_days) {
                throw ValidationException::withMessages(['ends_on' => __('This leave type allows at most :days consecutive days.', ['days' => $type->max_consecutive_days])]);
            }

            if ($span > 366) {
                throw ValidationException::withMessages(['ends_on' => __('A leave request cannot be longer than a year.')]);
            }

            $policy = $this->policies->forEmployee($locked, $type);
            $calendar = $this->calendars->forEmployee($locked->id, $from, $to);

            try {
                $calculation = LeaveCalculator::calculate($calendar, $from, $to, $startSession, $endSession, $policy !== null && $policy->sandwich_rule);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['start_session' => __($exception->getMessage())]);
            }

            $units = $calculation['units'];

            if ($units < 1) {
                throw ValidationException::withMessages(['starts_on' => __('The selected dates contain no working day.')]);
            }

            if ($policy?->max_units_per_request !== null && $units > $policy->max_units_per_request) {
                throw ValidationException::withMessages(['ends_on' => __('This request is longer than the policy allows.')]);
            }

            $slots = self::slots($calculation['days'], $from, $to, $startSession, $endSession);
            $this->ensureNoOverlap($locked, $from, $to, $slots);

            $year = $this->settings->leaveYear($from);

            if ($type->tracks_balance) {
                $this->balances->ensureAvailable($locked->id, $type->id, $year, $units);
            }

            $status = $this->firstStatus($locked, $type);

            $request = (new LeaveRequest([
                'leave_type_id' => $type->id,
                'starts_on' => $from,
                'ends_on' => $to,
                'start_session' => $startSession,
                'end_session' => $from === $to ? $startSession : $endSession,
                'units' => $units,
                'days' => $calculation['days'],
                'leave_year' => $year,
                'status' => $status,
                'reason' => $reason,
                'submitted_by' => $actor?->id,
            ]))->placeFor($locked);
            $request->save();

            if ($type->tracks_balance) {
                $this->balances->append($locked->id, $type->id, $year, -$units, LedgerKind::Request, $from, 'request:'.$request->id, $request->id, null, $actor?->id);
            }

            $this->audit->record('hrm.leave.requested', $request, ['employee_id' => $locked->id, 'units' => $units, 'status' => $status->value]);
            LeaveRequested::dispatch([
                'leave_request_id' => $request->id,
                'employee_id' => $locked->id,
                'membership_id' => $locked->owner_id,
                'leave_type_id' => $type->id,
                'starts_on' => $from,
                'ends_on' => $to,
                'units' => $units,
                'status' => $status->value,
            ]);

            return $request;
        });

        return $request->load('leaveType');
    }

    /**
     * Which approval step the person may decide on this request right now, or null.
     *
     * The reporting manager (holding `hrm.leave.approve`) decides the manager step; a person
     * holding `hrm.leave.finalize` over the record decides the HR step and may also decide the
     * manager step in the manager's place. Nobody decides their own request.
     */
    public function stepFor(User $actor, LeaveRequest $request): ?ApprovalStep
    {
        $employee = $request->employee;

        if ($this->resolver->isSelf($actor, $employee)) {
            return null;
        }

        $hr = $this->records->allows($actor, HrmPermissions::LEAVE_FINALIZE, $request);

        return match ($request->status) {
            LeaveStatus::PendingManager => $this->access->allows($actor, HrmPermissions::LEAVE_APPROVE) && $this->resolver->isManagerOf($actor, $employee)
                ? ApprovalStep::Manager
                : ($hr ? ApprovalStep::Hr : null),
            LeaveStatus::PendingHr => $hr ? ApprovalStep::Hr : null,
            default => null,
        };
    }

    public function decide(LeaveRequest $request, User $actor, bool $approve, ?string $note, ?TenantMembership $membership = null): LeaveRequest
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! $approve && $note === null) {
            throw ValidationException::withMessages(['note' => __('Say why the request is rejected.')]);
        }

        return DB::transaction(function () use ($request, $actor, $approve, $note, $membership): LeaveRequest {
            /** @var LeaveRequest $locked */
            $locked = LeaveRequest::query()->with(['employee', 'leaveType'])->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ValidationException::withMessages(['status' => __('This request has already been decided.')]);
            }

            $step = $this->stepFor($actor, $locked);

            if ($step === null) {
                abort(404);
            }

            $now = CarbonImmutable::now('UTC');
            LeaveApproval::query()->create([
                'leave_request_id' => $locked->id,
                'step' => $step,
                'decision' => $approve ? 'approved' : 'rejected',
                'decided_by' => $membership?->id,
                'note' => $note,
                'decided_at' => $now,
            ]);

            if (! $approve) {
                $locked->update(['status' => LeaveStatus::Rejected, 'decided_at' => $now]);
                $this->release($locked, $membership?->id, 'rejection');
                $this->audit->record('hrm.leave.rejected', $locked, ['step' => $step->value, 'note' => $note]);

                return $locked;
            }

            $final = $step === ApprovalStep::Hr
                || $locked->leaveType->approval_flow === LeaveApprovalFlow::ManagerOnly;

            if (! $final) {
                $locked->update(['status' => LeaveStatus::PendingHr]);
                $this->audit->record('hrm.leave.manager_approved', $locked, ['note' => $note]);

                return $locked;
            }

            $locked->update(['status' => LeaveStatus::Approved, 'decided_at' => $now]);
            $this->audit->record('hrm.leave.approved', $locked, ['step' => $step->value, 'units' => $locked->units]);
            $this->announceApproved($locked);
            $this->refreshAttendance($locked);

            return $locked;
        });
    }

    /** May the person cancel this request? Own pending / not yet started, or anyone with `hrm.leave.manage` over it. */
    public function mayCancel(User $actor, LeaveRequest $request): bool
    {
        if (! $request->status->isLive()) {
            return false;
        }

        if ($this->records->allows($actor, HrmPermissions::LEAVE_MANAGE, $request)) {
            return true;
        }

        if (! $this->resolver->isSelf($actor, $request->employee)) {
            return false;
        }

        return $request->status->isPending() || $request->starts_on->toDateString() > $this->settings->today()->toDateString();
    }

    public function cancel(LeaveRequest $request, User $actor, ?string $reason, ?TenantMembership $membership = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason, $membership): LeaveRequest {
            /** @var LeaveRequest $locked */
            $locked = LeaveRequest::query()->with(['employee', 'leaveType'])->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isLive()) {
                throw ValidationException::withMessages(['status' => __('This request can no longer be cancelled.')]);
            }

            if (! $this->mayCancel($actor, $locked)) {
                abort(404);
            }

            $this->cancelInternal($locked, $membership?->id, $reason);

            return $locked;
        });
    }

    /** Cancel every live request that reaches past the employee's last working day. */
    public function cancelAfterLastWorkingDay(Employee $employee, ?TenantMembership $actor): void
    {
        $last = $employee->last_working_on?->toDateString() ?? $employee->terminated_on?->toDateString();

        if ($last === null) {
            return;
        }

        LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr, LeaveStatus::Approved])
            ->where('ends_on', '>', $last)
            ->get()
            ->each(fn (LeaveRequest $request) => $this->cancelInternal($request, $actor?->id, 'Employment ended'));
    }

    /**
     * The half-day slots (`date:a` first half, `date:b` second half) a request occupies.
     *
     * @param  array<string, int>  $days
     * @return list<string>
     */
    public static function slots(array $days, string $from, string $to, LeaveSession $start, LeaveSession $end): array
    {
        $slots = [];

        foreach ($days as $date => $units) {
            if ($units >= LeaveUnits::PER_DAY) {
                $slots[] = $date.':a';
                $slots[] = $date.':b';

                continue;
            }

            $session = $date === $from ? $start : $end;
            $slots[] = $date.($session === LeaveSession::SecondHalf ? ':b' : ':a');
        }

        return $slots;
    }

    /** @param list<string> $slots */
    private function ensureNoOverlap(Employee $employee, string $from, string $to, array $slots): void
    {
        $others = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [LeaveStatus::PendingManager, LeaveStatus::PendingHr, LeaveStatus::Approved])
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->get();

        foreach ($others as $other) {
            $theirs = self::slots($other->days, $other->starts_on->toDateString(), $other->ends_on->toDateString(), $other->start_session, $other->end_session);

            if (array_intersect($slots, $theirs) !== []) {
                throw ValidationException::withMessages(['starts_on' => __('This overlaps another leave request.')]);
            }
        }
    }

    private function firstStatus(Employee $employee, LeaveType $type): LeaveStatus
    {
        if ($type->approval_flow === LeaveApprovalFlow::HrOnly) {
            return LeaveStatus::PendingHr;
        }

        $manager = $employee->manager_id === null ? null : Employee::query()->find($employee->manager_id);

        // A manager who has no workspace account (or left) cannot decide: go straight to HR.
        if ($manager === null || $manager->owner_id === null || $manager->status === EmployeeStatus::Terminated) {
            return LeaveStatus::PendingHr;
        }

        return LeaveStatus::PendingManager;
    }

    private function cancelInternal(LeaveRequest $request, ?string $actorId, ?string $reason): void
    {
        $wasApproved = $request->status === LeaveStatus::Approved;
        $request->update([
            'status' => LeaveStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now('UTC'),
            'cancelled_by' => $actorId,
            'cancellation_reason' => $reason,
        ]);
        $this->release($request, $actorId, 'cancellation');
        $this->audit->record('hrm.leave.cancelled', $request, ['reason' => $reason, 'was_approved' => $wasApproved]);

        if ($wasApproved) {
            $this->refreshAttendance($request);
        }
    }

    /** Give the reserved units back. Keyed on the request, so it can happen once. */
    private function release(LeaveRequest $request, ?string $actorId, string $why): void
    {
        $type = $request->leaveType;

        if (! $type->tracks_balance) {
            return;
        }

        $this->balances->append(
            $request->employee_id,
            $type->id,
            $request->leave_year,
            $request->units,
            LedgerKind::Reversal,
            $this->settings->today()->toDateString(),
            'reversal:'.$request->id,
            $request->id,
            $why,
            $actorId,
        );
    }

    private function announceApproved(LeaveRequest $request): void
    {
        LeaveApproved::dispatch([
            'leave_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'membership_id' => $request->owner_id,
            'leave_type_id' => $request->leave_type_id,
            'leave_type_code' => $request->leaveType->code,
            'is_paid' => $request->leaveType->is_paid,
            'starts_on' => $request->starts_on->toDateString(),
            'ends_on' => $request->ends_on->toDateString(),
            'units' => $request->units,
        ]);
    }

    /** Days already closed must show the leave (or no longer show it). */
    private function refreshAttendance(LeaveRequest $request): void
    {
        $yesterday = $this->settings->today()->subDay()->toDateString();
        $to = min($request->ends_on->toDateString(), $yesterday);
        $from = $request->starts_on->toDateString();

        if ($from > $to) {
            return;
        }

        $this->attendance->refreshClosedDays($request->employee, $from, $to);
    }
}
