<?php

declare(strict_types=1);

namespace App\Modules\CRM\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportResult;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Lead;

/** Leads created in the period by source, with how many were qualified and converted. */
final class LeadSourceReport implements ReportProvider
{
    public const string PERMISSION = 'crm.leads.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'crm.lead_sources',
            module: 'crm',
            label: 'Leads by source',
            description: 'Leads created in the period by source, with how many were qualified and converted. Only the leads you may see are counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('source', 'Source'),
                new ReportColumn('leads', 'Leads', ColumnType::Integer),
                new ReportColumn('qualified', 'Qualified', ColumnType::Integer),
                new ReportColumn('converted', 'Converted', ColumnType::Integer),
                new ReportColumn('conversion_bps', 'Conversion rate', ColumnType::BasisPoints),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $converted = LeadStatus::Converted->value;

        $rows = $this->records->constrain(Lead::query(), $context->user, self::PERMISSION)
            ->leftJoin('crm_lead_sources as sources', fn ($join) => $join->on('sources.id', '=', 'crm_leads.source_id')->on('sources.tenant_id', '=', 'crm_leads.tenant_id'))
            ->whereBetween('crm_leads.created_at', [$context->fromAt, $context->toAt])
            ->groupBy('sources.id', 'sources.name')
            ->orderByDesc('leads')->orderBy('sources.name')
            ->select(['sources.name as source'])
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN crm_leads.qualified_at IS NOT NULL THEN 1 ELSE 0 END) as qualified')
            ->selectRaw('SUM(CASE WHEN crm_leads.status = ? THEN 1 ELSE 0 END) as converted', [$converted])
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        return new ReportResult($rows->map(static function (object $row): array {
            $leads = (int) $row->leads;
            $converted = (int) $row->converted;

            return [
                'source' => $row->source === null ? __('No source') : (string) $row->source,
                'leads' => $leads,
                'qualified' => (int) $row->qualified,
                'converted' => $converted,
                'conversion_bps' => $leads === 0 ? null : intdiv($converted * 20000 + $leads, $leads * 2),
            ];
        })->all());
    }
}
