<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Actions\Analytics;
use App\Modules\WhatsappMarketing\Http\Presenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WhatsApp figures for the last 7, 30 or 90 days, from this app's own records. */
final class AnalyticsController
{
    public function __construct(
        private readonly Analytics $analytics,
        private readonly Presenter $presenter,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $this->presenter->actor($request);
        $input = $request->validate(['days' => ['nullable', 'integer', Rule::in([7, 30, 90])]]);
        $days = (int) ($input['days'] ?? 30);
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        return Inertia::render('Modules/WhatsappMarketing/Pages/Analytics/Index', [
            'days' => $days,
            'overview' => $this->analytics->overview($from, $to),
            'daily' => $this->analytics->byDay($from, $to, $this->context->require()->timezone),
        ]);
    }
}
