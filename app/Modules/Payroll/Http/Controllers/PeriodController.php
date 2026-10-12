<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Modules\Payroll\Actions\PeriodManager;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\PeriodKind;
use App\Modules\Payroll\Http\Presenters\PayrollPresenter;
use App\Modules\Payroll\Http\Requests\PeriodRequest;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;
use App\Modules\Payroll\Support\PayrollPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pay periods: create, edit while open, delete, close, and see their runs. */
final class PeriodController
{
    public function __construct(
        private readonly PeriodManager $periods,
        private readonly AccessChecker $access,
        private readonly PayrollControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $periods = Period::query()->orderByDesc('starts_on')->paginate(24)->withQueryString();

        return Inertia::render('Modules/Payroll/Pages/Periods/Index', [
            'periods' => $periods->through(static fn (Period $period): array => PayrollPresenter::period($period)),
            'kinds' => PeriodKind::values(),
            'today' => $this->support->today()->toDateString(),
            'canManage' => $this->access->allows($actor, PayrollPermissions::PERIODS_MANAGE),
        ]);
    }

    public function show(Request $request, Period $period): Response
    {
        $actor = $this->support->actor($request);
        $runs = Run::query()->with('period:id,name')->where('period_id', $period->id)->orderBy('created_at')->orderBy('id')->get();
        $pending = Item::query()->where('period_id', $period->id)->where('status', ItemStatus::Pending)->count();

        return Inertia::render('Modules/Payroll/Pages/Periods/Show', [
            'period' => PayrollPresenter::period($period),
            'runs' => $runs->map(static fn (Run $run): array => PayrollPresenter::run($run))->all(),
            'pendingItems' => $pending,
            'hasEnded' => $period->ends_on->toDateString() < $this->support->today()->toDateString(),
            'abilities' => [
                'manage' => $this->access->allows($actor, PayrollPermissions::PERIODS_MANAGE),
                'prepare' => $this->access->allows($actor, PayrollPermissions::RUNS_PREPARE),
                'viewRuns' => $this->access->allows($actor, PayrollPermissions::RUNS_VIEW),
            ],
        ]);
    }

    public function store(PeriodRequest $request): RedirectResponse
    {
        $period = $this->periods->create($request->validated());
        Toast::success(__('Pay period created.'));

        return redirect()->route('payroll.periods.show', $period);
    }

    public function update(PeriodRequest $request, Period $period): RedirectResponse
    {
        $this->periods->update($period, $request->validated());
        Toast::success(__('Pay period saved.'));

        return back();
    }

    public function destroy(Period $period): RedirectResponse
    {
        $this->periods->delete($period);
        Toast::success(__('Pay period deleted.'));

        return redirect()->route('payroll.periods.index');
    }

    public function close(Period $period): RedirectResponse
    {
        $this->periods->close($period);
        Toast::success(__('Pay period closed.'));

        return back();
    }
}
