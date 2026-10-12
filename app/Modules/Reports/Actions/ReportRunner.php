<?php

declare(strict_types=1);

namespace App\Modules\Reports\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Tenancy\TenantContext;
use App\Modules\Reports\Enums\RangeMode;
use App\Modules\Reports\Models\SavedReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates a request against a provider's declaration, runs the provider as the viewer, caps the
 * rows (with an explicit `truncated` flag) and keeps only the chosen columns.
 *
 * The provider's own definition is the whole schema: dates are bounded, columns must be declared,
 * filters must be declared and typed. Nothing a viewer sends is ever part of a query other than as
 * a bound value inside the provider.
 */
final class ReportRunner
{
    public const int MAX_DAYS = 366;

    public const int DEFAULT_DAYS = 30;

    /** The most rows a screen or an export carries; a larger result is cut and flagged. */
    public const int ROW_LIMIT = 2000;

    public function __construct(
        private readonly AccessChecker $access,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Validate the request input against the definition.
     *
     * @param  array<string, mixed>  $input  `from`, `to`, `columns`, `filters`
     * @return array{from: string, to: string, columns: list<string>, filters: array<string, string|int|bool>}
     *
     * @throws ValidationException
     */
    public function normalise(ReportDefinition $definition, array $input): array
    {
        $rules = [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'columns' => ['nullable', 'array', 'max:'.count($definition->columns)],
            'columns.*' => ['string', 'in:'.implode(',', $definition->columnKeys())],
            'filters' => ['nullable', 'array'],
        ];

        foreach ($definition->filters as $filter) {
            $rules['filters.'.$filter->key] = $filter->rules();
        }

        $data = Validator::make($input, $rules)->validate();

        // The validated data keeps only keys that have a rule, so unknown keys are read from the input.
        $given = array_keys(is_array($input['filters'] ?? null) ? $input['filters'] : []);
        $unknown = array_diff($given, array_map(static fn ($filter): string => $filter->key, $definition->filters));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => __('This report has no such filter.')]);
        }

        $filters = [];

        foreach ($definition->filters as $filter) {
            $value = $data['filters'][$filter->key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $filters[$filter->key] = match ($filter->type) {
                FilterType::Integer => (int) $value,
                FilterType::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                FilterType::Currency => strtoupper((string) $value),
                default => (string) $value,
            };
        }

        [$from, $to] = $this->range($data['from'] ?? null, $data['to'] ?? null);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'columns' => array_values(array_unique($data['columns'] ?? [])),
            'filters' => $filters,
        ];
    }

    /**
     * Run a provider as the viewer.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function run(User $user, ReportProvider $provider, array $input): ReportOutput
    {
        $definition = $provider->definition();
        $scope = $this->access->scopeFor($user, $definition->permission);
        abort_if($scope === null, 404);

        $request = $this->normalise($definition, $input);
        $timezone = $this->timezone();
        $context = new ReportContext(
            user: $user,
            scope: $scope,
            from: $request['from'],
            to: $request['to'],
            fromAt: CarbonImmutable::createFromFormat('!Y-m-d', $request['from'], $timezone)->startOfDay()->utc(),
            toAt: CarbonImmutable::createFromFormat('!Y-m-d', $request['to'], $timezone)->endOfDay()->utc(),
            timezone: $timezone,
            filters: $request['filters'],
            rowLimit: self::ROW_LIMIT,
        );

        $result = $provider->run($context);
        $rows = $result->rows;
        $truncated = $result->truncated || count($rows) > self::ROW_LIMIT;

        if (count($rows) > self::ROW_LIMIT) {
            $rows = array_slice($rows, 0, self::ROW_LIMIT);
        }

        $columns = $this->columns($definition, $request['columns']);
        $keys = array_map(static fn (ReportColumn $column): string => $column->key, $columns);
        $projected = [];

        foreach ($rows as $row) {
            $line = [];

            foreach ($keys as $key) {
                $line[$key] = $row[$key] ?? null;
            }

            $projected[] = $line;
        }

        return new ReportOutput(
            definition: $definition,
            columns: $columns,
            rows: $projected,
            truncated: $truncated,
            rowLimit: self::ROW_LIMIT,
            from: $request['from'],
            to: $request['to'],
            filters: $request['filters'],
            scope: $scope,
        );
    }

    /**
     * The input a saved report stands for today.
     *
     * @return array{from: string, to: string, columns: list<string>|null, filters: array<string, mixed>}
     */
    public function savedInput(SavedReport $saved): array
    {
        $today = CarbonImmutable::now($this->timezone())->startOfDay();

        if ($saved->range_mode === RangeMode::Fixed && $saved->range_from !== null && $saved->range_to !== null) {
            $from = $saved->range_from->toDateString();
            $to = $saved->range_to->toDateString();
        } else {
            $to = $today->toDateString();
            $from = $today->subDays(max(1, min(self::MAX_DAYS, $saved->range_days)) - 1)->toDateString();
        }

        return ['from' => $from, 'to' => $to, 'columns' => $saved->columns, 'filters' => $saved->filters ?? []];
    }

    /**
     * The columns shown: the chosen ones (or the defaults) in declared order, plus the currency
     * column of any money column shown so an amount never appears without its currency.
     *
     * @param  list<string>  $chosen
     * @return list<ReportColumn>
     */
    private function columns(ReportDefinition $definition, array $chosen): array
    {
        $wanted = $chosen === []
            ? array_map(static fn (ReportColumn $column): string => $column->key, array_filter($definition->columns, static fn (ReportColumn $column): bool => $column->default))
            : $chosen;

        foreach ($wanted as $key) {
            $column = $definition->column($key);

            if ($column?->type === ColumnType::Money && $column->currencyColumn !== null) {
                $wanted[] = $column->currencyColumn;
            }
        }

        return array_values(array_filter(
            $definition->columns,
            static fn (ReportColumn $column): bool => in_array($column->key, $wanted, true),
        ));
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function range(?string $from, ?string $to): array
    {
        $timezone = $this->timezone();

        try {
            $end = $to === null ? CarbonImmutable::now($timezone)->startOfDay() : CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);
            $start = $from === null ? $end->subDays(self::DEFAULT_DAYS - 1) : CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages(['to' => __('Choose a valid date range.')]);
        }

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['to' => __('The end date must be on or after the start date.')]);
        }

        if ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Choose a range of at most :days days.', ['days' => self::MAX_DAYS])]);
        }

        return [$start, $end];
    }

    private function timezone(): string
    {
        return $this->tenants->require()->timezone;
    }
}
