<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Modules\ModuleRegistry;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ChangeStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Change;
use App\Modules\MetaAds\Models\Connection;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Models\MetaAdsSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithMetaAds;

uses(InteractsWithMetaAds::class);

$connection = fn (): Connection => Connection::query()->create([
    'external_user_id' => 'fb-'.Str::random(8), 'access_token' => 'secret-token', 'status' => ConnectionStatus::Connected,
]);
$account = fn (): AdAccount => AdAccount::query()->create([
    'connection_id' => $connection()->id, 'external_id' => 'act_'.random_int(1000, 999999), 'name' => 'Shop', 'currency' => 'BDT', 'status' => AdAccountStatus::Tracked,
]);
$object = fn (): AdObject => AdObject::query()->create([
    'account_id' => $account()->id, 'level' => 'campaign', 'external_id' => (string) random_int(1000, 999999), 'name' => 'Sale',
    'currency' => 'BDT', 'first_seen_at' => now(), 'last_seen_at' => now(),
]);

it('isolates every Meta Ads model between workspaces', function () use ($connection, $account, $object) {
    $this->assertTenantIsolated(Connection::class, fn () => $connection());
    $this->assertTenantIsolated(AdAccount::class, fn () => $account());
    $this->assertTenantIsolated(AdObject::class, fn () => $object());
    $this->assertTenantIsolated(Insight::class, fn () => Insight::query()->create([
        'account_id' => $account()->id, 'level' => 'account', 'object_external_id' => 'act_1', 'metric_date' => '2026-10-01', 'currency' => 'BDT', 'synced_at' => now(),
    ]));
    $this->assertTenantIsolated(Change::class, function () use ($object) {
        $item = $object();

        return Change::query()->create([
            'account_id' => $item->account_id, 'object_id' => $item->id, 'request_id' => Str::random(20), 'action' => 'pause', 'field' => 'status',
            'level' => 'campaign', 'object_name' => 'Sale', 'status' => ChangeStatus::Pending, 'requested_at' => now(),
        ]);
    });
    $this->assertTenantIsolated(MetaAdsSetting::class, fn () => MetaAdsSetting::query()->create([]));
});

it('keeps the same ad account and the same insight day apart in two workspaces', function () {
    $one = $this->metaTenant('meta-iso-1');
    $two = $this->metaTenant('meta-iso-2');
    $a = $this->metaAccount($one, null, ['external_id' => 'act_777']);
    $b = $this->metaAccount($two, null, ['external_id' => 'act_777']);
    $this->metaInsight($one, $a, '2026-10-01', ['spend' => 1000]);
    $this->metaInsight($two, $b, '2026-10-01', ['spend' => 9999]);

    $this->inTenant($one, fn () => expect(Insight::query()->sole()->spend)->toBe(1000)->and(AdAccount::query()->count())->toBe(1));
    $this->inTenant($two, fn () => expect(Insight::query()->sole()->spend)->toBe(9999));
});

it('answers 404 from another workspace for every route that takes an id or a filter id', function () {
    $home = $this->metaTenant('meta-home');
    $intruder = $this->metaTenant('meta-intruder');
    $connection = $this->metaConnection($home);
    $account = $this->metaAccount($home, $connection);
    $object = $this->metaObject($home, $account);
    $this->inTenant($home, fn () => MetaAdsSetting::query()->create(['management_enabled' => true]));
    $this->actingAsOwner($intruder);
    Bus::fake();
    $url = fn (string $path) => $this->metaUrl($intruder, $path);

    $this->post($url('/campaigns/'.$object->id.'/status'), ['action' => 'pause', 'expected' => 'ACTIVE', 'request_id' => 'req-0000000000000001'])->assertNotFound();
    $this->post($url('/campaigns/'.$object->id.'/budget'), ['amount' => '10', 'expected' => '100000', 'request_id' => 'req-0000000000000002'])->assertNotFound();
    $this->post($url('/accounts/'.$account->id.'/track'))->assertNotFound();
    $this->delete($url('/accounts/'.$account->id.'/track'))->assertNotFound();
    $this->post($url('/accounts/'.$account->id.'/sync'))->assertNotFound();
    $this->delete($url('/connections/'.$connection->id))->assertNotFound();
    $this->get($url('/?account='.$account->id))->assertNotFound();
    $this->get($url('/campaigns?account='.$account->id))->assertNotFound();
    $this->get($url('/performance/export?account='.$account->id))->assertNotFound();

    // Nothing of the home workspace changed.
    $this->inTenant($home, function () use ($connection, $account): void {
        expect(Connection::query()->findOrFail($connection->id)->status)->toBe(ConnectionStatus::Connected)
            ->and(AdAccount::query()->findOrFail($account->id)->status)->toBe(AdAccountStatus::Tracked)
            ->and(Change::query()->count())->toBe(0);
    });
    Bus::assertNothingDispatched();
});

it('answers 404 on every route when the workspace is not entitled to Meta Ads, even for the owner', function () {
    $tenant = $this->createTenant('meta-no-app');
    $this->entitle($tenant, 'social');
    $this->actingAsOwner($tenant);

    foreach (['/', '/campaigns', '/accounts', '/settings', '/performance/export'] as $path) {
        $this->get($this->metaUrl($tenant, $path))->assertNotFound();
    }

    $this->post($this->metaUrl($tenant, '/accounts/connect'))->assertNotFound();
    $this->get($this->metaUrl($tenant, '/accounts/callback?code=x&state=y'))->assertNotFound();
});

it('declares its dependency on core and the Social app', function () {
    $tenant = $this->createTenant('meta-no-social');
    $this->configureMeta();
    $this->entitle($tenant, 'meta_ads');
    $registry = app(ModuleRegistry::class);

    expect($registry->get('meta_ads')->dependencies)->toBe(['core', 'social']);
});

it('sends signed-out visitors to sign in', function () {
    $tenant = $this->metaTenant('meta-out');

    foreach (['/', '/campaigns', '/accounts', '/settings'] as $path) {
        $this->get($this->metaUrl($tenant, $path))->assertRedirect();
    }
});

it('gives each route only to the permission it names', function () {
    $tenant = $this->metaTenant('meta-perms');
    $account = $this->metaAccount($tenant);
    $this->inTenant($tenant, fn () => MetaAdsSetting::query()->create([]));
    $routes = [
        ['get', '/', 'meta_ads.insights.view'],
        ['get', '/performance/export', 'meta_ads.insights.export'],
        ['get', '/campaigns', 'meta_ads.campaigns.view'],
        ['get', '/accounts', 'meta_ads.accounts.view'],
        ['get', '/settings', 'meta_ads.settings.manage'],
        ['post', '/accounts/connect', 'meta_ads.accounts.manage'],
        ['post', '/accounts/'.$account->id.'/track', 'meta_ads.accounts.manage'],
        ['post', '/accounts/'.$account->id.'/sync', 'meta_ads.accounts.manage'],
        ['put', '/settings', 'meta_ads.settings.manage'],
    ];
    Bus::fake();

    foreach ($routes as [$verb, $path, $permission]) {
        $others = array_values(array_unique(array_diff(array_column($routes, 2), [$permission])));
        [$wrong] = $this->metaMember($tenant, $others);
        $this->startNewBrowserSession();
        $this->actingAsMember($tenant, $wrong);
        $this->{$verb}($this->metaUrl($tenant, $path))->assertForbidden();
    }

    // The right permission alone is enough to be let in (the response may still be a redirect or a validation error).
    [$viewer] = $this->metaMember($tenant, ['meta_ads.insights.view']);
    $this->startNewBrowserSession();
    $this->actingAsMember($tenant, $viewer);
    $this->get($this->metaUrl($tenant, '/'))->assertOk();
    $this->get($this->metaUrl($tenant, '/campaigns'))->assertForbidden();
});

it('declares the sensitive permissions and the module facts in the manifest', function () {
    $manifest = app(ModuleRegistry::class)->get('meta_ads');
    $permissions = collect($manifest->permissions)->keyBy('name');

    expect($permissions->keys()->all())->toBe([
        'meta_ads.insights.view', 'meta_ads.insights.export', 'meta_ads.campaigns.view', 'meta_ads.campaigns.manage',
        'meta_ads.accounts.view', 'meta_ads.accounts.manage', 'meta_ads.settings.manage',
    ])
        ->and($permissions['meta_ads.campaigns.manage']->sensitive)->toBeTrue()
        ->and($permissions['meta_ads.accounts.manage']->sensitive)->toBeTrue()
        ->and($permissions['meta_ads.settings.manage']->sensitive)->toBeTrue()
        ->and($permissions['meta_ads.insights.view']->sensitive)->toBeFalse()
        ->and(collect($manifest->scheduledJobs)->pluck('key')->all())->toBe(['meta_ads.sync'])
        ->and(collect($manifest->limits)->pluck('key')->all())->toBe(['meta_ads.ad_accounts'])
        ->and(array_keys($manifest->morphMap))->toHaveCount(6);
});

it('saves settings and per-account ceilings in the account currency, and audits a ceiling change', function () {
    $tenant = $this->metaTenant('meta-settings');
    $bdt = $this->metaAccount($tenant, null, ['currency' => 'BDT']);
    $jpy = $this->metaAccount($tenant, null, ['currency' => 'JPY']);
    $this->actingAsOwner($tenant);
    $put = fn (array $data) => $this->put($this->metaUrl($tenant, '/settings'), ['management_enabled' => true, 'lookback_days' => 45, ...$data]);

    $put(['ceilings' => [
        ['account_id' => $bdt->id, 'daily' => '2,500.50', 'lifetime' => ''],
        ['account_id' => $jpy->id, 'daily' => '30000', 'lifetime' => '900000'],
    ]])->assertSessionHasNoErrors();

    $this->inTenant($tenant, function () use ($bdt, $jpy): void {
        $one = AdAccount::query()->findOrFail($bdt->id);
        $two = AdAccount::query()->findOrFail($jpy->id);
        expect($one->daily_budget_ceiling)->toBe(250050)->and($one->lifetime_budget_ceiling)->toBeNull()
            ->and($two->daily_budget_ceiling)->toBe(30000)->and($two->lifetime_budget_ceiling)->toBe(900000)
            ->and(MetaAdsSetting::query()->sole()->management_enabled)->toBeTrue()
            ->and(MetaAdsSetting::query()->sole()->lookback_days)->toBe(45)
            ->and(AuditLog::query()->where('event', 'meta_ads.account.ceilings_changed')->count())->toBe(2);
    });

    // Nothing changed: no new audit entries.
    $put(['ceilings' => [['account_id' => $bdt->id, 'daily' => '2500.50', 'lifetime' => '']]])->assertSessionHasNoErrors();
    $this->inTenant($tenant, fn () => expect(AuditLog::query()->where('event', 'meta_ads.account.ceilings_changed')->count())->toBe(2));
});

it('rejects settings that are not plain amounts, out of range or for a stranger\'s account', function () {
    $tenant = $this->metaTenant('meta-settings-bad');
    $account = $this->metaAccount($tenant);
    $other = $this->metaTenant('meta-settings-other');
    $foreign = $this->metaAccount($other);
    $this->actingAsOwner($tenant);
    $put = fn (array $data) => $this->put($this->metaUrl($tenant, '/settings'), ['management_enabled' => false, 'lookback_days' => 30, ...$data]);

    foreach (['abc', '-5', '0', '1e5', '1.2.3'] as $bad) {
        $put(['ceilings' => [['account_id' => $account->id, 'daily' => $bad, 'lifetime' => '']]])->assertSessionHasErrors('ceilings.0.daily');
    }

    $put(['lookback_days' => 3])->assertSessionHasErrors('lookback_days');
    $put(['lookback_days' => 500])->assertSessionHasErrors('lookback_days');
    $put(['ceilings' => [['account_id' => $foreign->id, 'daily' => '10', 'lifetime' => '']]])->assertNotFound();

    $this->inTenant($tenant, fn () => expect(AdAccount::query()->findOrFail($account->id)->daily_budget_ceiling)->toBeNull());
});

it('renders the pages for the people allowed to see them', function () {
    $tenant = $this->metaTenant('meta-pages');
    $this->actingAsOwner($tenant);

    foreach ([
        '/' => 'Modules/MetaAds/Pages/Performance/Index',
        '/campaigns' => 'Modules/MetaAds/Pages/Campaigns/Index',
        '/accounts' => 'Modules/MetaAds/Pages/Accounts/Index',
        '/settings' => 'Modules/MetaAds/Pages/Settings/Index',
    ] as $path => $component) {
        $this->get($this->metaUrl($tenant, $path))->assertOk()->assertInertia(fn ($page) => $page->component($component));
    }
});
