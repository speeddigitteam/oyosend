<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Models\SocialAccount;

/**
 * Audience comments received per connected account in the period, how many were answered, how long the first
 * answer took and how many are still waiting. Only comments the viewer's `social.comments.view` scope reaches.
 */
final class CommentVolumeReport implements ReportProvider
{
    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'social.comment_volume',
            module: 'social',
            label: 'Comment volume and response time',
            description: 'Audience comments received per account, how many were answered, the average time to the first answer and how many still wait for one.',
            permission: 'social.comments.view',
            columns: [
                new ReportColumn('account', 'Account'),
                new ReportColumn('platform', 'Platform'),
                new ReportColumn('received', 'Received', ColumnType::Integer),
                new ReportColumn('replied', 'Answered', ColumnType::Integer),
                new ReportColumn('response_minutes', 'Average time to answer', ColumnType::Minutes),
                new ReportColumn('waiting', 'Waiting', ColumnType::Integer),
                new ReportColumn('hidden', 'Hidden', ColumnType::Integer),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = $this->records->constrain(AudienceComment::query(), $context->user, 'social.comments.view')
            ->where('is_from_page', false)
            ->whereBetween('commented_at', [$context->fromAt, $context->toAt])
            ->selectRaw('account_id, COUNT(*) as received')
            ->selectRaw('SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) as replied')
            ->selectRaw('AVG(CASE WHEN replied_at IS NOT NULL AND replied_at >= commented_at THEN TIMESTAMPDIFF(MINUTE, commented_at, replied_at) END) as response_minutes')
            ->selectRaw('SUM(CASE WHEN handled_at IS NULL AND remote_state != ? THEN 1 ELSE 0 END) as waiting', [RemoteState::Deleted->value])
            ->selectRaw('SUM(CASE WHEN remote_state = ? THEN 1 ELSE 0 END) as hidden', [RemoteState::Hidden->value])
            ->groupBy('account_id')
            ->limit($context->rowLimit + 1)
            ->get();

        $accounts = SocialAccount::query()->whereIn('id', $rows->pluck('account_id'))->get()->keyBy('id');

        return new ReportResult($rows->map(static function (AudienceComment $row) use ($accounts): array {
            $account = $accounts->get($row->account_id);
            $average = $row->getAttribute('response_minutes');

            return [
                'account' => $account instanceof SocialAccount ? $account->name : null,
                'platform' => $account instanceof SocialAccount ? $account->platform : null,
                'received' => (int) $row->getAttribute('received'),
                'replied' => (int) $row->getAttribute('replied'),
                // No answered comment in the period means "not reported", never zero.
                'response_minutes' => $average === null ? null : (int) round((float) $average),
                'waiting' => (int) $row->getAttribute('waiting'),
                'hidden' => (int) $row->getAttribute('hidden'),
            ];
        })->all());
    }
}
