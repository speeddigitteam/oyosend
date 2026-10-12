<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\RatingParty;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Events\ReviewAcknowledged;
use App\Modules\Talent\Events\ReviewCalibrated;
use App\Modules\Talent\Events\ReviewSubmitted;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewFormItem;
use App\Modules\Talent\Models\ReviewRating;
use App\Modules\Talent\Support\ReviewScoring;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The review state machine:
 *
 *   pending_self --submitSelf--> pending_manager --submitManager--> pending_calibration
 *   pending_calibration --calibrate (cycle in calibration, locks)--> calibrated --acknowledge--> acknowledged
 *
 * Steps are only open while the cycle is `active` (calibration while it is `calibration`); a
 * calibrated review is locked. Scores are integer hundredths computed by {@see ReviewScoring}.
 */
final class ReviewManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeLookup $employees,
    ) {}

    /**
     * @param  array<string, array{rating: int|string, comment?: string|null}>  $ratings  form item id => rating
     */
    public function submitSelf(Review $review, array $ratings, ?string $comment, string $actorEmployeeId): Review
    {
        return $this->submit($review, RatingParty::Self, $ratings, $comment, $actorEmployeeId);
    }

    /**
     * @param  array<string, array{rating: int|string, comment?: string|null}>  $ratings
     */
    public function submitManager(Review $review, array $ratings, ?string $comment, string $actorEmployeeId): Review
    {
        return $this->submit($review, RatingParty::Manager, $ratings, $comment, $actorEmployeeId);
    }

    public function assignReviewer(Review $review, ?string $employeeId): Review
    {
        return DB::transaction(function () use ($review, $employeeId): Review {
            /** @var Review $locked */
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [ReviewStatus::Calibrated, ReviewStatus::Acknowledged], true)) {
                throw ValidationException::withMessages(['reviewer_employee_id' => __('A calibrated review is locked.')]);
            }

            if ($employeeId !== null) {
                if ($employeeId === $locked->employee_id) {
                    throw ValidationException::withMessages(['reviewer_employee_id' => __('Nobody reviews themselves as the manager.')]);
                }

                $this->employees->require($employeeId, 'reviewer_employee_id');
            }

            $locked->reviewer_employee_id = $employeeId;
            $locked->save();
            $this->audit->record('talent.review.reviewer_assigned', $locked, ['reviewer_employee_id' => $employeeId]);

            return $locked;
        });
    }

    /**
     * Lock the review with its final score: the manager's weighted score, or an explicit override
     * (hundredths) with a required note.
     *
     * @param  array{final_score?: int|string|null, note?: string|null}  $attributes
     */
    public function calibrate(Review $review, array $attributes, string $actorEmployeeId, ?TenantMembership $actor): Review
    {
        return DB::transaction(function () use ($review, $attributes, $actorEmployeeId, $actor): Review {
            /** @var Review $locked */
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            /** @var ReviewCycle $cycle */
            $cycle = ReviewCycle::query()->with('form')->findOrFail($locked->cycle_id);

            if ($cycle->status !== CycleStatus::Calibration) {
                throw ValidationException::withMessages(['status' => __('Reviews are calibrated while the cycle is in calibration.')]);
            }

            if ($locked->status !== ReviewStatus::PendingCalibration) {
                throw ValidationException::withMessages(['status' => __('This review is not waiting for calibration.')]);
            }

            if ($actorEmployeeId === $locked->employee_id) {
                throw ValidationException::withMessages(['status' => __('Nobody calibrates their own review.')]);
            }

            $override = $attributes['final_score'] ?? null;
            $note = isset($attributes['note']) ? trim((string) $attributes['note']) : '';
            $managerScore = (int) $locked->manager_score;

            if ($override !== null && $override !== '' && (int) $override !== $managerScore) {
                $score = (int) $override;

                if ($score < 100 || $score > $cycle->form->scale_max * 100) {
                    throw ValidationException::withMessages(['final_score' => __('The final score must be within the rating scale.')]);
                }

                if ($note === '') {
                    throw ValidationException::withMessages(['note' => __('Explain why the final score differs from the manager\'s score.')]);
                }

                $locked->final_score = $score;
                $locked->final_overridden = true;
            } else {
                $locked->final_score = $managerScore;
                $locked->final_overridden = false;
            }

            $locked->calibration_note = $note === '' ? null : $note;
            $locked->status = ReviewStatus::Calibrated;
            $locked->calibrated_at = now();
            $locked->calibrated_by = $actor?->id;
            $locked->save();

            $this->audit->record('talent.review.calibrated', $locked, ['overridden' => $locked->final_overridden], $actor?->user);
            ReviewCalibrated::dispatch([
                'review_id' => $locked->id, 'cycle_id' => $locked->cycle_id, 'employee_id' => $locked->employee_id,
                'membership_id' => $locked->owner_id, 'final_score' => (int) $locked->final_score,
            ]);

            return $locked;
        });
    }

    public function acknowledge(Review $review, ?string $comment, string $actorEmployeeId): Review
    {
        return DB::transaction(function () use ($review, $comment, $actorEmployeeId): Review {
            /** @var Review $locked */
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($locked->employee_id !== $actorEmployeeId) {
                abort(404);
            }

            if ($locked->status !== ReviewStatus::Calibrated) {
                throw ValidationException::withMessages(['status' => __('Only a calibrated review can be acknowledged.')]);
            }

            $locked->status = ReviewStatus::Acknowledged;
            $locked->acknowledged_at = now();
            $locked->employee_comment = $comment;
            $locked->save();
            $this->audit->record('talent.review.acknowledged', $locked);
            ReviewAcknowledged::dispatch([
                'review_id' => $locked->id, 'cycle_id' => $locked->cycle_id, 'employee_id' => $locked->employee_id, 'membership_id' => $locked->owner_id,
            ]);

            return $locked;
        });
    }

    /**
     * @param  array<string, array{rating: int|string, comment?: string|null}>  $ratings
     */
    private function submit(Review $review, RatingParty $party, array $ratings, ?string $comment, string $actorEmployeeId): Review
    {
        return DB::transaction(function () use ($review, $party, $ratings, $comment, $actorEmployeeId): Review {
            /** @var Review $locked */
            $locked = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            /** @var ReviewCycle $cycle */
            $cycle = ReviewCycle::query()->with('form.items')->findOrFail($locked->cycle_id);

            if ($cycle->status !== CycleStatus::Active) {
                throw ValidationException::withMessages(['status' => __('This review cycle is not accepting reviews.')]);
            }

            $expected = $party === RatingParty::Self ? ReviewStatus::PendingSelf : ReviewStatus::PendingManager;

            if ($locked->status !== $expected) {
                throw ValidationException::withMessages(['status' => __('This review step is not open.')]);
            }

            if ($party === RatingParty::Self && $actorEmployeeId !== $locked->employee_id) {
                abort(404);
            }

            if ($party === RatingParty::Manager) {
                if ($locked->reviewer_employee_id === null || $actorEmployeeId !== $locked->reviewer_employee_id) {
                    abort(404);
                }

                if ($actorEmployeeId === $locked->employee_id) {
                    throw ValidationException::withMessages(['status' => __('Nobody reviews themselves as the manager.')]);
                }
            }

            /** @var Collection<int, ReviewFormItem> $items */
            $items = $cycle->form->items;
            $weights = $items->mapWithKeys(static fn ($item): array => [$item->id => $item->weight_bp])->all();
            $given = [];
            $scale = $cycle->form->scale_max;

            foreach ($items as $item) {
                $entry = $ratings[$item->id] ?? null;
                $value = $entry['rating'] ?? null;

                if (! is_numeric($value) || (int) $value < 1 || (int) $value > $scale) {
                    throw ValidationException::withMessages(["ratings.{$item->id}.rating" => __('Rate every competency from 1 to :max.', ['max' => $scale])]);
                }

                $given[$item->id] = (int) $value;
            }

            $score = ReviewScoring::weighted($weights, $given, $scale);

            foreach ($items as $item) {
                ReviewRating::query()->updateOrCreate(
                    ['review_id' => $locked->id, 'item_id' => $item->id, 'party' => $party],
                    ['rating' => $given[$item->id], 'comment' => $ratings[$item->id]['comment'] ?? null],
                );
            }

            if ($party === RatingParty::Self) {
                $locked->self_score = $score;
                $locked->self_comment = $comment;
                $locked->self_submitted_at = now();
                $locked->status = ReviewStatus::PendingManager;
            } else {
                $locked->manager_score = $score;
                $locked->manager_comment = $comment;
                $locked->manager_submitted_at = now();
                $locked->status = ReviewStatus::PendingCalibration;
            }

            $locked->save();
            $this->audit->record('talent.review.'.$party->value.'_submitted', $locked, ['score' => $score]);
            ReviewSubmitted::dispatch([
                'review_id' => $locked->id, 'cycle_id' => $locked->cycle_id, 'employee_id' => $locked->employee_id,
                'membership_id' => $locked->owner_id, 'step' => $party->value,
            ]);

            return $locked;
        });
    }
}
