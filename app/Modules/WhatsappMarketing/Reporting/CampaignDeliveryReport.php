<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\WhatsappMarketing\Actions\Analytics;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;

/** Campaigns started in the period, with how far each message got. Rates are `null` when there is nothing to divide. */
final class CampaignDeliveryReport implements ReportProvider
{
    public function __construct(private readonly Analytics $analytics) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'whatsapp_marketing.campaign_delivery',
            module: 'whatsapp_marketing',
            label: 'WhatsApp campaign delivery',
            description: 'Campaigns started in the period with recipients, messages sent, delivered and read, and the failures and blocks.',
            permission: 'whatsapp_marketing.analytics.view',
            columns: [
                new ReportColumn('name', 'Campaign', ColumnType::Text),
                new ReportColumn('status', 'Status', ColumnType::Text),
                new ReportColumn('started_at', 'Started', ColumnType::DateTime),
                new ReportColumn('recipients', 'Recipients', ColumnType::Integer),
                new ReportColumn('sent', 'Sent', ColumnType::Integer),
                new ReportColumn('delivered', 'Delivered', ColumnType::Integer),
                new ReportColumn('read', 'Read', ColumnType::Integer),
                new ReportColumn('failed', 'Failed', ColumnType::Integer),
                new ReportColumn('blocked', 'Blocked (no consent or suppressed)', ColumnType::Integer),
                new ReportColumn('skipped', 'Skipped', ColumnType::Integer),
                new ReportColumn('delivered_rate', 'Delivered rate', ColumnType::BasisPoints),
                new ReportColumn('read_rate', 'Read rate', ColumnType::BasisPoints),
            ],
            filters: [
                new ReportFilter('status', 'Status', FilterType::Select, array_combine(CampaignStatus::values(), CampaignStatus::values())),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = $this->analytics->campaigns($context->fromAt, $context->toAt, $context->rowLimit + 1, $context->string('status'));

        return new ReportResult(array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'status' => $row['status'],
            'started_at' => $row['startedAt'],
            'recipients' => $row['recipients'],
            'sent' => $row['sent'],
            'delivered' => $row['delivered'],
            'read' => $row['read'],
            'failed' => $row['failed'],
            'blocked' => $row['blocked'],
            'skipped' => $row['skipped'],
            'delivered_rate' => $row['deliveredBps'],
            'read_rate' => $row['readBps'],
        ], $rows));
    }
}
