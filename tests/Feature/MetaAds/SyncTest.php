<?php

declare(strict_types=1);

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\MetaAds\Actions\AccountSynchronizer;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Enums\ObjectLevel;
use App\Modules\MetaAds\Enums\SyncState;
use App\Modules\MetaAds\Events\ConnectionNeedsReauthorization;
use App\Modules\MetaAds\Jobs\SyncAdAccounts;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Models\MetaAdsSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithMetaAds;

uses(InteractsWithMetaAds::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC'));
    $this->tenant = $this->metaTenant('meta-sync');
    $this->connection = $this->metaConnection($this->tenant, ['external_user_id' => 'fb-1']);
    $this->account = $this->metaAccount($this->tenant, $this->connection, ['external_id' => 'act_111', 'currency' => 'BDT']);
    $this->sync = fn (?AdAccount $account = null) => $this->inTenant($this->tenant, fn () => app(AccountSynchronizer::class)->sync(($account ?? $this->account)->fresh()));
    $this->fresh = fn () => $this->inTenant($this->tenant, fn () => AdAccount::query()->findOrFail($this->account->id));

    $this->campaigns = ['data' => [
        ['id' => '1001', 'name' => 'Eid Sale', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS', 'daily_budget' => '150000', 'lifetime_budget' => '0', 'budget_remaining' => '0', 'start_time' => '2026-10-01T00:00:00+0600', 'stop_time' => '2026-11-01T00:00:00+0600', 'updated_time' => '2026-10-10T10:00:00+0600'],
        ['id' => '1002', 'name' => '=HYPERLINK("http://evil")', 'status' => 'PAUSED', 'effective_status' => 'PAUSED', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => '0', 'lifetime_budget' => '5000000', 'budget_remaining' => '1200000'],
        ['id' => '1003', 'name' => 'Old', 'status' => 'PAUSED', 'effective_status' => 'DELETED'],
        ['name' => 'No id'],
    ]];
    $this->adsets = ['data' => [
        ['id' => '2001', 'name' => 'Dhaka 18-35', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'campaign_id' => '1001', 'daily_budget' => '0', 'optimization_goal' => 'LEAD_GENERATION'],
    ]];
    $this->ads = ['data' => [
        ['id' => '3001', 'name' => 'Video 1', 'status' => 'ACTIVE', 'effective_status' => 'ACTIVE', 'campaign_id' => '1001', 'adset_id' => '2001'],
    ]];
    $this->accountInsights = ['data' => [
        ['date_start' => '2026-10-12', 'account_currency' => 'BDT', 'spend' => '1234.56', 'impressions' => '10000', 'reach' => '8000', 'clicks' => '250', 'actions' => [['action_type' => 'lead', 'value' => '12'], ['action_type' => 'link_click', 'value' => '230'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '9']]],
        ['date_start' => '2026-10-11', 'account_currency' => 'BDT', 'spend' => '0.07', 'impressions' => '5'],
        ['date_start' => '2026-10-10', 'account_currency' => 'USD', 'spend' => '99.00', 'impressions' => '500'],
        ['date_start' => 'garbage', 'spend' => '1.00'],
    ]];
    $this->campaignInsights = ['data' => [
        ['campaign_id' => '1001', 'date_start' => '2026-10-12', 'account_currency' => 'BDT', 'spend' => '1000.00', 'impressions' => '9000', 'clicks' => '200', 'actions' => [['action_type' => 'lead', 'value' => '12']]],
        ['campaign_id' => '1002', 'date_start' => '2026-10-12', 'account_currency' => 'BDT', 'spend' => '234.56', 'impressions' => '1000', 'clicks' => '50'],
    ]];
    $this->fakeAll = function (array $overrides = []): void {
        // Overrides come first and win: the first matching stub answers.
        $this->fakeMeta($overrides + [
            'act_111/campaigns*' => $this->campaigns,
            'act_111/adsets*' => $this->adsets,
            'act_111/ads*' => $this->ads,
            'act_111/insights?level=account*' => $this->accountInsights,
            'act_111/insights?level=campaign*' => $this->campaignInsights,
        ]);
    };
});

it('maps campaigns, ad sets and ads into the workspace and ignores deleted or id-less rows', function () {
    ($this->fakeAll)();

    expect(($this->sync)())->toBe(SyncState::Ok);

    $this->inTenant($this->tenant, function (): void {
        $campaigns = AdObject::query()->where('level', ObjectLevel::Campaign->value)->orderBy('external_id')->get();
        expect($campaigns)->toHaveCount(2);

        [$eid, $second] = [$campaigns[0], $campaigns[1]];
        expect($eid->external_id)->toBe('1001')
            ->and($eid->name)->toBe('Eid Sale')
            ->and($eid->status)->toBe('ACTIVE')
            ->and($eid->objective)->toBe('OUTCOME_LEADS')
            ->and($eid->daily_budget)->toBe(150000)
            ->and($eid->lifetime_budget)->toBeNull()
            ->and($eid->budget_remaining)->toBeNull()
            ->and($eid->currency)->toBe('BDT')
            ->and($eid->start_at?->toIso8601String())->toBe('2026-09-30T18:00:00+00:00')
            ->and($eid->end_at?->toIso8601String())->toBe('2026-10-31T18:00:00+00:00')
            ->and($eid->campaign_external_id)->toBe('1001')
            ->and($second->daily_budget)->toBeNull()
            ->and($second->lifetime_budget)->toBe(5000000)
            ->and($second->budget_remaining)->toBe(1200000)
            ->and($second->name)->toBe('=HYPERLINK("http://evil")');

        $adset = AdObject::query()->where('level', ObjectLevel::AdSet->value)->sole();
        $ad = AdObject::query()->where('level', ObjectLevel::Ad->value)->sole();
        expect($adset->parent_external_id)->toBe('1001')
            ->and($adset->campaign_external_id)->toBe('1001')
            ->and($adset->daily_budget)->toBeNull()
            ->and($ad->parent_external_id)->toBe('2001')
            ->and($ad->campaign_external_id)->toBe('1001')
            ->and(AdObject::query()->where('external_id', '1003')->exists())->toBeFalse();
    });

    $account = ($this->fresh)();
    expect($account->sync_state)->toBe(SyncState::Ok)
        ->and($account->last_synced_at)->not->toBeNull()
        ->and($account->insights_through?->toDateString())->toBe('2026-10-13');
});

it('is idempotent: a second run changes no row count and creates no duplicates', function () {
    ($this->fakeAll)();
    ($this->sync)();
    $counts = $this->inTenant($this->tenant, fn () => [AdObject::query()->count(), Insight::query()->count(), Insight::query()->pluck('id')->sort()->values()->all()]);

    ($this->sync)();

    $after = $this->inTenant($this->tenant, fn () => [AdObject::query()->count(), Insight::query()->count(), Insight::query()->pluck('id')->sort()->values()->all()]);
    expect($after)->toBe($counts);
});

it('parses spend exactly into minor units and keeps unreported figures null, never zero', function () {
    ($this->fakeAll)();
    ($this->sync)();

    $this->inTenant($this->tenant, function (): void {
        $rows = Insight::query()->where('level', Insight::LEVEL_ACCOUNT)->orderBy('metric_date')->get();
        expect($rows)->toHaveCount(2);

        [$small, $big] = [$rows[0], $rows[1]];
        expect($small->metric_date->toDateString())->toBe('2026-10-11')
            ->and($small->spend)->toBe(7)
            ->and($small->impressions)->toBe(5)
            ->and($small->reach)->toBeNull()
            ->and($small->clicks)->toBeNull()
            ->and($small->leads)->toBeNull()
            ->and($small->actions)->toBeNull()
            ->and($big->spend)->toBe(123456)
            ->and($big->impressions)->toBe(10000)
            ->and($big->reach)->toBe(8000)
            ->and($big->clicks)->toBe(250)
            ->and($big->leads)->toBe(12)
            ->and($big->actions)->toBe(['lead' => 12, 'link_click' => 230, 'onsite_conversion.lead_grouped' => 9])
            ->and($big->currency)->toBe('BDT');

        $campaign = Insight::query()->where('level', Insight::LEVEL_CAMPAIGN)->where('object_external_id', '1001')->sole();
        expect($campaign->spend)->toBe(100000)->and($campaign->leads)->toBe(12);
        $other = Insight::query()->where('level', Insight::LEVEL_CAMPAIGN)->where('object_external_id', '1002')->sole();
        expect($other->leads)->toBeNull()->and($other->reach)->toBeNull();
    });
});

it('never mixes currencies: a day Meta reports in another currency is skipped and the account says so', function () {
    ($this->fakeAll)();
    ($this->sync)();

    $this->inTenant($this->tenant, function (): void {
        expect(Insight::query()->where('metric_date', '2026-10-10')->exists())->toBeFalse()
            ->and(Insight::query()->where('currency', '!=', 'BDT')->exists())->toBeFalse();
    });

    $account = ($this->fresh)();
    expect($account->sync_error_code)->toBe('currency_mismatch')->and($account->sync_error)->not->toBeNull();
});

it('restates recent days: a changed figure updates the same row', function () {
    ($this->fakeAll)();
    ($this->sync)();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC'));

    $this->accountInsights['data'][0]['spend'] = '1300.00';
    ($this->fakeAll)();
    ($this->sync)();

    $this->inTenant($this->tenant, function (): void {
        $row = Insight::query()->where('level', Insight::LEVEL_ACCOUNT)->where('metric_date', '2026-10-12')->sole();
        expect($row->spend)->toBe(130000);
    });

    // The window re-read the last 3 days before the stored high-water mark (2026-10-13 in Dhaka).
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), 'act_111/insights') || ! str_contains($request->url(), 'level=account')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['time_range'] ?? '') === json_encode(['since' => '2026-10-10', 'until' => '2026-10-14']);
    });
});

it('reads the first sync window from the workspace setting, clamped', function () {
    $this->inTenant($this->tenant, fn () => MetaAdsSetting::query()->create(['lookback_days' => 14]));
    ($this->fakeAll)();
    ($this->sync)();

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), 'level=account')
            && ($query['time_range'] ?? '') === json_encode(['since' => '2026-09-30', 'until' => '2026-10-13']);
    });
});

it('follows cursors with its own parameters and never the paging.next URL', function () {
    $page1 = ['data' => [$this->campaigns['data'][0]], 'paging' => ['cursors' => ['after' => 'CUR2'], 'next' => 'https://graph.facebook.com/v23.0/act_111/campaigns?access_token=LEAKED-NEXT-TOKEN&after=CUR2']];
    $page2 = ['data' => [$this->campaigns['data'][1]], 'paging' => ['cursors' => ['after' => 'CUR3']]];
    ($this->fakeAll)(['act_111/campaigns*after=CUR2*' => $page2, 'act_111/campaigns*' => $page1]);

    ($this->sync)();

    $this->inTenant($this->tenant, fn () => expect(AdObject::query()->where('level', 'campaign')->count())->toBe(2));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'LEAKED-NEXT-TOKEN'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'access_token'));
});

it('marks what Meta stopped returning as missing only after a complete listing', function () {
    ($this->fakeAll)();
    ($this->sync)();

    $this->campaigns['data'] = [$this->campaigns['data'][0]];
    ($this->fakeAll)();
    $this->travelTo(CarbonImmutable::parse('2026-10-13 07:00:00', 'UTC'));
    ($this->sync)();

    $this->inTenant($this->tenant, function (): void {
        expect(AdObject::query()->where('external_id', '1002')->sole()->missing_at)->not->toBeNull()
            ->and(AdObject::query()->where('external_id', '1001')->sole()->missing_at)->toBeNull();
    });

    // A listing cut short by the page budget must not declare anything missing.
    config(['meta_ads.sync.max_pages_per_object' => 1]);
    $this->inTenant($this->tenant, fn () => AdObject::query()->where('external_id', '1002')->update(['missing_at' => null]));
    $page = ['data' => [$this->campaigns['data'][0]], 'paging' => ['cursors' => ['after' => 'X'], 'next' => 'https://graph.facebook.com/next']];
    ($this->fakeAll)(['act_111/campaigns*' => $page]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'));

    expect(($this->sync)())->toBe(SyncState::Partial);
    $this->inTenant($this->tenant, fn () => expect(AdObject::query()->where('external_id', '1002')->sole()->missing_at)->toBeNull());
});

it('pauses an ad account when Meta reports its quota nearly used and resumes after the wait', function () {
    $usage = ['x-business-use-case-usage' => json_encode(['9' => [['type' => 'ads_management', 'call_count' => 92, 'total_cputime' => 10, 'total_time' => 10, 'estimated_time_to_regain_access' => 10]]])];
    ($this->fakeAll)(['act_111/campaigns*' => Http::response($this->campaigns, 200, $usage)]);

    expect(($this->sync)())->toBe(SyncState::Throttled);

    $account = ($this->fresh)();
    expect($account->sync_state)->toBe(SyncState::Throttled)
        ->and($account->usage_percent)->toBe(92)
        ->and($account->isThrottled())->toBeTrue()
        ->and($account->throttled_until?->toIso8601String())->toBe('2026-10-13T06:10:00+00:00');

    // Only the first listing was requested.
    Http::assertSentCount(1);

    // While throttled, a sync makes no calls at all.
    expect(($this->sync)())->toBe(SyncState::Throttled);
    Http::assertSentCount(1);

    // After the wait, work resumes.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 06:11:00', 'UTC'));
    ($this->fakeAll)();
    expect(($this->sync)())->toBe(SyncState::Ok);
    expect(($this->fresh)()->throttled_until)->toBeNull();
});

it('backs off on a throttling error instead of failing, and never retries inside the same run', function () {
    foreach ([4, 17, 613, 80004] as $code) {
        $this->inTenant($this->tenant, fn () => AdAccount::query()->whereKey($this->account->id)->update(['throttled_until' => null, 'sync_state' => null]));
        ($this->fakeAll)(['act_111/campaigns*' => $this->graphError($code, 'Calls to this api have exceeded the rate limit.')]);

        expect(($this->sync)())->toBe(SyncState::Throttled);

        $account = ($this->fresh)();
        expect($account->sync_error_code)->toBe('rate_limited')
            ->and($account->isThrottled())->toBeTrue()
            ->and($account->throttled_until?->gte(now()->addMinutes(1)))->toBeTrue();
        Http::assertSentCount(1);
    }
});

it('drops a rejected token, asks for authorisation again and announces it once', function () {
    Event::fake([ConnectionNeedsReauthorization::class]);
    ($this->fakeAll)(['act_111/campaigns*' => $this->graphError(190, 'Error validating access token: Session has expired for token '.'EAAB-secret', 401, ['error_subcode' => 463])]);

    expect(($this->sync)())->toBe(SyncState::Error);

    $this->inTenant($this->tenant, function (): void {
        $connection = Connection::query()->findOrFail($this->connection->id);
        expect($connection->status)->toBe(ConnectionStatus::NeedsReauthorization)
            ->and($connection->access_token)->toBeNull()
            ->and($connection->status_reason)->not->toContain('EAAB-secret');
    });
    expect(($this->fresh)()->sync_error_code)->toBe('reauthorization_required');
    Event::assertDispatchedTimes(ConnectionNeedsReauthorization::class, 1);

    // Nothing more is sent with a dropped token.
    Http::assertSentCount(1);
    ($this->sync)();
    Http::assertSentCount(1);
});

it('reports a missing permission on the account without dropping the authorisation', function () {
    ($this->fakeAll)(['act_111/campaigns*' => $this->graphError(200, '(#200) Requires ads_read permission')]);

    expect(($this->sync)())->toBe(SyncState::Error);

    expect(($this->fresh)()->sync_error_code)->toBe('permission_missing');
    $this->inTenant($this->tenant, fn () => expect(Connection::query()->findOrFail($this->connection->id)->status)->toBe(ConnectionStatus::Connected));
});

it('survives a network failure and a Meta outage without throwing', function () {
    ($this->fakeAll)(['act_111/campaigns*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
    expect(($this->sync)())->toBe(SyncState::Error);
    expect(($this->fresh)()->sync_error_code)->toBe('network_error');

    ($this->fakeAll)(['act_111/campaigns*' => $this->graphError(2, 'Service temporarily unavailable', 503)]);
    expect(($this->sync)())->toBe(SyncState::Error);
    expect(($this->fresh)()->sync_error_code)->toBe('meta_server_error');
});

it('never leaves a token or app secret in the stored error text', function () {
    ($this->fakeAll)(['act_111/campaigns*' => $this->graphError(100, 'Invalid parameter for token '.$this->connection->access_token.' secret app-secret-xyz')]);
    ($this->sync)();

    $account = ($this->fresh)();
    expect((string) $account->sync_error)->not->toContain($this->connection->access_token)->not->toContain('app-secret-xyz')
        ->and(strlen((string) $account->sync_error))->toBeLessThanOrEqual(290);
});

it('extends a long-lived token that is about to expire, and asks again when Meta refuses', function () {
    $this->inTenant($this->tenant, fn () => Connection::query()->whereKey($this->connection->id)->update(['token_expires_at' => now()->addDays(3), 'token_renewed_at' => null]));
    ($this->fakeAll)(['oauth/access_token' => ['access_token' => 'RENEWED-TOKEN', 'expires_in' => 5184000]]);

    ($this->sync)();

    $this->inTenant($this->tenant, function (): void {
        $connection = Connection::query()->findOrFail($this->connection->id);
        expect($connection->access_token)->toBe('RENEWED-TOKEN')
            ->and($connection->token_expires_at?->gt(now()->addDays(50)))->toBeTrue();
    });
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth/access_token') && ($request['grant_type'] ?? null) === 'fb_exchange_token');

    // Within a day it is not asked again.
    $this->inTenant($this->tenant, fn () => Connection::query()->whereKey($this->connection->id)->update(['token_expires_at' => now()->addDays(3)]));
    ($this->fakeAll)(['oauth/access_token' => $this->graphError(190, 'expired')]);
    ($this->sync)();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth/access_token'));
});

it('does nothing for an account that is not tracked or whose authorisation is gone', function () {
    $this->inTenant($this->tenant, fn () => AdAccount::query()->whereKey($this->account->id)->update(['status' => AdAccountStatus::Available->value]));
    ($this->fakeAll)();
    ($this->sync)();
    Http::assertNothingSent();

    $this->inTenant($this->tenant, function (): void {
        AdAccount::query()->whereKey($this->account->id)->update(['status' => AdAccountStatus::Tracked->value]);
        Connection::query()->whereKey($this->connection->id)->update(['status' => ConnectionStatus::Disconnected->value, 'access_token' => null]);
    });
    expect(($this->sync)())->toBe(SyncState::Error);
    Http::assertNothingSent();
});

it('runs the scheduled job within its caps: tracked accounts only, least recently synced first, throttled skipped, one failure never stops the rest', function () {
    config(['meta_ads.sync.max_accounts_per_run' => 2]);
    $a = $this->account;
    $b = $this->metaAccount($this->tenant, $this->connection, ['external_id' => 'act_222', 'name' => 'B', 'last_synced_at' => now()->subHours(5)]);
    $c = $this->metaAccount($this->tenant, $this->connection, ['external_id' => 'act_333', 'name' => 'C', 'last_synced_at' => now()->subHours(2)]);
    $this->metaAccount($this->tenant, $this->connection, ['external_id' => 'act_444', 'name' => 'Untracked', 'status' => AdAccountStatus::Available]);
    $this->metaAccount($this->tenant, $this->connection, ['external_id' => 'act_555', 'name' => 'Throttled', 'throttled_until' => now()->addHour()]);
    $this->fakeMeta([
        'act_111/campaigns*' => $this->graphError(2, 'down', 503),
        '*' => ['data' => []],
    ]);

    $this->inTenant($this->tenant, fn () => app()->call([new SyncAdAccounts, 'handle']));

    // Never-synced first (act_111 throws, the job goes on), then the oldest (act_222); the cap is two.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'act_111/campaigns'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'act_222/campaigns'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'act_333'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'act_444') || str_contains($request->url(), 'act_555'));
});

it('declares itself as a tenant-bound, bounded, encrypted job on the campaigns queue', function () {
    $job = new SyncAdAccounts;

    expect($job)->toBeInstanceOf(RequiresTenantContext::class)
        ->and($job)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($job->queue)->toBe('campaigns')
        ->and($job->tries)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeLessThan(300);
});
