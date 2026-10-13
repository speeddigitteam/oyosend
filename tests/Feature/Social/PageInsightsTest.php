<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Core\Reporting\ReportContext;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\PageInsightsCollector;
use App\Modules\Social\Actions\PagePerformance;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Jobs\MaintainSocialAccounts;
use App\Modules\Social\Models\AccountAccess;
use App\Modules\Social\Models\AccountDiagnostic;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\PageInsight;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMetric;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Adapters\LinkedInPlatform;
use App\Modules\Social\Platforms\Facebook\FacebookPageInsights;
use App\Modules\Social\Platforms\PageInsightsSnapshot;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Reporting\CampaignPerformanceReport;
use App\Modules\Social\Reporting\PageInsightsReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithReports;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class, InteractsWithReports::class);

/** The end_time Meta puts on the value of Pacific day `$day`: the next Pacific midnight, in UTC. */
function fbEndTime(string $day): string
{
    return CarbonImmutable::parse($day.' 00:00:00', 'America/Los_Angeles')->addDay()->utc()->format('Y-m-d\TH:i:s').'+0000';
}

/**
 * A Graph API fake. `$metrics` maps a metric name to a list of `[day => value]` (a series), or to
 * an error code. Anything not listed answers "(#100) not a valid insights metric".
 *
 * @param  array<string, array<string, mixed>|int>  $metrics
 * @param  array<string, mixed>  $extra  `page`, `roles`, `debug`: responses for the other calls
 */
function fakeGraph(array $metrics, array $extra = [], ?Closure $onRequest = null): void
{
    // Http::fake() keeps the first matching stub, so a later call only replaces the scenario it reads.
    $GLOBALS['fbScenario'] = [$metrics, $extra, $onRequest];

    Http::fake(function (Request $request) {
        [$metrics, $extra, $onRequest] = $GLOBALS['fbScenario'];

        if ($onRequest !== null) {
            $onRequest($request);
        }

        $path = parse_url($request->url(), PHP_URL_PATH);
        $data = $request->data();

        if (str_ends_with($path, '/insights')) {
            $name = (string) ($data['metric'] ?? '');
            $series = $metrics[$name] ?? null;

            if (is_int($series)) {
                return Http::response(['error' => ['code' => $series, 'message' => 'denied']], $series === 4 ? 400 : 400);
            }

            if ($series === null) {
                return Http::response(['error' => ['code' => 100, 'message' => '(#100) The value must be a valid insights metric']], 400);
            }

            return Http::response(['data' => [[
                'name' => $name,
                'period' => 'day',
                'values' => array_map(static fn (string $day, mixed $value): array => ['value' => $value, 'end_time' => fbEndTime($day)], array_keys($series), array_values($series)),
            ]]]);
        }

        if (str_ends_with($path, '/roles')) {
            return Http::response($extra['roles'] ?? ['error' => ['code' => 200, 'message' => 'permission']], isset($extra['roles']) ? 200 : 403);
        }

        if (str_ends_with($path, '/debug_token')) {
            return Http::response($extra['debug'] ?? ['data' => ['scopes' => ['pages_read_engagement'], 'user_id' => '999']]);
        }

        return Http::response($extra['page'] ?? ['followers_count' => 1500, 'fan_count' => 1400]);
    });
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));
    config([
        'social.platforms.facebook.client_id' => 'client-id',
        'social.platforms.facebook.client_secret' => 'client-secret',
        'social.platforms.facebook.api_version' => 'v23.0',
    ]);
    $this->tenant = $this->socialTenant('soc-insights');
    $this->page = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => 'page-1', 'name' => 'Brand Page']);
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->collect = fn (?SocialAccount $account = null) => $this->inTenant(
        $this->tenant,
        fn () => app(PageInsightsCollector::class)->collect($account ?? $this->page),
    );
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('only claims the insights capability where a provider is implemented', function () {
    $platforms = app(SocialPlatforms::class);

    expect($platforms->find('facebook')->capabilities()->insights)->toBeTrue()
        ->and($platforms->find('facebook')->capabilities()->toArray()['insights'])->toBeTrue()
        ->and(app(LinkedInPlatform::class)->capabilities()->insights)->toBeFalse()
        ->and($platforms->find('x')->capabilities()->insights)->toBeFalse();
});

it('maps documented Page metrics to figures, falls back to the next candidate and keeps unavailable ones null', function () {
    fakeGraph([
        'page_follows' => ['2026-04-12' => 1480, '2026-04-13' => 1490],
        'page_daily_follows_unique' => ['2026-04-12' => 10, '2026-04-13' => 0],
        // page_media_view is retired: the API answers #100, so the next candidate is used.
        'page_impressions' => ['2026-04-12' => 700, '2026-04-13' => 800],
        'page_total_media_view_unique' => ['2026-04-12' => 300],
        'page_post_engagements' => ['2026-04-12' => 55],
        'page_actions_post_reactions_total' => ['2026-04-12' => ['like' => 5, 'love' => 2], '2026-04-13' => []],
    ]);

    expect(($this->collect)())->toBe('ok');

    $rows = $this->inTenant($this->tenant, fn () => PageInsight::query()->orderBy('metric_date')->get()->keyBy(fn ($row) => $row->metric_date->format('Y-m-d')));
    $first = $rows['2026-04-12'];
    $second = $rows['2026-04-13'];

    expect($first->followers)->toBe(1480)
        ->and($first->new_followers)->toBe(10)
        ->and($second->new_followers)->toBe(0)
        ->and($first->impressions)->toBe(700)
        ->and($first->reach)->toBe(300)
        ->and($second->reach)->toBeNull()
        ->and($first->post_engagements)->toBe(55)
        ->and($first->reactions)->toBe(7)
        // An empty breakdown is "not reported", never zero.
        ->and($second->reactions)->toBeNull()
        // No candidate answered these.
        ->and($first->unfollows)->toBeNull()
        ->and($first->page_views)->toBeNull()
        ->and($first->video_views)->toBeNull()
        ->and($first->sources['impressions'])->toBe('page_impressions')
        ->and($first->sources['followers'])->toBe('page_follows');

    // The Page's current counts land on the platform's current day.
    $today = $rows['2026-04-15'];
    expect($today->followers)->toBe(1500)->and($today->fans)->toBe(1400)->and($today->impressions)->toBeNull();

    $diagnostic = $this->inTenant($this->tenant, fn () => AccountDiagnostic::query()->sole());
    expect($diagnostic->insights_status)->toBe('ok')->and($diagnostic->insights_synced_at)->not->toBeNull();
});

it('stores nothing and reports no_data when every candidate metric is retired', function () {
    fakeGraph([], ['page' => ['error' => ['code' => 100, 'message' => 'nope']]]);

    expect(($this->collect)())->toBe('no_data');
    $this->inTenant($this->tenant, fn () => expect(PageInsight::query()->count())->toBe(0));
});

it('never overwrites a stored figure with "not reported" when a later read lacks it', function () {
    fakeGraph(['page_post_engagements' => ['2026-04-13' => 40], 'page_impressions' => ['2026-04-13' => 900]]);
    ($this->collect)();

    fakeGraph(['page_post_engagements' => ['2026-04-13' => 42]]);
    ($this->collect)();

    $row = $this->inTenant($this->tenant, fn () => PageInsight::query()->whereDate('metric_date', '2026-04-13')->sole());
    expect($row->post_engagements)->toBe(42)->and($row->impressions)->toBe(900);
});

it('backfills a bounded window once, then re-collects only the last days, with the token in the header only', function () {
    $requests = [];
    fakeGraph(['page_post_engagements' => ['2026-04-12' => 5]], [], function (Request $request) use (&$requests): void {
        if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/insights')) {
            $requests[] = $request;
        }
    });

    ($this->collect)();
    $firstSince = (int) $requests[0]->data()['since'];
    $expectedFirst = CarbonImmutable::parse('2026-03-18 00:00:00', 'America/Los_Angeles')->getTimestamp();
    expect($firstSince)->toBe($expectedFirst);

    $requests = [];
    $this->inTenant($this->tenant, fn () => AccountDiagnostic::query()->update(['insights_synced_at' => now()->subDay()]));
    ($this->collect)();
    $secondSince = (int) $requests[0]->data()['since'];
    expect($secondSince)->toBe(CarbonImmutable::parse('2026-04-12 00:00:00', 'America/Los_Angeles')->getTimestamp());

    foreach ($requests as $request) {
        expect($request->url())->not->toContain('tok-access')
            ->and($request->hasHeader('Authorization', 'Bearer '.$this->page->access_token))->toBeTrue();
    }
});

it('stops at the first rate-limit answer, records it and does not hammer the platform', function () {
    $insightCalls = 0;
    fakeGraph(['page_follows' => 4], [], function (Request $request) use (&$insightCalls): void {
        if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/insights')) {
            $insightCalls++;
        }
    });

    expect(($this->collect)())->toBe(PageInsightsSnapshot::RATE_LIMITED);
    expect($insightCalls)->toBe(1);

    $diagnostic = $this->inTenant($this->tenant, fn () => AccountDiagnostic::query()->sole());
    expect($diagnostic->insights_status)->toBe('rate_limited')->and($diagnostic->insights_error_code)->toBe('fb_4');
});

it('stops when Meta usage headers show the app is close to its limit', function () {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        return Http::response(['followers_count' => 10], 200, ['x-app-usage' => json_encode(['call_count' => 91, 'total_time' => 5, 'total_cputime' => 5])]);
    });

    expect(($this->collect)())->toBe(PageInsightsSnapshot::THROTTLED)->and($calls)->toBe(1);
});

it('buckets Meta days by Pacific time across the daylight-saving change', function () {
    $zone = new DateTimeZone('America/Los_Angeles');

    // 07 March, PST (UTC-8): the day ends at 08:00 UTC. 08 March: DST starts at 02:00 local, the day is 23 hours.
    expect(FacebookPageInsights::dayOf('2026-03-08T08:00:00+0000', $zone))->toBe('2026-03-07')
        ->and(FacebookPageInsights::dayOf('2026-03-09T07:00:00+0000', $zone))->toBe('2026-03-08')
        ->and(FacebookPageInsights::dayOf('2026-11-01T07:00:00+0000', $zone))->toBe('2026-10-31')
        ->and(FacebookPageInsights::dayOf('2026-11-02T08:00:00+0000', $zone))->toBe('2026-11-01')
        ->and(FacebookPageInsights::dayOf('garbage', $zone))->toBeNull();
});

it('computes deltas against the previous period and only compares like with like', function () {
    $this->inTenant($this->tenant, function (): void {
        $rows = [
            '2026-04-09' => ['followers' => 1000],
            '2026-04-10' => ['impressions' => 100, 'reach' => 40, 'page_views' => 10],
            '2026-04-11' => ['impressions' => 200, 'page_views' => 10],
            '2026-04-12' => ['followers' => 1100],
            '2026-04-03' => ['impressions' => 50, 'page_views' => 5],
            '2026-04-04' => ['impressions' => 50],
            '2026-04-05' => ['followers' => 900],
        ];

        foreach ($rows as $date => $figures) {
            PageInsight::query()->create(['account_id' => $this->page->id, 'metric_date' => $date, 'captured_at' => now(), ...$figures]);
        }
    });

    $report = $this->inTenant($this->tenant, fn () => app(PagePerformance::class)->dashboard($this->ownerOf($this->tenant), 7));
    $kpis = collect($report['kpis'])->keyBy('figure');

    expect($report['state'])->toBe('ready')
        ->and($report['period']['from'])->toBe('2026-04-09')->and($report['period']['to'])->toBe('2026-04-15')
        ->and($kpis['impressions']['value'])->toBe(300)->and($kpis['impressions']['previous'])->toBe(100)
        ->and($kpis['impressions']['delta'])->toBe(200)->and($kpis['impressions']['deltaPct'])->toBe(200.0)
        // Previous period has no reach: no delta, and current stays a number.
        ->and($kpis['reach']['value'])->toBe(40)->and($kpis['reach']['previous'])->toBeNull()->and($kpis['reach']['delta'])->toBeNull()
        // 2 reported days against 1: not comparable.
        ->and($kpis['page_views']['value'])->toBe(20)->and($kpis['page_views']['delta'])->toBeNull()
        // A level: the latest value in each period.
        ->and($kpis['followers']['value'])->toBe(1100)->and($kpis['followers']['delta'])->toBe(200)->and($kpis['followers']['deltaPct'])->toBe(22.2)
        // Nobody reported this: not zero.
        ->and($kpis['video_views']['value'])->toBeNull()->and($kpis['video_views']['delta'])->toBeNull()
        ->and($report['coverage']['daysReported'])->toBe(4)
        ->and($report['coverage']['notes'])->toContain('platform_days')->toContain('partial_days');

    $trend = $report['trend']['impressions']['total'];
    expect($trend)->toHaveCount(7)->and($trend[1])->toBe(100)->and($trend[0])->toBeNull();
});

it('derives best posting times from our own posts in the workspace timezone, across a DST change', function () {
    $tenant = $this->createTenant('soc-dst', ['timezone' => 'America/New_York']);
    $this->entitle($tenant, 'social');
    $account = $this->socialAccount($tenant, ['platform' => 'facebook', 'external_id' => 'dst-page']);

    $publish = function (string $utc, int $likes) use ($tenant, $account): void {
        $this->inTenant($tenant, function () use ($utc, $likes, $account): void {
            $post = Post::query()->create(['body' => 'Post '.$utc, 'status' => PostStatus::Published, 'published_at' => $utc]);
            $target = PostTarget::query()->create([
                'post_id' => $post->id, 'account_id' => $account->id, 'status' => TargetStatus::Published,
                'published_at' => $utc, 'external_id' => 'x-'.Str::random(6), 'idempotency_key' => Str::random(40),
            ]);
            PostMetric::query()->create(['target_id' => $target->id, 'captured_at' => now(), 'likes' => $likes]);
        });
    };

    // Sunday 8 March 2026: US DST starts at 02:00. 06:30 UTC is 01:30 EST, 07:30 UTC is 03:30 EDT.
    $publish('2026-03-08 06:30:00', 10);
    $publish('2026-03-08 07:30:00', 30);
    // The week before, 07:30 UTC was 02:30 EST (a different hour than after the change).
    $publish('2026-03-01 07:30:00', 20);

    $report = $this->inTenant($tenant, fn () => app(PagePerformance::class)->dashboard($this->ownerOf($tenant), 90));
    $best = $report['posts']['bestTimes'];
    $hours = collect($best['byHour'])->keyBy('hour');

    expect($best['timezone'])->toBe('America/New_York')
        ->and($best['posts'])->toBe(3)->and($best['sufficient'])->toBeFalse()
        ->and($hours[1]['posts'])->toBe(1)->and($hours[1]['engagement'])->toBe(10)
        ->and($hours[3]['posts'])->toBe(1)->and($hours[3]['average'])->toBe(30.0)
        ->and($hours[2]['posts'])->toBe(1)
        // Hours without posts are "no data", not zero.
        ->and($hours[9]['posts'])->toBe(0)->and($hours[9]['average'])->toBeNull();

    $weekdays = collect($best['byWeekday'])->keyBy('weekday');
    expect($weekdays[0]['posts'])->toBe(3);
});

it('ranks top and bottom posts, content types and campaigns from reported figures only', function () {
    $campaign = $this->inTenant($this->tenant, fn () => Campaign::query()->create(['name' => 'Spring', 'status' => 'active']));

    $make = function (string $title, ?int $likes, ?string $campaignId, ?string $link = null) {
        return $this->inTenant($this->tenant, function () use ($title, $likes, $campaignId, $link): void {
            $post = Post::query()->create(['title' => $title, 'body' => $title, 'status' => PostStatus::Published, 'published_at' => '2026-04-14 10:00:00', 'campaign_id' => $campaignId, 'link_url' => $link]);
            $target = PostTarget::query()->create([
                'post_id' => $post->id, 'account_id' => $this->page->id, 'status' => TargetStatus::Published,
                'published_at' => '2026-04-14 10:00:00', 'external_id' => 'x-'.Str::random(6), 'idempotency_key' => Str::random(40),
            ]);

            if ($likes !== null) {
                PostMetric::query()->create(['target_id' => $target->id, 'captured_at' => now(), 'likes' => $likes]);
            }
        });
    };

    foreach ([['A', 50, $campaign->id, 'https://example.com'], ['B', 5, $campaign->id], ['C', null, null], ['D', 20, null], ['E', 1, null], ['F', 9, null], ['G', 3, null]] as $args) {
        $make(...$args);
    }

    $posts = $this->inTenant($this->tenant, fn () => app(PagePerformance::class)->dashboard($this->ownerOf($this->tenant), 28))['posts'];

    expect($posts['published'])->toBe(7)->and($posts['reported'])->toBe(6)
        ->and(array_column($posts['top'], 'title'))->toBe(['A', 'D', 'F', 'B', 'G'])
        ->and(array_column($posts['bottom'], 'title'))->toBe(['E']);

    $types = collect($posts['contentTypes'])->keyBy('type');
    expect($types['link']['posts'])->toBe(1)->and($types['text']['posts'])->toBe(6)->and($types['text']['reported'])->toBe(5);

    $campaigns = collect($posts['campaigns'])->keyBy(fn ($row) => $row['id'] ?? 'none');
    expect($campaigns[$campaign->id]['engagement'])->toBe(55)->and($campaigns[$campaign->id]['totals']['likes'])->toBe(55)
        ->and($campaigns[$campaign->id]['totals']['shares'])->toBeNull()
        ->and($campaigns[$campaign->id]['timeline'])->toHaveCount(1)
        ->and($campaigns['none']['posts'])->toBe(5);
});

it('shows state-specific empty and not-configured states with a coverage note', function () {
    $this->actingAsOwner($this->tenant);

    $this->get(($this->url)('/analytics'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('insights.state', 'waiting')
        ->where('insights.kpis.3.value', null)
        ->where('insights.coverage.lastUpdatedAt', null)
        ->where('insights.coverage.notes', ['never_read']));

    config(['social.platforms.facebook.client_secret' => null]);
    app()->forgetInstance(SocialPlatforms::class);
    $this->get(($this->url)('/analytics'))->assertInertia(fn (AssertableInertia $page) => $page->where('insights.state', 'not_configured'));

    $empty = $this->socialTenant('soc-empty');
    $this->actingAsOwner($empty);
    $this->get($this->socialUrl($empty, '/analytics'))->assertInertia(fn (AssertableInertia $page) => $page->where('insights.state', 'no_accounts'));
});

it('keeps page insights, diagnostics and allow-list entries inside their workspace', function () {
    $account = fn (): SocialAccount => SocialAccount::query()->create([
        'platform' => 'facebook', 'external_id' => 'p-'.Str::random(8), 'name' => 'P', 'account_type' => 'page',
        'status' => 'connected', 'access_token' => 'secret',
    ]);

    $this->assertTenantIsolated(PageInsight::class, fn () => PageInsight::query()->create([
        'account_id' => $account()->id, 'metric_date' => '2026-04-10', 'captured_at' => now(), 'reach' => 3,
    ]));
    $this->assertTenantIsolated(AccountDiagnostic::class, fn () => AccountDiagnostic::query()->create(['account_id' => $account()->id, 'tasks' => ['ANALYZE']]));
    $this->assertTenantIsolated(AccountAccess::class, fn () => AccountAccess::query()->create([
        'account_id' => $account()->id, 'subject_type' => 'role', 'subject_id' => (string) Str::ulid(), 'can_publish' => true,
    ]));
});

it('never shows another workspace\'s page in the dashboard or the report', function () {
    $other = $this->socialTenant('soc-insights-other');
    $foreign = $this->socialAccount($other, ['platform' => 'facebook', 'external_id' => 'foreign', 'name' => 'Foreign Page']);
    $this->inTenant($other, fn () => PageInsight::query()->create(['account_id' => $foreign->id, 'metric_date' => '2026-04-12', 'captured_at' => now(), 'impressions' => 999]));
    $this->inTenant($this->tenant, fn () => PageInsight::query()->create(['account_id' => $this->page->id, 'metric_date' => '2026-04-12', 'captured_at' => now(), 'impressions' => 7]));

    $this->actingAsOwner($this->tenant);
    $this->get(($this->url)('/analytics?days=28&pages[]='.$foreign->id))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('insights.selected', [])
        ->where('insights.pages.0.id', $this->page->id)
        ->where('insights.kpis.3.value', null));

    $this->get(($this->url)('/analytics'))->assertInertia(fn (AssertableInertia $page) => $page->where('insights.kpis.3.value', 7));
});

it('exports the dashboard data as formula-safe CSV for the allowed pages only', function () {
    $this->inTenant($this->tenant, function (): void {
        PageInsight::query()->create(['account_id' => $this->page->id, 'metric_date' => '2026-04-12', 'captured_at' => now(), 'impressions' => 12, 'reach' => 4]);
        $post = Post::query()->create(['title' => '=HYPERLINK("http://evil.test")', 'body' => 'x', 'status' => PostStatus::Published, 'published_at' => '2026-04-13 10:00:00']);
        $target = PostTarget::query()->create([
            'post_id' => $post->id, 'account_id' => $this->page->id, 'status' => TargetStatus::Published,
            'published_at' => '2026-04-13 10:00:00', 'external_id' => 'x1', 'idempotency_key' => Str::random(40),
        ]);
        PostMetric::query()->create(['target_id' => $target->id, 'captured_at' => now(), 'likes' => 2]);
    });

    $this->actingAsOwner($this->tenant);

    $daily = $this->get(($this->url)('/analytics/export?days=7'))->assertOk();
    $csv = $daily->streamedContent();
    expect($daily->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toContain('2026-04-12')->toContain('Brand Page');

    $posts = $this->get(($this->url)('/analytics/export?days=7&dataset=posts'))->assertOk()->streamedContent();
    expect($posts)->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK')->not->toContain("\n=HYPERLINK");

    $this->get(($this->url)('/analytics/export?dataset=bogus'))->assertSessionHasErrors('dataset');

    [$viewer] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->actingAsMember($this->tenant, $viewer);
    $this->get(($this->url)('/analytics/export'))->assertForbidden();
});

it('reads the Page tasks of the token holder and the permissions the token holds', function () {
    fakeGraph([], [
        'roles' => ['data' => [
            ['id' => '111', 'name' => 'Someone', 'tasks' => ['MANAGE']],
            ['id' => '999', 'name' => 'Connector', 'tasks' => ['create_content', 'ANALYZE', 'NOT_A_TASK']],
        ]],
        'debug' => ['data' => ['scopes' => ['pages_show_list', 'read_insights', 'Bad Scope!'], 'user_id' => '999']],
    ]);

    $report = $this->inTenant($this->tenant, fn () => app(PageInsightsCollector::class)->checkAccess($this->page));
    expect($report->status)->toBe('ok')->and($report->tasks)->toBe(['CREATE_CONTENT', 'ANALYZE'])
        ->and($report->scopes)->toBe(['pages_show_list', 'read_insights']);

    $this->actingAsOwner($this->tenant);
    $this->get(($this->url)('/accounts'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accessPanel.accounts.'.$this->page->id.'.tasks', ['CREATE_CONTENT', 'ANALYZE'])
        ->where('accessPanel.accounts.'.$this->page->id.'.scopes', ['pages_show_list', 'read_insights'])
        ->where('accessPanel.features.0.task', 'CREATE_CONTENT')
        ->missing('accounts.0.access_token'));
});

it('reports tasks as not reported rather than none when the roles list cannot be read', function () {
    fakeGraph([]);

    $report = $this->inTenant($this->tenant, fn () => app(PageInsightsCollector::class)->checkAccess($this->page));

    expect($report->status)->toBe('permission_missing')->and($report->tasks)->toBeNull();
    $diagnostic = $this->inTenant($this->tenant, fn () => AccountDiagnostic::query()->sole());
    expect($diagnostic->tasks)->toBeNull()->and($diagnostic->tasks_status)->toBe('permission_missing');
});

it('restricts publishing, approving and analytics to the account allow-list, enforced on the server', function () {
    $open = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => 'page-open', 'name' => 'Open Page']);
    [$author, $authorMember] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.create', 'social.posts.edit', 'social.posts.schedule', 'social.analytics.view']);
    [$other, $otherMember] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.create', 'social.posts.edit', 'social.posts.schedule', 'social.posts.approve', 'social.analytics.view']);
    $draft = $this->socialPost($this->tenant, [$this->page->id]);

    // Nothing restricted yet: behaves as before.
    $this->actingAsMember($this->tenant, $author);
    $this->post(($this->url)('/posts'), ['body' => 'free', 'targets' => [['account_id' => $this->page->id]]])->assertRedirect();

    $this->inTenant($this->tenant, fn () => app(AccountAllowList::class)->replace($this->page, [
        ['subject_type' => 'membership', 'subject_id' => $otherMember->id, 'can_publish' => true, 'can_approve' => false, 'can_analytics' => false],
    ]));

    // Publish: the author is no longer on the list for this page, but still free to use the open one.
    $this->actingAsMember($this->tenant, $author);
    $this->post(($this->url)('/posts'), ['body' => 'blocked', 'targets' => [['account_id' => $this->page->id]]])->assertForbidden();
    $this->post(($this->url)('/posts'), ['body' => 'mixed', 'targets' => [['account_id' => $this->page->id], ['account_id' => $open->id]]])->assertForbidden();
    $this->post(($this->url)('/posts'), ['body' => 'ok', 'targets' => [['account_id' => $open->id]]])->assertRedirect();
    $this->put(($this->url)("/posts/{$draft->id}"), ['body' => 'edit', 'targets' => [['account_id' => $this->page->id]]])->assertForbidden();
    $this->post(($this->url)("/posts/{$draft->id}/schedule"), ['publish_now' => true])->assertForbidden();
    $this->post(($this->url)("/posts/{$draft->id}/submit"))->assertForbidden();
    $this->delete(($this->url)("/posts/{$draft->id}"))->assertForbidden();

    $this->get(($this->url)('/posts/create'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('form.accounts', 1)->where('form.accounts.0.id', $open->id));

    // The listed member publishes, but may not approve or see analytics for the page.
    $this->actingAsMember($this->tenant, $other);
    $this->post(($this->url)('/posts'), ['body' => 'listed', 'targets' => [['account_id' => $this->page->id]]])->assertRedirect();
    $this->post(($this->url)("/posts/{$draft->id}/approve"))->assertForbidden();
    $this->get(($this->url)('/analytics'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('insights.pages', 1)->where('insights.pages.0.id', $open->id));

    // The owner is never locked out.
    $this->actingAsOwner($this->tenant);
    $this->post(($this->url)('/posts'), ['body' => 'owner', 'targets' => [['account_id' => $this->page->id]]])->assertRedirect();
    $this->get(($this->url)('/analytics'))->assertInertia(fn (AssertableInertia $page) => $page->has('insights.pages', 2));
});

it('grants an ability through a role entry and clears the restriction when the list is emptied', function () {
    [$member, $membership] = $this->socialMember($this->tenant, ['social.posts.view', 'social.posts.approve', 'social.analytics.view']);
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $this->ownerOf($this->tenant),
        'Approvers '.Str::random(4),
        null,
        ['social.posts.view'],
    ));
    $this->inTenant($this->tenant, fn () => (new Role)->getConnection()->table('model_has_roles')->insert([
        'role_id' => $role->id, 'model_type' => $member->getMorphClass(), 'model_id' => $member->getKey(), 'tenant_id' => $this->tenant->getTenantKey(),
    ]));

    $allow = fn () => app(AccountAllowList::class);
    $entry = fn (string $type, string $id, array $flags) => ['subject_type' => $type, 'subject_id' => $id, ...$flags];

    $this->inTenant($this->tenant, function () use ($allow, $entry, $role, $member): void {
        $allow()->replace($this->page, [$entry('role', (string) $role->id, ['can_approve' => true])]);

        expect($allow()->allows($member, 'approve', $this->page->id))->toBeTrue()
            ->and($allow()->allows($member, 'publish', $this->page->id))->toBeFalse()
            ->and($allow()->allows($member, 'analytics', $this->page->id))->toBeFalse();

        $allow()->replace($this->page, []);

        expect($allow()->allows($member, 'publish', $this->page->id))->toBeTrue();
    });
});

it('lets only a member who may manage accounts edit an allow-list, within their own workspace', function () {
    $membership = $this->membershipOf($this->tenant, $this->ownerOf($this->tenant));
    [$viewer] = $this->socialMember($this->tenant, ['social.accounts.view']);
    $payload = ['entries' => [['subject_type' => 'membership', 'subject_id' => $membership->id, 'can_publish' => true]]];

    $this->actingAsMember($this->tenant, $viewer);
    $this->put(($this->url)("/accounts/{$this->page->id}/access"), $payload)->assertForbidden();
    $this->post(($this->url)("/accounts/{$this->page->id}/access/check"))->assertForbidden();

    $this->actingAsOwner($this->tenant);
    $this->put(($this->url)("/accounts/{$this->page->id}/access"), ['entries' => [['subject_type' => 'membership', 'subject_id' => (string) Str::ulid(), 'can_publish' => true]]])
        ->assertSessionHasErrors('entries');
    $this->put(($this->url)("/accounts/{$this->page->id}/access"), ['entries' => [['subject_type' => 'user', 'subject_id' => $membership->id]]])
        ->assertSessionHasErrors('entries.0.subject_type');
    $this->put(($this->url)("/accounts/{$this->page->id}/access"), $payload)->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(AccountAccess::query()->count())->toBe(1));

    $other = $this->socialTenant('soc-access-other');
    $this->actingAsOwner($other);
    $this->put($this->socialUrl($other, "/accounts/{$this->page->id}/access"), ['entries' => []])->assertNotFound();
    $this->post($this->socialUrl($other, "/accounts/{$this->page->id}/access/check"))->assertNotFound();
});

it('registers page insights and campaign performance reports that respect the allow-list', function () {
    $this->inTenant($this->tenant, function (): void {
        PageInsight::query()->create(['account_id' => $this->page->id, 'metric_date' => '2026-04-12', 'captured_at' => now(), 'impressions' => 10, 'followers' => 100]);
        PageInsight::query()->create(['account_id' => $this->page->id, 'metric_date' => '2026-04-13', 'captured_at' => now(), 'impressions' => 5, 'followers' => 120]);
    });

    [$member, $membership] = $this->socialMember($this->tenant, ['social.analytics.view']);
    $make = fn ($user) => new ReportContext(
        $user,
        AccessScope::All,
        '2026-04-01',
        '2026-04-15',
        CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-04-15 23:59:59', 'UTC'),
        'Asia/Dhaka',
        [],
        500,
    );

    $keys = collect(app()->tagged(ReportProvider::TAG))->map(fn ($provider) => $provider->definition()->key)->all();
    expect($keys)->toContain('social.page_insights')->toContain('social.campaign_performance');

    $rows = $this->inTenant($this->tenant, fn () => app(PageInsightsReport::class)->run($make($member))->rows);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['impressions'])->toBe(15)->and($rows[0]['followers'])->toBe(120)->and($rows[0]['days'])->toBe(2)
        ->and($rows[0]['video_views'])->toBeNull()->and($rows[0]['reach'])->toBeNull();

    $this->inTenant($this->tenant, fn () => app(AccountAllowList::class)->replace($this->page, [
        ['subject_type' => 'membership', 'subject_id' => $this->membershipOf($this->tenant, $this->ownerOf($this->tenant))->id, 'can_analytics' => true],
    ]));

    expect($this->inTenant($this->tenant, fn () => app(PageInsightsReport::class)->run($make($member))->rows))->toBe([])
        ->and($this->inTenant($this->tenant, fn () => app(PageInsightsReport::class)->run($make($this->ownerOf($this->tenant)))->rows))->toHaveCount(1);

    $campaigns = $this->inTenant($this->tenant, fn () => app(CampaignPerformanceReport::class)->run($make($this->ownerOf($this->tenant)))->rows);
    expect($campaigns)->toBe([]);
});

it('reads only a few due accounts per hourly run, oldest first, and leaves fresh ones alone', function () {
    fakeGraph(['page_post_engagements' => ['2026-04-13' => 3]]);
    $extra = [];

    foreach (range(1, 3) as $i) {
        $extra[] = $this->socialAccount($this->tenant, ['platform' => 'facebook', 'external_id' => 'more-'.$i, 'name' => 'More '.$i]);
    }

    config(['social.insights.accounts_per_run' => 3]);
    expect($this->inTenant($this->tenant, fn () => app(PageInsightsCollector::class)->run()))->toBe(3);
    $this->inTenant($this->tenant, fn () => expect(AccountDiagnostic::query()->count())->toBe(3));

    // The fourth account is read next; the three just read are fresh.
    expect($this->inTenant($this->tenant, fn () => app(PageInsightsCollector::class)->run()))->toBe(1);
    expect($this->inTenant($this->tenant, fn () => app(PageInsightsCollector::class)->run()))->toBe(0);

    // The hourly job runs it together with the existing maintenance.
    $this->inTenant($this->tenant, fn () => AccountDiagnostic::query()->update(['insights_synced_at' => now()->subHours(7)]));
    $this->inTenant($this->tenant, fn () => app()->call([new MaintainSocialAccounts, 'handle']));
    $this->inTenant($this->tenant, fn () => expect(AccountDiagnostic::query()->where('insights_synced_at', '>', now()->subHour())->count())->toBe(3));
});

it('runs both reports through the Reports app with the viewer\'s permission', function () {
    $this->inTenant($this->tenant, fn () => PageInsight::query()->create([
        'account_id' => $this->page->id, 'metric_date' => '2026-04-12', 'captured_at' => now(), 'impressions' => 10,
    ]));
    $this->entitle($this->tenant, 'reports');
    $owner = $this->actingAsOwner($this->tenant);

    foreach (['social.page_insights' => 1, 'social.campaign_performance' => 0] as $key => $rows) {
        $page = $owner->get($this->reportsUrl($this->tenant, '/run/'.$key.'?from=2026-04-01&to=2026-04-15'))->assertOk()->viewData('page')['props'];

        expect($page['report']['key'])->toBe($key)->and($page['result']['rows'])->toHaveCount($rows);
    }

    [$viewer] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->actingAsMember($this->tenant, $viewer)
        ->get($this->reportsUrl($this->tenant, '/run/social.page_insights'))->assertForbidden();
});
