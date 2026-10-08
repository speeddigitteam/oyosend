<?php

declare(strict_types=1);

use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures\RecordsTenantJob;
use Tests\Support\Fixtures\TenantBoundJob;

/*
 * Note the block closures around every dispatch. `dispatch()` returns a
 * PendingDispatch that only reaches the queue when it is destroyed; an arrow
 * function would return it out of `inTenant()` and the job would be queued
 * after the tenant context had already ended.
 */
beforeEach(function () {
    RecordsTenantJob::$runs = [];

    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->context = app(TenantContext::class);
});

describe('on the sync connection', function () {
    it('runs a job dispatched inside a tenant in that tenant', function () {
        $this->inTenant($this->acme, function (): void {
            RecordsTenantJob::dispatch();
        });
        $this->inTenant($this->globex, function (): void {
            TenantBoundJob::dispatch();
        });

        expect(RecordsTenantJob::tenants())->toBe([$this->acme->id, $this->globex->id])
            ->and($this->context->check())->toBeFalse();
    });

    it('runs a job dispatched centrally with no tenant', function () {
        RecordsTenantJob::dispatch();

        expect(RecordsTenantJob::tenants())->toBe([null]);
    });

    it('fails a tenant-bound job dispatched with no tenant, before it is handled', function () {
        expect(function (): void {
            TenantBoundJob::dispatch();
        })
            ->toThrow(MissingTenantContextException::class, TenantBoundJob::class);

        expect(RecordsTenantJob::$runs)->toBe([]);
    });
});

/*
 * A real queue: the job is serialized inside one context and handled later by
 * a worker that starts out in another one. This is where the tenant has to be
 * carried in the payload and re-established.
 */
describe('on a queue worker', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);

        $this->work = function (int $jobs = 1): void {
            foreach (range(1, $jobs) as $ignored) {
                Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0]);
            }
        };

        $this->payloads = fn (): array => DB::table('jobs')
            ->orderBy('id')
            ->pluck('payload')
            ->map(fn (string $payload): array => json_decode($payload, true))
            ->all();
    });

    it('carries the tenant in the payload', function () {
        $this->inTenant($this->acme, function (): void {
            RecordsTenantJob::dispatch();
        });
        RecordsTenantJob::dispatch();

        [$tenantJob, $centralJob] = ($this->payloads)();

        expect($tenantJob['tenant_id'])->toBe($this->acme->id)
            ->and($centralJob)->not->toHaveKey('tenant_id');
    });

    it('re-establishes the tenant for a worker that starts in the central context', function () {
        $this->inTenant($this->acme, function (): void {
            TenantBoundJob::dispatch();
        });

        expect($this->context->check())->toBeFalse();

        ($this->work)();

        expect(RecordsTenantJob::tenants())->toBe([$this->acme->id])
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            // ... and the worker is not left inside the tenant afterwards.
            ->and($this->context->check())->toBeFalse();
    });

    it('gives each job its own context, whatever the previous job left behind', function () {
        $this->inTenant($this->acme, function (): void {
            RecordsTenantJob::dispatch('first');
        });
        RecordsTenantJob::dispatch('second');
        $this->inTenant($this->globex, function (): void {
            TenantBoundJob::dispatch('third');
        });
        $this->inTenant($this->acme, function (): void {
            RecordsTenantJob::dispatch('fourth');
        });

        ($this->work)(4);

        expect(RecordsTenantJob::tenants())->toBe([$this->acme->id, null, $this->globex->id, $this->acme->id]);
    });

    it('runs a job in the tenant it was dispatched from even while another tenant is active', function () {
        $this->inTenant($this->acme, function (): void {
            RecordsTenantJob::dispatch();
        });

        $this->inTenant($this->globex, function (): void {
            ($this->work)();

            expect($this->context->id())->toBe($this->globex->id);
        });

        expect(RecordsTenantJob::tenants())->toBe([$this->acme->id]);
    });

    it('fails a tenant-bound job that was queued without a tenant', function () {
        TenantBoundJob::dispatch();

        ($this->work)();

        expect(RecordsTenantJob::$runs)->toBe([])
            ->and(DB::table('jobs')->count())->toBe(0);

        $failed = DB::table('failed_jobs')->sole();

        expect($failed->exception)->toContain(MissingTenantContextException::class);
    });

    it('fails a tenant-bound job whose tenant id was stripped from the payload', function () {
        $this->inTenant($this->acme, function (): void {
            TenantBoundJob::dispatch();
        });

        $job = DB::table('jobs')->sole();
        $payload = json_decode($job->payload, true);
        unset($payload['tenant_id']);
        DB::table('jobs')->where('id', $job->id)->update(['payload' => json_encode($payload)]);

        // Even with a tenant still active in the worker from earlier work.
        $this->inTenant($this->acme, fn () => ($this->work)());

        expect(RecordsTenantJob::$runs)->toBe([])
            ->and(DB::table('failed_jobs')->sole()->exception)->toContain(MissingTenantContextException::class);
    });

    it('fails a job whose tenant no longer exists', function () {
        $this->inTenant($this->acme, function (): void {
            RecordsTenantJob::dispatch();
        });

        $this->acme->delete();

        ($this->work)();

        expect(RecordsTenantJob::$runs)->toBe([])
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and($this->context->check())->toBeFalse();
    });
});
