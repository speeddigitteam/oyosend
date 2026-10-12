<?php

declare(strict_types=1);

namespace App\Modules\Reports\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\Support\CsvWriter;
use App\Modules\Reports\Models\SavedReport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a {@see ReportOutput} into a CSV download and records the export in the audit trail. The
 * output was produced for the signed-in member with their own scope, so the file can never carry
 * more than the screen. Numbers are written raw (minor units, basis points, minutes) next to
 * their currency column; text cells are made formula-safe by {@see CsvWriter}.
 */
final class ReportExporter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function download(ReportOutput $output, ?SavedReport $saved = null): Response
    {
        $header = array_map(
            static fn (ReportColumn $column): string => __($column->label).$column->type->unitSuffix(),
            $output->columns,
        );
        $keys = array_map(static fn (ReportColumn $column): string => $column->key, $output->columns);
        $rows = [];

        foreach ($output->rows as $row) {
            $line = [];

            foreach ($keys as $key) {
                $value = $row[$key] ?? null;
                $line[] = is_string($value) || is_int($value) || is_bool($value) ? $value : null;
            }

            $rows[] = $line;
        }

        if ($output->truncated) {
            // An explicit, human-readable marker in the file itself (and the header below).
            $rows[] = [__('Truncated: the report has more rows than the export limit of :limit.', ['limit' => $output->rowLimit])];
        }

        $csv = CsvWriter::render($header, $rows);

        $this->audit->record('reports.report.exported', $saved, [
            'report' => $output->definition->key,
            'from' => $output->from,
            'to' => $output->to,
            'filters' => $output->filters,
            'columns' => $keys,
            'rows' => count($output->rows),
            'truncated' => $output->truncated,
            'scope' => $output->scope->value,
        ]);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $output->definition->key.'-'.$output->from.'_'.$output->to) ?? 'report';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
            'X-Report-Truncated' => $output->truncated ? '1' : '0',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
