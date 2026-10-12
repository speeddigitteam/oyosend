<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Modules\Support\Actions\SupportReports;
use App\Modules\Support\Actions\SupportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController
{
    public function __construct(
        private readonly SupportReports $reports,
        private readonly SupportSettings $settings,
        private readonly SupportControllerSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'queue_id' => ['nullable', 'string', 'size:26'],
        ]);
        $today = CarbonImmutable::now($this->settings->timezone())->startOfDay();
        $to = $filters['to'] ?? $today->toDateString();
        $from = $filters['from'] ?? $today->subDays(29)->toDateString();

        return Inertia::render('Modules/Support/Pages/Reports/Index', [
            'report' => $this->reports->overview($from, $to, $filters['queue_id'] ?? null),
            'filters' => ['from' => $from, 'to' => $to, 'queueId' => $filters['queue_id'] ?? null],
            'queues' => $this->support->queues(activeOnly: false),
            'maxDays' => SupportReports::MAX_DAYS,
        ]);
    }
}
