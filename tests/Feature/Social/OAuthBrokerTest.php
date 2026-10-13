<?php

declare(strict_types=1);

use App\Core\Tenancy\Enums\TenantStatus;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Support\OAuthBroker;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class);

beforeEach(function () {
    $this->tenant = $this->socialTenant('soc-broker');
    $this->fakePlatform();
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, $path);
    $this->broker = fn (string $query) => $this->get($this->centralUrl('/social/oauth/callback/fake?'.$query));
    // Start the flow; returns [platform-visible state, authorize URL query].
    $this->begin = function (): array {
        $response = $this->post(($this->url)('/accounts/connect/fake'));
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [(string) $query['state'], $query];
    };
});

it('starts the flow with the central redirect URI and an enveloped state', function () {
    $this->actingAsOwner($this->tenant);
    [$state, $query] = ($this->begin)();

    expect($query['redirect_uri'])->toBe($this->centralUrl('/social/oauth/callback/fake'))
        ->and($state)->toStartWith('soc-broker.')
        ->and(strlen($state))->toBeLessThan(200)
        ->and(app(OAuthBroker::class)->unwrap($state, 'fake'))->toMatchArray(['slug' => 'soc-broker']);
});

it('completes the full flow through the central broker', function () {
    $this->actingAsOwner($this->tenant);
    [$state] = ($this->begin)();

    $hop = ($this->broker)('code=ok&state='.urlencode($state));
    $hop->assertRedirect();
    $location = (string) $hop->headers->get('Location');

    expect($location)->toStartWith(($this->url)('/accounts/callback/fake?'))
        ->and($location)->toContain('code=ok')
        ->and($hop->headers->get('Set-Cookie'))->toBeNull()
        ->and($hop->headers->has('Cache-Control'))->toBeTrue();

    $this->get($location)->assertRedirect(($this->url)('/accounts'));
    $account = $this->inTenant($this->tenant, fn () => SocialAccount::query()->sole());
    expect($account->name)->toBe('Fake Page');
});

it('forwards a platform error unchanged', function () {
    $this->actingAsOwner($this->tenant);
    [$state] = ($this->begin)();

    $location = (string) ($this->broker)('error=access_denied&state='.urlencode($state))->headers->get('Location');

    expect($location)->toContain('error=access_denied');
});

it('answers 404 for a tampered, wrong-platform, expired or missing envelope', function () {
    $this->actingAsOwner($this->tenant);
    [$state] = ($this->begin)();
    $parts = explode('.', $state, 5);

    // Tampered: inner value, slug, MAC.
    $swapInner = implode('.', [...array_slice($parts, 0, 4), 'attacker-controlled-state']);
    $swapSlug = implode('.', ['other', ...array_slice($parts, 1)]);
    $badMac = implode('.', [$parts[0], $parts[1], $parts[2], strrev($parts[3]), $parts[4]]);

    foreach ([$swapInner, $swapSlug, $badMac, 'plain-random-state', ''] as $bad) {
        ($this->broker)('code=ok&state='.urlencode($bad))->assertNotFound();
    }

    ($this->broker)('code=ok')->assertNotFound();

    // Another platform's callback with a state minted for "fake".
    $this->get($this->centralUrl('/social/oauth/callback/linkedin?code=ok&state='.urlencode($state)))->assertNotFound();

    // Expired.
    $this->travel(11)->minutes();
    ($this->broker)('code=ok&state='.urlencode($state))->assertNotFound();
});

it('answers 404 for an unknown or suspended workspace', function () {
    $broker = app(OAuthBroker::class);

    ($this->broker)('code=ok&state='.urlencode($broker->wrap('no-such-ws', 'fake', 'inner-state')))->assertNotFound();

    $ghost = ($this->broker)('code=ok&state='.urlencode($broker->wrap('soc-broker', 'fake', 'inner-state')));
    $ghost->assertRedirect();

    $this->tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    ($this->broker)('code=ok&state='.urlencode($broker->wrap('soc-broker', 'fake', 'inner-state')))->assertNotFound();
});

it('never builds the redirect target from request input', function () {
    $broker = app(OAuthBroker::class);

    // Signed envelopes for crafted slugs still never leave the platform domain.
    foreach (['evil.com', 'a.b', 'soc-broker@evil.com', '-x', 'admin', 'www', 'soc-broker/../x', 'xn--abc'] as $slug) {
        expect($broker->target($slug, 'fake'))->toBeNull();
    }

    $good = (string) $broker->target('soc-broker', 'fake');
    expect(parse_url($good, PHP_URL_HOST))->toBe('soc-broker.'.config('platform.domain'));

    // A crafted Host header on the central route changes nothing about the target.
    $this->actingAsOwner($this->tenant);
    [$state] = ($this->begin)();
    $response = $this->withHeaders(['X-Forwarded-Host' => 'evil.com'])->get($this->centralUrl('/social/oauth/callback/fake?code=ok&state='.urlencode($state)));
    expect(parse_url((string) $response->headers->get('Location'), PHP_URL_HOST))->toBe('soc-broker.'.config('platform.domain'));

    // Extra query keys are dropped, not forwarded.
    $location = (string) ($this->broker)('code=ok&next=https://evil.com&state='.urlencode($state))->headers->get('Location');
    expect($location)->not->toContain('evil.com')->not->toContain('next=');
});

it('refuses a replayed state on the workspace side and a state minted for another workspace', function () {
    $this->actingAsOwner($this->tenant);
    [$state] = ($this->begin)();
    $location = (string) ($this->broker)('code=ok&state='.urlencode($state))->headers->get('Location');

    $this->get($location)->assertRedirect(($this->url)('/accounts'));
    $this->get($location)->assertRedirect(($this->url)('/accounts'));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(1));

    // A valid envelope for another workspace does not match this session.
    $other = $this->socialTenant('soc-broker-2');
    $foreign = app(OAuthBroker::class)->wrap($other->slug, 'fake', 'inner');
    ($this->begin)();
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.urlencode($foreign)))->assertRedirect(($this->url)('/accounts'));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(1));
});

it('falls back to per-workspace URIs when the broker is disabled', function () {
    config(['social.redirect_broker' => false]);
    $this->actingAsOwner($this->tenant);
    [$state, $query] = ($this->begin)();

    expect($query['redirect_uri'])->toBe(($this->url)('/accounts/callback/fake'))
        ->and($state)->not->toContain('soc-broker.');

    ($this->broker)('code=ok&state='.urlencode($state))->assertNotFound();
    $this->get(($this->url)('/accounts/callback/fake?code=ok&state='.urlencode($state)))->assertRedirect(($this->url)('/accounts'));
    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->count())->toBe(1));
});

it('shows the single central redirect URI on the accounts page', function () {
    $this->actingAsOwner($this->tenant);

    $props = $this->get(($this->url)('/accounts'))->viewData('page')['props'];
    $uris = collect($props['redirectUris'])->pluck('uri')->all();

    expect($uris)->not->toBeEmpty();

    foreach ($props['redirectUris'] as $row) {
        expect($row['uri'])->toBe($this->centralUrl('/social/oauth/callback/'.$row['platform']));
    }
});
