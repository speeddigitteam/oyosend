<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\Social\LeadAds\LeadStatus;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Facebook lead ads received per day, Page and form, with how many reached the CRM, from this application's records. */
final class LeadAdsReport implements ReportProvider
{
    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'social.lead_ads',
            module: 'social',
            label: 'Facebook lead ads',
            description: 'Leads received from Facebook lead forms per day, Page and form, and what became of them.',
            permission: 'social.leadads.view',
            columns: [
                new ReportColumn('day', 'Day', ColumnType::Date),
                new ReportColumn('page', 'Page'),
                new ReportColumn('form', 'Form'),
                new ReportColumn('received', 'Received', ColumnType::Integer),
                new ReportColumn('captured', 'Handed to the CRM', ColumnType::Integer),
                new ReportColumn('failed', 'Failed', ColumnType::Integer),
                new ReportColumn('waiting', 'Waiting or skipped', ColumnType::Integer, default: false),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $offset = CarbonImmutable::now($context->timezone)->utcOffset() * 60;

        $rows = LeadAdLead::query()
            ->where('received_at', '>=', $context->fromAt)
            ->where('received_at', '<=', $context->toAt)
            ->select(
                DB::raw('DATE(DATE_ADD(received_at, INTERVAL '.(int) $offset.' SECOND)) as day'),
                'account_id',
                'form_id',
                'external_form_id',
                'status',
                DB::raw('count(*) as total'),
            )
            ->groupBy('day', 'account_id', 'form_id', 'external_form_id', 'status')
            ->orderBy('day')
            ->limit(($context->rowLimit + 1) * 6)
            ->get();

        $pages = SocialAccount::query()->whereIn('id', $rows->pluck('account_id')->unique()->all())->pluck('name', 'id');
        $forms = LeadForm::query()->whereIn('id', $rows->pluck('form_id')->filter()->unique()->all())->pluck('name', 'id');

        $grouped = [];

        foreach ($rows as $row) {
            $key = $row->getAttribute('day').'|'.$row->account_id.'|'.($row->form_id ?? $row->external_form_id ?? '');
            $status = $row->status;
            $total = (int) $row->getAttribute('total');

            $grouped[$key] ??= [
                'day' => (string) $row->getAttribute('day'),
                'page' => (string) ($pages[$row->account_id] ?? '-'),
                'form' => $row->form_id !== null ? (string) ($forms[$row->form_id] ?? '-') : (string) ($row->external_form_id ?? '-'),
                'received' => 0,
                'captured' => 0,
                'failed' => 0,
                'waiting' => 0,
            ];
            $grouped[$key]['received'] += $total;

            match (true) {
                $status->isFinal() => $grouped[$key]['captured'] += $total,
                $status === LeadStatus::Failed => $grouped[$key]['failed'] += $total,
                default => $grouped[$key]['waiting'] += $total,
            };
        }

        $out = array_values($grouped);

        return new ReportResult(array_slice($out, 0, $context->rowLimit + 1), count($out) > $context->rowLimit);
    }
}
