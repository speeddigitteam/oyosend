<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Modules\Hrm\Actions\HrmCatalogue;
use App\Modules\Hrm\Http\Presenters\HrmPresenter;
use App\Modules\Hrm\Http\Requests\HolidayRequest;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Support\HrmPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HolidayController
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly HrmCatalogue $catalogue,
        private readonly HrmControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? $this->support->today()->year);

        return Inertia::render('Modules/Hrm/Pages/Holidays/Index', [
            'year' => $year,
            'holidays' => Holiday::query()->whereBetween('holiday_on', ["{$year}-01-01", "{$year}-12-31"])->orderBy('holiday_on')->get()
                ->map(static fn (Holiday $holiday): array => HrmPresenter::holiday($holiday))->all(),
            'canManage' => $this->access->allows($actor, HrmPermissions::HOLIDAYS_MANAGE),
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        $this->catalogue->saveHoliday(null, $request->validated());
        Toast::success(__('Holiday added.'));

        return back();
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $this->catalogue->saveHoliday($holiday, $request->validated());
        Toast::success(__('Holiday saved.'));

        return back();
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();
        Toast::success(__('Holiday removed.'));

        return back();
    }
}
