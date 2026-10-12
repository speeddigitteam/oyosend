<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Modules\Talent\Enums\EnrolmentStatus;
use App\Modules\Talent\Enums\ProbationStatus;
use App\Modules\Talent\Events\CertificateExpired;
use App\Modules\Talent\Events\CertificateExpiring;
use App\Modules\Talent\Events\ProbationReviewDue;
use App\Modules\Talent\Models\Enrolment;
use App\Modules\Talent\Models\Probation;
use App\Modules\Talent\Models\Reminder;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Time-driven announcements: probation reviews coming due and certifications expiring or expired.
 *
 * Every announcement writes a `talent_reminders` row whose unique key identifies the fact
 * (`probation:{id}:{ends_on}`, `cert-expiring:{enrolment}:{expires_on}`), in the same transaction as
 * the event, so reruns and overlapping workers announce each fact exactly once. Delivering the
 * notification itself is Phase 8; consumers subscribe to the events or automation triggers.
 */
final class ReminderProcessor
{
    public function __construct(
        private readonly TalentSettings $settings,
        private readonly EmployeeLookup $employees,
        private readonly ProbationManager $probations,
    ) {}

    /** @return array{probation: int, expiring: int, expired: int} */
    public function run(): array
    {
        return [
            'probation' => $this->probation(),
            'expiring' => $this->certificates(expiring: true),
            'expired' => $this->certificates(expiring: false),
        ];
    }

    public function probation(): int
    {
        $settings = $this->settings->current();
        $today = $this->settings->today();
        $horizon = $today->addDays($settings->probation_reminder_days)->toDateString();
        $sent = 0;

        Probation::query()->where('status', ProbationStatus::InProgress)->orderBy('id')->chunkById(100, function ($chunk) use ($horizon, &$sent): void {
            $snapshots = $this->employees->many($chunk->pluck('employee_id')->all());

            foreach ($chunk as $probation) {
                $probation = $this->probations->sync($probation, $snapshots[$probation->employee_id] ?? null);

                if ($probation->status !== ProbationStatus::InProgress
                    || $probation->ends_on->toDateString() > $horizon
                    || $this->probations->hasFinalDecision($probation)) {
                    continue;
                }

                $ends = $probation->ends_on->toDateString();
                $daysLeft = $this->settings->daysUntil($ends);

                $sent += $this->announce('probation', $probation->id, "probation:{$probation->id}:{$ends}", $ends, static function () use ($probation, $ends, $daysLeft): void {
                    ProbationReviewDue::dispatch([
                        'probation_id' => $probation->id,
                        'employee_id' => $probation->employee_id,
                        'membership_id' => $probation->owner_id,
                        'ends_on' => $ends,
                        'days_left' => $daysLeft,
                    ]);
                }) ? 1 : 0;
            }
        });

        return $sent;
    }

    public function certificates(bool $expiring): int
    {
        $window = $this->settings->current()->certification_reminder_days;
        $today = $this->settings->today();
        $todayDate = $today->toDateString();
        $sent = 0;

        $query = Enrolment::query()->where('status', EnrolmentStatus::Completed)->whereNotNull('expires_on');

        if ($expiring) {
            $query->where('expires_on', '>=', $todayDate)->where('expires_on', '<=', $today->addDays($window)->toDateString());
        } else {
            $query->where('expires_on', '<', $todayDate);
        }

        $query->orderBy('id')->chunkById(100, function ($chunk) use ($expiring, &$sent): void {
            $snapshots = $this->employees->many($chunk->pluck('employee_id')->all());

            foreach ($chunk as $enrolment) {
                $employee = $snapshots[$enrolment->employee_id] ?? null;

                if ($employee === null || $employee->status === 'terminated' || $this->superseded($enrolment)) {
                    continue;
                }

                $expires = $enrolment->expires_on?->toDateString();

                if ($expires === null) {
                    continue;
                }

                $payload = [
                    'enrolment_id' => $enrolment->id,
                    'course_id' => $enrolment->course_id,
                    'employee_id' => $enrolment->employee_id,
                    'membership_id' => $employee->membershipId,
                    'expires_on' => $expires,
                ];

                if ($expiring) {
                    $daysLeft = $this->settings->daysUntil($expires);
                    $sent += $this->announce('cert-expiring', $enrolment->id, "cert-expiring:{$enrolment->id}:{$expires}", $expires,
                        static function () use ($payload, $daysLeft): void {
                            CertificateExpiring::dispatch([...$payload, 'days_left' => $daysLeft]);
                        }) ? 1 : 0;
                } else {
                    $sent += $this->announce('cert-expired', $enrolment->id, "cert-expired:{$enrolment->id}:{$expires}", $expires,
                        static function () use ($payload): void {
                            CertificateExpired::dispatch($payload);
                        }) ? 1 : 0;
                }
            }
        });

        return $sent;
    }

    /** A later completion of the same course by the same person replaces this certificate. */
    private function superseded(Enrolment $enrolment): bool
    {
        return Enrolment::query()
            ->where('employee_id', $enrolment->employee_id)
            ->where('course_id', $enrolment->course_id)
            ->where('status', EnrolmentStatus::Completed)
            ->where('id', '!=', $enrolment->id)
            ->where(function ($query) use ($enrolment): void {
                $query->where('completed_on', '>', $enrolment->completed_on)
                    ->orWhere(fn ($same) => $same->where('completed_on', $enrolment->completed_on)->where('id', '>', $enrolment->id));
            })
            ->exists();
    }

    /** @param Closure(): void $dispatch */
    private function announce(string $kind, string $subjectId, string $key, ?string $dueOn, Closure $dispatch): bool
    {
        try {
            DB::transaction(function () use ($kind, $subjectId, $key, $dueOn, $dispatch): void {
                Reminder::query()->create(['kind' => $kind, 'subject_id' => $subjectId, 'reminder_key' => $key, 'due_on' => $dueOn]);
                $dispatch();
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
