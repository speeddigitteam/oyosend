# Testing

Backend tests are Pest on a real MySQL database. Frontend tests are Vitest. Playwright end-to-end tests start in
Phase 2 (spec section 0) and are not installed.

## 1. Commands

| Command | Runs |
|---|---|
| `composer test` | `php artisan config:clear`, then `php artisan test` (Pest) |
| `php artisan test --filter="isolates invitations"` | One test by name |
| `vendor/bin/pest tests/Feature/Tenancy` | One folder |
| `composer lint` | `vendor/bin/pint --test`. Fix with `vendor/bin/pint` |
| `composer analyse` | Larastan (`phpstan.neon`: level 6; `app`, `bootstrap/app.php`, `config`, `database`, `routes`) |
| `composer check` | lint, analyse, test, in that order |
| `npm run typecheck` | `vue-tsc --noEmit` (strict; covers `resources/js` and `tests/js`) |
| `npm run test` / `npm run test:watch` | Vitest |

A change is done when `composer check`, `npm run typecheck` and `npm run test` all pass.

- There is no `pint.json`: Pint uses its default Laravel preset.
- `tests/` is not in Larastan's paths. `phpstan/stubs/TenantCollection.stub` restores the generics the tenancy
  package's collection class lacks; it is the only stub.
- **Windows, Git Bash:** start Vitest from a path with an upper-case drive letter (`cd D:/laragon/www/oyosend`).
  Started from `/d/...`, every test file fails with `Cannot read properties of undefined (reading 'config')`.

## 2. The test database

- Tests use MySQL, database **`oyosend_testing`** (`phpunit.xml`), not SQLite: production is MySQL and the code
  uses MySQL-only SQL (ADR 0004). Create it once: `CREATE DATABASE oyosend_testing;`. Under Sail,
  `docker/mysql/create-testing-database.sh` creates it when the MySQL volume is first initialised.
- `phpunit.xml` sets only the connection and the database name. Host, user and password come from `.env`.
- Feature tests use `RefreshDatabase`: the schema is rebuilt with `migrate:fresh` at the start of a run and every
  test runs in a transaction that is rolled back.
- **Guard:** `Tests\TestCase::refreshApplication()` throws unless the configured database is named `testing` or
  ends in `_testing` (or `_testing_test_N`, the names `artisan test --parallel` uses). A cached config or a
  stray shell variable therefore cannot point `migrate:fresh` at a real database. If you hit the guard, run
  `php artisan config:clear`.

Other settings from `phpunit.xml`:

| Setting | Value | Consequence |
|---|---|---|
| `APP_URL`, `APP_DOMAIN` | `http://localhost`, `localhost` | Workspaces are `http://{slug}.localhost`, the panel `http://admin.localhost`, no port |
| `APP_DEBUG` | `false` | Error responses are rendered the way production renders them |
| `CACHE_STORE` | `array` | No Redis needed. The array store ignores key prefixes and is thrown away on every context switch, so nothing cached survives a tenant switch; use `useDatabaseCache()` when a test needs that |
| `QUEUE_CONNECTION` | `sync` | Queued work runs inline, in the tenant context of the dispatch |
| `SESSION_DRIVER`, `MAIL_MAILER` | `array` | |
| `LOG_CHANNEL` | `null` | Expected failures (failed jobs, 500s under test) stay out of `storage/logs` |
| `PLATFORM_REQUIRE_2FA` | `true` | Platform users need a confirmed second factor to pass `platform.2fa` |
| `BCRYPT_ROUNDS` | `4` | Fast hashing |

`Tests\TestCase` also:

- fakes `Sleep` (the login time-box would otherwise add 250 ms per attempt) and calls `withoutVite()`;
- before **every simulated request** forgets scoped services, shared Inertia props, the default guard and the
  locale, because one test makes several requests on one application instance while production serves each from
  a fresh one. The tenant context is deliberately *not* reset between requests: the context middleware must cope
  with a leftover tenant, and this keeps that under test.

Inertia pages must exist: `inertia.testing.ensure_pages_exist` is on, so `assertInertia()->component('…')`
fails when the Vue file is missing under `resources/js`. Assert the component in at least one test per page.

## 3. Layout

```
tests/
  Pest.php                 Feature: TestCase + RefreshDatabase.  Unit: TestCase, no database.
  TestCase.php             Base class; uses the five traits in Support/
  Support/                 Helpers (traits)
  Support/Fixtures/        A stand-in optional module (Crm/), a test entitlement checker, two queued jobs
  Feature/<Area>/*Test.php HTTP and database behaviour
  Unit/<Area>/*Test.php    Pure logic; the application boots but the database is never touched
  js/*.test.ts             Vitest (jsdom); js/setup.ts installs the real i18n catalogue
```

| Suite | Files | Covers |
|---|---|---|
| `Feature/Tenancy` | `HostResolution`, `RoutePipeline`, `TenantContext`, `TenantScope`, `TenantProvisioner`, `CacheIsolation`, `QueueContext`, `TenantStorage` | Subdomain resolution and area separation; the effective middleware order of every route; context switching; the row scope and its write guards; provisioning; per-tenant cache and rate limits; tenant context in queued jobs; tenant file paths |
| `Feature/Auth` | `Login`, `TwoFactorChallenge`, `PasswordReset`, `SecuritySettings`, `SessionBinding`, `WorkspaceSwitch` | Uniform sign-in failures and throttling; second factor, replay and lockout; reset flow; password and 2FA settings; session-to-workspace binding and "remember me"; the handoff |
| `Feature/Access` | `RoleAccess`, `RoleManagement`, `PermissionCache`, `PermissionSynchronizer` | What each system role may reach; role editing rules; grant caching and invalidation; `permissions:sync` |
| `Feature/Members`, `Feature/Invitations` | `MemberManagement`; `InviteMember`, `AcceptInvitation` | Member changes and the hierarchy rule; inviting, mail language, acceptance by new and existing accounts |
| `Feature/Modules` | `Entitlement`, `NavigationAndDashboard`, `DomainEvent` | A non-core module behind entitlement (routes, permissions, sidebar, widgets); domain event conventions |
| `Feature/Audit` | `AuditTrail` | What is recorded, redaction, immutability, the audit page and its filters |
| `Feature/Platform` | `PlatformAuth`, `PlatformPages`, `CreatePlatformAdminCommand` | Super Admin sign-in and mandatory 2FA; dashboard and tenant list; `platform:create-admin` |
| `Feature/Central` | `CentralSite`, `ErrorPages` | Landing page and workspace finder; error pages per area |
| `Unit` | `Tenancy/TenantHost`, `Modules/ModuleRegistry`, `Modules/AutomationRegistry`, `Access/AccessScope` | Host parsing, registries, scope ordering |
| `tests/js` | `components`, `composables`, `format`, `locales`, `contrast`, `audit` | Base components; toasts, theme, labels; number and money formatting; `en`/`bn` catalogue parity; WCAG AA contrast recomputed from `app.css`; audit entry rendering |

`Feature/Tenancy/TenantScopeTest.php` is the model to copy for cross-tenant tests: it points
`assertTenantIsolated()` at every tenant-owned model that exists (`Invitation`, `TenantMembership`, `AuditLog`).

## 4. Helpers (`tests/Support`)

Available on `$this` in every Pest test.

**`InteractsWithTenants`**

| Signature | Does |
|---|---|
| `createTenant(?string $slug = null, array $attributes = [], ?User $owner = null): Tenant` | A fully provisioned workspace (tenant, subdomain, system roles, owner) through `TenantProvisioner`. Random `ws-…` slug by default. `$attributes`: `name`, `status`, `locale`, `timezone`, `currency`, `trial_ends_at` |
| `syncPermissions(): void` | Fills `permissions` from the registered manifests. `createTenant()` calls it once; `registerModule()` calls it again |
| `ownerOf(Tenant $tenant): User` | The workspace owner |
| `addMember(Tenant $tenant, Role\|string $role = Role::EMPLOYEE, ?User $user = null, MembershipStatus $status = MembershipStatus::Active): User` | Puts a person (new, or an existing account) in the workspace with a role |
| `membershipOf(Tenant $tenant, User $user): TenantMembership` | The membership row, e.g. for `{membership}` URLs |
| `roleOf(Tenant $tenant, string $name): Role` | A role of that tenant by name |
| `createRole(Tenant $tenant, string $name, array $permissions = []): Role` | A custom role, created through `RoleManager` as the owner |
| `createInvitation(Tenant $tenant, string $email, Role\|string $role = Role::EMPLOYEE, array $attributes = []): array` | An invitation whose emailed token the test knows. Returns `[Invitation, string $token]` |
| `auditTrail(?Tenant $tenant = null, ?string $event = null): Collection` | The workspace's audit entries, oldest first; with no tenant, the platform-level ones |
| `actingAsMember(Tenant $tenant, User $user): static` | Signs in **and** binds the session to the tenant. Plain `actingAs()` is rejected by `EnsureTenantMember` |
| `actingAsOwner(Tenant $tenant): static` | The same, as the owner |
| `startNewBrowserSession(): static` | Forgets the signed-in user, session and cookies, as if another browser made the next request |
| `useDatabaseCache(): void` | Switches the cache to the shared, prefix-aware `database` store, for tests about cache isolation or cached state that must survive requests to more than one workspace (rate limits, grants). Must be called before the first tenant is created; it throws otherwise |
| `tenantUrl(Tenant\|string $tenant, string $path = '/'): string` | `http://{slug}.localhost/{path}` |
| `centralUrl(string $path = '/'): string`, `adminUrl(string $path = '/'): string` | URLs on the central site and the Super Admin panel |
| `inTenant(Tenant $tenant, Closure $callback): mixed` | Runs the callback with that tenant active, then restores the previous context |
| `inCentral(Closure $callback): mixed` | Runs the callback with no tenant active, then restores |

**`InteractsWithModules`**

| Signature | Does |
|---|---|
| `registerModule(string $provider): ModuleManifest` | Registers a module provider for this test as if it were in `config/modules.php`: manifest, routes, and its permissions synced |
| `entitle(Tenant $tenant, string ...$modules): void` | Entitles a tenant to optional modules. Swaps the bound `EntitlementChecker` for `Fixtures\GrantsModules` (Phase 1 rules plus explicit grants) |

**`AssertsTenantIsolation`**

| Signature | Asserts |
|---|---|
| `assertTenantIsolated(string $model, Closure $makeInTenant): void` | The whole row-isolation contract. Provisions two tenants and calls `$makeInTenant(Tenant)` once in each (it must create and return one record). Then: stamped with its tenant; invisible from the other tenant to `find`, `get`, `count`, `exists`, route binding, bulk update and bulk delete; every read throws with no tenant; reachable through `withoutTenancy()` and `forTenant()`. For `BelongsToTenant` models also the write guards |
| `assertNotFoundFromOtherTenant(string $method, string $path, Tenant $home, array $data = [], ?Tenant $intruder = null): void` | The route answers 404 when called on another workspace's host by that workspace's owner, and does **not** answer 404 or 5xx in the record's own workspace (the control). With a destructive verb the control really performs the action, so call it last |

**`InteractsWithPlatform`**

| Signature | Does |
|---|---|
| `createPlatformUser(PlatformRole $role = PlatformRole::SuperAdmin, bool $twoFactor = true): PlatformUser` | A platform account, with a confirmed second factor by default |
| `actingAsPlatformUser(PlatformUser $user): static` | Signs in on the `platform` guard |

**`InteractsWithTwoFactor`**

| Signature | Does |
|---|---|
| `enableTwoFactor(TwoFactorAuthenticatable&Model $account): array` | Confirmed TOTP for a user or platform user; returns the recovery codes |
| `twoFactorCode(TwoFactorAuthenticatable $account, int $stepsAhead = 0): string` | The code the authenticator shows now. A code works once: pass `stepsAhead: 1` for a second valid one |
| `wrongTwoFactorCode(TwoFactorAuthenticatable $account): string` | A well-formed code that is not valid now |

**Fixtures (`tests/Support/Fixtures`)**

| Fixture | Use |
|---|---|
| `Crm\CrmServiceProvider` | A stand-in optional app (`crm`, non-core): permissions `crm.leads.view` / `.edit`, routes `crm/leads` and `crm/ping`, a sidebar entry, a widget, a trigger. Register it with `registerModule()` |
| `GrantsModules` | The entitlement checker `entitle()` installs |
| `RecordsTenantJob`, `TenantBoundJob` | Queued jobs that record the tenant they ran in; the second implements `RequiresTenantContext` |

Factories exist for `Tenant`, `User`, `TenantMembership`, `Invitation` and `PlatformUser` (`database/factories`).
`TenantFactory` makes a bare row; use `createTenant()` when the test needs a working workspace.

## 5. Conventions

**Every new tenant-owned model and every new route gets a cross-tenant test** (spec section 29):

```php
it('isolates notes between tenants', function () {
    $this->assertTenantIsolated(Note::class, fn (Tenant $tenant) => Note::factory()->create());
});

it('does not serve a note to another workspace', function () {
    $tenant = $this->createTenant();
    $note = $this->inTenant($tenant, fn () => Note::factory()->create());

    $this->assertNotFoundFromOtherTenant('GET', "/notes/{$note->id}", $tenant);
});
```

- Hosts are part of the behaviour under test. Always request a full URL: `tenantUrl()`, `centralUrl()`,
  `adminUrl()`.
- Outside `inTenant()` a test runs in the central context. After a request to a workspace host that tenant stays
  active. Do not rely on either: wrap every assertion on tenant-owned models in `inTenant()`.
- Sign in with `actingAsMember()` / `actingAsOwner()` / `actingAsPlatformUser()`. Call
  `startNewBrowserSession()` before acting as someone else in the same test.
- For a permission, test both sides: a role that holds it (`createRole()` + `addMember()`) and one that does not.
  The owner holds everything, so the owner proves nothing about permissions.
- Slugs must be valid, unreserved labels. `test`, `demo`, `dev`, `app`, `api` and the others in
  `platform.reserved_subdomains` are rejected by `TenantProvisioner`.
- A non-core module is denied by the Phase 1 entitlement checker. In its tests call `registerModule()` and
  `entitle()`, and also test the not-entitled case (404). `Feature/Modules/EntitlementTest.php` shows the pattern.
- Dispatch inside `inTenant()` with a block closure: `function (): void { SomeJob::dispatch(); }`. An arrow
  function returns the `PendingDispatch`, which only reaches the queue when it is destroyed, after the tenant
  context has ended.
- Under `Queue::fake()` the tenancy package does not add `tenant_id` to payloads; to test tenant propagation
  use the `sync` queue.
- Fakes for outside services are bound only in tests (`Mail::fake()`, …). Never add a fake to a production code path.
- `Feature/Tenancy/RoutePipelineTest.php` checks every registered workspace route: tenant resolved first, then
  sign-in, session check and membership, and only tenant-scoped models bound. A route meant for signed-out
  visitors has to be added to its `PUBLIC_WORKSPACE_ROUTES` list, which makes the exception visible in review.
- Frontend: specs live in `tests/js`, mounted with `@vue/test-utils`. A new design token used for text or a
  surface must be `#rrggbb` or `rgb(r g b / a)` so `contrast.test.ts` can read it.

## 6. CI

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests. **It has never run on GitHub**; treat
it as unverified until the first run is green.

| Job | Environment | Steps |
|---|---|---|
| `php` | PHP 8.4, MySQL 8.0 service with database `oyosend_testing`, Redis 7 service | `cp .env.example .env`, `composer install`, `key:generate`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` |
| `js` | Node 22 | `npm ci`, `npm run typecheck`, `npm run test`, `npm run build` |

The workflow points the Sail-flavoured `.env.example` at its service containers through real environment
variables (`DB_HOST=127.0.0.1`, `DB_USERNAME=root`, …), which win over `.env`. The test run itself does not need
Redis (cache `array`, queue `sync`, session `array`).
