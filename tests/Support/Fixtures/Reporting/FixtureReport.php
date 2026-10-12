<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Reporting;

use App\Core\Reporting\ColumnType;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\FilterType;
use App\Core\Reporting\ReportColumn;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportDefinition;
use App\Core\Reporting\ReportFilter;
use App\Core\Reporting\ReportResult;

/**
 * A provider owned by the `reports` module itself (so it is entitled wherever the app is), whose
 * rows a test controls: `FixtureReport::$rows`, and the last context it was run with.
 */
final class FixtureReport implements ReportProvider
{
    /** @var list<array<string, string|int|bool|null>> */
    public static array $rows = [];

    public static ?ReportContext $last = null;

    public static int $runs = 0;

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'reports.fixture',
            module: 'reports',
            label: 'Fixture report',
            description: 'A report used by tests.',
            permission: 'reports.reports.view',
            columns: [
                new ReportColumn('name', 'Name'),
                new ReportColumn('currency', 'Currency', default: false),
                new ReportColumn('amount', 'Amount', ColumnType::Money, 'currency'),
                new ReportColumn('count', 'Count', ColumnType::Integer),
                new ReportColumn('note', 'Note', default: false),
            ],
            filters: [
                new ReportFilter('kind', 'Kind', FilterType::Select, ['a' => 'A', 'b' => 'B']),
                new ReportFilter('limit', 'Limit', FilterType::Integer, min: 1, max: 10),
                new ReportFilter('flag', 'Flag', FilterType::Boolean),
            ],
        );
    }

    public function run(ReportContext $context): ReportResult
    {
        self::$last = $context;
        self::$runs++;

        return new ReportResult(self::$rows);
    }

    public static function reset(): void
    {
        self::$rows = [];
        self::$last = null;
        self::$runs = 0;
    }
}
