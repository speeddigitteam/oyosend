<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Support\Actions\SlaConfiguration;
use App\Modules\Support\Actions\SupportSettings;
use App\Modules\Support\Http\Requests\CalendarRequest;
use App\Modules\Support\Http\Requests\PolicyRequest;
use App\Modules\Support\Models\Calendar;
use App\Modules\Support\Models\SlaPolicy;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** SLA policies and business-hours calendars (`support.sla.manage`). */
final class SlaController
{
    public function __construct(
        private readonly SlaConfiguration $sla,
        private readonly SupportSettings $settings,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Support/Pages/Sla/Index', [
            'policies' => SlaPolicy::query()->orderBy('name')->get()->map(fn (SlaPolicy $policy): array => [
                'id' => $policy->id,
                'name' => $policy->name,
                'calendarId' => $policy->calendar_id,
                'targets' => $policy->targets,
                'pauseOnWaiting' => $policy->pause_on_waiting,
                'isActive' => $policy->is_active,
            ])->all(),
            'calendars' => Calendar::query()->orderBy('name')->get()->map(fn (Calendar $calendar): array => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'timezone' => $calendar->timezone,
                'hours' => $calendar->hours,
                'holidays' => $calendar->holidays ?? [],
                'isActive' => $calendar->is_active,
            ])->all(),
            'workspaceTimezone' => $this->settings->timezone(),
            'maxMinutes' => SlaConfiguration::MAX_MINUTES,
            'priorities' => ['low', 'normal', 'high', 'urgent'],
        ]);
    }

    public function storePolicy(PolicyRequest $request): RedirectResponse
    {
        $this->sla->savePolicy(null, $request->validated());
        Toast::success(__('SLA policy saved.'));

        return back();
    }

    public function updatePolicy(PolicyRequest $request, SlaPolicy $policy): RedirectResponse
    {
        $this->sla->savePolicy($policy, $request->validated());
        Toast::success(__('SLA policy saved.'));

        return back();
    }

    public function storeCalendar(CalendarRequest $request): RedirectResponse
    {
        $this->sla->saveCalendar(null, $request->validated());
        Toast::success(__('Business hours saved.'));

        return back();
    }

    public function updateCalendar(CalendarRequest $request, Calendar $calendar): RedirectResponse
    {
        $this->sla->saveCalendar($calendar, $request->validated());
        Toast::success(__('Business hours saved.'));

        return back();
    }
}
