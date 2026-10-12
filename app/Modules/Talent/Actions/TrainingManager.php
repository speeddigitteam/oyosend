<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\SessionStatus;
use App\Modules\Talent\Events\TrainingCompleted;
use App\Modules\Talent\Events\TrainingEnrolled;
use App\Modules\Talent\Models\Course;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The course catalogue, sessions, enrolments and certification expiry. */
final class TrainingManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TalentSettings $settings,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function saveCourse(?Course $course, array $attributes): Course
    {
        $course ??= new Course;
        $course->fill([
            'title' => $attributes['title'],
            'code' => $attributes['code'] ?? null,
            'provider' => $attributes['provider'] ?? null,
            'description' => $attributes['description'] ?? null,
            'duration_minutes' => isset($attributes['duration_minutes']) && $attributes['duration_minutes'] !== '' ? (int) $attributes['duration_minutes'] : null,
            'validity_months' => isset($attributes['validity_months']) && $attributes['validity_months'] !== '' ? (int) $attributes['validity_months'] : null,
            'is_mandatory' => (bool) ($attributes['is_mandatory'] ?? false),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
        ]);

        try {
            $course->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['title' => __('A course with that title already exists.')]);
        }

        return $course;
    }

    /** @param array{date: string, time: string, duration_minutes?: int|string|null, location?: string|null, trainer?: string|null, capacity?: int|string|null, status?: string|null} $attributes */
    public function saveSession(Course $course, ?TrainingSession $session, array $attributes): TrainingSession
    {
        $start = $this->settings->toUtc($attributes['date'], $attributes['time']);
        $minutes = isset($attributes['duration_minutes']) && $attributes['duration_minutes'] !== '' ? (int) $attributes['duration_minutes'] : null;
        $capacity = isset($attributes['capacity']) && $attributes['capacity'] !== '' ? (int) $attributes['capacity'] : null;

        if ($session === null && $start->isPast()) {
            throw ValidationException::withMessages(['date' => __('A session cannot be scheduled in the past.')]);
        }

        return DB::transaction(function () use ($course, $session, $attributes, $start, $minutes, $capacity): TrainingSession {
            if ($session !== null) {
                if ($session->course_id !== $course->id) {
                    abort(404);
                }

                $held = Enrolment::query()->where('session_id', $session->id)->whereIn('status', [EnrolmentStatus::Enrolled, EnrolmentStatus::Completed])->count();

                if ($capacity !== null && $capacity < $held) {
                    throw ValidationException::withMessages(['capacity' => __('The capacity cannot be below the number of people enrolled.')]);
                }
            }

            $session ??= new TrainingSession;
            $session->fill([
                'course_id' => $course->id,
                'starts_at' => $start,
                'ends_at' => $minutes === null ? null : $start->addMinutes($minutes),
                'timezone' => $this->settings->timezone(),
                'location' => $attributes['location'] ?? null,
                'trainer' => $attributes['trainer'] ?? null,
                'capacity' => $capacity,
            ]);

            if (! $session->exists) {
                $session->status = SessionStatus::Scheduled;
            } elseif (isset($attributes['status']) && SessionStatus::tryFrom($attributes['status']) !== null) {
                $session->status = SessionStatus::from($attributes['status']);
            }

            $session->save();

            return $session;
        });
    }

    public function enrol(Course $course, EmployeeSnapshot $employee, ?TrainingSession $session, ?TenantMembership $actor): Enrolment
    {
        if (! $course->is_active) {
            throw ValidationException::withMessages(['course_id' => __('This course is not available.')]);
        }

        if ($employee->status === 'terminated') {
            throw ValidationException::withMessages(['employee_id' => __('Choose an employee of this workspace.')]);
        }

        return DB::transaction(function () use ($course, $employee, $session, $actor): Enrolment {
            if ($session !== null) {
                /** @var TrainingSession $locked */
                $locked = TrainingSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

                if ($locked->course_id !== $course->id || $locked->status !== SessionStatus::Scheduled) {
                    throw ValidationException::withMessages(['session_id' => __('Choose a scheduled session of this course.')]);
                }

                $held = Enrolment::query()->where('session_id', $locked->id)->whereIn('status', [EnrolmentStatus::Enrolled, EnrolmentStatus::Completed])->count();

                if ($locked->capacity !== null && $held >= $locked->capacity) {
                    throw ValidationException::withMessages(['session_id' => __('This session is full.')]);
                }
            }

            $enrolment = new Enrolment([
                'course_id' => $course->id,
                'session_id' => $session?->id,
                'status' => EnrolmentStatus::Enrolled,
                'enrolled_on' => $this->settings->today()->toDateString(),
                'open_marker' => 1,
                'created_by' => $actor?->id,
            ]);
            $enrolment->placeForEmployee($employee);

            try {
                $enrolment->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['employee_id' => __('This employee is already enrolled in the course.')]);
            }

            TrainingEnrolled::dispatch([
                'enrolment_id' => $enrolment->id, 'course_id' => $course->id, 'employee_id' => $employee->id, 'membership_id' => $employee->membershipId,
            ]);

            return $enrolment;
        });
    }

    /** @param array{completed_on?: string|null, score?: int|string|null, notes?: string|null} $attributes */
    public function complete(Enrolment $enrolment, array $attributes, ?TenantMembership $actor): Enrolment
    {
        return DB::transaction(function () use ($enrolment, $attributes, $actor): Enrolment {
            /** @var Enrolment $locked */
            $locked = Enrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();
            $course = Course::query()->findOrFail($locked->course_id);

            if ($locked->status !== EnrolmentStatus::Enrolled) {
                throw ValidationException::withMessages(['status' => __('Only an open enrolment can be completed.')]);
            }

            $today = $this->settings->today();
            $completedOn = isset($attributes['completed_on']) && $attributes['completed_on'] !== ''
                ? CarbonImmutable::parse((string) $attributes['completed_on'], 'UTC')
                : $today;

            if ($completedOn->toDateString() > $today->toDateString()) {
                throw ValidationException::withMessages(['completed_on' => __('The completion date cannot be in the future.')]);
            }

            $score = $attributes['score'] ?? null;

            if ($score !== null && $score !== '' && ((int) $score < 0 || (int) $score > 100)) {
                throw ValidationException::withMessages(['score' => __('The score is a whole number from 0 to 100.')]);
            }

            $locked->status = EnrolmentStatus::Completed;
            $locked->setAttribute('completed_on', $completedOn->toDateString());
            $locked->score = $score === null || $score === '' ? null : (int) $score;
            $locked->notes = $attributes['notes'] ?? $locked->notes;
            $locked->open_marker = null;
            $locked->setAttribute('expires_on', $course->validity_months === null ? null : $completedOn->addMonthsNoOverflow($course->validity_months)->toDateString());
            $locked->save();

            $employee = $locked->employee_id;
            $this->audit->record('talent.enrolment.completed', $locked, ['course_id' => $course->id, 'employee_id' => $employee], $actor?->user);
            TrainingCompleted::dispatch([
                'enrolment_id' => $locked->id, 'course_id' => $course->id, 'employee_id' => $employee, 'membership_id' => $locked->owner_id,
                'expires_on' => $locked->expires_on?->toDateString(),
            ]);

            return $locked;
        });
    }

    public function close(Enrolment $enrolment, EnrolmentStatus $to, ?string $notes = null): Enrolment
    {
        if (! in_array($to, [EnrolmentStatus::Failed, EnrolmentStatus::Cancelled], true)) {
            throw new \InvalidArgumentException('An enrolment closes as failed or cancelled.');
        }

        return DB::transaction(function () use ($enrolment, $to, $notes): Enrolment {
            /** @var Enrolment $locked */
            $locked = Enrolment::query()->whereKey($enrolment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EnrolmentStatus::Enrolled) {
                throw ValidationException::withMessages(['status' => __('Only an open enrolment can be closed.')]);
            }

            $locked->status = $to;
            $locked->open_marker = null;
            $locked->notes = $notes ?? $locked->notes;
            $locked->save();

            return $locked;
        });
    }
}
