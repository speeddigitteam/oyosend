<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\EmailMarketing\Actions\CampaignReports;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;

/**
 * One row per campaign finished in the period, from this app's own delivery records. Rates are basis points and
 * empty when nothing was sent. Opens are not a column: the platform mailer sends plain text, so they cannot be
 * measured.
 */
final class CampaignPerformanceReport implements ReportProvider
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly CampaignReports $reports,
    ) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'email_marketing.campaigns',
            module: 'email_marketing',
            label: 'Email campaign performance',
            description: 'Planned, sent, blocked, bounced and clicked emails per campaign finished in the period. Opens are not measured.',
            permission: 'email_marketing.reports.view',
            columns: [
                new ReportColumn('campaign', 'Campaign'),
                new ReportColumn('finished_at', 'Finished', ColumnType::DateTime),
                new ReportColumn('planned', 'Planned', ColumnType::Integer),
                new ReportColumn('sent', 'Sent', ColumnType::Integer),
                new ReportColumn('blocked', 'Blocked', ColumnType::Integer),
                new ReportColumn('bounced', 'Bounced', ColumnType::Integer),
                new ReportColumn('clickers', 'Clicked', ColumnType::Integer),
                new ReportColumn('click_rate', 'Click rate', ColumnType::BasisPoints),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $campaigns = $this->records->constrain(Campaign::query(), $context->user, 'email_marketing.reports.view')
            ->where('status', CampaignStatus::Sent->value)
            ->whereBetween('finished_at', [$context->fromAt, $context->toAt])
            ->orderByDesc('finished_at')
            ->limit($context->rowLimit + 1)
            ->get();

        $rows = [];

        foreach ($campaigns as $campaign) {
            $figures = $this->reports->figures(Delivery::query()->where('campaign_id', $campaign->id));

            $rows[] = [
                'campaign' => $campaign->name,
                'finished_at' => $campaign->finished_at?->toIso8601String(),
                'planned' => $figures['planned'],
                'sent' => $figures['sentTotal'],
                'blocked' => $figures['blocked'],
                'bounced' => $figures['bounced'],
                'clickers' => $figures['clickers'],
                'click_rate' => $figures['clickRate'],
            ];
        }

        return new ReportResult($rows);
    }
}
