<?php

declare(strict_types=1);

use App\Core\Social\Contracts\ConnectedPages;
use App\Core\Social\NullConnectedPages;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use App\Modules\MetaAds\Support\OAuthBroker;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\SocialAccount;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithMetaAds;

uses(InteractsWithMetaAds::class);

/** @param array<string, mixed> $overrides */
function metaSocialAccount(object $test, $tenant, array $overrides = []): SocialAccount
{
    return app(TenantContext::class)->run($tenant, fn (): SocialAccount => SocialAccount::query()->create([
        'platform' => 'facebook', 'external_id' => 'page-'.bin2hex(random_bytes(3)), 'name' => 'Shop Page', 'account_type' => 'page',
        'status' => AccountStatus::Connected, 'access_token' => 'PAGE-TOKEN-SECRET', ...$overrides,
    ]));
}

it('lists the workspace Facebook Pages through the Social contract, without any token', function () {
    $tenant = $this->metaTenant('meta-pages-c');
    metaSocialAccount($this, $tenant, ['name' => 'B Page', 'external_id' => 'p2']);
    metaSocialAccount($this, $tenant, ['name' => 'A Page', 'external_id' => 'p1', 'status' => AccountStatus::NeedsReauthorization]);
    metaSocialAccount($this, $tenant, ['name' => 'Gone', 'external_id' => 'p3', 'status' => AccountStatus::Disconnected]);
    metaSocialAccount($this, $tenant, ['name' => 'LinkedIn', 'platform' => 'linkedin', 'external_id' => 'li1']);

    $pages = $this->inTenant($tenant, fn () => app(ConnectedPages::class)->pages());

    expect(array_map(fn ($page) => [$page->name, $page->externalPageId, $page->status], $pages))->toBe([
        ['A Page', 'p1', 'needs_reauthorization'],
        ['B Page', 'p2', 'connected'],
    ])->and(json_encode($pages))->not->toContain('PAGE-TOKEN-SECRET');
    $this->inTenant($tenant, fn () => expect(app(ConnectedPages::class)->isAvailable())->toBeTrue());
});

it('shows only the active workspace Pages and nothing when Social is not available', function () {
    $one = $this->metaTenant('meta-pc-1');
    $two = $this->metaTenant('meta-pc-2');
    metaSocialAccount($this, $one, ['name' => 'Mine']);
    metaSocialAccount($this, $two, ['name' => 'Theirs']);

    expect($this->inTenant($one, fn () => collect(app(ConnectedPages::class)->pages())->pluck('name')->all()))->toBe(['Mine']);

    $bare = $this->createTenant('meta-pc-bare');
    $this->entitle($bare, 'meta_ads');
    $this->inTenant($bare, function (): void {
        expect(app(ConnectedPages::class)->isAvailable())->toBeFalse()->and(app(ConnectedPages::class)->pages())->toBe([]);
    });
});

it('has an honest null object for installations without Social', function () {
    $null = new NullConnectedPages;

    expect($null->isAvailable())->toBeFalse()->and($null->pages())->toBe([]);
});

it('feeds the setup checklist and the connection page without importing a Social class', function () {
    $tenant = $this->metaTenant('meta-pc-ui');
    $this->actingAsOwner($tenant);

    $this->get($this->metaUrl($tenant, '/accounts'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('socialPages.available', true)->where('socialPages.pages', [])
        ->where('readiness.4', ['key' => 'social_page', 'state' => 'optional']));

    metaSocialAccount($this, $tenant, ['name' => 'Shop Page']);
    $this->get($this->metaUrl($tenant, '/accounts'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('socialPages.pages.0.name', 'Shop Page')
        ->where('readiness.4', ['key' => 'social_page', 'state' => 'done']));

    // The module never reaches into Social's code or tables.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Modules/MetaAds'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            expect(file_get_contents($file->getPathname()))->not->toContain('App\\Modules\\Social')->not->toContain('social_accounts');
        }
    }
});

it('wraps and unwraps the redirect state with a signed, expiring, workspace-bound envelope', function () {
    $broker = app(OAuthBroker::class);
    $state = $broker->wrap('acme', 'inner-state-value', 1_000_000);

    expect($broker->unwrap($state, 1_000_001))->toBe(['slug' => 'acme', 'inner' => 'inner-state-value'])
        ->and($broker->unwrap($state, 1_000_000 + OAuthBroker::TTL_SECONDS + 1))->toBeNull()
        ->and($broker->unwrap($state, 999_000))->toBeNull();

    $parts = explode('.', $state, 5);
    $forgeries = [
        implode('.', ['evil', $parts[1], $parts[2], $parts[3], $parts[4]]),
        implode('.', [$parts[0], $parts[1], $parts[2], $parts[3], 'other-inner']),
        implode('.', [$parts[0], $parts[1], $parts[2], strrev($parts[3]), $parts[4]]),
        implode('.', [$parts[0], $parts[1], 'aaaaaaaaaaaa', $parts[3], $parts[4]]),
        'plain', '', str_repeat('a.', 400),
    ];

    foreach ($forgeries as $bad) {
        expect($broker->unwrap($bad, 1_000_001))->toBeNull();
    }

});

it('forwards a verified return to the workspace and answers 404 to anything else', function () {
    $tenant = $this->metaTenant('meta-brk');
    $broker = app(OAuthBroker::class);
    $good = $broker->wrap('meta-brk', 'inner');
    $callback = fn (string $query) => $this->get($this->centralUrl('/meta-ads/oauth/callback?'.$query));

    $hop = $callback('code=abc&state='.urlencode($good).'&unrelated=ignored');
    $hop->assertRedirect();
    $location = (string) $hop->headers->get('Location');

    expect($location)->toStartWith($this->metaUrl($tenant, '/accounts/callback?'))
        ->and($location)->toContain('code=abc')->not->toContain('unrelated')
        ->and($hop->headers->get('Set-Cookie'))->toBeNull()
        ->and((string) $hop->headers->get('Referrer-Policy'))->toBe('no-referrer');

    $callback('error=access_denied&state='.urlencode($good))->assertRedirect();
    $callback('code=abc')->assertNotFound();
    $callback('code=abc&state='.urlencode($broker->wrap('nobody-here', 'inner')))->assertNotFound();
    $callback('code=abc&state=forged')->assertNotFound();

    // A suspended workspace is not reachable through the broker.
    $this->inTenant($tenant, fn () => null);
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    $callback('code=abc&state='.urlencode($good))->assertNotFound();

    config(['meta_ads.redirect_broker' => false]);
    $callback('code=abc&state='.urlencode($good))->assertNotFound();
});
