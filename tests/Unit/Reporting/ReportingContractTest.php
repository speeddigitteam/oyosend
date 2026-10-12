<?php

declare(strict_types=1);

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\Support\CsvWriter;

it('neutralises every formula trigger in a text cell and leaves numbers alone', function (string $cell) {
    expect(CsvWriter::safe($cell))->toBe("'".$cell);
})->with(['=1+1', '+1', '-1', '@x', "\tx", "\rx", "\nx", ' =1+1', "\u{00A0}@x"]);

it('does not touch ordinary text', function (string $cell) {
    expect(CsvWriter::safe($cell))->toBe($cell);
})->with(['Plain', '', 'a=b', 'x-y', 'Total: 5', 'Café', 'বাংলা']);

it('writes integers as numbers (including negatives), quotes every field and starts with a BOM', function () {
    $csv = CsvWriter::render(['A', 'B'], [[-5, '=2'], [null, true], [1.5, 'say "hi"']]);

    expect($csv)->toBe(CsvWriter::BOM."\"A\",\"B\"\r\n\"-5\",\"'=2\"\r\n\"\",\"1\"\r\n\"1.5\",\"say \"\"hi\"\"\"\r\n");
});

it('refuses a malformed report definition', function () {
    $column = new ReportColumn('name', 'Name');

    expect(fn () => new ReportDefinition('pipeline', 'crm', 'x', 'x', 'crm.deals.view', [$column]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportDefinition('hrm.pipeline', 'crm', 'x', 'x', 'crm.deals.view', [$column]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportDefinition('crm.pipeline', 'crm', 'x', 'x', 'hrm.reports.view', [$column]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportDefinition('crm.pipeline', 'crm', 'x', 'x', 'crm.deals.view', []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportDefinition('crm.pipeline', 'crm', 'x', 'x', 'crm.deals.view', [$column, $column]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportDefinition('crm.pipeline', 'crm', 'x', 'x', 'crm.deals.view', [new ReportColumn('amount', 'A', ColumnType::Money, 'currency')]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportDefinition('crm.pipeline', 'crm', 'x', 'x', 'crm.deals.view', [$column], [new ReportFilter('a', 'A'), new ReportFilter('a', 'B')]))->toThrow(InvalidArgumentException::class);
});

it('requires a money column to name its currency and nothing else to', function () {
    expect(fn () => new ReportColumn('amount', 'Amount', ColumnType::Money))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportColumn('count', 'Count', ColumnType::Integer, 'currency'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportColumn('Bad Key', 'Bad'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportFilter('kind', 'Kind', FilterType::Select))->toThrow(InvalidArgumentException::class);
});
