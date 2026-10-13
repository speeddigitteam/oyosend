<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Reporting\Support\CsvWriter;
use App\Modules\MetaAds\Actions\PerformanceReport;
use App\Modules\MetaAds\Actions\SetupReadiness;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Http\Requests\PerformanceRequest;
use App\Modules\MetaAds\Models\AdAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The performance dashboard: KPIs with deltas, a daily trend, top campaigns and a CSV export. */
final class PerformanceController
{
    private const int EXPORT_ROWS = 5000;

    public function __construct(
        private readonly PerformanceReport $performance,
        private readonly SetupReadiness $readiness,
        private readonly MetaAdsSupport $support,
        private readonly AuditLogger $audit,
    ) {}

    public function index(PerformanceRequest $request): Response
    {
        $actor = $this->support->actor($request);
        $range = $this->performance->range($request->validated('from'), $request->validated('to'));
        $currencies = $this->performance->currencies($range['from'], $range['to']);
        $accountId = $this->accountId($request);
        $currency = $this->currency($request, $currencies);

        $base = [
            'range' => $range,
            'currencies' => $currencies,
            'currency' => $currency,
            'accountId' => $accountId,
            'accounts' => AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->orderBy('name')->get(['id', 'name', 'external_id', 'currency'])
                ->map(static fn (AdAccount $a): array => ['id' => $a->id, 'name' => $a->name, 'externalId' => $a->external_id, 'currency' => $a->currency])->all(),
            'canExport' => $actor->can('meta_ads.insights.export'),
            'canSetup' => $actor->can('meta_ads.accounts.view'),
            'readiness' => $this->readiness->items(),
            'timezoneNote' => true,
        ];

        if ($currency === null) {
            return Inertia::render('Modules/MetaAds/Pages/Performance/Index', $base + [
                'summary' => null, 'series' => [], 'campaigns' => [], 'syncedAt' => null,
            ]);
        }

        return Inertia::render('Modules/MetaAds/Pages/Performance/Index', $base + [
            'summary' => $this->performance->summary($range['from'], $range['to'], $currency, $accountId),
            'series' => $this->performance->series($range['from'], $range['to'], $currency, $accountId),
            'campaigns' => $this->performance->campaigns($range['from'], $range['to'], $currency, $accountId, 10),
            'syncedAt' => $this->performance->freshness()?->toIso8601String(),
        ]);
    }

    public function export(PerformanceRequest $request): StreamedResponse
    {
        $this->support->actor($request);
        $range = $this->performance->range($request->validated('from'), $request->validated('to'));
        $accountId = $this->accountId($request);
        $currencies = $this->performance->currencies($range['from'], $range['to']);
        $currency = $this->currency($request, $currencies);
        $kind = $request->validated('kind') === 'daily' ? 'daily' : 'campaigns';
        $rows = [];

        if ($currency !== null) {
            if ($kind === 'daily') {
                $header = ['Date', 'Currency', 'Spend (minor units)', 'Impressions', 'Clicks', 'Leads'];

                foreach ($this->performance->series($range['from'], $range['to'], $currency, $accountId) as $day) {
                    $rows[] = [$day['date'], $currency, $day['spend'], $day['impressions'], $day['clicks'], $day['leads']];
                }
            } else {
                $header = ['Ad account', 'Campaign', 'Currency', 'Spend (minor units)', 'Impressions', 'Clicks', 'CTR (basis points)', 'Cost per click (minor units)', 'Leads', 'Cost per lead (minor units)'];

                foreach ($this->performance->campaigns($range['from'], $range['to'], $currency, $accountId, self::EXPORT_ROWS) as $row) {
                    $rows[] = [$row['accountName'], $row['name'] ?? $row['campaignId'], $currency, $row['spend'], $row['impressions'], $row['clicks'], $row['ctr'], $row['cpc'], $row['leads'], $row['costPerLead']];
                }
            }
        } else {
            $header = ['Message'];
            $rows[] = ['No data in this range'];
        }

        $this->audit->record('meta_ads.performance.exported', null, [
            'kind' => $kind, 'from' => $range['from'], 'to' => $range['to'], 'currency' => $currency, 'rows' => count($rows),
        ], subjectLabel: 'Meta Ads performance');

        $csv = CsvWriter::render($header, $rows);
        $name = sprintf('meta-ads-%s-%s-%s.csv', $kind, $range['from'], $range['to']);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function accountId(Request $request): ?string
    {
        $id = $request->query('account');

        if (! is_string($id) || $id === '') {
            return null;
        }

        // Tenant-scoped: an id from another workspace is simply not found.
        return AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->findOrFail($id)->id;
    }

    /** @param list<string> $currencies */
    private function currency(Request $request, array $currencies): ?string
    {
        $wanted = $request->query('currency');

        if (is_string($wanted) && in_array(strtoupper($wanted), $currencies, true)) {
            return strtoupper($wanted);
        }

        return $currencies[0] ?? null;
    }
}
