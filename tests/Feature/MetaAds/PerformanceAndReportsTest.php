<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Audit\Models\AuditLog;
use App\Core\Reporting\ReportContext;
use App\Core\Reporting\ReportRegistry;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Reporting\CampaignPerformanceReport;
use App\Modules\MetaAds\Reporting\DailySpendReport;
use App\Modules\MetaAds\Widgets\MetaAdsOverviewWidget;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithMetaAds;

uses(InteractsWithMetaAds::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC'));
    $this->tenant = $this->metaTenant('meta-perf');
    $this->bdt = $this->metaAccount($this->tenant, null, ['external_id' => 'act_111', 'name' => 'Shop Ads', 'currency' => 'BDT', 'last_synced_at' => now()->subHour()]);
    $this->usd = $this->metaAccount($this->tenant, null, ['external_id' => 'act_222', 'name' => 'Export Ads', 'currency' => 'USD', 'last_synced_at' => now()->subHour()]);
    $this->url = fn (string $path = '') => $this->metaUrl($this->tenant, $path);

    // Current period 2026-10-04..2026-10-13 (10 days); the 10 days before are the comparison.
    foreach (['2026-10-12' => 100000, '2026-10-11' => 50000] as $date => $spend) {
        $this->metaInsight($this->tenant, $this->bdt, $date, ['spend' => $spend, 'impressions' => 10000, 'reach' => 8000, 'clicks' => 200, 'leads' => 10]);
    }
    $this->metaInsight($this->tenant, $this->bdt, '2026-10-01', ['spend' => 75000, 'impressions' => 5000, 'reach' => 4000, 'clicks' => 100, 'leads' => 5]);
    $this->metaInsight($this->tenant, $this->usd, '2026-10-12', ['spend' => 999999, 'impressions' => 1, 'clicks' => 1, 'leads' => 1]);

    foreach (['1001' => ['Eid Sale', 120000, 15], '1002' => ['=HYPERLINK("http://evil")', 30000, null]] as $id => [$name, $spend, $leads]) {
        $this->metaObject($this->tenant, $this->bdt, ['external_id' => $id, 'name' => $name]);
        $this->metaInsight($this->tenant, $this->bdt, '2026-10-12', [
            'level' => Insight::LEVEL_CAMPAIGN, 'object_external_id' => $id, 'spend' => $spend, 'impressions' => 9000, 'clicks' => 150, 'leads' => $leads,
        ]);
    }

    $this->actingAsOwner($this->tenant);
});

it('shows KPIs in one currency with deltas against the period before and exact derived figures', function () {
    $this->get(($this->url)('/?from=2026-10-04&to=2026-10-13'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Modules/MetaAds/Pages/Performance/Index')
        ->where('currency', 'BDT')
        ->where('currencies', ['BDT', 'USD'])
        ->where('summary.current.spend', 150000)
        ->where('summary.current.impressions', 20000)
        ->where('summary.current.reach', 16000)
        ->where('summary.current.clicks', 400)
        ->where('summary.current.leads', 20)
        ->where('summary.current.ctr', 200)           // 400 / 20000 = 2.00%
        ->where('summary.current.cpc', 375)           // 150000 / 400
        ->where('summary.current.cpm', 7500)          // 150000 / 20000 * 1000
        ->where('summary.current.costPerLead', 7500)  // 150000 / 20
        ->where('summary.previous.spend', 75000)
        ->where('summary.deltas.spend', 10000)        // +100.00%
        ->where('summary.deltas.leads', 30000)
        ->has('series', 10)
        ->where('series.8.date', '2026-10-12')
        ->where('series.8.spend', 100000)
        ->where('series.0.spend', null));
});

it('never mixes currencies: the other currency is its own report, chosen explicitly', function () {
    $this->get(($this->url)('/?currency=USD&from=2026-10-04&to=2026-10-13'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('currency', 'USD')
        ->where('summary.current.spend', 999999)
        ->where('summary.current.leads', 1));

    // An unknown currency falls back to the main one rather than creating an empty mixed view.
    $this->get(($this->url)('/?currency=EUR'))->assertInertia(fn (AssertableInertia $page) => $page->where('currency', 'BDT'));
});

it('reports a missing figure as not reported (null), never zero', function () {
    $this->inTenant($this->tenant, fn () => Insight::query()->update(['leads' => null, 'reach' => null, 'clicks' => null]));

    $this->get(($this->url)('/?from=2026-10-04&to=2026-10-13'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.current.leads', null)
        ->where('summary.current.reach', null)
        ->where('summary.current.ctr', null)
        ->where('summary.current.cpc', null)
        ->where('summary.current.costPerLead', null)
        ->where('summary.deltas.leads', null)
        ->where('summary.current.spend', 150000));
});

it('ranks top campaigns by spend and pairs spend with results, with null cost per lead when no leads were reported', function () {
    $this->get(($this->url)('/?from=2026-10-04&to=2026-10-13'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('campaigns', 2)
        ->where('campaigns.0.name', 'Eid Sale')
        ->where('campaigns.0.spend', 120000)
        ->where('campaigns.0.leads', 15)
        ->where('campaigns.0.costPerLead', 8000)
        ->where('campaigns.1.leads', null)
        ->where('campaigns.1.costPerLead', null)
        ->where('campaigns.1.ctr', 167));              // 150 / 9000
});

it('narrows to one ad account, and validates the range', function () {
    $this->get(($this->url)('/?account='.$this->bdt->id.'&from=2026-10-04&to=2026-10-13'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accountId', $this->bdt->id)->where('summary.current.spend', 150000));

    $this->get(($this->url)('/?from=2026-10-10&to=2026-10-01'))->assertSessionHasErrors('to');
    $this->get(($this->url)('/?from=2024-01-01&to=2026-10-13'))->assertSessionHasErrors('to');
    $this->get(($this->url)('/?from=nope'))->assertSessionHasErrors('from');
});

it('shows the setup checklist instead of numbers before any ad account is tracked', function () {
    $empty = $this->metaTenant('meta-empty');
    $this->actingAsOwner($empty);

    $this->get($this->metaUrl($empty, '/'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('currency', null)->where('summary', null)
        ->where('readiness.0', ['key' => 'meta_app', 'state' => 'done'])
        ->where('readiness.1', ['key' => 'authorize', 'state' => 'todo'])
        ->where('readiness.2', ['key' => 'accounts', 'state' => 'blocked']));

    config(['meta_ads.app_id' => null]);
    $this->get($this->metaUrl($empty, '/'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('readiness.0', ['key' => 'meta_app', 'state' => 'blocked'])
        ->where('readiness.1', ['key' => 'authorize', 'state' => 'blocked']));
});

it('exports a formula-safe CSV with raw minor units, the currency and an audit entry', function () {
    $response = $this->get(($this->url)('/performance/export?from=2026-10-04&to=2026-10-13'));
    $response->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('"Spend (minor units)"')
        ->and($csv)->toContain('"Eid Sale","BDT","120000","9000","150","167","800","15","8000"')
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"http://evil\"\")\"")
        ->and($csv)->not->toContain('"=HYPERLINK')
        ->and($csv)->not->toContain('999999');

    $audit = $this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'meta_ads.performance.exported')->sole());
    expect($audit->properties)->toMatchArray(['kind' => 'campaigns', 'currency' => 'BDT', 'rows' => 2]);

    $daily = $this->get(($this->url)('/performance/export?kind=daily&from=2026-10-04&to=2026-10-13'))->streamedContent();
    expect($daily)->toContain('"2026-10-12","BDT","100000","10000","200","10"')->and($daily)->toContain('"2026-10-04","BDT","","","",""');
});

it('lets only people with the export permission download', function () {
    [$viewer] = $this->metaMember($this->tenant, ['meta_ads.insights.view']);
    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $viewer);

    $this->get(($this->url)('/performance/export'))->assertForbidden();
    $this->get(($this->url)('/'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canExport', false));
});

it('publishes two report providers to Reports and Analytics, gated by the insights permission', function () {
    $keys = collect(app(ReportRegistry::class)->all())->map(fn ($provider) => $provider->definition()->key)->all();
    expect($keys)->toContain('meta_ads.campaign_performance', 'meta_ads.daily_spend');

    $user = $this->ownerOf($this->tenant);
    $context = fn (array $filters = []) => new ReportContext(
        $user, AccessScope::All, '2026-10-04', '2026-10-13',
        CarbonImmutable::parse('2026-10-04 00:00:00'), CarbonImmutable::parse('2026-10-13 23:59:59'), 'Asia/Dhaka', $filters, 2000,
    );

    $this->inTenant($this->tenant, function () use ($context): void {
        $campaigns = app(CampaignPerformanceReport::class)->run($context());
        $byName = collect($campaigns->rows)->keyBy('campaign');
        expect($campaigns->rows)->toHaveCount(2)
            ->and($byName['Eid Sale']['spend'])->toBe(120000)
            ->and($byName['Eid Sale']['currency'])->toBe('BDT')
            ->and($byName['Eid Sale']['cost_per_lead'])->toBe(8000)
            ->and($byName['=HYPERLINK("http://evil")']['leads'])->toBeNull();

        $onlyUsd = app(CampaignPerformanceReport::class)->run($context(['currency' => 'usd']));
        expect($onlyUsd->rows)->toBe([]);

        $daily = app(DailySpendReport::class)->run($context());
        $currencies = collect($daily->rows)->pluck('currency')->unique()->sort()->values()->all();
        expect($currencies)->toBe(['BDT', 'USD'])
            ->and(collect($daily->rows)->where('currency', 'USD')->sole()['spend'])->toBe(999999);

        $capped = app(DailySpendReport::class)->run(new ReportContext(
            $context()->user, AccessScope::All, '2026-10-04', '2026-10-13', CarbonImmutable::parse('2026-10-04'), CarbonImmutable::parse('2026-10-13'), 'Asia/Dhaka', [], 1,
        ));
        expect($capped->truncated)->toBeTrue();
    });

    foreach ([CampaignPerformanceReport::class, DailySpendReport::class] as $class) {
        $definition = app($class)->definition();
        expect($definition->permission)->toBe('meta_ads.insights.view')->and($definition->module)->toBe('meta_ads');
    }
});

it('gives the dashboard widget seven days in the main currency and counts accounts needing attention', function () {
    $this->inTenant($this->tenant, fn () => AdAccount::query()->whereKey($this->usd->id)->update(['sync_state' => 'error']));
    $user = $this->ownerOf($this->tenant);

    $data = $this->inTenant($this->tenant, fn () => app(MetaAdsOverviewWidget::class)($user));

    expect($data)->toMatchArray([
        'currency' => 'BDT', 'currencies' => 2, 'spend' => 150000, 'leads' => 20, 'trackedAccounts' => 2, 'accountsNeedingAttention' => 1,
    ]);

    [$viewer] = $this->metaMember($this->tenant, ['meta_ads.insights.view']);
    $hidden = $this->inTenant($this->tenant, fn () => app(MetaAdsOverviewWidget::class)($viewer));
    expect($hidden['accountsNeedingAttention'])->toBe(0);
});
