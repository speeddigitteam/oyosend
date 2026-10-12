<?php

declare(strict_types=1);

namespace App\Modules\Projects\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\TimeEntry;

/** Logged time per project and member in the period. Approved entries unless another status is chosen. */
final class TimesheetReport implements ReportProvider
{
    public const string PERMISSION = 'projects.time.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'projects.timesheets',
            module: 'projects',
            label: 'Timesheets',
            description: 'Time logged per project and member in the period (approved entries unless you choose another status). Only the entries you may see are counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('project_code', 'Project code'),
                new ReportColumn('project', 'Project'),
                new ReportColumn('member', 'Member'),
                new ReportColumn('entries', 'Entries', ColumnType::Integer),
                new ReportColumn('minutes', 'Time', ColumnType::Minutes),
                new ReportColumn('billable_minutes', 'Billable time', ColumnType::Minutes),
            ],
            filters: [
                new ReportFilter('status', 'Entry status', FilterType::Select, [
                    'approved' => 'Approved', 'submitted' => 'Submitted', 'draft' => 'Draft', 'rejected' => 'Rejected',
                ]),
                new ReportFilter('project_id', 'Project', FilterType::Id),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $groups = $this->records->constrain(TimeEntry::query(), $context->user, self::PERMISSION)
            ->whereBetween('worked_on', [$context->from, $context->to])
            ->where('status', $context->string('status') ?? 'approved')
            ->when($context->string('project_id'), fn ($query, string $id) => $query->where('project_id', $id))
            ->groupBy('project_id', 'membership_id')
            ->orderBy('project_id')->orderBy('membership_id')
            ->select(['project_id', 'membership_id'])
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('COALESCE(SUM(minutes), 0) as minutes')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_billable = 1 THEN minutes ELSE 0 END), 0) as billable_minutes')
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        $projects = Project::query()->whereKey($groups->pluck('project_id')->unique()->all())->get(['id', 'code', 'name'])->keyBy('id');
        $members = TenantMembership::query()->whereKey($groups->pluck('membership_id')->unique()->all())->with('user:id,name')->get()->keyBy('id');

        return new ReportResult($groups->map(static fn (object $row): array => [
            'project_code' => $projects->get($row->project_id)?->code,
            'project' => $projects->get($row->project_id)?->name,
            'member' => $members->get($row->membership_id)?->user->name,
            'entries' => (int) $row->entries,
            'minutes' => (int) $row->minutes,
            'billable_minutes' => (int) $row->billable_minutes,
        ])->all());
    }
}
