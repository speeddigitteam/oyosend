<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

/** An already-formatted decimal amount for a CSV cell (see {@see SafeCsv::number()}). */
final readonly class CsvNumber
{
    public function __construct(public string $value) {}
}
