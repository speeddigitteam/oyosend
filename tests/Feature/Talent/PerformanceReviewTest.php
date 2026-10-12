<?php

declare(strict_types=1);

use App\Core\Workforce\Contracts\EmployeeDirectory;
use App\Modules\Talent\Actions\GoalManager;
use App\Modules\Talent\Actions\ReviewCycleManager;
use App\Modules\Talent\Actions\ReviewManager;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\GoalStatus;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Events\GoalAchieved;
use App\Modules\Talent\Events\ReviewCalibrated;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewForm;
use App\Modules\Talent\Support\GoalProgress;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithTalent;

uses(InteractsWithHrm::class, InteractsWithTalent::class);

beforeEach(function () {
    $this->tenant = $this->talentTenant('talent-perf');
    $this->hrmAt('2026-03-10 10:00:00');
});

it('rejects a form whose weights do not add up to exactly 100%', function () {
    $this->inTenant($this->tenant, function (): void {
        $manager = app(ReviewCycleManager::class);
        $items = static fn (int ...$weights): array => array_map(static fn (int $w, int $i): array => ['name' => 'C'.$i, 'weight_bp' => $w], $weights, array_keys($weights));

        expect(fn () => $manager->saveForm(null, ['name' => 'Bad', 'scale_max' => 5, 'items' => $items(5000, 4999)]))->toThrow(ValidationException::class)
            ->and(fn () => $manager->saveForm(null, ['name' => 'Bad', 'scale_max' => 5, 'items' => $items(6000, 5000)]))->toThrow(ValidationException::class)
            ->and(fn () => $manager->saveForm(null, ['name' => 'Bad', 'scale_max' => 2, 'items' => $items(10000)]))->toThrow(ValidationException::class);

        $form = $manager->saveForm(null, ['name' => 'Good', 'scale_max' => 5, 'items' => $items(3333, 3333, 3334)]);
        expect((int) $form->items()->sum('weight_bp'))->toBe(10000);
    });
});

it('launches a cycle with one review per working employee, once', function () {
    [, $employee, $cycle, $review] = $this->talentReviewSetup($this->tenant);

    $this->inTenant($this->tenant, function () use ($cycle, $review): void {
        expect($cycle->refresh()->status)->toBe(CycleStatus::Active)
            ->and(Review::query()->where('cycle_id', $cycle->id)->count())->toBe(2)
            ->and($review->status)->toBe(ReviewStatus::PendingSelf)
            ->and($review->reviewer_employee_id)->not->toBeNull();

        expect(fn () => app(ReviewCycleManager::class)->launch($cycle, null))->toThrow(ValidationException::class);
    });
});

it('runs the review state machine and computes exact weighted scores', function () {
    Event::fake([ReviewCalibrated::class]);
    [$manager, $employee, $cycle, $review, $items] = $this->talentReviewSetup($this->tenant);

    $this->inTenant($this->tenant, function () use ($manager, $employee, $cycle, $review, $items): void {
        $reviews = app(ReviewManager::class);
        $self = [$items[0] => ['rating' => 4], $items[1] => ['rating' => 5]];
        $mgr = [$items[0] => ['rating' => 3], $items[1] => ['rating' => 4]];

        // Steps are not interchangeable and only the right person may take them.
        expect(fn () => $reviews->submitManager($review, $mgr, null, $manager->id))->toThrow(ValidationException::class)
            ->and(fn () => $reviews->submitSelf($review, $self, null, $manager->id))->toThrow(HttpException::class);

        // Every competency must be rated, within the scale.
        expect(fn () => $reviews->submitSelf($review, [$items[0] => ['rating' => 4]], null, $employee->id))->toThrow(ValidationException::class)
            ->and(fn () => $reviews->submitSelf($review, [$items[0] => ['rating' => 6], $items[1] => ['rating' => 1]], null, $employee->id))->toThrow(ValidationException::class);

        $reviews->submitSelf($review, $self, 'Good year', $employee->id);
        $review->refresh();
        // 4 * 60% + 5 * 40% = 4.40 -> 440 hundredths.
        expect($review->status)->toBe(ReviewStatus::PendingManager)->and($review->self_score)->toBe(440);

        expect(fn () => $reviews->submitManager($review, $mgr, null, $employee->id))->toThrow(HttpException::class);
        $reviews->submitManager($review, $mgr, 'Steady', $manager->id);
        $review->refresh();
        // 3 * 60% + 4 * 40% = 3.40
        expect($review->status)->toBe(ReviewStatus::PendingCalibration)->and($review->manager_score)->toBe(340);

        // Calibration is only open while the cycle is in calibration.
        expect(fn () => $reviews->calibrate($review, [], $manager->id, null))->toThrow(ValidationException::class);
        app(ReviewCycleManager::class)->startCalibration($cycle, null);

        // Nobody calibrates their own review; an override needs a note and a score inside the scale.
        expect(fn () => $reviews->calibrate($review, [], $employee->id, null))->toThrow(ValidationException::class)
            ->and(fn () => $reviews->calibrate($review, ['final_score' => 400], $manager->id, null))->toThrow(ValidationException::class)
            ->and(fn () => $reviews->calibrate($review, ['final_score' => 900, 'note' => 'x'], $manager->id, null))->toThrow(ValidationException::class);

        $reviews->calibrate($review, ['final_score' => 400, 'note' => 'Strong stretch project'], $manager->id, null);
        $review->refresh();
        expect($review->status)->toBe(ReviewStatus::Calibrated)
            ->and($review->final_score)->toBe(400)
            ->and($review->final_overridden)->toBeTrue()
            ->and($review->calibrated_at)->not->toBeNull();

        // Locked: no more steps, and only the employee can acknowledge.
        expect(fn () => $reviews->submitManager($review, $mgr, null, $manager->id))->toThrow(ValidationException::class)
            ->and(fn () => $reviews->calibrate($review, [], $manager->id, null))->toThrow(ValidationException::class)
            ->and(fn () => $reviews->acknowledge($review, null, $manager->id))->toThrow(HttpException::class);

        $reviews->acknowledge($review, 'Thanks', $employee->id);
        expect($review->refresh()->status)->toBe(ReviewStatus::Acknowledged)
            ->and($review->employee_comment)->toBe('Thanks')
            ->and(fn () => $reviews->acknowledge($review, null, $employee->id))->toThrow(ValidationException::class);
    });

    Event::assertDispatched(ReviewCalibrated::class, fn (ReviewCalibrated $event): bool => $event->payload()['final_score'] === 400);
});

it('takes the manager score as final when calibration does not override it', function () {
    [$manager, $employee, $cycle, $review, $items] = $this->talentReviewSetup($this->tenant, requiresSelf: false);

    $this->inTenant($this->tenant, function () use ($manager, $cycle, $review, $items): void {
        $reviews = app(ReviewManager::class);

        // Without a self step the review starts with the manager.
        expect($review->status)->toBe(ReviewStatus::PendingManager);
        $reviews->submitManager($review, [$items[0] => ['rating' => 5], $items[1] => ['rating' => 2]], null, $manager->id);
        app(ReviewCycleManager::class)->startCalibration($cycle, null);
        $reviews->calibrate($review, ['final_score' => 380], $manager->id, null);   // equal to the computed 5*0.6 + 2*0.4 = 3.80: not an override

        $review->refresh();
        expect($review->manager_score)->toBe(380)
            ->and($review->final_score)->toBe(380)
            ->and($review->final_overridden)->toBeFalse();
    });
});

it('stops accepting steps once the cycle leaves the active state', function () {
    [, $employee, $cycle, $review, $items] = $this->talentReviewSetup($this->tenant);

    $this->inTenant($this->tenant, function () use ($employee, $cycle, $review, $items): void {
        app(ReviewCycleManager::class)->startCalibration($cycle, null);

        expect(fn () => app(ReviewManager::class)->submitSelf($review, [$items[0] => ['rating' => 3], $items[1] => ['rating' => 3]], null, $employee->id))
            ->toThrow(ValidationException::class);

        expect(fn () => app(ReviewCycleManager::class)->launch($cycle, null))->toThrow(ValidationException::class);
        app(ReviewCycleManager::class)->close($cycle, null);
        expect($cycle->refresh()->status)->toBe(CycleStatus::Closed);
    });
});

it('locks the form of a launched cycle', function () {
    [, , $cycle] = $this->talentReviewSetup($this->tenant);
    $this->inTenant($this->tenant, function () use ($cycle): void {
        $form = ReviewForm::query()->findOrFail($cycle->form_id);
        $manager = app(ReviewCycleManager::class);

        expect(fn () => $manager->saveForm($form, ['name' => $form->name, 'scale_max' => 5, 'items' => [['name' => 'Only', 'weight_bp' => 10000]]]))->toThrow(ValidationException::class);
        $manager->saveForm($form, ['name' => 'Renamed', 'scale_max' => 5, 'is_active' => false]);
        expect($form->refresh()->name)->toBe('Renamed');
    });
});

it('tracks goals with exact basis-point progress, up or down, and appends an update history', function () {
    Event::fake([GoalAchieved::class]);
    $employee = $this->hire($this->tenant);

    $this->inTenant($this->tenant, function () use ($employee): void {
        $snapshot = app(EmployeeDirectory::class)->find($employee->id);
        $manager = app(GoalManager::class);
        $up = $manager->create($snapshot, ['title' => 'Close deals', 'kind' => 'kpi', 'unit' => 'deals', 'start_value' => 0, 'target_value' => 30], null);

        expect($up->current_value)->toBe(0);
        $manager->recordProgress($up, 10, 'Q1', null);
        expect(GoalProgress::basisPoints($up->refresh()->start_value, $up->target_value, $up->current_value))->toBe(3333);

        $manager->recordProgress($up, 30, null, null);
        expect($up->refresh()->status)->toBe(GoalStatus::Achieved)
            ->and($up->updates()->count())->toBe(2)
            ->and(fn () => $manager->recordProgress($up, 31, null, null))->toThrow(ValidationException::class);

        // Lower is better: churn 20 -> 5.
        $down = $manager->create($snapshot, ['title' => 'Cut defects', 'start_value' => 20, 'target_value' => 5], null);
        $manager->recordProgress($down, 12, null, null);
        expect(GoalProgress::basisPoints(20, 5, 12))->toBe(5333)
            ->and($down->refresh()->status)->toBe(GoalStatus::Active);
        $manager->recordProgress($down, 5, null, null);
        expect($down->refresh()->status)->toBe(GoalStatus::Achieved);

        expect(fn () => $manager->create($snapshot, ['title' => 'Flat', 'start_value' => 5, 'target_value' => 5], null))->toThrow(ValidationException::class);
    });

    Event::assertDispatchedTimes(GoalAchieved::class, 2);
});
