<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Talent\Actions\ReviewCycleManager;
use App\Modules\Talent\Actions\TalentAnalytics;
use App\Modules\Talent\Enums\CycleStatus;
use App\Modules\Talent\Http\Presenters\TalentPresenter;
use App\Modules\Talent\Http\Requests\ReviewCycleRequest;
use App\Modules\Talent\Http\Requests\ReviewFormRequest;
use App\Modules\Talent\Models\Review;
use App\Modules\Talent\Models\ReviewCycle;
use App\Modules\Talent\Models\ReviewForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Review forms and cycles (`talent.cycles.manage`): configuration, launch, calibration, close. */
final class ReviewCycleController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly ReviewCycleManager $cycles,
        private readonly TalentAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->user($request);
        $counts = Review::query()->selectRaw('cycle_id, count(*) as total')->groupBy('cycle_id')->pluck('total', 'cycle_id');

        return Inertia::render('Modules/Talent/Pages/Cycles/Index', [
            'cycles' => ReviewCycle::query()->orderByDesc('starts_on')->get()
                ->map(static fn (ReviewCycle $cycle): array => TalentPresenter::cycle($cycle, (int) ($counts[$cycle->id] ?? 0)))->all(),
            'forms' => ReviewForm::query()->with('items')->orderBy('name')->get()
                ->map(static fn (ReviewForm $form): array => TalentPresenter::form($form))->all(),
            'completion' => $this->analytics->reviews(),
            'departments' => $this->support->organization()['departments'],
        ]);
    }

    public function show(Request $request, ReviewCycle $cycle): Response
    {
        $this->support->user($request);
        $cycle->load('form.items');
        $byStatus = Review::query()->where('cycle_id', $cycle->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(static fn ($total): int => (int) $total)->all();

        return Inertia::render('Modules/Talent/Pages/Cycles/Show', [
            'cycle' => TalentPresenter::cycle($cycle, array_sum($byStatus)),
            'form' => TalentPresenter::form($cycle->form),
            'byStatus' => $byStatus,
            'abilities' => [
                'edit' => $cycle->status === CycleStatus::Draft,
                'launch' => $cycle->status === CycleStatus::Draft,
                'startCalibration' => $cycle->status === CycleStatus::Active,
                'close' => $cycle->status === CycleStatus::Calibration,
            ],
        ]);
    }

    public function store(ReviewCycleRequest $request): RedirectResponse
    {
        $actor = $this->support->user($request);
        $cycle = $this->cycles->saveCycle(null, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Review cycle created.'));

        return to_route('talent.cycles.show', $cycle);
    }

    public function update(ReviewCycleRequest $request, ReviewCycle $cycle): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->cycles->saveCycle($cycle, $request->validated(), $this->support->membership($actor));
        Toast::success(__('Review cycle saved.'));

        return back();
    }

    public function launch(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->cycles->launch($cycle, $this->support->membership($actor));
        Toast::success(__('Review cycle launched.'));

        return back();
    }

    public function startCalibration(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->cycles->startCalibration($cycle, $this->support->membership($actor));
        Toast::success(__('Calibration started.'));

        return back();
    }

    public function close(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        $actor = $this->support->user($request);
        $this->cycles->close($cycle, $this->support->membership($actor));
        Toast::success(__('Review cycle closed.'));

        return back();
    }

    public function storeForm(ReviewFormRequest $request): RedirectResponse
    {
        /** @var array{name: string, description?: string|null, scale_max: int|string, is_active?: bool, items?: list<array{name: string, description?: string|null, weight_bp: int|string}>} $data */
        $data = $request->validated();
        $this->cycles->saveForm(null, $data);
        Toast::success(__('Review form created.'));

        return back();
    }

    public function updateForm(ReviewFormRequest $request, ReviewForm $form): RedirectResponse
    {
        /** @var array{name: string, description?: string|null, scale_max: int|string, is_active?: bool, items?: list<array{name: string, description?: string|null, weight_bp: int|string}>} $data */
        $data = $request->validated();
        $this->cycles->saveForm($form, $data);
        Toast::success(__('Review form saved.'));

        return back();
    }
}
