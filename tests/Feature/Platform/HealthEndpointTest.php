<?php

declare(strict_types=1);

use App\Platform\Support\SystemHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

describe('GET /health', function () {
    it('answers ok on the central, admin and workspace hosts without a session', function () {
        $this->createTenant('acme');

        foreach (['http://localhost', 'http://admin.localhost', 'http://acme.localhost'] as $host) {
            $this->getJson("{$host}/health")
                ->assertOk()
                ->assertExactJson(['status' => 'ok'])
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    });
});

describe('GET /health/ready', function () {
    beforeEach(function () {
        // The shipped default is empty (token required); the cases below opt in to loopback.
        config(['platform.health.allowed_ips' => ['127.0.0.1', '::1']]);
    });

    it('ships with no allow-listed address, so the bearer token is required', function () {
        expect(file_get_contents(config_path('platform.php')))->toContain("env('HEALTH_ALLOWED_IPS', '')");
    });

    it('reports every check to an allow-listed address and leaks no workspace data', function () {
        $this->createTenant('secret-co', ['name' => 'Secret Company Ltd']);

        $response = $this->getJson('http://localhost/health/ready')->assertOk();

        expect(collect($response->json('checks'))->pluck('key')->all())->toBe([
            'database', 'cache', 'queue', 'redis', 'storage', 'migrations', 'scheduler', 'queue_backlog', 'failed_jobs',
        ]);
        expect($response->getContent())->not->toContain('secret-co')->not->toContain('Secret Company');
    });

    it('is a 404 for an address that is not allow-listed and for a wrong token', function () {
        config(['platform.health.allowed_ips' => ['203.0.113.0/24'], 'platform.health.token' => 'a-long-random-token']);

        $this->getJson('http://localhost/health/ready')->assertNotFound();
        $this->getJson('http://localhost/health/ready', ['Authorization' => 'Bearer nope'])->assertNotFound();
    });

    it('lets a bearer token in, and a CIDR block match', function () {
        config(['platform.health.allowed_ips' => [], 'platform.health.token' => 'a-long-random-token']);
        $this->getJson('http://localhost/health/ready', ['Authorization' => 'Bearer a-long-random-token'])->assertOk();

        config(['platform.health.allowed_ips' => ['127.0.0.0/8'], 'platform.health.token' => null]);
        $this->getJson('http://localhost/health/ready')->assertOk();
    });

    it('is closed when nothing is configured and the token is empty', function () {
        config(['platform.health.allowed_ips' => [], 'platform.health.token' => '']);

        $this->getJson('http://localhost/health/ready', ['Authorization' => 'Bearer '])->assertNotFound();
    });

    it('is degraded until the scheduler has beaten, and strict mode turns that into a 503', function () {
        $this->getJson('http://localhost/health/ready')->assertOk()->assertJsonPath('status', 'degraded');
        $this->getJson('http://localhost/health/ready?strict=1')->assertStatus(503);

        app(SystemHealth::class)->beat();

        $this->getJson('http://localhost/health/ready?strict=1')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    });

    it('fails the scheduler check when the heartbeat is older than the limit', function () {
        app('cache.central')->forever(SystemHealth::SCHEDULER_KEY, time() - 600);

        $check = collect(app(SystemHealth::class)->readiness())->firstWhere('key', 'scheduler');

        expect($check['ok'])->toBeFalse()->and($check['count'])->toBeGreaterThanOrEqual(600);
    });

    it('treats failed jobs as advisory, not as unavailable', function () {
        app(SystemHealth::class)->beat();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'x',
            'failed_at' => now(),
        ]);

        $this->getJson('http://localhost/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'degraded');
    });

    it('answers 503 when a critical dependency is down', function () {
        // A directory cannot be created below a regular file, on any operating system.
        config(['filesystems.disks.local.root' => __FILE__.'/unwritable']);
        Storage::forgetDisk('local');

        $response = $this->getJson('http://localhost/health/ready')->assertStatus(503);

        expect($response->json('status'))->toBe('unavailable');
        $storage = collect($response->json('checks'))->firstWhere('key', 'storage');
        expect($storage['ok'])->toBeFalse()->and($storage['detail'])->toBe('check failed');
        expect($response->getContent())->not->toContain('unwritable');
    });

    it('counts a migration that has not run', function () {
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $check = collect(app(SystemHealth::class)->readiness())->firstWhere('key', 'migrations');

        expect($check['ok'])->toBeFalse()->and($check['count'])->toBe(1);
    });
});
