<?php

declare(strict_types=1);

namespace App\Modules\Automation\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Automation\Enums\RunMode;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;

/** How live workflow runs started in the period ended, per workflow. Test (dry-run) runs are left out. */
final class RunOutcomesReport implements ReportProvider
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'automation.run_outcomes',
            module: 'automation',
            label: 'Workflow run outcomes',
            description: 'Live workflow runs started in the period by outcome, per workflow. Test runs are not counted.',
            permission: 'automation.runs.view',
            columns: [
                new ReportColumn('workflow', 'Workflow'),
                new ReportColumn('runs', 'Runs', ColumnType::Integer),
                new ReportColumn('succeeded', 'Succeeded', ColumnType::Integer),
                new ReportColumn('failed', 'Failed', ColumnType::Integer),
                new ReportColumn('cancelled', 'Cancelled', ColumnType::Integer),
                new ReportColumn('in_progress', 'In progress', ColumnType::Integer),
                new ReportColumn('success_bps', 'Success rate', ColumnType::BasisPoints),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $groups = Run::query()
            ->where('mode', RunMode::Live->value)
            ->whereBetween('created_at', [$context->fromAt, $context->toAt])
            ->groupBy('workflow_id', 'status')
            ->select(['workflow_id', 'status'])
            ->selectRaw('COUNT(*) as total')
            ->toBase()
            ->get();

        /** @var array<string, array{runs: int, succeeded: int, failed: int, cancelled: int, in_progress: int}> $byWorkflow */
        $byWorkflow = [];

        foreach ($groups as $group) {
            $id = (string) $group->workflow_id;
            $line = $byWorkflow[$id] ?? ['runs' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0, 'in_progress' => 0];
            $total = (int) $group->total;
            $line['runs'] += $total;

            match ($group->status) {
                RunStatus::Succeeded->value => $line['succeeded'] += $total,
                RunStatus::Failed->value => $line['failed'] += $total,
                RunStatus::Cancelled->value => $line['cancelled'] += $total,
                default => $line['in_progress'] += $total,
            };

            $byWorkflow[$id] = $line;
        }

        $names = Workflow::query()->whereKey(array_keys($byWorkflow))->pluck('name', 'id');
        $rows = [];

        foreach ($byWorkflow as $workflowId => $line) {
            $finished = $line['succeeded'] + $line['failed'];
            $rows[] = [
                'workflow' => (string) ($names[$workflowId] ?? $workflowId),
                ...$line,
                'success_bps' => $finished === 0 ? null : intdiv($line['succeeded'] * 20000 + $finished, $finished * 2),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['runs'], $a['workflow']] <=> [$a['runs'], $b['workflow']]);

        return new ReportResult(array_slice($rows, 0, $context->rowLimit + 1));
    }
}
