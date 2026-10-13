<?php

declare(strict_types=1);

namespace App\Modules\Notes\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Notes\Models\Note;

/** Notes created in the period by their author, with how many are pinned. */
final class NotesByAuthorReport implements ReportProvider
{
    public const string PERMISSION = 'notes.notes.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'notes.by_author',
            module: 'notes',
            label: 'Notes by author',
            description: 'Notes created in the period by their author, with how many are pinned. Only the notes you may see are counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('author', 'Author'),
                new ReportColumn('notes', 'Notes', ColumnType::Integer),
                new ReportColumn('pinned', 'Pinned', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = $this->records->constrain(Note::query(), $context->user, self::PERMISSION)
            ->leftJoin('tenant_memberships as tm', fn ($join) => $join->on('tm.id', '=', 'notes.owner_id')->on('tm.tenant_id', '=', 'notes.tenant_id'))
            ->leftJoin('users as u', 'u.id', '=', 'tm.user_id')
            ->whereBetween('notes.created_at', [$context->fromAt, $context->toAt])
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('notes')->orderBy('u.name')
            ->select(['u.name as author'])
            ->selectRaw('COUNT(*) as notes')
            ->selectRaw('SUM(CASE WHEN notes.is_pinned = 1 THEN 1 ELSE 0 END) as pinned')
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        return new ReportResult($rows->map(static fn (object $row): array => [
            'author' => $row->author === null ? __('No author') : (string) $row->author,
            'notes' => (int) $row->notes,
            'pinned' => (int) $row->pinned,
        ])->all());
    }
}
