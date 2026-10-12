<?php

declare(strict_types=1);

namespace App\Modules\Talent\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Talent\Actions\TalentAnalytics;

/** How many applications received in the period reached each pipeline stage. Sensitive: `talent.analytics.view`. */
final class RecruitmentFunnelReport implements ReportProvider
{
    public function __construct(private readonly TalentAnalytics $analytics) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'talent.recruitment_funnel',
            module: 'talent',
            label: 'Recruitment funnel',
            description: 'How many applications received in the period reached each pipeline stage, and their share of all applications.',
            permission: 'talent.analytics.view',
            columns: [
                new ReportColumn('position', 'Stage order', ColumnType::Integer, default: false),
                new ReportColumn('stage', 'Stage'),
                new ReportColumn('stage_type', 'Stage type'),
                new ReportColumn('reached', 'Applications reached', ColumnType::Integer),
                new ReportColumn('share_bps', 'Share of applications', ColumnType::BasisPoints),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $recruitment = $this->analytics->recruitment($context->from, $context->to);
        /** @var array{total: int} $applications */
        $applications = $recruitment['applications'];
        $total = (int) $applications['total'];
        /** @var list<array{stage: string, type: string, position: int, reached: int}> $funnel */
        $funnel = $recruitment['funnel'];

        return new ReportResult(array_map(static fn (array $stage): array => [
            'position' => $stage['position'],
            'stage' => $stage['stage'],
            'stage_type' => $stage['type'],
            'reached' => $stage['reached'],
            'share_bps' => $total === 0 ? null : intdiv($stage['reached'] * 20000 + $total, $total * 2),
        ], $funnel));
    }
}
