<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\SalesAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SalesReportController
{
    public function __construct(
        private readonly SalesAnalytics $analytics,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $timezone = $this->context->require()->timezone;
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $timezone) : CarbonImmutable::now($timezone)->startOfMonth();
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $timezone) : CarbonImmutable::now($timezone)->endOfMonth();
        abort_if($to->isBefore($from), 422, __('The report end date must be on or after its start date.'));
        $currency = strtoupper((string) ($filters['currency'] ?? $this->context->require()->currency));

        return Inertia::render('Modules/CRM/Pages/Sales/Reports/Index', [
            'report' => $this->analytics->overview($actor, $from, $to, $currency),
            'quoteStatusBreakdown' => $this->analytics->quoteStatusBreakdown($actor, $from, $to, $currency),
            'orderTrend' => $this->analytics->orderTrend($actor, $from, $to, $currency),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'currency' => $currency,
            ],
        ]);
    }
}
