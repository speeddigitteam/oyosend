<?php

declare(strict_types=1);

use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Actions\LeaveAccruals;
use App\Modules\Hrm\Actions\LeaveAdjustments;
use App\Modules\Hrm\Actions\LeaveBalances;
use App\Modules\Hrm\Actions\LeaveManager;
use App\Modules\Hrm\Enums\LeaveSession;
use App\Modules\Hrm\Enums\LeaveStatus;
use App\Modules\Hrm\Enums\LedgerKind;
use App\Modules\Hrm\Events\LeaveApproved;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Support\EmployeeCalendar;
use App\Modules\Hrm\Support\LeaveCalculator;
use App\Modules\Hrm\Support\WorkCalendars;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\InteractsWithHrm;

uses(InteractsWithHrm::class);

beforeEach(function () {
    $this->tenant = $this->hrmTenant('hrm-leave');
    $this->hrmAt('2026-03-02 10:00:00');
    $this->type = $this->hrmLeaveType($this->tenant);
    $this->policy = $this->hrmPolicy($this->tenant, $this->type, ['annual_units' => 20]);

    // Boss (manager, approves the manager step), HR (finalizes), and the employee.
    [$this->bossUser, $this->boss] = $this->hrmStaff($this->tenant, [
        'hrm.self.view', 'hrm.leave.view', 'hrm.leave.request', 'hrm.leave.approve',
    ], ['hrm.leave.view' => 'own'], ['first_name' => 'Boss']);
    [$this->hrUser, $this->hr] = $this->hrmStaff($this->tenant, [
        'hrm.self.view', 'hrm.leave.view', 'hrm.leave.request', 'hrm.leave.approve', 'hrm.leave.finalize', 'hrm.leave.manage',
    ], ['hrm.leave.view' => 'all', 'hrm.leave.finalize' => 'all', 'hrm.leave.manage' => 'all'], ['first_name' => 'Hana']);
    [$this->staffUser, $this->staff, $this->staffMembership] = $this->hrmStaff($this->tenant, [
        'hrm.self.view', 'hrm.leave.view', 'hrm.leave.request',
    ], ['hrm.leave.view' => 'own'], ['first_name' => 'Staff', 'manager_id' => $this->boss->id]);

    $this->balance = fn (?string $employeeId = null): int => $this->inTenant($this->tenant, fn (): int => app(LeaveBalances::class)
        ->balance($employeeId ?? $this->staff->id, $this->type->id, 2026));
    $this->submit = fn (array $input, ?object $employee = null): LeaveRequest => $this->inTenant($this->tenant, fn (): LeaveRequest => app(LeaveManager::class)
        ->submit($employee ?? $this->staff, ['leave_type_id' => $this->type->id, ...$input]));
    $this->decide = fn (LeaveRequest $request, $user, bool $approve = true, ?string $note = null) => $this->inTenant($this->tenant, function () use ($request, $user, $approve, $note): LeaveRequest {
        $request = LeaveRequest::query()->with(['employee', 'leaveType'])->findOrFail($request->id);

        return app(LeaveManager::class)->decide($request, $user, $approve, $note, $this->membershipOf($this->tenant, $user));
    });
});

it('counts leave units for working days, half days and the sandwich rule', function () {
    // The default calendar: Friday and Saturday are the weekly days off.
    $calendar = $this->inTenant($this->tenant, fn (): EmployeeCalendar => app(WorkCalendars::class)->forEmployee($this->staff->id, '2026-03-01', '2026-03-31'));
    $calc = fn (string $from, string $to, LeaveSession $start = LeaveSession::Full, LeaveSession $end = LeaveSession::Full, bool $sandwich = false): array => LeaveCalculator::calculate($calendar, $from, $to, $start, $end, $sandwich);

    // Wed 4 .. Mon 9 March: Wed, Thu, Sun, Mon work; Fri 6 and Sat 7 are off.
    expect($calc('2026-03-04', '2026-03-09')['units'])->toBe(8)
        ->and($calc('2026-03-04', '2026-03-09', sandwich: true)['units'])->toBe(12)
        ->and($calc('2026-03-04', '2026-03-09', LeaveSession::SecondHalf, LeaveSession::FirstHalf)['units'])->toBe(6)
        ->and($calc('2026-03-04', '2026-03-04', LeaveSession::FirstHalf)['units'])->toBe(1)
        ->and($calc('2026-03-06', '2026-03-07')['units'])->toBe(0)
        // A sandwich never extends past the first and last working day of the request.
        ->and($calc('2026-03-05', '2026-03-09', sandwich: true)['days'])->toBe(['2026-03-05' => 2, '2026-03-06' => 2, '2026-03-07' => 2, '2026-03-08' => 2, '2026-03-09' => 2]);

    expect(fn () => $calc('2026-03-04', '2026-03-09', LeaveSession::FirstHalf))->toThrow(InvalidArgumentException::class);
});

it('accrues annually and monthly with exact integer arithmetic, once', function () {
    $monthly = $this->hrmLeaveType($this->tenant, ['name' => 'Casual', 'code' => 'CL']);
    $this->hrmPolicy($this->tenant, $monthly, ['accrual' => 'monthly', 'annual_units' => 25]);

    $this->hrmAt('2026-06-15 10:00:00');
    $posted = $this->inTenant($this->tenant, fn (): int => app(LeaveAccruals::class)->accrueEmployee($this->staff));
    $again = $this->inTenant($this->tenant, fn (): int => app(LeaveAccruals::class)->accrueEmployee($this->staff));

    $sum = fn (string $typeId): int => $this->inTenant($this->tenant, fn (): int => app(LeaveBalances::class)->balance($this->staff->id, $typeId, 2026));

    // Annual: 20 at the start of the year. Monthly 25: January..June = floor(25*6/12) = 12.
    expect($again)->toBe(0)
        ->and($posted)->toBeGreaterThan(0)
        ->and($sum($this->type->id))->toBe(20)
        ->and($sum($monthly->id))->toBe(12);

    $this->hrmAt('2026-12-20 10:00:00');
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($this->staff));

    // Every unit of the year is granted exactly once: 2,2,2,2,2,2,2,2,2,2,2,3.
    expect($sum($monthly->id))->toBe(25)
        ->and($this->inTenant($this->tenant, fn () => LeaveLedgerEntry::query()->where('employee_id', $this->staff->id)->where('leave_type_id', $monthly->id)->count()))->toBe(12);
});

it('pro-rates the first year, caps the balance and carries forward up to the maximum', function () {
    $type = $this->hrmLeaveType($this->tenant, ['name' => 'Earned', 'code' => 'EL']);
    $this->hrmPolicy($this->tenant, $type, ['accrual' => 'annual', 'annual_units' => 24, 'carry_forward_max_units' => 10, 'max_balance_units' => 30]);
    $joiner = $this->hire($this->tenant, ['first_name' => 'Late', 'joined_on' => '2026-07-01']);

    $this->hrmAt('2026-07-02 10:00:00');
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($joiner));

    // Joined in July: six months of twelve remain: 24 * 6 / 12.
    expect($this->inTenant($this->tenant, fn () => app(LeaveBalances::class)->balance($joiner->id, $type->id, 2026)))->toBe(12);

    // Use some, then the year turns over.
    $this->inTenant($this->tenant, fn () => app(LeaveAdjustments::class)->adjust($joiner, $type, 6, 'Bonus leave', 2026));
    $this->inTenant($this->tenant, fn () => app(LeaveAdjustments::class)->adjust($joiner, $type, -2, 'Correction', 2026));
    $this->hrmAt('2027-01-04 10:00:00');
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($joiner));

    $this->inTenant($this->tenant, function () use ($joiner, $type): void {
        $balances = app(LeaveBalances::class);
        // 12 + 6 - 2 = 16 remained; carry-forward max 10; the year's closing balance is zeroed.
        expect($balances->balance($joiner->id, $type->id, 2026))->toBe(0)
            ->and($balances->balance($joiner->id, $type->id, 2027))->toBe(10 + 20);

        $kinds = LeaveLedgerEntry::query()->where('employee_id', $joiner->id)->where('leave_type_id', $type->id)->where('leave_year', 2027)->pluck('kind')->map->value->sort()->values()->all();
        expect($kinds)->toBe(['accrual', 'carry_forward']);
    });
});

it('keeps the ledger append-only', function () {
    $this->inTenant($this->tenant, function (): void {
        app(LeaveAccruals::class)->accrueEmployee($this->staff);
        $entry = LeaveLedgerEntry::query()->where('employee_id', $this->staff->id)->firstOrFail();

        expect(fn () => $entry->update(['units' => 999]))->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class)
            ->and(fn () => app(LeaveBalances::class)->append($this->staff->id, $this->type->id, 2026, 5, LedgerKind::Adjustment, '2026-03-02', $entry->idempotency_key))->not->toThrow(Throwable::class);

        // The repeated key returned the stored row and posted nothing.
        expect(LeaveLedgerEntry::query()->where('idempotency_key', $entry->idempotency_key)->count())->toBe(1)
            ->and(app(LeaveBalances::class)->balance($this->staff->id, $this->type->id, 2026))->toBe(20);
    });
});

it('reserves units on submit, refuses over-balance and overlapping requests, and returns units on rejection', function () {
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($this->staff));
    expect(($this->balance)())->toBe(20);

    $first = ($this->submit)(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10', 'reason' => 'Family']);

    expect($first->units)->toBe(4)
        ->and($first->status)->toBe(LeaveStatus::PendingManager)
        ->and(($this->balance)())->toBe(16);

    // Overlap, including a single shared day.
    expect(fn () => ($this->submit)(['starts_on' => '2026-03-10', 'ends_on' => '2026-03-11']))->toThrow(ValidationException::class);

    // Half days on opposite halves of one day do not collide; a full day over them does.
    $morning = ($this->submit)(['starts_on' => '2026-03-12', 'ends_on' => '2026-03-12', 'start_session' => 'first_half']);
    $afternoon = ($this->submit)(['starts_on' => '2026-03-12', 'ends_on' => '2026-03-12', 'start_session' => 'second_half']);
    expect($morning->units)->toBe(1)->and($afternoon->units)->toBe(1)
        ->and(fn () => ($this->submit)(['starts_on' => '2026-03-12', 'ends_on' => '2026-03-12']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->submit)(['starts_on' => '2026-03-12', 'ends_on' => '2026-03-12', 'start_session' => 'first_half']))->toThrow(ValidationException::class)
        ->and(($this->balance)())->toBe(14);

    // More than is left.
    expect(fn () => ($this->submit)(['starts_on' => '2026-03-15', 'ends_on' => '2026-03-26']))->toThrow(ValidationException::class);

    // Rejection by the manager gives the units back and records the decision.
    $rejected = ($this->decide)($first, $this->bossUser, false, 'Project deadline');
    expect($rejected->status)->toBe(LeaveStatus::Rejected)->and(($this->balance)())->toBe(18);

    $this->inTenant($this->tenant, fn () => expect(LeaveLedgerEntry::query()->where('leave_request_id', $first->id)->orderBy('units')->pluck('units')->all())->toBe([-4, 4]));
});

it('walks the two approval steps, announces approval once and cancels with a reversal', function () {
    Event::fake([LeaveApproved::class]);
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($this->staff));
    $request = ($this->submit)(['starts_on' => '2026-03-16', 'ends_on' => '2026-03-17']);

    // The employee cannot approve their own request, and HR is not the manager yet.
    expect(fn () => ($this->decide)($request, $this->staffUser))->toThrow(NotFoundHttpException::class);

    $afterManager = ($this->decide)($request, $this->bossUser);
    expect($afterManager->status)->toBe(LeaveStatus::PendingHr);

    // The manager has no say over the HR step.
    expect(fn () => ($this->decide)($request, $this->bossUser))->toThrow(NotFoundHttpException::class);

    $approved = ($this->decide)($request, $this->hrUser);
    expect($approved->status)->toBe(LeaveStatus::Approved)
        ->and($approved->decided_at)->not->toBeNull()
        ->and(($this->balance)())->toBe(16);
    Event::assertDispatchedTimes(LeaveApproved::class, 1);

    // Decided requests cannot be decided again.
    expect(fn () => ($this->decide)($request, $this->hrUser))->toThrow(ValidationException::class);

    $this->inTenant($this->tenant, function () use ($request): void {
        $record = LeaveRequest::query()->with(['employee', 'leaveType', 'approvals'])->findOrFail($request->id);
        expect($record->approvals->pluck('step')->map->value->all())->toBe(['manager', 'hr']);

        $cancelled = app(LeaveManager::class)->cancel($record, $this->staffUser, 'Plans changed', $this->staffMembership);
        expect($cancelled->status)->toBe(LeaveStatus::Cancelled);
    });

    expect(($this->balance)())->toBe(20);
});

it('lets HR decide in place of the manager and skips the manager step when there is none', function () {
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($this->staff));
    $request = ($this->submit)(['starts_on' => '2026-03-16', 'ends_on' => '2026-03-16']);

    expect(($this->decide)($request, $this->hrUser)->status)->toBe(LeaveStatus::Approved);

    // A manager-less employee goes straight to HR.
    $this->inTenant($this->tenant, fn () => app(EmployeeManager::class)->update($this->staff, ['manager_id' => null]));
    $direct = ($this->submit)(['starts_on' => '2026-03-18', 'ends_on' => '2026-03-18']);
    expect($direct->status)->toBe(LeaveStatus::PendingHr);

    // A manager-only flow is complete after the manager's approval.
    $this->inTenant($this->tenant, fn () => app(EmployeeManager::class)->update($this->staff, ['manager_id' => $this->boss->id]));
    $managerOnly = $this->hrmLeaveType($this->tenant, ['name' => 'Casual', 'code' => 'CL', 'approval_flow' => 'manager_only', 'tracks_balance' => false]);
    $one = $this->inTenant($this->tenant, fn () => app(LeaveManager::class)->submit($this->staff, ['leave_type_id' => $managerOnly->id, 'starts_on' => '2026-03-22', 'ends_on' => '2026-03-22']));

    expect(($this->decide)($one, $this->bossUser)->status)->toBe(LeaveStatus::Approved);
});

it('enforces leave type rules: reason, notice, maximum length and half days', function () {
    $strict = $this->hrmLeaveType($this->tenant, [
        'name' => 'Strict', 'code' => 'ST', 'tracks_balance' => false, 'requires_reason' => true, 'min_notice_days' => 5,
        'max_consecutive_days' => 3, 'allows_half_day' => false, 'approval_flow' => 'hr_only',
    ]);
    $submit = fn (array $input) => $this->inTenant($this->tenant, fn () => app(LeaveManager::class)->submit($this->staff, ['leave_type_id' => $strict->id, ...$input]));

    expect(fn () => $submit(['starts_on' => '2026-03-20', 'ends_on' => '2026-03-20']))->toThrow(ValidationException::class) // no reason
        ->and(fn () => $submit(['starts_on' => '2026-03-03', 'ends_on' => '2026-03-03', 'reason' => 'x']))->toThrow(ValidationException::class) // notice
        ->and(fn () => $submit(['starts_on' => '2026-03-22', 'ends_on' => '2026-03-26', 'reason' => 'x']))->toThrow(ValidationException::class) // too long
        ->and(fn () => $submit(['starts_on' => '2026-03-22', 'ends_on' => '2026-03-22', 'reason' => 'x', 'start_session' => 'first_half']))->toThrow(ValidationException::class)
        ->and($submit(['starts_on' => '2026-03-22', 'ends_on' => '2026-03-24', 'reason' => 'x'])->units)->toBe(6);

    // Dates before joining and requests that contain no working day are refused.
    expect(fn () => $submit(['starts_on' => '2026-01-04', 'ends_on' => '2026-01-04', 'reason' => 'x']))->toThrow(ValidationException::class)
        ->and(fn () => $submit(['starts_on' => '2026-03-27', 'ends_on' => '2026-03-28', 'reason' => 'x']))->toThrow(ValidationException::class);
});

it('adjusts balances only forwards of zero and keeps who and why', function () {
    $this->inTenant($this->tenant, function (): void {
        $adjustments = app(LeaveAdjustments::class);
        // The staff member was accrued 20 units when hired.
        $entry = $adjustments->adjust($this->staff, $this->type, 6, 'Opening balance', 2026);

        expect($entry->kind)->toBe(LedgerKind::Adjustment)->and($entry->note)->toBe('Opening balance')
            ->and(fn () => $adjustments->adjust($this->staff, $this->type, -27, 'Too much', 2026))->toThrow(ValidationException::class)
            ->and(fn () => $adjustments->adjust($this->staff, $this->type, 0, 'Nothing', 2026))->toThrow(ValidationException::class)
            ->and(fn () => $adjustments->adjust($this->staff, $this->type, 1, '  ', 2026))->toThrow(ValidationException::class);

        // The same automation key grants once.
        $a = $adjustments->adjust($this->staff, $this->type, 4, 'Grant', 2026, 'key-1');
        $b = $adjustments->adjust($this->staff, $this->type, 4, 'Grant', 2026, 'key-1');
        expect($a->id)->toBe($b->id)->and(app(LeaveBalances::class)->balance($this->staff->id, $this->type->id, 2026))->toBe(30);
    });
});

it('exposes leave over HTTP with scope: own requests, the manager inbox and the HR calendar', function () {
    $this->inTenant($this->tenant, fn () => app(LeaveAccruals::class)->accrueEmployee($this->staff));
    $this->actingAsMember($this->tenant, $this->staffUser)
        ->post($this->hrmUrl($this->tenant, '/leave/requests'), [
            'leave_type_id' => $this->type->id, 'starts_on' => '2026-03-16', 'ends_on' => '2026-03-16', 'reason' => 'Dentist',
        ])->assertRedirect();

    $request = $this->inTenant($this->tenant, fn () => LeaveRequest::query()->sole());

    // The employee sees their own request; the boss' list (own scope) does not include it, the inbox does.
    $this->get($this->hrmUrl($this->tenant, '/leave'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests.data', 1)->where('requests.data.0.status', 'pending_manager')->where('requests.data.0.abilities.cancel', true));

    $this->actingAsMember($this->tenant, $this->bossUser)->get($this->hrmUrl($this->tenant, '/leave'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests.data', 0));
    $this->get($this->hrmUrl($this->tenant, '/leave/approvals'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests.data', 1)->where('requests.data.0.abilities.decide', true)->where('requests.data.0.abilities.step', 'manager'));

    // The boss cannot open someone else's request without view scope over it.
    $this->get($this->hrmUrl($this->tenant, "/leave/requests/{$request->id}"))->assertNotFound();

    $this->post($this->hrmUrl($this->tenant, "/leave/requests/{$request->id}/decision"), ['decision' => 'reject'])->assertSessionHasErrors('note');
    $this->post($this->hrmUrl($this->tenant, "/leave/requests/{$request->id}/decision"), ['decision' => 'approve'])->assertRedirect();

    $this->actingAsMember($this->tenant, $this->hrUser)
        ->get($this->hrmUrl($this->tenant, '/leave/calendar?month=2026-03'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('month', '2026-03')->has('requests', 1)->where('requests.0.status', 'pending_hr'));

    // A colleague with only own scope gets a 404 for the request.
    [$otherUser] = $this->hrmStaff($this->tenant, ['hrm.self.view', 'hrm.leave.view', 'hrm.leave.request'], ['hrm.leave.view' => 'own'], ['first_name' => 'Other']);
    $this->actingAsMember($this->tenant, $otherUser)->get($this->hrmUrl($this->tenant, "/leave/requests/{$request->id}"))->assertNotFound();
    $this->post($this->hrmUrl($this->tenant, "/leave/requests/{$request->id}/cancel"))->assertNotFound();
});
