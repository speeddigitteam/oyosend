<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Modules\EmailMarketing\Actions\CampaignReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController
{
    public function __construct(
        private readonly CampaignReports $reports,
        private readonly EmailMarketingSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->actor($request);

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90,365'],
        ]);

        $days = (int) ($data['days'] ?? 30);
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        return Inertia::render('Modules/EmailMarketing/Pages/Reports/Index', [
            'report' => $this->reports->overview($from, $to),
            'days' => $days,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ]);
    }
}
