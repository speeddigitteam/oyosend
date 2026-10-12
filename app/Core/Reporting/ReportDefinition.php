<?php

declare(strict_types=1);

namespace App\Core\Reporting;

use InvalidArgumentException;

/**
 * The declarative description of one report: who may run it, what it can show and what it can be
 * filtered by. Nothing in a definition is executable; a viewer's request is validated against it.
 */
final readonly class ReportDefinition
{
    /**
     * @param  string  $key  `{module}.{report}`, lower snake case, globally unique.
     * @param  string  $module  The module key that owns the report; the tenant must be entitled to it.
     * @param  string  $label  English title; translated through `__()` when presented.
     * @param  string  $permission  The permission the viewer must hold (effective, entitlement included).
     *                              When it is scopable the provider narrows its records to the viewer's scope.
     * @param  list<ReportColumn>  $columns
     * @param  list<ReportFilter>  $filters
     * @param  bool  $usesDateRange  Whether `from` / `to` mean something to the report (a snapshot report ignores them).
     */
    public function __construct(
        public string $key,
        public string $module,
        public string $label,
        public string $description,
        public string $permission,
        public array $columns,
        public array $filters = [],
        public bool $usesDateRange = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/D', $key) !== 1 || ! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException("Report key [{$key}] must be `{$module}.{report}`.");
        }

        if (! str_starts_with($permission, $module.'.')) {
            throw new InvalidArgumentException("Report [{$key}] must require a permission of its own module.");
        }

        if ($columns === []) {
            throw new InvalidArgumentException("Report [{$key}] declares no columns.");
        }

        $keys = array_map(static fn (ReportColumn $column): string => $column->key, $columns);

        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidArgumentException("Report [{$key}] declares a column twice.");
        }

        foreach ($columns as $column) {
            if ($column->currencyColumn !== null && ! in_array($column->currencyColumn, $keys, true)) {
                throw new InvalidArgumentException("Report [{$key}] column [{$column->key}] names an unknown currency column.");
            }
        }

        $filterKeys = array_map(static fn (ReportFilter $filter): string => $filter->key, $filters);

        if (count($filterKeys) !== count(array_unique($filterKeys))) {
            throw new InvalidArgumentException("Report [{$key}] declares a filter twice.");
        }
    }

    /** @return list<string> */
    public function columnKeys(): array
    {
        return array_map(static fn (ReportColumn $column): string => $column->key, $this->columns);
    }

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    public function filter(string $key): ?ReportFilter
    {
        foreach ($this->filters as $filter) {
            if ($filter->key === $key) {
                return $filter;
            }
        }

        return null;
    }
}
