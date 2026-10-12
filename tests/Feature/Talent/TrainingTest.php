<?php

declare(strict_types=1);

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Hrm\Actions\EmployeeManager;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Talent\Actions\ReminderProcessor;
use App\Modules\Talent\Actions\TrainingManager;
use App\Modules\Talent\Automation\Actions\EnrolInCourseAction;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Events\CertificateExpired;
use App\Modules\Talent\Events\CertificateExpiring;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Reminder;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithTalent;

uses(InteractsWithHrm::class, InteractsWithTalent::class);

beforeEach(function () {
    $this->tenant = $this->talentTenant('talent-train');
    $this->hrmAt('2026-03-02 10:00:00');
});

it('enrols once per open course, respects capacity and stores the session time in UTC', function () {
    $a = $this->hire($this->tenant, ['first_name' => 'A']);
    $b = $this->hire($this->tenant, ['first_name' => 'B']);

    $this->inTenant($this->tenant, function () use ($a, $b): void {
        $training = app(TrainingManager::class);
        $course = $training->saveCourse(null, ['title' => 'Fire safety', 'validity_months' => 12]);
        $session = $training->saveSession($course, null, ['date' => '2026-03-20', 'time' => '09:30', 'duration_minutes' => 120, 'capacity' => 1, 'location' => 'Room 2']);

        expect($session->starts_at->utc()->toDateTimeString())->toBe('2026-03-20 03:30:00')
            ->and($session->ends_at->utc()->toDateTimeString())->toBe('2026-03-20 05:30:00')
            ->and($session->timezone)->toBe('Asia/Dhaka');

        $snapshotA = app(EmployeeDirectory::class)->find($a->id);
        $snapshotB = app(EmployeeDirectory::class)->find($b->id);
        $training->enrol($course, $snapshotA, $session, null);

        expect(fn () => $training->enrol($course, $snapshotA, null, null))->toThrow(ValidationException::class)
            ->and(fn () => $training->enrol($course, $snapshotB, $session, null))->toThrow(ValidationException::class);

        $training->enrol($course, $snapshotB, null, null);
        expect(Enrolment::query()->count())->toBe(2);

        $course->update(['is_active' => false]);
        expect(fn () => $training->enrol($course->refresh(), app(EmployeeDirectory::class)->find($b->id), null, null))->toThrow(ValidationException::class);
    });
});

it('computes certificate expiry from the completion date and the course validity', function () {
    $employee = $this->hire($this->tenant);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $training = app(TrainingManager::class);
        $permanent = $training->saveCourse(null, ['title' => 'Induction']);
        $annual = $training->saveCourse(null, ['title' => 'First aid', 'validity_months' => 12]);
        $snapshot = app(EmployeeDirectory::class)->find($employee->id);

        $one = $training->complete($training->enrol($permanent, $snapshot, null, null), ['score' => 90], null);
        $two = $training->complete($training->enrol($annual, $snapshot, null, null), ['completed_on' => '2025-02-28'], null);

        expect($one->status)->toBe(EnrolmentStatus::Completed)
            ->and($one->expires_on)->toBeNull()
            ->and($one->open_marker)->toBeNull()
            ->and($two->expires_on->toDateString())->toBe('2026-02-28')
            ->and(fn () => $training->complete($two, [], null))->toThrow(ValidationException::class);

        $third = $training->enrol($annual, $snapshot, null, null);
        expect(fn () => $training->complete($third, ['completed_on' => '2026-04-01'], null))->toThrow(ValidationException::class)
            ->and(fn () => $training->complete($third, ['score' => 120], null))->toThrow(ValidationException::class);
    });
});

it('announces an expiring certificate once, then the expiry once, and never for a renewed one', function () {
    Event::fake([CertificateExpiring::class, CertificateExpired::class]);
    $employee = $this->hire($this->tenant);

    $enrolmentId = $this->inTenant($this->tenant, function () use ($employee): string {
        $training = app(TrainingManager::class);
        $course = $training->saveCourse(null, ['title' => 'First aid', 'validity_months' => 12]);
        $enrolment = $training->enrol($course, app(EmployeeDirectory::class)->find($employee->id), null, null);

        return $training->complete($enrolment, ['completed_on' => '2026-03-02'], null)->id;
    });

    $run = fn (): array => $this->inTenant($this->tenant, fn () => app(ReminderProcessor::class)->run());

    // Expires 2027-03-02; the default window is 30 days.
    $this->hrmAt('2027-01-30 09:00:00');
    expect($run())->toBe(['probation' => 0, 'expiring' => 0, 'expired' => 0]);

    $this->hrmAt('2027-02-10 09:00:00');
    expect($run()['expiring'])->toBe(1)->and($run()['expiring'])->toBe(0);
    Event::assertDispatchedTimes(CertificateExpiring::class, 1);
    Event::assertDispatched(CertificateExpiring::class, fn (CertificateExpiring $event): bool => $event->payload()['enrolment_id'] === $enrolmentId
        && $event->payload()['expires_on'] === '2027-03-02' && $event->payload()['days_left'] === 20);

    // A later, still-expiring day is the same fact: no second reminder.
    $this->hrmAt('2027-02-25 09:00:00');
    expect($run()['expiring'])->toBe(0);

    $this->hrmAt('2027-03-05 09:00:00');
    expect($run()['expired'])->toBe(1)->and($run()['expired'])->toBe(0);
    Event::assertDispatchedTimes(CertificateExpired::class, 1);

    $this->inTenant($this->tenant, fn () => expect(Reminder::query()->count())->toBe(2));
});

it('does not announce a certificate that was renewed or whose holder has left', function () {
    Event::fake([CertificateExpiring::class, CertificateExpired::class]);
    $renewer = $this->hire($this->tenant, ['first_name' => 'Renewer']);
    $leaver = $this->hire($this->tenant, ['first_name' => 'Leaver']);

    $this->inTenant($this->tenant, function () use ($renewer, $leaver): void {
        $training = app(TrainingManager::class);
        $course = $training->saveCourse(null, ['title' => 'Forklift', 'validity_months' => 12]);
        $directory = app(EmployeeDirectory::class);

        foreach ([$renewer, $leaver] as $person) {
            $training->complete($training->enrol($course, $directory->find($person->id), null, null), ['completed_on' => '2026-03-02'], null);
        }

        // The renewer takes the course again, a year later, before the old certificate lapses.
        $this->hrmAt('2027-02-10 10:00:00');
        $training->complete($training->enrol($course, $directory->find($renewer->id), null, null), ['completed_on' => '2027-02-01'], null);

        app(EmployeeManager::class)->changeStatus($leaver, EmployeeStatus::Terminated, [
            'reason' => 'Resigned', 'termination_type' => 'resignation', 'effective_on' => '2026-03-02',
        ]);
    });

    $this->hrmAt('2027-03-05 09:00:00');
    $result = $this->inTenant($this->tenant, fn () => app(ReminderProcessor::class)->run());

    // The renewer's first certificate is superseded, the new one runs to 2028; the leaver is gone.
    expect($result['expired'])->toBe(0)->and($result['expiring'])->toBe(0);
    Event::assertNotDispatched(CertificateExpired::class);
});

it('enrols from an automation action with a durable receipt', function () {
    $employee = $this->hire($this->tenant);
    $course = $this->inTenant($this->tenant, fn (): Course => app(TrainingManager::class)->saveCourse(null, ['title' => 'Code of conduct']));

    $this->inTenant($this->tenant, function () use ($employee, $course): void {
        $action = app(EnrolInCourseAction::class);
        $input = ['employee_id' => $employee->id, 'course_id' => $course->id];
        $first = $action->execute($input, 'wf-9');
        $again = $action->execute($input, 'wf-9');

        expect($again)->toBe($first)
            ->and(Enrolment::query()->count())->toBe(1)
            ->and(fn () => $action->execute($input, 'wf-10'))->toThrow(ValidationException::class);
    });
});
