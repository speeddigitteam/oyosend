<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\Talent\Actions\TalentAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** HR analytics: workspace-wide, sensitive aggregates (`talent.analytics.view`). */
final class AnalyticsController
{
    public function __construct(
        private readonly TalentSupport $support,
        private readonly TalentAnalytics $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->user($request);
        $today = $this->support->today();
        $from = $this->support->date($request, 'from', $today->subMonths(12)->toDateString());
        $to = $this->support->date($request, 'to', $today->toDateString());
        $months = (int) $request->query('months', 12);

        return Inertia::render('Modules/Talent/Pages/Analytics/Index', [
            'from' => $from,
            'to' => $to,
            'recruitment' => $this->analytics->recruitment($from, $to),
            'workforce' => $this->analytics->workforce($months),
            'reviews' => $this->analytics->reviews(),
            'training' => $this->analytics->training(),
        ]);
    }
}
