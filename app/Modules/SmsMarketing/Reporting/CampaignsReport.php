<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\SmsMarketing\Actions\CampaignStats;
use App\Modules\SmsMarketing\Models\Campaign;

/** One row per campaign started in the period: what happened to its recipients, from this app's own records. */
final class CampaignsReport implements ReportProvider
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sms_marketing.campaigns',
            module: 'sms_marketing',
            label: 'SMS campaigns',
            description: 'Recipients, messages the provider accepted, delivery receipts, failures and blocked contacts per campaign started in the period. Delivered counts only provider receipts.',
            permission: 'sms_marketing.analytics.view',
            columns: [
                new ReportColumn('campaign', 'Campaign'),
                new ReportColumn('status', 'Status'),
                new ReportColumn('started_at', 'Started', ColumnType::DateTime),
                new ReportColumn('recipients', 'Recipients', ColumnType::Integer),
                new ReportColumn('accepted', 'Accepted by provider', ColumnType::Integer),
                new ReportColumn('delivered', 'Delivered', ColumnType::Integer),
                new ReportColumn('failed', 'Failed', ColumnType::Integer),
                new ReportColumn('blocked', 'Blocked (no consent or suppressed)', ColumnType::Integer),
                new ReportColumn('skipped', 'Skipped', ColumnType::Integer),
                new ReportColumn('segments', 'Segments', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $campaigns = Campaign::query()
            ->whereBetween('started_at', [$context->fromAt, $context->toAt])
            ->orderByDesc('started_at')
            ->limit($context->rowLimit + 1)
            ->get(['id', 'name', 'status', 'started_at']);

        $stats = CampaignStats::for($campaigns->pluck('id')->all());

        return new ReportResult($campaigns->map(static fn (Campaign $campaign): array => [
            'campaign' => $campaign->name,
            'status' => $campaign->status->value,
            'started_at' => $campaign->started_at?->toIso8601String(),
            'recipients' => $stats[$campaign->id]['recipients'],
            'accepted' => $stats[$campaign->id]['accepted'],
            'delivered' => $stats[$campaign->id]['delivered'],
            'failed' => $stats[$campaign->id]['failed'],
            'blocked' => $stats[$campaign->id]['blocked'],
            'skipped' => $stats[$campaign->id]['skipped'],
            'segments' => $stats[$campaign->id]['segments'],
        ])->all());
    }
}
