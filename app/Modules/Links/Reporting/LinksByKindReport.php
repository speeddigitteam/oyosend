<?php

declare(strict_types=1);

namespace App\Modules\Links\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Links\Enums\LinkKind;
use App\Modules\Links\Models\Link;

/** Links saved in the period by kind, with how many are pinned. */
final class LinksByKindReport implements ReportProvider
{
    public const string PERMISSION = 'links.links.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'links.by_kind',
            module: 'links',
            label: 'Links by kind',
            description: 'Links saved in the period by kind, with how many are pinned. Only the links you may see are counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('kind', 'Kind'),
                new ReportColumn('links', 'Links', ColumnType::Integer),
                new ReportColumn('pinned', 'Pinned', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = $this->records->constrain(Link::query(), $context->user, self::PERMISSION)
            ->whereBetween('links.created_at', [$context->fromAt, $context->toAt])
            ->groupBy('links.kind')
            ->orderByDesc('links')->orderBy('links.kind')
            ->select(['links.kind'])
            ->selectRaw('COUNT(*) as links')
            ->selectRaw('SUM(CASE WHEN links.is_pinned = 1 THEN 1 ELSE 0 END) as pinned')
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        return new ReportResult($rows->map(static fn (object $row): array => [
            'kind' => __(LinkKind::tryFrom((string) $row->kind)?->label() ?? (string) $row->kind),
            'links' => (int) $row->links,
            'pinned' => (int) $row->pinned,
        ])->all());
    }
}
