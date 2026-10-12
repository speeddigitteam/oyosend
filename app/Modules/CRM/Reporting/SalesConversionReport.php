<?php

declare(strict_types=1);

namespace App\Modules\CRM\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Actions\SalesAnalytics;
use Carbon\CarbonImmutable;

/** Quote to order conversion, forecast and target attainment for one currency; the numbers of the CRM sales report. */
final class SalesConversionReport implements ReportProvider
{
    public function __construct(
        private readonly SalesAnalytics $analytics,
        private readonly TenantContext $tenants,
    ) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'crm.sales_conversion',
            module: 'crm',
            label: 'Sales conversion and forecast',
            description: 'Quotes, orders, conversion rate, weighted forecast and target attainment for one currency, as in the CRM sales report.',
            permission: 'crm.reports.view',
            columns: [
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('quote_count', 'Quotes', ColumnType::Integer),
                new ReportColumn('accepted_quote_count', 'Accepted quotes', ColumnType::Integer),
                new ReportColumn('conversion_bps', 'Conversion rate', ColumnType::BasisPoints),
                new ReportColumn('order_count', 'Orders', ColumnType::Integer),
                new ReportColumn('quoted_amount', 'Quoted amount', ColumnType::Money, 'currency'),
                new ReportColumn('actual_sales_amount', 'Actual sales', ColumnType::Money, 'currency'),
                new ReportColumn('open_weighted_amount', 'Open weighted pipeline', ColumnType::Money, 'currency'),
                new ReportColumn('forecast_amount', 'Forecast', ColumnType::Money, 'currency'),
                new ReportColumn('target_amount', 'Target', ColumnType::Money, 'currency'),
                new ReportColumn('target_attainment_bps', 'Target attainment', ColumnType::BasisPoints),
            ],
            filters: [new ReportFilter('currency', 'Currency (workspace currency when empty)', FilterType::Currency)],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $timezone = $context->timezone;
        $currency = $context->currency() ?? strtoupper($this->tenants->require()->currency);
        $overview = $this->analytics->overview(
            $context->user,
            CarbonImmutable::parse($context->from, $timezone),
            CarbonImmutable::parse($context->to, $timezone),
            $currency,
        );

        return new ReportResult([[
            'currency' => $currency,
            'quote_count' => (int) $overview['quote_count'],
            'accepted_quote_count' => (int) $overview['accepted_quote_count'],
            'conversion_bps' => $this->nullableInt($overview['conversion_bps']),
            'order_count' => (int) $overview['order_count'],
            'quoted_amount' => (int) $overview['quoted_amount'],
            'actual_sales_amount' => (int) $overview['actual_sales_amount'],
            'open_weighted_amount' => (int) $overview['open_weighted_amount'],
            'forecast_amount' => (int) $overview['forecast_amount'],
            'target_amount' => (int) $overview['target_amount'],
            'target_attainment_bps' => $this->nullableInt($overview['target_attainment_bps']),
        ]]);
    }

    private function nullableInt(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
