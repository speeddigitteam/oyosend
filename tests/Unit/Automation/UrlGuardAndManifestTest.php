<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Modules\ModuleRegistry;
use App\Modules\Automation\Engine\Outbound\HostResolver;
use App\Modules\Automation\Engine\Outbound\ResponseCap;
use App\Modules\Automation\Engine\Outbound\UrlGuard;
use GuzzleHttp\Psr7\Response;

function resolverReturning(array $addresses): HostResolver
{
    return new class($addresses) extends HostResolver
    {
        public function __construct(private readonly array $addresses) {}

        public function resolve(string $host): array
        {
            return $this->addresses;
        }
    };
}

it('accepts only https URLs to public host names', function (string $url, bool $ok) {
    $guard = new UrlGuard(resolverReturning(['93.184.216.34']));

    expect($guard->syntaxProblem($url) === null)->toBe($ok);
})->with([
    ['https://hooks.example.com/path?x=1', true],
    ['http://hooks.example.com/', false],
    ['ftp://hooks.example.com/', false],
    ['https://user:pw@hooks.example.com/', false],
    ['https://hooks.example.com:8443/', false],
    ['https://127.0.0.1/', false],
    ['https://[::1]/', false],
    ['https://localhost/', false],
    ['https://intranet/', false],
    ['https://service.internal/', false],
    ['not a url', false],
]);

it('refuses a name that resolves to loopback, private, link-local or reserved space', function (string $address) {
    $guard = new UrlGuard(resolverReturning([$address]));

    expect($guard->inspect('https://hooks.example.com/'))->toBeString();
})->with(['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '240.0.0.1', '::ffff:10.0.0.1', '::1', 'fe80::1', 'fc00::1']);

it('refuses a name when any one of its addresses is private, and when it does not resolve', function () {
    expect((new UrlGuard(resolverReturning(['93.184.216.34', '10.0.0.1'])))->inspect('https://hooks.example.com/'))->toBeString()
        ->and((new UrlGuard(resolverReturning([])))->inspect('https://hooks.example.com/'))->toBeString();
});

it('returns the pinned public addresses', function () {
    $result = (new UrlGuard(resolverReturning(['93.184.216.34'])))->inspect('https://Hooks.Example.com/in');

    expect($result)->toBe(['host' => 'hooks.example.com', 'addresses' => ['93.184.216.34']]);
});

it('declares the automation module, its permissions, limits, scheduled sweep and engine triggers', function () {
    $manifest = app(ModuleRegistry::class)->get('automation');

    expect($manifest->core)->toBeFalse()
        ->and($manifest->dependencies)->toBe(['core'])
        ->and($manifest->category->value)->toBe('workflow_automation')
        ->and(array_map(fn ($permission) => $permission->name, $manifest->permissions))->toBe([
            'automation.workflows.view', 'automation.workflows.manage', 'automation.workflows.publish',
            'automation.runs.view', 'automation.runs.start', 'automation.runs.manage',
            'automation.approvals.decide', 'automation.webhooks.manage',
        ])
        ->and(array_map(fn ($limit) => $limit->key, $manifest->limits))->toBe(['automation.runs', 'automation.workflows'])
        ->and($manifest->scheduledJobs[0]->key)->toBe('automation.sweep')
        ->and($manifest->scheduledJobs[0]->frequency->value)->toBe('every_minute')
        ->and($manifest->offers[0]->type->value)->toBe('free');

    $registered = app(AutomationRegistry::class)->registeredTriggers();

    expect($registered)->toHaveKeys(['automation.trigger.manual', 'automation.trigger.webhook', 'automation.trigger.schedule']);

    foreach (['automation.trigger.manual', 'automation.trigger.webhook', 'automation.trigger.schedule'] as $key) {
        expect($registered[$key]::event())->toBeNull();
    }
});

it('knows every module trigger event so workflows can follow it', function () {
    $registry = app(AutomationRegistry::class);

    foreach ($registry->registeredTriggers() as $key => $trigger) {
        $event = $trigger::event();

        if ($event !== null) {
            expect(class_exists($event))->toBeTrue("{$key} points at a missing event class");
        }
    }
});

it('judges IPv4 carried in IPv6, translation and tunnel prefixes as the IPv4 they carry', function (string $address) {
    $guard = new UrlGuard(resolverReturning([$address]));

    expect($guard->inspect('https://hooks.example.com/'))->toBeString();
})->with([
    '64:ff9b::a00:1',          // NAT64 of 10.0.0.1
    '64:ff9b::7f00:1',         // NAT64 of 127.0.0.1
    '64:ff9b::169.254.169.254',
    '64:ff9b:1::1',            // local-use NAT64
    '2002:a00:1::1',           // 6to4 of 10.0.0.1
    '2002:7f00:1::',           // 6to4 of 127.0.0.1
    '2001:0:4136:e378:8000:63bf:3fff:fdd2', // Teredo
    '::ffff:a00:1',            // mapped, hex form
    '::ffff:7f00:1',
    '::10.0.0.1',              // IPv4-compatible
    '::',
    '198.18.0.1',
]);

it('accepts a public IPv4 carried in IPv6 and ordinary public addresses', function (string $address) {
    $guard = new UrlGuard(resolverReturning([$address]));

    expect($guard->inspect('https://hooks.example.com/'))->toBeArray();
})->with(['64:ff9b::5db8:d822', '2002:5db8:d822::1', '::ffff:93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

it('aborts an outbound response past the cap and keeps its status', function () {
    $cap = new ResponseCap(1000);

    $cap->onProgress(0, 500);
    expect($cap->exceeded)->toBeFalse();

    expect(fn () => $cap->onProgress(0, 1001))->toThrow(RuntimeException::class);
    expect($cap->exceeded)->toBeTrue();

    $announced = new ResponseCap(1000);
    $response = new Response(200, ['Content-Length' => '5000']);

    expect(fn () => $announced->onHeaders($response))->toThrow(RuntimeException::class)
        ->and($announced->status)->toBe(200)
        ->and($announced->exceeded)->toBeTrue();
});

it('reads the outbound response cap from configuration and falls back to 64 KB', function () {
    config(['automation.limits.outbound_response_bytes' => 2000]);
    $cap = new ResponseCap;
    $cap->onProgress(0, 2000);
    expect($cap->exceeded)->toBeFalse()
        ->and(fn () => $cap->onProgress(0, 2001))->toThrow(RuntimeException::class);

    config(['automation.limits.outbound_response_bytes' => 0]);
    $fallback = new ResponseCap;
    $fallback->onProgress(0, ResponseCap::DEFAULT_MAX_BYTES);
    expect($fallback->exceeded)->toBeFalse()
        ->and(fn () => $fallback->onProgress(0, ResponseCap::DEFAULT_MAX_BYTES + 1))->toThrow(RuntimeException::class)
        ->and(config('automation.limits.outbound_response_bytes'))->toBe(0);
});
