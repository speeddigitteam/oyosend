<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use App\Modules\EmailMarketing\Enums\EnrollmentStatus;
use App\Modules\EmailMarketing\Enums\SequenceStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Sends the sequence steps that are due. Runs inside the same every-minute job as campaigns, after them, within
 * what is left of the workspace's per-minute capacity. One delivery per (enrolment, step): the unique send key
 * makes a repeated pass harmless.
 */
final class SequenceRunner
{
    public function __construct(private readonly DeliverySender $sender) {}

    /** @return int Emails handed to the platform. */
    public function run(int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $lock = Cache::lock('email_marketing.sequence_run', (int) config('email_marketing.run_lock_seconds', 300));

        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->pass(min($budget, (int) config('email_marketing.sequence_chunk', 200)));
        } finally {
            $lock->release();
        }
    }

    private function pass(int $budget): int
    {
        $active = Sequence::query()->where('status', SequenceStatus::Active->value)->get()->keyBy('id');

        if ($active->isEmpty()) {
            return 0;
        }

        $steps = SequenceStep::query()->whereIn('sequence_id', $active->keys())->orderBy('position')->get()->groupBy('sequence_id');

        $due = Enrollment::query()
            ->whereIn('sequence_id', $active->keys())
            ->where('status', EnrollmentStatus::Active->value)
            ->where('next_due_at', '<=', CarbonImmutable::now())
            ->orderBy('next_due_at')
            ->limit($budget)
            ->get();

        $handed = 0;

        foreach ($due as $enrollment) {
            $ordered = $steps->get($enrollment->sequence_id)?->values() ?? collect();
            /** @var SequenceStep|null $step */
            $step = $ordered->firstWhere('position', $enrollment->next_position);

            if ($step === null) {
                $this->complete($enrollment);

                continue;
            }

            $subscription = $enrollment->subscription_id === null ? null : Subscription::query()->find($enrollment->subscription_id);

            if ($subscription === null || $subscription->status !== SubscriptionStatus::Subscribed) {
                $this->stop($enrollment, 'not_subscribed');

                continue;
            }

            $delivery = $this->delivery($enrollment, $step, $subscription);
            $result = $this->sender->send($delivery, $step);

            if ($result->paused()) {
                // Leave the enrolment due: the same delivery is picked up again when the channel is back.
                break;
            }

            if ($result->status !== null) {
                $handed++;
            }

            if ($result->status === null) {
                $current = Delivery::query()->find($delivery->id);

                if ($current === null || in_array($current->status, [DeliveryStatus::Pending, DeliveryStatus::Sending, DeliveryStatus::Held], true)) {
                    // Another worker holds the claim right now; look again next pass.
                    continue;
                }
            }

            $this->advance($enrollment, $step, $ordered->all());
        }

        return $handed;
    }

    private function delivery(Enrollment $enrollment, SequenceStep $step, Subscription $subscription): Delivery
    {
        $attributes = [
            'sequence_id' => $enrollment->sequence_id,
            'step_id' => $step->id,
            'enrollment_id' => $enrollment->id,
            'contact_id' => $enrollment->contact_id,
            'subscription_id' => $subscription->id,
            'send_key' => 'sequence:'.$enrollment->id.':'.$step->id,
            'variant' => 'a',
            'status' => DeliveryStatus::Pending,
        ];

        try {
            return Delivery::query()->firstOrCreate(['send_key' => $attributes['send_key']], $attributes);
        } catch (UniqueConstraintViolationException) {
            return Delivery::query()->where('send_key', $attributes['send_key'])->firstOrFail();
        }
    }

    /** @param  list<SequenceStep>  $ordered */
    private function advance(Enrollment $enrollment, SequenceStep $step, array $ordered): void
    {
        $next = null;

        foreach ($ordered as $candidate) {
            if ($candidate->position > $step->position) {
                $next = $candidate;

                break;
            }
        }

        if ($next === null) {
            $this->complete($enrollment);

            return;
        }

        $enrollment->forceFill([
            'next_position' => $next->position,
            'next_due_at' => CarbonImmutable::now()->addMinutes($next->delay_minutes),
        ])->save();
    }

    private function complete(Enrollment $enrollment): void
    {
        $enrollment->forceFill([
            'status' => EnrollmentStatus::Completed,
            'completed_at' => CarbonImmutable::now(),
            'next_due_at' => null,
        ])->save();
    }

    private function stop(Enrollment $enrollment, string $reason): void
    {
        $enrollment->forceFill(['status' => EnrollmentStatus::Stopped, 'stopped_reason' => $reason, 'next_due_at' => null])->save();
    }
}
