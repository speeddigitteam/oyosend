<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http;

use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Modules\Reports\Actions\ReportOutput;
use App\Modules\Reports\Actions\ReportRunner;
use App\Modules\Reports\Models\SavedReport;

/** The shapes sent to the report pages. Labels are translated here; values stay raw. */
final class ReportPresenter
{
    public const int PER_PAGE = 50;

    /** @return array<string, mixed> */
    public static function definition(ReportDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'module' => $definition->module,
            'label' => __($definition->label),
            'description' => __($definition->description),
            'usesDateRange' => $definition->usesDateRange,
            'columns' => array_map(static fn (ReportColumn $column): array => self::column($column), $definition->columns),
            'defaultColumns' => array_values(array_map(
                static fn (ReportColumn $column): string => $column->key,
                array_filter($definition->columns, static fn (ReportColumn $column): bool => $column->default),
            )),
            'filters' => array_map(static fn (ReportFilter $filter): array => [
                'key' => $filter->key,
                'label' => __($filter->label),
                'type' => $filter->type->value,
                'options' => array_map(
                    static fn (string $value, string $label): array => ['value' => $value, 'label' => __($label)],
                    array_keys($filter->options),
                    array_values($filter->options),
                ),
                'min' => $filter->min,
                'max' => $filter->max,
            ], $definition->filters),
        ];
    }

    /** @return array<string, mixed> */
    public static function column(ReportColumn $column): array
    {
        return [
            'key' => $column->key,
            'label' => __($column->label),
            'type' => $column->type->value,
            'currencyColumn' => $column->currencyColumn,
        ];
    }

    /**
     * One page of a result.
     *
     * @return array<string, mixed>
     */
    public static function result(ReportOutput $output, int $page): array
    {
        $total = count($output->rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $lastPage));

        return [
            'columns' => array_map(static fn (ReportColumn $column): array => self::column($column), $output->columns),
            'rows' => array_slice($output->rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => ['currentPage' => $page, 'lastPage' => $lastPage, 'perPage' => self::PER_PAGE, 'total' => $total],
            'truncated' => $output->truncated,
            'rowLimit' => $output->rowLimit,
            'scope' => $output->scope->value,
            'from' => $output->from,
            'to' => $output->to,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function saved(SavedReport $saved, bool $isOwner, bool $canManage, bool $available, ?string $providerLabel): array
    {
        return [
            'id' => $saved->id,
            'name' => $saved->name,
            'providerKey' => $saved->provider_key,
            'providerLabel' => $providerLabel === null ? null : __($providerLabel),
            'available' => $available,
            'columns' => $saved->columns ?? [],
            'filters' => $saved->filters ?? [],
            'rangeMode' => $saved->range_mode->value,
            'rangeDays' => $saved->range_days,
            'rangeFrom' => $saved->range_from?->toDateString(),
            'rangeTo' => $saved->range_to?->toDateString(),
            'visibility' => $saved->visibility->value,
            'roleId' => $saved->role_id,
            'departmentId' => $saved->department_id,
            'ownerName' => $saved->owner?->user?->name,
            'isOwner' => $isOwner,
            'canManage' => $canManage,
            'updatedAt' => $saved->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{maxDays: int, defaultDays: int, rowLimit: int} */
    public static function bounds(): array
    {
        return ['maxDays' => ReportRunner::MAX_DAYS, 'defaultDays' => ReportRunner::DEFAULT_DAYS, 'rowLimit' => ReportRunner::ROW_LIMIT];
    }
}
