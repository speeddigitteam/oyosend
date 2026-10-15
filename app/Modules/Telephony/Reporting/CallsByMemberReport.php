<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Telephony\Models\Call;

/** Calls in the period by member: how many, how many answered, missed, and the talk time. */
final class CallsByMemberReport implements ReportProvider
{
    public const string PERMISSION = 'telephony.calls.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'telephony.calls_by_member',
            module: 'telephony',
            label: 'Calls by member',
            description: 'Calls started in the period by the member who made or took them: answered, missed and talk time. Only the calls you may see are counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('member', 'Member'),
                new ReportColumn('calls', 'Calls', ColumnType::Integer),
                new ReportColumn('outbound', 'Outgoing', ColumnType::Integer),
                new ReportColumn('inbound', 'Incoming', ColumnType::Integer),
                new ReportColumn('answered', 'Answered', ColumnType::Integer),
                new ReportColumn('missed', 'Missed', ColumnType::Integer),
                new ReportColumn('talk_minutes', 'Talk time (minutes)', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = $this->records->constrain(Call::query(), $context->user, self::PERMISSION)
            ->leftJoin('tenant_memberships as tm', fn ($join) => $join->on('tm.id', '=', 'telephony_calls.owner_id')->on('tm.tenant_id', '=', 'telephony_calls.tenant_id'))
            ->leftJoin('users as u', 'u.id', '=', 'tm.user_id')
            ->whereBetween('telephony_calls.started_at', [$context->fromAt, $context->toAt])
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('calls')->orderBy('u.name')
            ->select(['u.name as member'])
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw("SUM(CASE WHEN telephony_calls.direction = 'outbound' THEN 1 ELSE 0 END) as outbound")
            ->selectRaw("SUM(CASE WHEN telephony_calls.direction = 'inbound' THEN 1 ELSE 0 END) as inbound")
            ->selectRaw('SUM(CASE WHEN telephony_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered')
            ->selectRaw("SUM(CASE WHEN telephony_calls.status = 'missed' THEN 1 ELSE 0 END) as missed")
            ->selectRaw('COALESCE(SUM(telephony_calls.duration_seconds), 0) as talk_seconds')
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        return new ReportResult($rows->map(static fn (object $row): array => [
            'member' => $row->member === null ? __('No member') : (string) $row->member,
            'calls' => (int) $row->calls,
            'outbound' => (int) $row->outbound,
            'inbound' => (int) $row->inbound,
            'answered' => (int) $row->answered,
            'missed' => (int) $row->missed,
            'talk_minutes' => intdiv((int) $row->talk_seconds + 59, 60),
        ])->all());
    }
}
