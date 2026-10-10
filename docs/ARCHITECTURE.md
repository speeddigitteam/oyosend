# Architecture

State: end of Phase 1 (Architecture & Foundation). This page describes what is in the code. Anything not built
is listed in [section 12](#12-not-built-yet). Decisions and their reasons are in `docs/adr/`.

## 1. Shape

- A modular monolith: one Laravel 13 application, one shared MySQL database, `tenant_id` on tenant-owned tables.
- Server-side routing with Inertia 3 + Vue 3 pages. There is no JSON API, no WebSocket server and no SSR yet.
- Three areas, each on its own kind of host, each with its own middleware group, guard and Inertia layout.

## 2. Areas and hosts

| | Central site | Workspace (tenant) | Super Admin (platform) |
|---|---|---|---|
| Host | `APP_DOMAIN` | `{tenant}.APP_DOMAIN` | `{ADMIN_SUBDOMAIN}.APP_DOMAIN` (default `admin.`) |
| Route file | `routes/central.php` | `routes/tenant.php` (core) and each module's own file | `routes/platform.php` |
| Registered in | `bootstrap/app.php` (`then:`) | `ModuleServiceProvider::loadTenantRoutes()` | `bootstrap/app.php` (`then:`) |
| Middleware groups | `web`, `central` | `web`, `tenant` (+ `entitled:{module}` for non-core modules) | `web`, `platform` |
| Route name prefix | `central.` | none; routes name themselves (`core.*`, `auth.*`, later `{module}.*`) | `platform.` |
| Guard → provider | none (no sign-in) | `web` → `users` | `platform` → `platform_users` |
| Tenant context | none (ended if one leaked) | resolved from the subdomain | none; default guard switched to `platform` |
| Inertia middleware | `HandleCentralInertia` | `HandleTenantInertia` | `HandlePlatformInertia` |
| Pages (under `resources/js`) | `Core/Pages/Central/*` | `Core/Pages/*`, later `Modules/<M>/Pages/*` | `Platform/Pages/*` |

Host rules live in `app/Core/Tenancy/Support/TenantHost.php`:

- A tenant host is exactly one label in front of `APP_DOMAIN`, matching `[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?`,
  and not reserved. Reserved labels are `platform.reserved_subdomains` plus the admin subdomain.
- The `{tenant}` route pattern carries a negative look-ahead for the reserved labels, so `admin.` and `www.` can
  never match a workspace route. An unknown label is a 404.
- `TenantHost::url($tenant, $path)` builds absolute workspace URLs from `APP_URL`, so scheme and port are right.

Routes with no domain constraint answer on every host: `/up` (health), `/storage/{path}` (Laravel's signed
URLs for the private `local` disk). Horizon is mounted on the admin host at `/horizon`. `routes/web.php` holds
only a comment and is **not loaded**.

## 3. Request lifecycle

Order below is the effective one after Laravel's middleware-priority sort, not the order of declaration.
`bootstrap/app.php` puts the three context middleware ahead of authentication in the priority list, so the
context is settled before auth and before route-model binding. `tests/Feature/Tenancy/RoutePipelineTest.php`
asserts it with `Router::gatherRouteMiddleware()` for every workspace route, the admin panel (Horizon included)
and the central site.

**Workspace, signed-in route** (`['web', 'tenant']` + `['auth:web', 'auth.session', 'member']` + `can:`):

| # | Middleware | What it does |
|---|---|---|
| 1 | `web` group | Cookies, session, CSRF (`PreventRequestForgery`) |
| 2 | `InitializeTenancy` | Host → label → tenant, 404 if none. Starts the tenant context (runs the bootstrappers) and removes the `{tenant}` route parameter |
| 3 | `auth:web` | Guests go to `auth.login` |
| 4 | `throttle:*` | Where declared. Counts are per tenant (`TenantAwareRateLimiter`) |
| 5 | `auth.session` | Ends sessions whose password hash is stale |
| 6 | `SubstituteBindings` | Route-model binding, already tenant-scoped |
| 7 | `SetLocale` | User's locale → `locale` cookie → tenant default |
| 8 | `HandleTenantInertia` | Shared props: `app`, `tenant`, `auth`, `navigation`, `workspaces` |
| 9 | `EnsureTenantIsActive` | Pending or suspended workspace → 403 page |
| 10 | `entitled:{module}` | Non-core modules only → 404 if the tenant is not entitled |
| 11 | `member` (`EnsureTenantMember`) | Session must be bound to this tenant and the user an active member; else sign out and redirect. Binds a session restored by "remember me" if it was issued here. Sets the `membership` request attribute |
| 12 | `can:{permission}` | `Gate::before` → `AccessChecker` |

Guest workspace routes (`guest:web`) run 1, 2, 4, 6–9 and then the guest check.

**Super Admin:** `web` → `InitializePlatformContext` (ends any tenant context, `Auth::shouldUse('platform')`) →
`auth:platform` → `SubstituteBindings` → `HandlePlatformInertia` → `platform.2fa` (`EnsurePlatformTwoFactor`,
redirects to enrolment) → `can:platform.*`. There is no `SetLocale` here: the panel renders in the app locale.

**Central:** `web` → `EnsureCentralContext` → `SubstituteBindings` → `SetLocale` → `HandleCentralInertia`.

**Errors:** `App\Core\Support\ErrorPageResponder` renders `Core/Pages/Errors/Error` for 403, 404, 429, 500 and
503 (5xx keep the framework page while `APP_DEBUG` is on) and turns 419 into a redirect back with a toast.
Guests are redirected by host: the admin host to `platform.login`, everything else to `auth.login`.

## 4. Code layout

```
app/
  Core/
    Tenancy/        context, host rules, scope, bootstrappers, storage, provisioning, job guard, rate limiter
    Identity/       User, TenantMembership, Invitation, sign-in, 2FA, team + settings controllers
    Access/         Role, Permission, AccessChecker, PermissionResolver, RoleManager, permissions:sync
    Modules/        ModuleManifest, ModuleRegistry, ModuleServiceProvider, navigation + widget definitions
    Entitlements/   EntitlementChecker contract + the Phase 1 implementation
    Automation/     trigger/action contracts, AutomationRegistry, the one core trigger
    Events/         DomainEvent, TenantDomainEvent
    Audit/          AuditLog, AuditLogger, Auditable
    Dashboard/      DashboardBuilder and the core widgets
    Http/, Support/ Inertia middleware, locale, Toast, error pages, Like (LIKE escaping)
    CoreServiceProvider.php   the `core` module's manifest and bindings
  Platform/         Super Admin panel: PlatformUser, PlatformRole, controllers, middleware, SystemHealth,
                    Console/CreatePlatformAdminCommand
  Providers/        AppServiceProvider (framework guards), HorizonServiceProvider
routes/             central.php, tenant.php, platform.php, console.php
config/             platform.php, tenancy.php, modules.php, permission.php, horizon.php, ziggy.php (+ framework)
database/           migrations/, factories/, seeders/
lang/               en/, bn/, bn.json (server-side strings: validation, mail, toasts)
resources/js/       Core/ (components, layouts, pages, composables, i18n, types), Platform/ (pages, components);
                    app.ts also resolves Modules/<Module>/Pages/**. Guide: resources/js/README.md
tests/              Feature/, Unit/, Support/ (helpers, fixtures), js/ (Vitest)
.github/workflows/  ci.yml
```

`app/Modules/` does not exist yet; the first optional module creates it (`docs/MODULES.md`).

## 5. Module boundaries and the module contract

- A module is one service provider extending `App\Core\Modules\ModuleServiceProvider`, listed in
  `config/modules.php`. `describe()` returns a `ModuleManifest`; `bootModule()` loads routes and migrations.
- The foundation is itself a module: key `core`, `core: true`, declared in `App\Core\CoreServiceProvider`.
- Being registered makes a module's code available. Whether a tenant may use it is a separate, server-side
  entitlement check (`EntitlementChecker`).
- A module owns its routes, controllers, models, migrations, policies, events, jobs and tests. Modules talk to
  each other through domain events and documented service contracts, never by importing another module's models.
- Shared records and services belong in `app/Core`. `app/Platform` is not a module: it is the operator's panel
  and the only place that reads across tenants on purpose.
- Boot fails with a `RuntimeException` if a manifest names a dependency that is not registered.

The manifest carries: `key`, `name`, `version`, `core`, `dependencies`, `permissions`, `navigation`, `widgets`,
`triggers`, `actions`, `morphMap`. Field-by-field rules and a worked example are in `docs/MODULES.md`.

## 6. Shared services

| Service | Class | Lifetime | Use it for |
|---|---|---|---|
| Tenant context | `Core\Tenancy\TenantContext` | scoped | `tenant()`, `requireId()`, `run()`, `central()`, `withoutScope()` |
| Host rules | `Core\Tenancy\Support\TenantHost` | singleton | Hosts, reserved labels, workspace URLs |
| Tenant files | `Core\Tenancy\Storage\TenantStorage` | scoped | Every read or write of a tenant file |
| Connection seam | `Core\Tenancy\Support\TenantConnectionResolver` | scoped | Used by `BelongsToTenant`; not called directly |
| Central cache | container key `cache.central` | singleton | Data that must not be tenant-prefixed (handoff tokens, 2FA replay and lockout) |
| Rate limiter | `Core\Tenancy\Support\TenantAwareRateLimiter` | singleton; answers for `Illuminate\Cache\RateLimiter` | Per-tenant counts; named limiters survive a tenant switch |
| Provisioning | `Core\Tenancy\TenantProvisioner` | per call | Create a tenant with domain, system roles and owner |
| Module registry | `Core\Modules\ModuleRegistry` | singleton | Manifests and the permission index |
| Entitlements | `Core\Entitlements\Contracts\EntitlementChecker` | bound to `CoreModulesEntitlementChecker` | "May this tenant use this module?" |
| Authorization | `Core\Access\AccessChecker` | scoped | `allows()`, `scopeFor()`, `effectivePermissions()`, `membership()` |
| Role changes | `Core\Access\RoleManager` | per call | Create / update / delete roles, `canAssign()`, `flush()` |
| Audit trail | `Core\Audit\AuditLogger`, `Audit\Concerns\Auditable` | scoped / trait | `record()` for business events; the trait for attribute changes |
| Navigation, dashboard | `Core\Modules\NavigationBuilder`, `Core\Dashboard\DashboardBuilder` | per call | Entitlement- and permission-filtered sidebar and widgets |
| Automation registry | `Core\Automation\AutomationRegistry` | singleton | Catalogue of triggers and actions (nothing executes yet) |
| Two-factor | `Core\Identity\TwoFactor\TwoFactorService` | per call | TOTP for both guards |
| Flash messages | `Core\Support\Toast` | static | One-shot toast on the next page |
| Search filters | `Core\Support\Like` | static | `Like::contains($text)` / `Like::escape($text)` for any user text in a `LIKE` |
| Member changes | `Core\Identity\Actions\MemberManager` | per call | Change role, suspend, remove; `canManage()` |

## 7. Tenancy design

ADR 0001 has the reasoning. The moving parts:

- **Resolution.** `InitializeTenancy` takes the tenant from the hostname only. `DomainTenantResolver` looks the
  label up in `tenant_domains.domain`. Soft-deleted tenants do not resolve.
- **Context.** `TenantContext` wraps the package's `Tenancy` singleton. Everything tenant-aware reads it.
- **Bootstrappers** (`config/tenancy.php`), run in this order whenever a context starts, in HTTP, console and
  queue alike, and reverted when it ends: `CachePrefixBootstrapper` (re-prefixes every cache store and hands the
  rate limiter the new one), `PermissionTeamBootstrapper`, `UrlDefaultsBootstrapper` (fills `{tenant}` for
  `route()`), `QueueTenancyBootstrapper`.
- **Rows.** `TenantScope` adds `where tenant_id = ?` and throws `MissingTenantContextException` with no tenant.
  `BelongsToTenant` also stamps `tenant_id` on create, refuses a foreign `tenant_id`
  (`CrossTenantAccessException`) and refuses to change it.
- **Ways out, all explicit:** `Model::withoutTenancy()`, `Model::forTenant($tenant)`,
  `TenantContext::withoutScope(fn)`. Platform code and maintenance commands only.
- **Changing context:** `TenantContext::run($tenant, fn)` and `TenantContext::central(fn)` restore the previous
  context afterwards, also when the callback throws. Use them instead of the package's `$tenant->run()`, which
  does not restore on an exception.
- **Dispatching inside `run()`:** use a block closure, `function (): void { SomeJob::dispatch(); }`. An arrow
  function returns the `PendingDispatch`, which reaches the queue only when it is destroyed: after the context
  has ended, so the job would carry the wrong tenant or none.
- **Unknown host.** A 404 for an unknown workspace does not end a tenant initialised earlier in the same
  process. That cannot happen under PHP-FPM; it would have to be handled before running on Octane.

How each model is classified:

| Traits | Meaning | Models |
|---|---|---|
| `BelongsToTenant` | Tenant-owned: read scope, write guards, tenant connection | `Invitation` |
| `BelongsToTenant` + `UsesCentralConnection` | Tenant-scoped rows that always stay in the central database | `TenantMembership` |
| `ScopedToTenant` | Read scope only; `tenant_id` may be null (platform events) | `AuditLog` |
| `UsesCentralConnection` | Platform data, no tenant scope | `User`, `PlatformUser`, `Permission` |
| none, scoped by hand | See ADR 0002 | `Role` |
| package models | Central | `Tenant`, `Domain` |

Cache, files, queues and the remaining isolation layers are mapped mechanism by mechanism in `docs/SECURITY.md`.

## 8. Authorization pipeline

```
route `can:crm.leads.edit`  /  $user->can()  /  Gate::allows()
        └─ Gate::before            App\Core\CoreServiceProvider::registerGate()
             ├─ PlatformUser + `platform.*`  → PlatformRole::allows()
             ├─ not a registered module permission → null (ordinary gates and policies decide)
             └─ AccessChecker::allows()
                  1. a tenant is active
                  2. the permission is registered by a module
                  3. EntitlementChecker::allows(tenant, module)
                  4. the user has an active membership in this tenant
                  5. membership.is_owner, or PermissionResolver::grantsFor(user) contains it
```

- `PermissionResolver` runs one query (`model_has_roles` ⋈ `roles` ⋈ `role_has_permissions` ⋈ `permissions`,
  filtered by `tenant_id` twice) and caches it for 10 minutes under `access:grants:{version}:{user}` in the
  tenant's cache namespace. `RoleManager::flush()` bumps the version.
- The `permissions` and `navigation` props sent to the browser are a courtesy to the UI. Every route checks again.
- Phase 1 has no policy classes: all checks are `can:` middleware on routes plus rules inside `RoleManager`
  (nobody grants or removes a permission they do not hold) and `MemberManager` (nobody changes, suspends or
  removes a member who holds a permission they lack).

## 9. Events

- `Core\Events\DomainEvent` is the contract: `name()` (`{module}.{entity}.{verb}`, past tense), `tenantId()`,
  `occurredAt()`, `payload()` (ids and scalars, never models).
- `Core\Events\TenantDomainEvent` is the base class. It captures the active tenant at construction (throws without
  one) and implements `ShouldDispatchAfterCommit`.
- Existing events: `core.member.invited`, `core.member.joined`, `core.member.removed`
  (`app/Core/Identity/Events`). Nothing listens to them yet. `MemberJoinedTrigger` exposes the second one to the
  automation catalogue.

## 10. Queues

- Connection: Redis (`QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`). `config/queue.php` still falls back to
  `database` if the variable is missing.
- Queue names, from spec section 25: `default`, `notifications`, `webhooks`, `billing`, `automation`,
  `campaigns`, `ai`.
- Horizon supervisors (`config/horizon.php`): `interactive` works `notifications, webhooks, default` (60 s
  timeout); `background` works `billing, automation, campaigns, ai` (300 s timeout). The Redis queue's
  `retry_after` is 330 s and must stay above the longest timeout. Horizon needs Linux; `composer dev` runs one
  `queue:listen` over all seven names instead.
- Tenant context: `QueueTenancyBootstrapper` adds `tenant_id` to every payload dispatched inside a tenant and
  restores the context before the job runs. A job, mailable, notification or listener that implements
  `RequiresTenantContext` is failed by `EnforceTenantContextOnJobs` before `handle()` if that context is missing.
- The only queued work today is `Core\Identity\Mail\InvitationMail` (queue `notifications`, encrypted payload,
  sent in the invitee's language or the workspace's). The password-reset link is not queued: it is sent after
  the response with `defer()`, inside the workspace's context.
- Nothing is scheduled: `routes/console.php` holds only the stock `inspire` command.

## 11. Dedicated tenant databases (summary)

Not implemented. What exists is the seam: `tenants.db_connection` (null = shared), `TenantConnectionResolver`,
and `BelongsToTenant::getConnectionName()`, which asks the resolver on every query. Platform models are pinned
with `UsesCentralConnection`. ULID keys mean rows can be copied between databases without re-keying. The full
strategy, and the gaps that must be closed before the first move, are in `docs/DATABASE.md`.

## 12. Not built yet

| Area | Phase | Today |
|---|---|---|
| Tenant self-registration, onboarding, email verification flow | 2 | Tenants come from `DemoSeeder` via `TenantProvisioner`; there is no command or screen to create one |
| Branches, departments | 2 | `department` / `branch` scopes are stored, not evaluated |
| Shared contacts and companies | 2 | — |
| Plans, billing, metering, entitlement store | 2 | `CoreModulesEntitlementChecker`: core modules only |
| Super Admin tenant management, impersonation | 2 | Read-only tenant list and dashboard. Accounts are created with `php artisan platform:create-admin`. The panel has no language switch |
| API tokens (Sanctum) | 2+ | Not installed; there are no API routes |
| Integration centre (OAuth connections, webhooks) | not assigned in spec 27 | — |
| App Store | 3 | Manifest and entitlement contracts only |
| Notification centre, realtime (Reverb) | 8 | Not installed; `BROADCAST_CONNECTION=log` |
| Automation engine | 10 | Contracts and registry only |
| File manager | — | `TenantStorage` primitive only |
| Global data search | — | Scout installed (`database` driver), no searchable model; Ctrl/Cmd+K is navigation only |
| Custom domains | future | `tenant_domains.type` exists, no routing |
| Playwright end-to-end tests | 2 | — |
