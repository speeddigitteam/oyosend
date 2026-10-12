<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ReviewManager;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Enums\RatingParty;
use App\Modules\Talent\Enums\ReviewStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\AcknowledgeRequest;
use App\Modules\Talent\Http\Requests\CalibrateRequest;
use App\Modules\Talent\Http\Requests\ReviewerRequest;
use App\Modules\Talent\Http\Requests\ReviewSubmitRequest;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewRating;
use App\Modules\Talent\Support\TalentPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Individual reviews. Access: the record scope of `talent.reviews.view` (the employee through `own`),
 * plus the designated reviewer. The employee never sees the manager's side before calibration.
 */
final class ReviewController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly ReviewManager $reviews,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->user($request);
        $filters = $request->validate([
            'cycle_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'in:pending_self,pending_manager,pending_calibration,calibrated,acknowledged,all'],
            'mine' => ['nullable', 'boolean'],
        ]);
        $status = $filters['status'] ?? 'all';
        $own = $this->support->employeeId($actor);

        $query = Review::query()->with('cycle')->where(function ($match) use ($actor, $own): void {
            $match->whereIn('id', $this->support->scoped(Review::query(), $actor, TalentPermissions::REVIEWS_VIEW)->select('id'));

            if ($own !== null) {
                $match->orWhere('reviewer_employee_id', $own);
            }
        });

        if ($filters['mine'] ?? false) {
            $query->where(fn ($mine) => $mine->where('employee_id', $own ?? '')->orWhere('reviewer_employee_id', $own ?? ''));
        }

        if (($filters['cycle_id'] ?? '') !== '') {
            $query->where('cycle_id', $filters['cycle_id']);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        $people = $this->support->people($page->getCollection()->flatMap(static fn (Review $review): array => [$review->employee_id, $review->reviewer_employee_id])->all());
        $page->through(static fn (Review $review): array => TalentPresenter::review($review, $people, $own !== null && $review->employee_id === $own) + [
            'cycle' => ['id' => $review->cycle->id, 'name' => $review->cycle->name, 'status' => $review->cycle->status->value],
        ]);

        return Inertia::render('Modules/Talent/Pages/Reviews/Index', [
            'reviews' => $page,
            'filters' => ['cycle_id' => $filters['cycle_id'] ?? '', 'status' => $status, 'mine' => (bool) ($filters['mine'] ?? false)],
            'cycles' => ReviewCycle::query()->where('status', '!=', CycleStatus::Draft)->orderByDesc('starts_on')->limit(50)->get(['id', 'name', 'status'])
                ->map(static fn (ReviewCycle $cycle): array => ['id' => $cycle->id, 'name' => $cycle->name, 'status' => $cycle->status->value])->all(),
            'statuses' => ReviewStatus::values(),
            'hasProfile' => $own !== null,
        ]);
    }

    public function show(Request $request, Review $review): Response
    {
        $actor = $this->support->user($request);
        $own = $this->support->employeeId($actor);
        $isSubject = $own !== null && $review->employee_id === $own;
        $isReviewer = $own !== null && $review->reviewer_employee_id === $own;
        abort_unless($isReviewer || $this->support->allows($actor, TalentPermissions::REVIEWS_VIEW, $review), 404);

        $review->load(['cycle.form.items', 'ratings']);
        $people = $this->support->people([$review->employee_id, $review->reviewer_employee_id]);
        $released = in_array($review->status, [ReviewStatus::Calibrated, ReviewStatus::Acknowledged], true);
        $hideManager = $isSubject && ! $released;
        $cycle = $review->cycle;

        $ratings = $review->ratings
            ->filter(static fn (ReviewRating $rating): bool => ! ($hideManager && $rating->party === RatingParty::Manager))
            ->map(static fn (ReviewRating $rating): array => [
                'itemId' => $rating->item_id, 'party' => $rating->party->value, 'rating' => $rating->rating, 'comment' => $rating->comment,
            ])->values()->all();

        $calibrate = $cycle->status === CycleStatus::Calibration && $review->status === ReviewStatus::PendingCalibration
            && ! $isSubject && $this->support->allows($actor, TalentPermissions::REVIEWS_CALIBRATE, $review);
        $active = $cycle->status === CycleStatus::Active;
        $canSubmit = $this->support->can($actor, TalentPermissions::REVIEWS_SUBMIT);

        return Inertia::render('Modules/Talent/Pages/Reviews/Show', [
            'review' => TalentPresenter::review($review, $people, $isSubject) + [
                'selfComment' => $review->self_comment,
                'managerComment' => $hideManager ? null : $review->manager_comment,
                'calibrationNote' => $hideManager ? null : $review->calibration_note,
                'employeeComment' => $review->employee_comment,
            ],
            'cycle' => TalentPresenter::cycle($cycle),
            'form' => TalentPresenter::form($cycle->form),
            'ratings' => $ratings,
            'abilities' => [
                'submitSelf' => $active && $canSubmit && $isSubject && $review->status === ReviewStatus::PendingSelf,
                'submitManager' => $active && $canSubmit && $isReviewer && ! $isSubject && $review->status === ReviewStatus::PendingManager,
                'calibrate' => $calibrate,
                'acknowledge' => $isSubject && $review->status === ReviewStatus::Calibrated,
                'assignReviewer' => $this->support->can($actor, TalentPermissions::CYCLES_MANAGE)
                    && ! in_array($review->status, [ReviewStatus::Calibrated, ReviewStatus::Acknowledged], true),
            ],
            'employees' => $this->support->can($actor, TalentPermissions::CYCLES_MANAGE) ? $this->support->employeeOptions() : [],
        ]);
    }

    public function submitSelf(ReviewSubmitRequest $request, Review $review): RedirectResponse
    {
        $actor = $this->support->user($request);
        $employeeId = $this->requireEmployee($actor);
        $this->reviews->submitSelf($review, $request->validated('ratings'), $request->validated('comment'), $employeeId);
        Toast::success(__('Self review submitted.'));

        return back();
    }

    public function submitManager(ReviewSubmitRequest $request, Review $review): RedirectResponse
    {
        $actor = $this->support->user($request);
        $employeeId = $this->requireEmployee($actor);
        $this->reviews->submitManager($review, $request->validated('ratings'), $request->validated('comment'), $employeeId);
        Toast::success(__('Manager review submitted.'));

        return back();
    }

    public function assignReviewer(ReviewerRequest $request, Review $review): RedirectResponse
    {
        $this->support->user($request);
        $this->reviews->assignReviewer($review, $request->validated('reviewer_employee_id'));
        Toast::success(__('Reviewer assigned.'));

        return back();
    }

    public function calibrate(CalibrateRequest $request, Review $review): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->support->authorize($actor, TalentPermissions::REVIEWS_CALIBRATE, $review);
        $employeeId = $this->support->employeeId($actor) ?? '';
        /** @var array{final_score?: int|string|null, note?: string|null} $data */
        $data = $request->validated();
        $this->reviews->calibrate($review, $data, $employeeId, $this->support->membership($actor));
        Toast::success(__('Review calibrated and locked.'));

        return back();
    }

    public function acknowledge(AcknowledgeRequest $request, Review $review): RedirectResponse
    {
        $actor = $this->support->user($request);
        $employeeId = $this->requireEmployee($actor);
        $this->reviews->acknowledge($review, $request->validated('comment'), $employeeId);
        Toast::success(__('Review acknowledged.'));

        return back();
    }

    private function requireEmployee(User $actor): string
    {
        return $this->support->employeeId($actor) ?? abort(404);
    }
}
