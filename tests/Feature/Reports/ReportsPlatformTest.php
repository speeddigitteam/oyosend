<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportRegistry;
use App\Core\Reporting\Support\CsvWriter;
use App\Modules\Reports\Actions\ReportRunner;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Fixtures\Reporting\FixtureReport;
use Tests\Support\InteractsWithReports;

uses(InteractsWithReports::class);

beforeEach(function () {
    FixtureReport::reset();
    $this->app->tag([FixtureReport::class], ReportProvider::TAG);
    $this->reportsAt('2026-03-09 10:00');
    $this->tenant = $this->reportsTenant('rep-platform');
    $this->url = fn (string $path = '') => $this->reportsUrl($this->tenant, $path);
    $this->run = fn (string $query = ''): string => $this->reportsUrl($this->tenant, '/run/reports.fixture'.($query === '' ? '' : '?'.$query));
    [$this->viewer] = $this->reportsMember($this->tenant, ['reports.reports.view']);
    [$this->exporter] = $this->reportsMember($this->tenant, ['reports.reports.view', 'reports.reports.export']);
});

it('answers 404 for every Reports route when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('rep-plain');
    $this->actingAsOwner($plain);

    foreach (['', '/run/reports.fixture', '/run/reports.fixture/export', '/saved/01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $path) {
        $this->get($this->reportsUrl($plain, $path))->assertNotFound();
    }
});

it('forbids a member without the permission and shows the catalogue to a holder', function () {
    [$nobody] = $this->reportsMember($this->tenant, ['core.members.view']);

    $this->actingAsMember($this->tenant, $nobody)->get(($this->url)())->assertForbidden();
    $this->actingAsMember($this->tenant, $nobody)->get(($this->run)())->assertForbidden();

    $this->actingAsMember($this->tenant, $this->viewer)->get(($this->url)())->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('catalogue.0.module', 'reports')
            ->where('catalogue.0.reports.0.key', 'reports.fixture')
            ->where('canExport', false)
            ->where('canSave', false)
            ->where('bounds.maxDays', 366));
});

it('runs a report with default dates of the last 30 local days and the declared default columns', function () {
    FixtureReport::$rows = [['name' => 'Alpha', 'currency' => 'BDT', 'amount' => 125000, 'count' => 3, 'note' => 'x']];

    $this->actingAsMember($this->tenant, $this->viewer)->get(($this->run)())->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('values.from', '2026-02-08')
            ->where('values.to', '2026-03-09')
            ->where('result.columns.0.key', 'name')
            // The currency column is added to the chosen set so an amount never travels without it.
            ->where('result.columns.1.key', 'currency')
            ->where('result.columns.2.type', 'money')
            ->where('result.columns.2.currencyColumn', 'currency')
            ->where('result.rows.0.amount', 125000)
            ->missing('result.rows.0.note')
            ->where('result.truncated', false)
            ->where('result.scope', 'all'));

    expect(FixtureReport::$last?->from)->toBe('2026-02-08')
        ->and(FixtureReport::$last?->fromAt->toIso8601String())->toBe('2026-02-07T18:00:00+00:00')
        ->and(FixtureReport::$last?->toAt->toIso8601String())->toBe('2026-03-09T17:59:59+00:00');
});

it('keeps only the chosen columns and rejects an undeclared one', function () {
    FixtureReport::$rows = [['name' => 'Alpha', 'currency' => 'BDT', 'amount' => 5, 'count' => 3, 'note' => 'n']];

    $this->actingAsMember($this->tenant, $this->viewer)->get(($this->run)('columns[]=count&columns[]=note'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('result.columns', 2)
            ->where('result.columns.0.key', 'count')
            ->where('result.columns.1.key', 'note')
            ->where('result.rows.0.note', 'n')
            ->missing('result.rows.0.name'));

    $this->actingAsMember($this->tenant, $this->viewer)
        ->get(($this->run)('columns[]=password'))
        ->assertSessionHasErrors('columns.0');
});

it('bounds the date range', function () {
    $viewer = $this->actingAsMember($this->tenant, $this->viewer);

    // 366 days inclusive is the longest range.
    $viewer->get(($this->run)('from=2025-03-09&to=2026-03-09'))->assertOk();
    expect(FixtureReport::$runs)->toBe(1);

    $viewer->get(($this->run)('from=2025-03-08&to=2026-03-09'))->assertSessionHasErrors('to');
    $viewer->get(($this->run)('from=2026-03-09&to=2026-03-01'))->assertSessionHasErrors('to');
    $viewer->get(($this->run)('from=not-a-date&to=2026-03-01'))->assertSessionHasErrors('from');
    $viewer->get(($this->run)('from=2026-02-30&to=2026-03-01'))->assertSessionHasErrors('from');
    expect(FixtureReport::$runs)->toBe(1);
});

it('validates filters against the provider declaration only', function () {
    $viewer = $this->actingAsMember($this->tenant, $this->viewer);

    $viewer->get(($this->run)('filters[kind]=a&filters[limit]=5&filters[flag]=1'))->assertOk();
    expect(FixtureReport::$last?->filters)->toBe(['kind' => 'a', 'limit' => 5, 'flag' => true]);

    $viewer->get(($this->run)('filters[kind]=c'))->assertSessionHasErrors('filters.kind');
    $viewer->get(($this->run)('filters[limit]=11'))->assertSessionHasErrors('filters.limit');
    $viewer->get(($this->run)('filters[limit]=abc'))->assertSessionHasErrors('filters.limit');
    // A filter the provider did not declare is refused: there is no way to smuggle one in.
    $viewer->get(($this->run)('filters[sql]=1%3D1'))->assertSessionHasErrors('filters');
    expect(FixtureReport::$runs)->toBe(1);
});

it('cuts a result at the row limit and says so, and paginates what is left', function () {
    FixtureReport::$rows = array_map(
        static fn (int $i): array => ['name' => 'Row '.$i, 'currency' => 'BDT', 'amount' => $i, 'count' => $i],
        range(1, ReportRunner::ROW_LIMIT + 5),
    );
    $viewer = $this->actingAsMember($this->tenant, $this->viewer);

    $viewer->get(($this->run)())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('result.truncated', true)
        ->where('result.rowLimit', ReportRunner::ROW_LIMIT)
        ->where('result.page.total', ReportRunner::ROW_LIMIT)
        ->where('result.page.lastPage', 40)
        ->has('result.rows', 50)
        ->where('result.rows.0.name', 'Row 1'));

    $viewer->get(($this->run)('rows_page=2'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('result.page.currentPage', 2)
        ->where('result.rows.0.name', 'Row 51'));

    // Out of range pages fall back to the last page rather than failing.
    $viewer->get(($this->run)('rows_page=9999'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('result.page.currentPage', 40));
});

it('keeps null as "not reported" and never turns it into zero', function () {
    FixtureReport::$rows = [['name' => 'Quiet', 'currency' => 'BDT', 'amount' => null, 'count' => 0]];

    $this->actingAsMember($this->tenant, $this->viewer)->get(($this->run)())->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('result.rows.0.amount', null)
            ->where('result.rows.0.count', 0));
});

it('hides providers of apps the workspace is not entitled to, even from the owner who holds every permission', function () {
    $owner = $this->ownerOf($this->tenant);

    // The workspace is entitled to Reports only: every other app's report is absent, not merely forbidden.
    $keys = $this->inTenant($this->tenant, fn () => array_keys(app(ReportRegistry::class)->availableFor($owner)));
    expect($keys)->toBe(['reports.fixture']);

    $this->actingAsOwner($this->tenant)->get($this->reportsUrl($this->tenant, '/run/crm.pipeline'))->assertNotFound();
    $this->actingAsOwner($this->tenant)->get($this->reportsUrl($this->tenant, '/run/does.not_exist'))->assertNotFound();
    $this->actingAsOwner($this->tenant)->get($this->reportsUrl($this->tenant, '/run/crm.pipeline/export'))->assertNotFound();

    // A role cannot even be granted another app's permission before the workspace is entitled to the app.
    expect(fn () => $this->reportsMember($this->tenant, ['reports.reports.view', 'crm.deals.view']))->toThrow(ValidationException::class);
});

it('lists a provider only for members holding its permission, once the workspace is entitled', function () {
    $this->entitle($this->tenant, 'crm', 'projects', 'invoicing', 'hrm', 'payroll', 'talent', 'support', 'social', 'automation');
    $registry = fn ($user) => $this->inTenant($this->tenant, fn () => app(ReportRegistry::class)->availableFor($user));
    $all = $this->inTenant($this->tenant, fn () => app(ReportRegistry::class)->all());

    expect(array_keys($all))->toContain(
        'crm.pipeline', 'crm.lead_sources', 'crm.sales_conversion',
        'projects.performance', 'projects.timesheets',
        'invoicing.receivables_ageing', 'invoicing.revenue',
        'hrm.headcount', 'hrm.attendance', 'hrm.leave',
        'payroll.run_totals', 'talent.recruitment_funnel',
        'support.volume', 'support.sla', 'support.csat',
        'social.publishing', 'social.engagement', 'automation.run_outcomes',
    );

    // Every provider is available to exactly the members holding its own permission.
    foreach ($all as $key => $provider) {
        if ($key === 'reports.fixture') {
            continue;
        }

        $permission = $provider->definition()->permission;
        [$holder] = $this->reportsMember($this->tenant, ['reports.reports.view', $permission]);
        [$other] = $this->reportsMember($this->tenant, ['reports.reports.view']);

        expect(array_keys($registry($holder)))->toContain($key)
            ->and(array_keys($registry($other)))->not->toContain($key);
    }

    // The sensitive payroll report needs the payroll permission, nothing broader.
    [$crmOnly] = $this->reportsMember($this->tenant, ['reports.reports.view', 'crm.reports.view', 'hrm.reports.view']);
    expect(array_keys($registry($crmOnly)))->not->toContain('payroll.run_totals');
});

it('declares only well-formed definitions with unique keys', function () {
    $this->entitle($this->tenant, 'crm', 'projects', 'invoicing', 'hrm', 'payroll', 'talent', 'support', 'social', 'automation');
    $all = $this->inTenant($this->tenant, fn () => app(ReportRegistry::class)->all());

    foreach ($all as $key => $provider) {
        $definition = $provider->definition();

        expect($definition->key)->toBe($key)
            ->and($definition->columns)->not->toBeEmpty()
            ->and(str_starts_with($definition->permission, $definition->module.'.'))->toBeTrue();
    }
});

it('exports a formula-safe CSV for a holder of the export permission and audits it', function () {
    FixtureReport::$rows = [
        ['name' => '=HYPERLINK("http://evil.test","x")', 'currency' => 'BDT', 'amount' => 12345, 'count' => 1, 'note' => '+1'],
        ['name' => '-2+3', 'currency' => 'BDT', 'amount' => -50, 'count' => 2, 'note' => '@SUM(A1)'],
        ['name' => "\tTabbed", 'currency' => 'BDT', 'amount' => 0, 'count' => 3, 'note' => "\rReturn"],
        ['name' => 'Plain, "quoted"', 'currency' => 'BDT', 'amount' => null, 'count' => 4, 'note' => ' =padded'],
    ];

    $response = $this->actingAsMember($this->tenant, $this->exporter)->get($this->reportsUrl(
        $this->tenant,
        '/run/reports.fixture/export?columns[]=name&columns[]=amount&columns[]=count&columns[]=note&from=2026-03-01&to=2026-03-09&filters[kind]=a',
    ));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('reports.fixture-2026-03-01_2026-03-09.csv')
        ->and($response->headers->get('X-Report-Truncated'))->toBe('0');

    $csv = $response->getContent();

    expect($csv)->toStartWith(CsvWriter::BOM)
        // Header: label plus the unit of a raw number; the currency column rides along with money.
        ->and($csv)->toContain('"Name","Currency","Amount (minor units)","Count","Note"')
        // Every text cell that could start a formula is neutralised; numbers stay numbers.
        ->and($csv)->toContain('"\'=HYPERLINK(""http://evil.test"",""x"")","BDT","12345","1","\'+1"')
        ->and($csv)->toContain('"\'-2+3","BDT","-50","2","\'@SUM(A1)"')
        ->and($csv)->toContain("\"'\tTabbed\",\"BDT\",\"0\",\"3\",\"'\rReturn\"")
        ->and($csv)->toContain('"Plain, ""quoted""","BDT","","4","\' =padded"');

    $entry = AuditLog::query()->where('event', 'reports.report.exported')->latest('created_at')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->properties['report'])->toBe('reports.fixture')
        ->and($entry->properties['rows'])->toBe(4)
        ->and($entry->properties['scope'])->toBe('all')
        ->and($entry->properties['truncated'])->toBeFalse()
        ->and($entry->properties['filters'])->toBe(['kind' => 'a'])
        ->and($entry->actor_label)->not->toBeNull();
});

it('refuses to export without the export permission', function () {
    FixtureReport::$rows = [['name' => 'A', 'currency' => 'BDT', 'amount' => 1, 'count' => 1]];

    $this->actingAsMember($this->tenant, $this->viewer)
        ->get($this->reportsUrl($this->tenant, '/run/reports.fixture/export'))->assertForbidden();

    expect(AuditLog::query()->where('event', 'reports.report.exported')->count())->toBe(0);
});

it('marks a truncated export inside the file and in a header', function () {
    FixtureReport::$rows = array_map(
        static fn (int $i): array => ['name' => 'Row '.$i, 'currency' => 'BDT', 'amount' => $i, 'count' => $i],
        range(1, ReportRunner::ROW_LIMIT + 1),
    );

    $response = $this->actingAsMember($this->tenant, $this->exporter)
        ->get($this->reportsUrl($this->tenant, '/run/reports.fixture/export'));

    $response->assertOk();
    expect($response->headers->get('X-Report-Truncated'))->toBe('1')
        ->and(substr_count($response->getContent(), "\r\n"))->toBe(ReportRunner::ROW_LIMIT + 2)
        ->and($response->getContent())->toContain('Truncated: the report has more rows than the export limit of '.ReportRunner::ROW_LIMIT.'.');

    $entry = AuditLog::query()->where('event', 'reports.report.exported')->latest('created_at')->first();
    expect($entry->properties['truncated'])->toBeTrue()->and($entry->properties['rows'])->toBe(ReportRunner::ROW_LIMIT);
});

it('throttles exports', function () {
    FixtureReport::$rows = [['name' => 'A', 'currency' => 'BDT', 'amount' => 1, 'count' => 1]];
    $member = $this->actingAsMember($this->tenant, $this->exporter);

    for ($i = 0; $i < 20; $i++) {
        $member->get($this->reportsUrl($this->tenant, '/run/reports.fixture/export'))->assertOk();
    }

    $member->get($this->reportsUrl($this->tenant, '/run/reports.fixture/export'))->assertStatus(429);
});

it('renders the documented Inertia components', function () {
    $member = $this->actingAsMember($this->tenant, $this->viewer);

    $member->get(($this->url)())->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Modules/Reports/Pages/Index'));
    $member->get(($this->run)())->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Modules/Reports/Pages/Run'));
});
