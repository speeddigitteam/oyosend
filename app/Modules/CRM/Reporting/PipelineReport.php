<?php

declare(strict_types=1);

namespace App\Modules\CRM\Reporting;

use App\Core\Access\RecordAccess;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;
use App\Modules\CRM\Models\Deal;

/** Deals created in the period, by pipeline stage: how many, how much, and the probability-weighted amount. */
final class PipelineReport implements ReportProvider
{
    public const string PERMISSION = 'crm.deals.view';

    public function __construct(private readonly RecordAccess $records) {}

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'crm.pipeline',
            module: 'crm',
            label: 'Sales pipeline',
            description: 'Deals created in the period, by pipeline stage, with amounts and the probability-weighted amount. Only the deals you may see are counted.',
            permission: self::PERMISSION,
            columns: [
                new ReportColumn('pipeline', 'Pipeline'),
                new ReportColumn('stage', 'Stage'),
                new ReportColumn('currency', 'Currency'),
                new ReportColumn('deals', 'Deals', ColumnType::Integer),
                new ReportColumn('amount', 'Amount', ColumnType::Money, 'currency'),
                new ReportColumn('weighted_amount', 'Weighted amount', ColumnType::Money, 'currency'),
            ],
            filters: [
                new ReportFilter('pipeline_id', 'Pipeline', FilterType::Id),
                new ReportFilter('status', 'Status', FilterType::Select, ['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost']),
                new ReportFilter('currency', 'Currency', FilterType::Currency),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        $rows = $this->records->constrain(Deal::query(), $context->user, self::PERMISSION)
            ->join('crm_pipeline_stages as stages', fn ($join) => $join->on('stages.id', '=', 'crm_deals.stage_id')->on('stages.tenant_id', '=', 'crm_deals.tenant_id'))
            ->join('crm_pipelines as pipelines', fn ($join) => $join->on('pipelines.id', '=', 'crm_deals.pipeline_id')->on('pipelines.tenant_id', '=', 'crm_deals.tenant_id'))
            ->whereBetween('crm_deals.created_at', [$context->fromAt, $context->toAt])
            ->when($context->string('pipeline_id'), fn ($query, string $id) => $query->where('crm_deals.pipeline_id', $id))
            ->when($context->string('status'), fn ($query, string $status) => $query->where('crm_deals.status', $status))
            ->when($context->currency(), fn ($query, string $currency) => $query->where('crm_deals.currency', $currency))
            ->groupBy('pipelines.id', 'pipelines.name', 'stages.id', 'stages.name', 'stages.position', 'crm_deals.currency')
            ->orderBy('pipelines.name')->orderBy('stages.position')->orderBy('crm_deals.currency')
            ->select(['pipelines.name as pipeline', 'stages.name as stage', 'crm_deals.currency as currency'])
            ->selectRaw('COUNT(*) as deals')
            ->selectRaw('COALESCE(SUM(crm_deals.amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(crm_deals.amount * stages.probability) DIV 100, 0) as weighted_amount')
            ->limit($context->rowLimit + 1)
            ->toBase()
            ->get();

        return new ReportResult($rows->map(static fn (object $row): array => [
            'pipeline' => (string) $row->pipeline,
            'stage' => (string) $row->stage,
            'currency' => (string) $row->currency,
            'deals' => (int) $row->deals,
            'amount' => (int) $row->amount,
            'weighted_amount' => (int) $row->weighted_amount,
        ])->all());
    }
}
