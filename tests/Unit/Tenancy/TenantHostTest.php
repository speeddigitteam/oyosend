<?php

declare(strict_types=1);

use App\Core\Tenancy\Support\TenantHost;

beforeEach(function () {
    $this->hosts = app(TenantHost::class);
});

it('knows the central and admin hosts', function () {
    expect($this->hosts->domain())->toBe('localhost')
        ->and($this->hosts->adminHost())->toBe('admin.localhost');

    config(['platform.domain' => 'oyosend.com', 'platform.admin_subdomain' => 'control']);

    expect($this->hosts->domain())->toBe('oyosend.com')
        ->and($this->hosts->adminHost())->toBe('control.oyosend.com');
});

it('reads the tenant key from a tenant subdomain', function (string $host, string $key) {
    expect($this->hosts->tenantKey($host))->toBe($key);
})->with([
    'plain label' => ['acme.localhost', 'acme'],
    'upper case host' => ['ACME.LocalHost', 'acme'],
    'digits and hyphen' => ['team-42.localhost', 'team-42'],
    'single character' => ['a.localhost', 'a'],
    'longest DNS label' => [str_repeat('a', 63).'.localhost', str_repeat('a', 63)],
    'starts like a reserved label' => ['administrator.localhost', 'administrator'],
]);

it('refuses hosts that are not a tenant subdomain', function (string $host) {
    expect($this->hosts->tenantKey($host))->toBeNull();
})->with([
    'the central domain' => ['localhost'],
    'the admin host' => ['admin.localhost'],
    'a reserved label' => ['www.localhost'],
    'a reserved label in upper case' => ['API.localhost'],
    'a nested subdomain' => ['a.acme.localhost'],
    'another domain' => ['acme.example.com'],
    'our domain as a prefix of another' => ['acme.localhost.evil.com'],
    'our domain without the dot' => ['acmelocalhost'],
    'a suffix look-alike' => ['acme.notlocalhost'],
    'leading hyphen' => ['-acme.localhost'],
    'trailing hyphen' => ['acme-.localhost'],
    'underscore' => ['ac_me.localhost'],
    'label over 63 characters' => [str_repeat('a', 64).'.localhost'],
    'empty label' => ['.localhost'],
    'label ending in a line break' => ["acme\n.localhost"],
    'an IP address' => ['127.0.0.1'],
    'empty host' => [''],
]);

it('treats the configured admin subdomain as reserved', function () {
    expect($this->hosts->isReserved('admin'))->toBeTrue()
        ->and($this->hosts->isReserved('WWW'))->toBeTrue()
        ->and($this->hosts->isReserved('acme'))->toBeFalse()
        ->and($this->hosts->isReserved('control'))->toBeFalse();

    config(['platform.admin_subdomain' => 'control']);

    expect($this->hosts->isReserved('control'))->toBeTrue()
        ->and($this->hosts->tenantKey('control.localhost'))->toBeNull();
});

it('builds a route pattern that can never match a reserved host', function () {
    $pattern = '/^(?:'.$this->hosts->routePattern().')\.localhost$/';

    expect(preg_match($pattern, 'acme.localhost'))->toBe(1)
        ->and(preg_match($pattern, 'administrator.localhost'))->toBe(1)
        ->and(preg_match($pattern, 'admin.localhost'))->toBe(0)
        ->and(preg_match($pattern, 'www.localhost'))->toBe(0)
        ->and(preg_match($pattern, 'horizon.localhost'))->toBe(0)
        ->and(preg_match($pattern, 'a.b.localhost'))->toBe(0);
});

it('gives tests URL helpers for all three areas', function () {
    expect($this->tenantUrl('acme', 'login'))->toBe('http://acme.localhost/login')
        ->and($this->tenantUrl('acme'))->toBe('http://acme.localhost/')
        ->and($this->centralUrl('workspace'))->toBe('http://localhost/workspace')
        ->and($this->centralUrl())->toBe('http://localhost/')
        ->and($this->adminUrl('/tenants'))->toBe('http://admin.localhost/tenants');
});

it('builds workspace URLs from the scheme and port of APP_URL', function () {
    expect($this->hosts->url('acme'))->toBe('http://acme.localhost/')
        ->and($this->hosts->url('acme', 'login'))->toBe('http://acme.localhost/login')
        ->and($this->hosts->url('acme', '/team/members'))->toBe('http://acme.localhost/team/members');

    config(['app.url' => 'https://oyosend.com:8443', 'platform.domain' => 'oyosend.com']);

    expect($this->hosts->url('acme', 'login'))->toBe('https://acme.oyosend.com:8443/login');
});
