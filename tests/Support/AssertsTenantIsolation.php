<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Exceptions\CrossTenantAccessException;
use App\Core\Tenancy\Exceptions\MissingTenantContextException;
use App\Core\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * The cross-tenant checks every tenant-owned model and every route that
 * addresses one by id must pass (MASTER_PROMPT section 29).
 *
 * A new module points these at its own models and routes:
 *
 *     $this->assertTenantIsolated(Lead::class, fn () => Lead::factory()->create());
 *     $this->assertNotFoundFromOtherTenant('GET', "/crm/leads/{$lead->id}", $tenant);
 *
 * @mixin TestCase
 */
trait AssertsTenantIsolation
{
    /**
     * Assert the full row-isolation contract of a tenant-owned model.
     *
     * Two workspaces are provisioned and `$makeInTenant` is called once in
     * each. The record made in the first must then be:
     *
     *  - stamped with that tenant;
     *  - invisible from the second workspace to `find`, `get`, `count`,
     *    `exists` and route-model binding, and untouched by its bulk writes;
     *  - unreadable with no tenant active (the query throws, it does not
     *    return every tenant's rows);
     *  - still reachable through the explicit escapes `withoutTenancy()` and
     *    `forTenant()`, which proves the above is isolation, not absence.
     *
     * For models using BelongsToTenant the write guards are checked too: a
     * record cannot be created in another tenant, created with no tenant, or
     * moved to another tenant.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(Tenant): TModel  $makeInTenant  Runs with the given tenant active; creates and returns one record.
     */
    protected function assertTenantIsolated(string $model, Closure $makeInTenant): void
    {
        $home = $this->createTenant();
        $other = $this->createTenant();

        $record = $this->inTenant($home, fn (): Model => $makeInTenant($home));
        $foreign = $this->inTenant($other, fn (): Model => $makeInTenant($other));

        Assert::assertInstanceOf($model, $record);
        Assert::assertInstanceOf($model, $foreign);
        Assert::assertTrue($record->exists && $foreign->exists, "The factory closure must persist the {$model} it returns.");

        Assert::assertSame($home->getTenantKey(), $record->getAttribute('tenant_id'), "{$model} was not stamped with the active tenant.");
        Assert::assertSame($other->getTenantKey(), $foreign->getAttribute('tenant_id'), "{$model} was not stamped with the active tenant.");

        // Reads from the other workspace never see the record.
        $this->inTenant($other, function () use ($model, $record, $foreign, $other): void {
            Assert::assertNull($model::query()->find($record->getKey()), "{$model}::find() returned another tenant's record.");
            Assert::assertFalse($model::query()->whereKey($record->getKey())->exists(), "{$model} query matched another tenant's record.");
            Assert::assertNull(
                (new $model)->resolveRouteBinding($record->getRouteKey()),
                "Route-model binding resolved another tenant's {$model}.",
            );

            $visible = $model::query()->get();

            Assert::assertTrue($visible->contains($foreign), "{$model} does not list the active tenant's own record.");
            Assert::assertFalse($visible->contains($record), "{$model} lists another tenant's record.");
            Assert::assertSame(
                [$other->getTenantKey()],
                $visible->pluck('tenant_id')->unique()->values()->all(),
                "{$model} returned rows of more than the active tenant.",
            );
            Assert::assertSame($visible->count(), $model::query()->count(), "{$model}::count() is not tenant-scoped.");

            // Bulk writes are scoped as well: neither can reach the record.
            Assert::assertSame(
                0,
                $model::query()->whereKey($record->getKey())->update(['tenant_id' => $other->getTenantKey()]),
                "A bulk update from another tenant reached a {$model}.",
            );
            Assert::assertSame(
                0,
                $model::query()->whereKey($record->getKey())->toBase()->delete(),
                "A bulk delete from another tenant reached a {$model}.",
            );
        });

        // With no tenant active every read fails closed.
        $this->inCentral(function () use ($model, $record): void {
            $reads = [
                'get' => static fn () => $model::query()->get(),
                'first' => static fn () => $model::query()->first(),
                'find' => static fn () => $model::query()->find($record->getKey()),
                'count' => static fn () => $model::query()->count(),
                'exists' => static fn () => $model::query()->whereKey($record->getKey())->exists(),
                'paginate' => static fn () => $model::query()->paginate(),
                'route binding' => static fn () => (new $model)->resolveRouteBinding($record->getRouteKey()),
            ];

            foreach ($reads as $name => $read) {
                try {
                    $read();
                } catch (MissingTenantContextException) {
                    continue;
                }

                Assert::fail("{$model} [{$name}] ran without a tenant context instead of throwing.");
            }

            // The explicit escapes are the only way to it, and it is intact.
            $stored = $model::withoutTenancy()->find($record->getKey());

            Assert::assertNotNull($stored, "{$model}::withoutTenancy() cannot find the record.");
            Assert::assertSame($record->getAttribute('tenant_id'), $stored->getAttribute('tenant_id'), "The {$model} changed tenant.");
        });

        Assert::assertSame(
            [$home->getTenantKey()],
            $this->inTenant($other, fn (): array => $model::forTenant($home)
                ->whereKey([$record->getKey(), $foreign->getKey()])
                ->pluck('tenant_id')
                ->all()),
            "{$model}::forTenant() did not return exactly the named tenant's rows.",
        );

        if (! in_array(BelongsToTenant::class, class_uses_recursive($model), true)) {
            return;
        }

        $this->inTenant($other, function () use ($model, $home, $other, $foreign): void {
            $planted = $foreign->replicate();
            $planted->setAttribute('tenant_id', $home->getTenantKey());

            $this->assertThrows(
                static fn () => $planted->save(),
                CrossTenantAccessException::class,
            );

            $foreign->setAttribute('tenant_id', $home->getTenantKey());

            $this->assertThrows(
                static fn () => $foreign->save(),
                CrossTenantAccessException::class,
            );

            $foreign->setAttribute('tenant_id', $other->getTenantKey());

            Assert::assertSame(
                $other->getTenantKey(),
                $model::query()->findOrFail($foreign->getKey())->getAttribute('tenant_id'),
                "A {$model} was moved to another tenant.",
            );
        });

        $this->inCentral(function () use ($foreign): void {
            $orphan = $foreign->replicate();
            $orphan->setAttribute('tenant_id', null);

            $this->assertThrows(
                static fn () => $orphan->save(),
                MissingTenantContextException::class,
            );
        });
    }

    /**
     * Assert that a route addressing a record by id answers 404 from any
     * workspace but the record's own.
     *
     * The request is sent to another workspace's host by that workspace's
     * Owner - who holds every permission, so only tenant isolation can turn
     * it away. The same request is then sent to the record's own workspace as
     * its Owner and must NOT be a 404: that control keeps a mistyped path or
     * id from passing as "isolated". With a destructive verb the control
     * really performs the action, so call this last in a test. Whoever was
     * signed in before the call is signed out by it.
     *
     * @param  string  $method  HTTP verb.
     * @param  string  $path  Workspace-relative path holding the record's id, e.g. "/team/members/{$membership->id}".
     * @param  Tenant  $home  The tenant the record belongs to.
     * @param  array<string, mixed>  $data  Request payload.
     * @param  Tenant|null  $intruder  The workspace to try from; a freshly provisioned one by default.
     */
    protected function assertNotFoundFromOtherTenant(
        string $method,
        string $path,
        Tenant $home,
        array $data = [],
        ?Tenant $intruder = null,
    ): void {
        $intruder ??= $this->createTenant();

        Assert::assertNotSame($home->getTenantKey(), $intruder->getTenantKey(), 'The intruding tenant must differ from the home tenant.');

        $this->startNewBrowserSession()
            ->actingAsOwner($intruder)
            ->call($method, $this->tenantUrl($intruder, $path), $data)
            ->assertNotFound();

        $control = $this->startNewBrowserSession()
            ->actingAsOwner($home)
            ->call($method, $this->tenantUrl($home, $path), $data);

        Assert::assertNotSame(
            404,
            $control->getStatusCode(),
            "[{$method} {$path}] is a 404 even in the record's own workspace, so the cross-tenant 404 proves nothing. Check the path and the id.",
        );
        Assert::assertLessThan(
            500,
            $control->getStatusCode(),
            "[{$method} {$path}] failed in the record's own workspace.",
        );

        $this->startNewBrowserSession();
    }
}
