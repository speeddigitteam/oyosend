<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Enums\EnrollmentStatus;
use App\Modules\EmailMarketing\Enums\SequenceStatus;
use App\Modules\EmailMarketing\Models\Enrollment;
use App\Modules\EmailMarketing\Models\Sequence;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Starts the active sequences of a list for a contact that just became a subscribed member, and stops them
 * when the membership ends. A contact is enrolled once per sequence, ever: leaving and re-joining a list does
 * not restart a welcome series.
 */
final class SequenceEnroller
{
    public function enrollSubscribed(Subscription $subscription): int
    {
        $enrolled = 0;

        $sequences = Sequence::query()
            ->where('list_id', $subscription->list_id)
            ->where('status', SequenceStatus::Active->value)
            ->get();

        foreach ($sequences as $sequence) {
            $first = SequenceStep::query()->where('sequence_id', $sequence->id)->orderBy('position')->first();

            if ($first === null) {
                continue;
            }

            try {
                Enrollment::query()->firstOrCreate(
                    ['sequence_id' => $sequence->id, 'contact_id' => $subscription->contact_id],
                    [
                        'subscription_id' => $subscription->id,
                        'status' => EnrollmentStatus::Active,
                        'next_position' => $first->position,
                        'next_due_at' => CarbonImmutable::now()->addMinutes($first->delay_minutes),
                        'enrolled_at' => CarbonImmutable::now(),
                    ],
                );
                $enrolled++;
            } catch (UniqueConstraintViolationException) {
                // A concurrent request enrolled the same contact; once is enough.
            }
        }

        return $enrolled;
    }

    /** Stop the running enrolments of a contact (the membership ended, or the platform says they must not be mailed). */
    public function stopForContact(string $contactId, string $reason, ?string $listId = null): int
    {
        $query = Enrollment::query()->where('contact_id', $contactId)->where('status', EnrollmentStatus::Active->value);

        if ($listId !== null) {
            $query->whereIn('sequence_id', Sequence::query()->where('list_id', $listId)->select('id'));
        }

        return $query->update([
            'status' => EnrollmentStatus::Stopped->value,
            'stopped_reason' => $reason,
            'next_due_at' => null,
            'updated_at' => now(),
        ]);
    }
}
