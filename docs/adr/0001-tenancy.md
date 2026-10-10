# ADR 0001 — Tenancy layer

- **Status:** Accepted
- **Date:** 2026-10-08
- **Phase:** 1

## Context

The spec locks (section 0) one shared MySQL database with `tenant_id` on every tenant-owned table, and
subdomain resolution on `{tenant}.APP_DOMAIN`. For the package it says: prefer `stancl/tenancy` in
single-database mode **only if** a release officially supports Laravel 13; otherwise build a small custom layer.
Section 3.3 requires isolation in queries, jobs (fail closed), cache and file storage. Section 3.4 requires a path
to dedicated tenant databases later.

Checked on Packagist before installing: `stancl/tenancy` 3.10.1 requires `illuminate/support ^13` (MIT).

Two parts of the package do not meet the spec as shipped:

- its `BelongsToTenant` scope is **fail-open** — with no tenant active it adds no filter, so the query returns
  every tenant's rows;
- its cache isolation is **tag-based** — it needs a taggable store and is bypassed by `Cache::store()`.

## Decision

Use `stancl/tenancy` 3.10 for what it does well and own the pieces that have to fail closed.

| Concern | Provided by |
|---|---|
| `Tenant` / `Domain` models, tenancy manager, lifecycle events | Package, extended in `app/Core/Tenancy/Models` |
| Tenant id in queue payloads, restored before a job runs | Package `QueueTenancyBootstrapper` |
| Row isolation | Ours: `Concerns\BelongsToTenant` + `Scopes\TenantScope`; no tenant → `MissingTenantContextException` |
| Cache isolation | Ours: `Bootstrappers\CachePrefixBootstrapper` rewrites the key prefix per tenant; `cache.central` stays un-prefixed |
| File isolation | Ours: `Storage\TenantStorage`, an explicit API. The package's filesystem bootstrapper (it rewrites `storage_path()`) is **not** used |
| Tenant resolution | Ours: `Support\TenantHost` + `Http\Middleware\InitializeTenancy`, from the subdomain only |
| Jobs that must have a tenant | Ours: `Contracts\RequiresTenantContext` + `Jobs\EnforceTenantContextOnJobs` |
| "Which tenant is active" | Ours: `TenantContext`; application code does not call the package directly |

The package is also kept because it already has a multi-database mode, which is the natural path to dedicated
tenant databases. Today only the routing seam for that exists (`Support\TenantConnectionResolver`,
`tenants.db_connection`); see `docs/DATABASE.md`.

Custom domains are designed for (`tenant_domains.type` is `subdomain` or `custom`) but not routed: a host that is
not `{label}.APP_DOMAIN` never resolves to a tenant.

## Consequences

- A query on a tenant-owned model outside a tenant context throws instead of returning nothing or everything.
  Platform code must opt out explicitly (`Model::withoutTenancy()`, `Model::forTenant()`,
  `TenantContext::withoutScope()`), which keeps every cross-tenant read easy to find in review.
- We maintain the scope, the cache bootstrapper, the storage API and the job guard ourselves. On a package upgrade
  re-check the two contracts they rely on: the `TenancyBootstrapper` interface and the `tenant_id` payload key.
- Cache isolation covers every store in `config('cache.stores')`: each one is rebuilt with the tenant suffix on
  every context switch. That is a small cost per switch, and a store that ignores prefixes (`array`) simply
  starts empty each time. The framework's rate limiter is replaced by `Support\TenantAwareRateLimiter`, which is
  handed the new store instead of being rebuilt, so limiters named with `RateLimiter::for()` survive a switch.
- Application code switches tenant through `TenantContext::run()` / `central()`, which restore the previous
  context even when the callback throws; the package's `$tenant->run()` does not.
- `Storage::disk()` and `storage_path()` stay global. Tenant files are isolated only when code goes through
  `TenantStorage`.
- Models the permission package queries itself (`Role`) cannot carry the automatic scope; see ADR 0002.
- The `{tenant}` route parameter is removed by `InitializeTenancy`, so controllers and route-model binding never
  see it.
- Tenant ids are lower-case ULIDs (`Support\UlidGenerator`), matching Laravel's `HasUlids`.
