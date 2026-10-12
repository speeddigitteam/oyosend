<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\SmsMarketing\Actions\SmsAnalytics;

/** Messages handed to the provider per workspace day, with their outcome and segments. */
final class DailyReport implements ReportProvider
{
    public function __construct(private readonly SmsAnalytics $analytics) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sms_marketing.daily',
            module: 'sms_marketing',
            label: 'SMS per day',
            description: 'Messages the provider accepted (sent and delivered), failures and blocked contacts per day in the workspace timezone, with the segments used.',
            permission: 'sms_marketing.analytics.view',
            columns: [
                new ReportColumn('date', 'Date', ColumnType::Date),
                new ReportColumn('sent', 'Sent (no receipt)', ColumnType::Integer),
                new ReportColumn('delivered', 'Delivered', ColumnType::Integer),
                new ReportColumn('failed', 'Failed', ColumnType::Integer),
                new ReportColumn('blocked', 'Blocked', ColumnType::Integer),
                new ReportColumn('segments', 'Segments', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        return new ReportResult($this->analytics->daily($context->fromAt, $context->toAt));
    }
}
