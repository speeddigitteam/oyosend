# CLAUDE.md — Oyosend project rules

Multi-tenant business SaaS, modular monolith. Spec: `MASTER_PROMPT.md` (sections 0, 3, 27–31 matter most).
**Read before coding:** this file, `PROGRESS.md`, then `docs/ARCHITECTURE.md`, `docs/SECURITY.md`,
`docs/MODULES.md`, `docs/DATABASE.md`, `docs/TESTING.md`, `resources/js/README.md`; `docs/BILLING.md` before
touching billing. The code is the source of truth; if a doc and the code disagree, fix the doc and say so.

State: Phases 1–4 are complete; Phase 5 Projects is next and has not been started. CRM is the first production
optional app. One phase per session; stop at the end of the phase and report.

## Stack

PHP 8.4 · Laravel 13 · MySQL 8 · Redis (predis) · Horizon · Scout (`database`) · stancl/tenancy 3.10 ·
spatie/laravel-permission 8 · Inertia 3 · Vue 3 `<script setup lang="ts">` · TypeScript strict · Tailwind 4 ·
vue-i18n 11 · reka-ui · lucide · Pest 5 · Vitest 5 · Pint · Larastan level 6 · vue-tsc.
Not installed until needed: Sanctum (API tokens), Reverb (realtime), Meilisearch, the S3 driver.

## Locked decisions (spec section 0) and how each was resolved

| Decision | Resolution in this repo |
|---|---|
| Modular monolith, one shared MySQL DB, `tenant_id` on tenant-owned tables | `app/Core` + `app/Platform`; `app/Modules/` starts with the first optional module |
| Tenant = subdomain `{tenant}.APP_DOMAIN`; central site on the root; custom domains later | `TenantHost` + `InitializeTenancy`, hostname only. `tenant_domains.type` reserves `custom`; not routed |
| Local dev on `*.localhost` | Yes. Natively `php artisan serve` on `:8000` |
| Super Admin: own guard, own host `admin.APP_DOMAIN`, own layout, `platform_users` table | `platform` guard, `routes/platform.php`, `App\Platform`, `PlatformLayout`; 2FA mandatory |
| ULID primary keys for tenant-owned tables | ULIDs on every tenant-owned and identity table (`HasUlids`). Auto-increment only `permissions`, `tenant_domains`, framework tables |
| `stancl/tenancy` single-DB if it supports Laravel 13 | Used (3.10.1), with **our own** fail-closed scope, cache prefixing, storage API — ADR 0001 |
| `spatie/laravel-permission` teams mode | Used (team = tenant) for schema and assignment; **checks go through our `AccessChecker`** — ADR 0002; scopes — ADR 0007 |
| Scout, `database` driver first, Meilisearch later | Installed; no searchable model yet |
| Backend tests: Pest | Pest 5 on a real MySQL database `oyosend_testing` |
| Frontend tests: Vitest; Playwright from Phase 2 | Vitest in `tests/js`. Playwright: see `PROGRESS.md` |
| Local env: Laravel Sail | `compose.yaml` committed but **unverified** (no Docker here); verified natively on Laragon — ADR 0004 |
| English default + Bengali; BDT; Asia/Dhaka; per tenant | `config/platform.php` defaults, `tenants.locale/timezone/currency`, user `locale` + numerals preference. Bengali is complete: `lang/bn*`, `Core/i18n/locales/bn` (glossary in `bn/index.ts`) |
| Money = integer minor units + currency code | Unsigned `BIGINT` columns named `amount` / `*_amount` next to a `currency`; `Core\Billing\Support\Money` for text |
| Licences: MIT, BSD, Apache-2.0, ISC only | Held. Fonts are OFL-1.1, accepted for fonts only — ADR 0005 |
| One shared `contacts` / `companies` model in core | `Core\Contacts` (`Contact`, `Company`, `ContactDirectory`). Modules must not create their own customer tables |

Identity model (ADR 0003): `users` is a platform-wide identity with a globally unique email;
`tenant_memberships` is the tenant-scoped handle; sessions are host-only and bound to one tenant.
Billing model (ADR 0006): invoice-driven; nothing is charged automatically. Sign-up and support access: ADR 0008.

## Folder structure

```
app/Core/{Tenancy,Identity,Access,Organization,Contacts,Settings,Registration,Billing,AppStore,Modules,Entitlements,
          Automation,Events,Audit,Dashboard,Http,Support}
app/Core/CoreServiceProvider.php     the `core` module: manifest, bindings, Gate::before, gateways
app/Platform/                        Super Admin panel (the only code that reads across tenants)
app/Modules/<Module>/                optional apps (`CRM` is the first production module)
routes/{central,tenant,tenant-callbacks,platform,console}.php     routes/web.php is NOT loaded
config/{platform,tenancy,modules,permission,trustedproxy,horizon,ziggy}.php
database/{migrations,factories,seeders}          lang/{en,bn}, lang/bn.json (server-side strings)
resources/js/{Core,Platform,Modules/<Module>}    page name = path under resources/js
resources/css/app.css                design tokens (Tailwind 4 @theme)
tests/{Feature,Unit,Support,js}      helpers in tests/Support, Vitest in tests/js
docs/, docs/adr/                     documentation and decisions
```

Three areas: central (`APP_DOMAIN`, groups `web`+`central`, sign-up only), workspace (`{tenant}.APP_DOMAIN`,
`web`+`tenant`, guard `web`), Super Admin (`admin.APP_DOMAIN`, `web`+`platform`, guard `platform`). Plus the two
stateless payment callbacks on the workspace host (group `tenant.callback`).

## Commands

| | |
|---|---|
| `composer dev` | server on `127.0.0.1:8000` + queue listener + Vite |
| `composer test` | `php artisan test` (Pest) |
| `composer lint` | `pint --test`; fix with `vendor/bin/pint` |
| `composer analyse` | Larastan |
| `composer check` | lint + analyse + test |
| `npm run typecheck` · `npm run test` · `npm run build` | vue-tsc · Vitest · Vite build |
| `php artisan permissions:sync [--prune]` | sync the permission catalogue; after adding permissions and on every deploy |
| `php artisan apps:sync [--prune]` | sync compiled optional manifests into immutable catalogue versions; every deploy (`db:seed` also performs the non-pruning sync) |
| `php artisan platform:create-admin {email}` | create a Super Admin panel account; interactive, asks for the password |
| `php artisan billing:run [--tenant=slug]` | advances base and app subscriptions; scheduled hourly, safe to run twice |
| `php artisan apps:run-scheduled` | dispatch due optional-module jobs only for entitled tenants |

A task is done only when `composer check`, `npm run typecheck` and `npm run test` pass. CI
(`.github/workflows/ci.yml`) runs the same checks but has never run on GitHub yet. On Windows Git Bash start
Vitest from `D:/…`, not `/d/…`. The suite runs `migrate:fresh`: never run it twice at once on one database
(a second run takes `DB_DATABASE=oyosend_x_testing php artisan test …`). Demo logins and local setup: `README.md`.

## Conventions

**Tenancy**
- Tenant-owned model: `use BelongsToTenant, HasUlids`; table has an indexed `tenant_id` FK; every unique index
  starts with `tenant_id`. Platform model: `use UsesCentralConnection`. Central data keyed by tenant (billing,
  the organisation chart): both traits, like `TenantMembership`.
- Ask `App\Core\Tenancy\TenantContext` which tenant is active. With no tenant, tenant queries throw — by design.
  Switch tenant with `TenantContext::run()` / `central()`, not the package's `$tenant->run()`. Dispatch inside
  `run()` from a block closure: an arrow function returns the `PendingDispatch`, queued after the context ended.
- Tenant files only through `TenantStorage`. `Cache::` and rate limits are per tenant inside a tenant;
  platform-wide data goes to `app('cache.central')`.
- Queued jobs, mailables, notifications and listeners for a tenant implement `RequiresTenantContext`.
  Queues: `default`, `notifications`, `webhooks`, `billing`, `automation`, `campaigns`, `ai`.

**Authorization**
- Permissions are declared in a module manifest, named `{module}.{resource}.{action}`, synced with
  `permissions:sync`, and checked with `can:{permission}` on the route (or `$user->can()` / `AccessChecker`).
- A permission is effective only if the tenant is entitled to its module. Non-core module routes carry
  `entitled:{module}` (added by `loadTenantRoutes()`).
- Workspace routes: `['auth:web', 'auth.session', 'member']` + `can:`. `loadTenantRoutes()` does **not** add them.
  Routes that are part of working in the workspace also carry `subscribed` (the paywall).
- A `scopable` permission reaches own / department / branch / all records. The model uses `HasRecordOwner`; the
  controller narrows with `RecordAccess::constrain()` and `authorize()`. The route alone is not enough.
- Roles: query with `Role::forCurrentTenant()`; change through `RoleManager`; call `RoleManager::flush()` after
  any change to roles, grants, assignments or a member's branch/department. The owner is
  `tenant_memberships.is_owner`, not a grant.
- Nobody grants, re-scopes or removes a permission they lack (`RoleManager`), nor manages a member who holds one
  they lack (`MemberManager`). Keep both rules in anything that changes roles or members.

**Billing** (`docs/BILLING.md`)
- Base subscriptions change only through `SubscriptionManager` and `BillingCycle`. A paid plan starts only in
  `SubscriptionManager::settle()`, from an invoice that became paid.
- App installs/licences/subscription items change only through `AppStoreManager` / `AppBillingCycle`. App billing
  is separate from the base plan; a paid app activates only through verified `InvoiceSettlement`.
- A payment counts only after `PaymentProcessor::confirm()` got the gateway's own answer, or an operator recorded
  it. Never settle on a redirect, a notification payload or anything the browser sent.
- A module that can be capped declares a `LimitDefinition`, asks `UsageLimits::ensure()` before using more and
  records metered use with `UsageMeter::record()` and an idempotency key that identifies the event.
- Operator settings (registration mode, grace days, …) come from `PlatformSettings`, not `config()` directly.

**Backend style**
- `declare(strict_types=1)`, `final` classes (models excepted), constructor injection, full types, enums for states.
- Thin controllers; work lives in actions/managers (`app/Core/Identity/Actions`). Validate every input.
- Multi-write operations in `DB::transaction`; write the audit entry (`AuditLogger::record` or `Auditable`)
  inside it; announce with a `TenantDomainEvent` (dispatched after commit; ids and scalars only).
- Polymorphic/audited models need a morph alias in the module manifest (`Relation::requireMorphMap()` is on).
- Lazy loading throws outside production: eager-load. Paginate lists; cursor-paginate large ones.
- User text in a `LIKE` goes through `App\Core\Support\Like`.
- User feedback: `App\Core\Support\Toast`. Strings through `__()`, with their Bengali in `lang/bn.json`.
- Contacts and companies are written through `ContactDirectory`. Modules reference them by id and talk to each
  other through events and documented contracts, never another module's models.
- New module: follow `docs/MODULES.md` and register its triggers/actions (and limits) from day one.
- Optional apps are compiled first-party modules. Bump the version when versioned catalogue/contract fields
  change; uninstall disables access but retains licences/data for safe reinstall; schedules run through the
  worker-time entitlement-gated runner.
- CRM customer content and timelines attach to the shared contact/company identity, not a duplicate customer row.
  Its mutating automation actions persist tenant-scoped action receipts; a retry with the same action/key returns
  the stored output instead of repeating the mutation. Linked deals, not their quotes, own lifetime-value credit.

**Frontend** (details: `resources/js/README.md`)
- Pages in `resources/js/{Core,Platform,Modules/<M>}/Pages`, rendered by path; type every prop.
- Use the shared components in `resources/js/Core/Components` and token colours only; both themes; keyboard; 360px.
- No user-facing string in a template: `Core/i18n/locales/en/*.ts` first, and the same key in `bn` (checked).
- Text contrast on glass is tested (`tests/js/contrast.test.ts`); new colour tokens must pass it.
- URLs via `route()` from `@/Core/lib/route`; numbers, dates and money via `useFormat()`.
- `useAccess().can()` hides controls only. The server authorizes.

**Tests**
- Pest. Every new tenant-owned model gets `assertTenantIsolated()`, every new route that takes an id gets
  `assertNotFoundFromOtherTenant()` (`tests/Support/AssertsTenantIsolation.php`).
- Use the helpers (`createTenant`, `addMember`, `actingAsMember`, `tenantUrl`, `inTenant`; billing: `createPlan`,
  `subscribe`, `subscribeAndPay`, `runBillingCycle`, `payOpenInvoice`, `useTestGateway`; for a non-core module
  `registerModule` + `entitle`); see `docs/TESTING.md`. Pages asserted with `->component()` must exist.
- A workspace route for signed-out visitors must be added to `PUBLIC_WORKSPACE_ROUTES` in `RoutePipelineTest`.

**Docs**
- Keep `docs/` in step with the code in the same change. Major decision → `docs/adr/NNNN-title.md`.
- New dependency → check Laravel 13 support and licence first, record why in an ADR or `PROGRESS.md`.
- Update `PROGRESS.md` at the end of every session.

## Never do

- Never read the tenant, a tenant id, permissions, entitlements or billing status from request input, headers
  or the frontend. The tenant comes from the hostname; the rest from the database.
- Never use `withoutTenancy()`, `forTenant()` or `TenantContext::withoutScope()` outside `app/Platform` and
  maintenance commands.
- Never bind or list `User` in a workspace route; use `TenantMembership`. `User` has no tenant scope.
- Never query `Role` without `forCurrentTenant()` or an explicit `tenant_id`.
- Never use the permission package's check API (`hasPermissionTo`, `role:` / `permission:` middleware) or
  direct user permissions (`model_has_permissions` is not read).
- Never use `DB::table()` on a tenant-owned table without a `tenant_id` filter.
- Never add a unique index on a tenant-owned table that does not lead with `tenant_id`.
- Never store money as float or decimal, and never send a float to a gateway.
- Never mark an invoice paid or fulfil it outside verified `InvoiceSettlement` (which routes to the two managers).
- Never activate a paid app except through verified `InvoiceSettlement`; never upload/install/execute tenant code.
- Never give the payment callback routes a session, cookies or the `web` group, and never trust their payload.
- Never store gateway credentials in the database, show them, log them or put them in an exception message.
- Never call `Storage::disk()` with a hand-built tenant path, and never put tenant data in `cache.central` or
  platform data in the tenant-prefixed `Cache::`.
- Never set `SESSION_DOMAIN` to a parent domain. Sessions are host-only.
- Never store a class name in a morph column, a token in the clear, or a secret unencrypted. Never log them.
- Never call `env()` outside `config/`.
- Never run `migrate:fresh`, `db:wipe`, truncation or any destructive operation on anything but the disposable
  test database. Never move tenant data without explicit authorization and a verified backup.
- Never commit `.env` or credentials.
- Never add a GPL/AGPL/commercial dependency, or any dependency without the compatibility and licence check.
- Never present a placeholder, fake or simulated integration as working. Fakes are bound only in `testing`/`local`.
- Never execute tenant-supplied code or expressions.
- Never use `v-html` with user content, raw Tailwind palette colours, gradient text or emoji in the UI.
- Never show a control for something the person cannot do or that does not exist yet.
- Never document a feature that does not exist beyond a short "Planned (Phase N)" note.
- Never start the next phase in the same session, and never change a locked decision silently: stop, explain,
  propose, wait.
