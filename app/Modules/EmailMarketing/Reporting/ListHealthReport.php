<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Subscription;

/** Members of every list by state, as of now (a snapshot, so the date range does not apply). */
final class ListHealthReport implements ReportProvider
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'email_marketing.lists',
            module: 'email_marketing',
            label: 'Email list health',
            description: 'Subscribed, pending, unsubscribed, bounced and complained members of each list right now.',
            permission: 'email_marketing.reports.view',
            columns: [
                new ReportColumn('list', 'List'),
                new ReportColumn('subscribed', 'Subscribed', ColumnType::Integer),
                new ReportColumn('pending', 'Pending', ColumnType::Integer),
                new ReportColumn('unsubscribed', 'Unsubscribed', ColumnType::Integer),
                new ReportColumn('bounced', 'Bounced', ColumnType::Integer),
                new ReportColumn('complained', 'Complained', ColumnType::Integer),
            ],
            usesDateRange: false,
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $counts = Subscription::query()
            ->selectRaw('list_id, status, count(*) as total')
            ->groupBy('list_id', 'status')
            ->get();

        $byList = [];

        foreach ($counts as $row) {
            $byList[$row->list_id][$row->status->value] = (int) $row->getAttribute('total');
        }

        $rows = [];

        foreach (MailingList::query()->orderBy('name')->limit($context->rowLimit + 1)->get() as $list) {
            $rows[] = [
                'list' => $list->name,
                'subscribed' => $byList[$list->id]['subscribed'] ?? 0,
                'pending' => $byList[$list->id]['pending'] ?? 0,
                'unsubscribed' => $byList[$list->id]['unsubscribed'] ?? 0,
                'bounced' => $byList[$list->id]['bounced'] ?? 0,
                'complained' => $byList[$list->id]['complained'] ?? 0,
            ];
        }

        return new ReportResult($rows);
    }
}
