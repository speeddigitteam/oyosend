<?php

declare(strict_types=1);

namespace App\Modules\Reports\Actions;

use App\Core\Access\Enums\AccessScope;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportDefinition;

/** A finished, capped and column-projected run: what the screen and the CSV are both built from. */
final readonly class ReportOutput
{
    /**
     * @param  list<ReportColumn>  $columns  The columns shown, in the definition's order.
     * @param  list<array<string, string|int|bool|null>>  $rows  Keyed by the keys of `$columns`.
     * @param  array<string, string|int|bool>  $filters
     */
    public function __construct(
        public ReportDefinition $definition,
        public array $columns,
        public array $rows,
        public bool $truncated,
        public int $rowLimit,
        public string $from,
        public string $to,
        public array $filters,
        public AccessScope $scope,
    ) {}
}
