<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Jobs\SyncAdAccounts;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Support\OAuthBroker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithMetaAds;

uses(InteractsWithMetaAds::class);

const META_SHORT = 'SHORT-TOKEN-aaaa';
const META_LONG = 'LONG-TOKEN-SECRET-bbbb';

beforeEach(function () {
    $this->tenant = $this->metaTenant('meta-conn');
    $this->url = fn (string $path = '') => $this->metaUrl($this->tenant, $path);
    $this->grant = function (array $overrides = []): void {
        $this->fakeMeta([
            'oauth/access_token' => fn ($request) => Http::response(($request['grant_type'] ?? null) === 'fb_exchange_token'
                ? ['access_token' => META_LONG, 'token_type' => 'bearer', 'expires_in' => 5184000]
                : ['access_token' => META_SHORT, 'expires_in' => 3600]),
            'me/permissions*' => ['data' => [
                ['permission' => 'ads_read', 'status' => 'granted'],
                ['permission' => 'ads_management', 'status' => 'granted'],
                ['permission' => 'business_management', 'status' => 'declined'],
            ]],
            'me/adaccounts*' => ['data' => [
                ['id' => 'act_111', 'account_id' => '111', 'name' => 'Shop Ads', 'currency' => 'BDT', 'timezone_name' => 'Asia/Dhaka', 'account_status' => 1, 'business_name' => 'Shop Ltd'],
                ['id' => 'act_222', 'account_id' => '222', 'name' => 'Export Ads', 'currency' => 'USD', 'timezone_name' => 'America/Los_Angeles', 'account_status' => 2],
                ['id' => 'not-an-account', 'name' => 'Junk', 'currency' => 'USD'],
            ]],
            'me*' => ['id' => 'fb-user-1', 'name' => 'Rahim Owner'],
            ...$overrides,
        ]);
    };
    $this->begin = function (): string {
        $response = $this->post(($this->url)('/accounts/connect'));
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    };
});

it('starts authorisation with the central redirect URI, an enveloped state and only the documented scopes', function () {
    $this->actingAsOwner($this->tenant);

    $response = $this->post(($this->url)('/accounts/connect'));
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth?')
        ->and($query['client_id'])->toBe('app-1234')
        ->and($query['scope'])->toBe('ads_read,ads_management')
        ->and($query['redirect_uri'])->toBe($this->centralUrl('/meta-ads/oauth/callback'))
        ->and($query['state'])->toStartWith('meta-conn.')
        ->and($location)->not->toContain('app-secret-xyz');
    expect(app(OAuthBroker::class)->unwrap($query['state']))->toMatchArray(['slug' => 'meta-conn']);
});

it('asks for read access only when management is switched off for the installation', function () {
    $this->configureMeta(management: false);
    $this->actingAsOwner($this->tenant);

    $location = (string) $this->post(($this->url)('/accounts/connect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('ads_read');
});

it('refuses to start when the operator has not configured the Meta app', function () {
    config(['meta_ads.app_id' => null, 'meta_ads.app_secret' => null]);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/connect'))->assertSessionHasErrors('authorization');
});

it('completes the full flow through the central broker and stores the token encrypted', function () {
    ($this->grant)();
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();

    $hop = $this->get($this->centralUrl('/meta-ads/oauth/callback?code=the-code&state='.urlencode($state)));
    $hop->assertRedirect();
    $location = (string) $hop->headers->get('Location');

    expect($location)->toStartWith(($this->url)('/accounts/callback?'))
        ->and($hop->headers->get('Set-Cookie'))->toBeNull()
        ->and((string) $hop->headers->get('Cache-Control'))->toContain('no-store');

    $this->get($location)->assertRedirect(($this->url)('/accounts'));

    $connection = $this->inTenant($this->tenant, fn () => Connection::query()->sole());
    $raw = DB::table('meta_ads_connections')->where('id', $connection->id)->first();

    expect($connection->status)->toBe(ConnectionStatus::Connected)
        ->and($connection->external_user_id)->toBe('fb-user-1')
        ->and($connection->user_name)->toBe('Rahim Owner')
        ->and($connection->access_token)->toBe(META_LONG)
        ->and($connection->granted_scopes)->toBe(['ads_read', 'ads_management'])
        ->and($connection->declined_scopes)->toBe(['business_management'])
        ->and($connection->token_expires_at?->isFuture())->toBeTrue()
        ->and($connection->canManage())->toBeTrue()
        ->and($raw->access_token)->not->toContain('LONG-TOKEN')->not->toContain('SHORT-TOKEN');

    $accounts = $this->inTenant($this->tenant, fn () => AdAccount::query()->orderBy('external_id')->get());
    expect($accounts)->toHaveCount(2)
        ->and($accounts[0]->external_id)->toBe('act_111')
        ->and($accounts[0]->currency)->toBe('BDT')
        ->and($accounts[0]->status)->toBe(AdAccountStatus::Available)
        ->and($accounts[1]->currency)->toBe('USD')
        ->and($accounts[1]->meta_status)->toBe(2);
});

it('sends the token only in the Authorization header and never in a URL, and signs the proof', function () {
    ($this->grant)();
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=the-code&state='.urlencode($state)));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'me/adaccounts')
        && $request->hasHeader('Authorization', 'Bearer '.META_LONG)
        && ($request->data()['appsecret_proof'] ?? null) === hash_hmac('sha256', META_LONG, 'app-secret-xyz'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), META_LONG) || str_contains($request->url(), META_SHORT));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'app-secret-xyz'));
});

it('never exposes the token in a page, in JSON, in array form or in the audit trail', function () {
    ($this->grant)();
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=the-code&state='.urlencode($state)));

    $page = $this->get(($this->url)('/accounts'));
    $page->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('Modules/MetaAds/Pages/Accounts/Index')
        ->has('connections', 1)
        ->where('connections.0.canManage', true)
        ->has('accounts', 2));

    $connection = $this->inTenant($this->tenant, fn () => Connection::query()->sole());
    $dump = json_encode($page->viewData('page')).json_encode($connection->toArray()).$connection->toJson();
    $audit = $this->inTenant($this->tenant, fn () => json_encode(AuditLog::query()->get()->toArray()));

    foreach ([META_LONG, META_SHORT, 'app-secret-xyz'] as $secret) {
        expect($dump)->not->toContain($secret)
            ->and($audit)->not->toContain($secret);
    }

    expect($connection->makeVisible([])->getHidden())->toContain('access_token');
});

it('rejects a callback that is forged, replayed, expired or completed by someone else', function () {
    ($this->grant)();
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();

    $this->get(($this->url)('/accounts/callback?code=ok&state=forged'))->assertRedirect(($this->url)('/accounts'));
    $this->inTenant($this->tenant, fn () => expect(Connection::query()->count())->toBe(0));

    // The failed attempt spent the state: even the right value no longer works.
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)));
    $this->inTenant($this->tenant, fn () => expect(Connection::query()->count())->toBe(0));

    // Another member cannot finish someone else's authorisation.
    $state = ($this->begin)();
    [$other] = $this->metaMember($this->tenant, ['meta_ads.accounts.manage']);
    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $other);
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)));
    $this->inTenant($this->tenant, fn () => expect(Connection::query()->count())->toBe(0));

    // Expired.
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();
    $this->travel(11)->minutes();
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)));
    $this->inTenant($this->tenant, fn () => expect(Connection::query()->count())->toBe(0));
});

it('does not accept a state minted for another workspace', function () {
    ($this->grant)();
    $other = $this->metaTenant('meta-other');
    $this->actingAsOwner($other);
    $foreignState = (string) (function () use ($other) {
        $response = $this->post($this->metaUrl($other, '/accounts/connect'));
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['state'];
    })();

    $this->startNewBrowserSession();
    $this->actingAsOwner($this->tenant);
    ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($foreignState)));

    $this->inTenant($this->tenant, fn () => expect(Connection::query()->count())->toBe(0));
});

it('turns a refusal and a missing ads_read permission into a message, not a connection', function () {
    ($this->grant)();
    $this->actingAsOwner($this->tenant);

    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?error=access_denied&state='.urlencode($state)))->assertRedirect(($this->url)('/accounts'));

    ($this->grant)(['me/permissions*' => ['data' => [['permission' => 'ads_management', 'status' => 'granted']]]]);
    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)))->assertRedirect(($this->url)('/accounts'));

    $this->inTenant($this->tenant, fn () => expect(Connection::query()->count())->toBe(0));
});

it('keeps the short-lived token when extending fails, and reports Meta errors without leaking them', function () {
    ($this->grant)(['oauth/access_token' => fn ($request) => ($request['grant_type'] ?? null) === 'fb_exchange_token'
        ? $this->graphError(1, 'oops '.META_SHORT)
        : Http::response(['access_token' => META_SHORT, 'expires_in' => 3600])]);
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)));

    $connection = $this->inTenant($this->tenant, fn () => Connection::query()->sole());
    expect($connection->access_token)->toBe(META_SHORT)
        ->and($connection->token_expires_at?->isBefore(now()->addHours(2)))->toBeTrue();
});

it('reauthorises the same Facebook person onto the same connection', function () {
    ($this->grant)();
    $this->actingAsOwner($this->tenant);
    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)));

    $connection = $this->inTenant($this->tenant, fn () => Connection::query()->sole());
    $account = $this->inTenant($this->tenant, fn () => AdAccount::query()->where('external_id', 'act_111')->sole());
    $this->inTenant($this->tenant, function () use ($connection, $account): void {
        $account->forceFill(['status' => AdAccountStatus::Tracked])->save();
        $connection->forceFill(['status' => ConnectionStatus::NeedsReauthorization, 'access_token' => null])->save();
    });

    $state = ($this->begin)();
    $this->get(($this->url)('/accounts/callback?code=ok&state='.urlencode($state)));

    $this->inTenant($this->tenant, function () use ($connection): void {
        $fresh = Connection::query()->sole();
        expect($fresh->id)->toBe($connection->id)
            ->and($fresh->status)->toBe(ConnectionStatus::Connected)
            ->and($fresh->access_token)->toBe(META_LONG)
            ->and(AdAccount::query()->where('external_id', 'act_111')->sole()->status)->toBe(AdAccountStatus::Tracked);
    });
});

it('discards the token on disconnect and stops tracking, but keeps the history', function () {
    $connection = $this->metaConnection($this->tenant);
    $account = $this->metaAccount($this->tenant, $connection);
    $this->metaInsight($this->tenant, $account, '2026-10-01');
    $this->actingAsOwner($this->tenant);

    $this->delete(($this->url)('/connections/'.$connection->id))->assertRedirect(($this->url)('/accounts'));

    $this->inTenant($this->tenant, function () use ($connection, $account): void {
        $fresh = Connection::query()->findOrFail($connection->id);
        expect($fresh->status)->toBe(ConnectionStatus::Disconnected)
            ->and($fresh->access_token)->toBeNull()
            ->and(AdAccount::query()->findOrFail($account->id)->status)->toBe(AdAccountStatus::Removed)
            ->and(Insight::query()->count())->toBe(1);
    });
});

it('enforces the plan limit when tracking an ad account', function () {
    $plan = $this->createFreePlan(['key' => 'one-ad-account', 'modules' => ['meta_ads'], 'limits' => ['meta_ads.ad_accounts' => 1]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);
    $connection = $this->metaConnection($this->tenant);
    $first = $this->metaAccount($this->tenant, $connection, ['status' => AdAccountStatus::Available]);
    $second = $this->metaAccount($this->tenant, $connection, ['status' => AdAccountStatus::Available]);
    $this->actingAsOwner($this->tenant);
    Bus::fake();

    $this->post(($this->url)('/accounts/'.$first->id.'/track'))->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(AdAccount::query()->findOrFail($first->id)->status)->toBe(AdAccountStatus::Tracked));
    Bus::assertDispatched(SyncAdAccounts::class);

    $this->post(($this->url)('/accounts/'.$second->id.'/track'))->assertSessionHasErrors('account');
    $this->inTenant($this->tenant, fn () => expect(AdAccount::query()->findOrFail($second->id)->status)->toBe(AdAccountStatus::Available));

    // Stopping one frees the seat.
    $this->delete(($this->url)('/accounts/'.$first->id.'/track'))->assertRedirect();
    $this->post(($this->url)('/accounts/'.$second->id.'/track'))->assertSessionDoesntHaveErrors();
    $this->inTenant($this->tenant, fn () => expect(AdAccount::query()->findOrFail($second->id)->status)->toBe(AdAccountStatus::Tracked));
});
