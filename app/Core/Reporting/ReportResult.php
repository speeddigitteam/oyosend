<?php

declare(strict_types=1);

namespace App\Core\Reporting;

/**
 * Rows keyed by column key. A value is a scalar or null; null means "not reported" (never a
 * silent zero). Money is integer minor units next to its currency column.
 */
final readonly class ReportResult
{
    /**
     * @param  list<array<string, string|int|bool|null>>  $rows
     * @param  bool  $truncated  Set when the provider itself stopped early; the runner may add its own cap.
     */
    public function __construct(
        public array $rows,
        public bool $truncated = false,
    ) {}
}
