<?php

declare(strict_types=1);

namespace App\Core\Reporting;

use InvalidArgumentException;

/** One column a report can show. Rows are keyed by column key. */
final readonly class ReportColumn
{
    /**
     * @param  string  $key  Lower snake case, unique within the report.
     * @param  string  $label  English label; translated through `__()` when presented.
     * @param  string|null  $currencyColumn  For {@see ColumnType::Money}: the key of the column holding the ISO currency.
     * @param  bool  $default  Whether the column is shown when the viewer picks none.
     */
    public function __construct(
        public string $key,
        public string $label,
        public ColumnType $type = ColumnType::Text,
        public ?string $currencyColumn = null,
        public bool $default = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid report column key [{$key}].");
        }

        if ($type === ColumnType::Money && $currencyColumn === null) {
            throw new InvalidArgumentException("Money column [{$key}] must name its currency column.");
        }

        if ($type !== ColumnType::Money && $currencyColumn !== null) {
            throw new InvalidArgumentException("Only a money column may name a currency column [{$key}].");
        }
    }
}
