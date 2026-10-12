# Architecture

State: Phases 1–6, including the App Store, CRM, Projects and Invoicing, are implemented; the HR Management
backend (Phase 7, part A) is built. This page describes what is
in the code. Anything not built is listed in [section 12](#12-not-built-yet). Decisions are in `docs/adr/`.

## 1. Shape

- A modular monolith: one Laravel 13 application, one shared MySQL database, `tenant_id` on tenant-owned tables.
- Server-side routing with Inertia 3 + Vue 3 pages. There is no public JSON API, WebSocket server or SSR yet.
  Small same-origin JSON helpers, such as scoped contact-duplicate detection, remain behind workspace auth and
  permissions; the two payment gateway callbacks are the only stateless public endpoints.
- Three areas, each on its own kind of host, each with its own middleware group, guard and Inertia layout.

## 2. Areas and hosts

| | Central site | Workspace (tenant) | Super Admin (platform) |
|---|---|---|---|
| Host | `APP_DOMAIN` | `{tenant}.APP_DOMAIN` | `{ADMIN_SUBDOMAIN}.APP_DOMAIN` (default `admin.`) |
| Route file | `routes/central.php` | `routes/tenant.php` (core), `routes/tenant-callbacks.php` (stateless gateway callbacks) and each module's own file | `routes/platform.php` |
| Registered in | `bootstrap/app.php` (`then:`) | `ModuleServiceProvider::loadTenantRoutes()` | `bootstrap/app.php` (`then:`) |
| Middleware groups | `web`, `central` | `web`, `tenant` (+ `entitled:{module}` for non-core modules) | `web`, `platform` |
| Route name prefix | `central.` | none; routes name themselves (`core.*`, `auth.*`, `{module}.*`) | `platform.` |
| Guard → provider | none (no sign-in; sign-up only) | `web` → `users` | `platform` → `platform_users` |
| Tenant context | none (ended if one leaked) | resolved from the subdomain | none; default guard switched to `platform` |
| Inertia middleware | `HandleCentralInertia` | `HandleTenantInertia` | `HandlePlatformInertia` |
| Pages (under `resources/js`) | `Core/Pages/Central/*` | `Core/Pages/*`, `Modules/<M>/Pages/*` | `Platform/Pages/*` |

Host rules live in `app/Core/Tenancy/Support/TenantHost.php`:

- A tenant host is exactly one label in front of `APP_DOMAIN`, matching `[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?`,
  and not reserved. Reserved labels are `platform.reserved_subdomains` plus the admin subdomain.
- The `{tenant}` route pattern carries a negative look-ahead for the reserved labels, so `admin.` and `www.` can
  never match a workspace route. An unknown label is a 404.
- `TenantHost::url($tenant, $path)` builds absolute workspace URLs from `APP_URL`, so scheme and port are right.

The gateway callbacks are the one exception to the workspace column: they run on the workspace host in the
`tenant.callback` group (tenant resolution and a throttle, no `web` group), because a gateway returns the payer
with a cross-site POST that carries no session cookie (ADR 0006).

Routes with no domain constraint answer on every host: `/up` (health), `/storage/{path}` (Laravel's signed
URLs for the private `local` disk). Horizon is mounted on the admin host at `/horizon`. `routes/web.php` holds
only a comment and is **not loaded**.

## 3. Request lifecycle

Order below is the effective one after Laravel's middleware-priority sort, not the order of declaration.
`bootstrap/app.php` puts the three context middleware ahead of authentication in the priority list, so the
context is settled before auth and before route-model binding. `tests/Feature/Tenancy/RoutePipelineTest.php`
asserts it with `Router::gatherRouteMiddleware()` for every workspace route, the admin panel (Horizon included)
and the central site.

**Workspace, signed-in route** (`['web', 'tenant']` + `['auth:web', 'auth.session', 'member']` + `subscribed` + optional `can:`):

| # | Middleware | What it does |
|---|---|---|
| 1 | `web` group | Cookies, session, CSRF (`PreventRequestForgery`), and `SecurityHeaders` on the way out |
| 2 | `InitializeTenancy` | Host → label → tenant, 404 if none. Starts the tenant context (runs the bootstrappers) and removes the `{tenant}` route parameter |
| 3 | `auth:web` | Guests go to `auth.login` |
| 4 | `throttle:*` | Where declared. Counts are per tenant (`TenantAwareRateLimiter`) |
| 5 | `auth.session` | Ends sessions whose password hash is stale |
| 6 | `SubstituteBindings` | Route-model binding, already tenant-scoped |
| 7 | `SetLocale` | User's locale → `locale` cookie → tenant default |
| 8 | `HandleTenantInertia` | Shared props: `app`, `tenant`, `auth`, `navigation`, `workspaces` |
| 9 | `EnsureTenantIsActive` | Pending or suspended workspace → 403 page |
| 9a | `EnforceSupportSession` | Only for a support session: ends it when its time is up, refuses routes that act on the impersonated person |
| 10 | `entitled:{module}` | Non-core modules only → 404 if the tenant is not entitled |
| 11 | `member` (`EnsureTenantMember`) | Session must be bound to this tenant and the user an active member; else sign out and redirect. Binds a session restored by "remember me" if it was issued here. Sets the `membership` request attribute |
| 11a | `subscribed` (`EnsureWorkspaceIsSubscribed`) | The paywall: a workspace whose subscription has ended is redirected to billing (or shown a 402 page). Not on sign-out, personal settings and billing routes |
| 12 | `can:{permission}` | `Gate::before` → `AccessChecker` |

Guest workspace routes (`guest:web`) run 1, 2, 4, 6–9 and then the guest check.
Projects' two public portal routes omit the auth/member middleware and instead authorize the current invitation
capability in the controller; they still run the tenant, entitlement, binding, paywall and throttle layers.

**Super Admin:** `web` → `InitializePlatformContext` (ends any tenant context, `Auth::shouldUse('platform')`) →
`auth:platform` → `SubstituteBindings` → `SetLocale` → `HandlePlatformInertia` → `platform.2fa`
(`EnsurePlatformTwoFactor`, redirects to enrolment) → `can:platform.*`. The panel's language is a cookie; platform
accounts have no stored language.

**Central:** `web` → `EnsureCentralContext` → `SubstituteBindings` → `SetLocale` → `HandleCentralInertia`.

**Errors:** `App\Core\Support\ErrorPageResponder` renders `Core/Pages/Errors/Error` for 403, 404, 429, 500 and
503 (5xx keep the framework page while `APP_DEBUG` is on) and turns 419 into a redirect back with a toast.
Guests are redirected by host: the admin host to `platform.login`, everything else to `auth.login`.

## 4. Code layout

```
app/
  Core/
    Tenancy/        context, host rules, scope, bootstrappers, storage, provisioning, job guard, rate limiter
    Identity/       User, TenantMembership, Invitation, sign-in, 2FA, workspace handoff, team + settings controllers
    Access/         Role, Permission, AccessChecker, PermissionResolver, RoleManager, RecordAccess (scopes),
                    HasRecordOwner, permissions:sync
    Organization/   Branch, Department and their page
    Contacts/       Contact, Company (the shared customer identity), ContactDirectory, events, triggers
    Settings/       workspace profile and logo, first-run setup, PlatformSettings (operator settings)
    Registration/   sign-up on the central site: WorkspaceRegistration, StartRegistration, CompleteRegistration
    Billing/        plans, subscriptions, invoices, payments, gateways, limits, metering, billing:run
    AppStore/       compiled catalogue sync, immutable versions, installations, licences, app billing/lifecycle
    Modules/        ModuleManifest, ModuleRegistry, ModuleServiceProvider, navigation, widget and limit definitions
    Entitlements/   EntitlementChecker contract + PlanEntitlementChecker
    Automation/     trigger/action contracts, AutomationRegistry, the one core trigger
    Events/         DomainEvent, TenantDomainEvent
    Audit/          AuditLog, AuditLogger, Auditable
    Dashboard/      DashboardBuilder and the core widgets
    Http/, Support/ Inertia middleware, SecurityHeaders, locale, Toast, error pages, Like (LIKE escaping)
    CoreServiceProvider.php   the `core` module's manifest and bindings
  Platform/         Super Admin panel: PlatformUser, PlatformRole, controllers, middleware, SystemHealth,
                    Tenants/TenantManager, Impersonation/ (support access), Console/CreatePlatformAdminCommand
  Providers/        AppServiceProvider (framework guards), HorizonServiceProvider
routes/             central.php, tenant.php, tenant-callbacks.php, platform.php, console.php (the schedule)
config/             platform.php, tenancy.php, modules.php, permission.php, trustedproxy.php, horizon.php,
                    ziggy.php (+ framework)
database/           migrations/, factories/, seeders/
lang/               en/, bn/, bn.json (server-side strings: validation, mail, toasts)
resources/js/       Core/ (components, layouts, pages, composables, i18n, types), Platform/ (pages, components);
                    app.ts also resolves Modules/<Module>/Pages/**. Guide: resources/js/README.md
tests/              Feature/, Unit/, Support/ (helpers, fixtures), js/ (Vitest)
.github/workflows/  ci.yml
```

`app/Modules/CRM` is the first production optional business module. It owns its provider, manifest, migrations,
models, domain services, routes, Inertia pages, automation definitions and tests. Billing, contacts and the
organisation chart remain in `core`: every workspace has them, and billing has to stay reachable when nothing
else is. CRM references the shared core contacts/companies for customer content, timelines and segments, uses
exact commercial arithmetic and durable automation action receipts, and publishes `crm.quote.accepted` as the
Phase 6 invoicing boundary; see `docs/CRM.md`.

`app/Modules/Projects` is the second production optional business module. It reuses core customer and membership
identity, applies the same tenant/record-scope boundary to projects and tasks, and owns planning, collaboration,
time, resource, budget, approval, reporting and client-access records. Its CRM, Invoicing, HRM and notification
connections remain event/contract hand-offs rather than model imports; see `docs/PROJECTS.md`.

`app/Modules/Invoicing` is the third production optional business module: customer invoices, manually recorded
payments, credit notes, recurring invoices, reminders and receivables reporting. It is unrelated to the platform's
own billing in `app/Core/Billing`. It consumes `crm.quote.accepted` and `projects.time_entry.approved` through
listeners that read only the event payload, and references shared contacts/companies by id; see
`docs/INVOICING.md`.

`app/Modules/Hrm` is the fourth production optional business module: employee records with an immutable employment
history, shifts and exact-minute attendance, leave policies with an append-only balance ledger and two-step
approval, and HR reports. Payroll and Talent will depend on it and read it only through the contracts in
`app/Core/Workforce` (`EmployeeDirectory`, `EmployeeTimeSummary`, `EmployeeSnapshot`); see `docs/HRM.md`.

`app/Modules/Payroll` is the fifth production optional business module (key `payroll`, depends on `core` and
`hrm`): salary structures and immutable salary revisions, pay periods, runs with four-eyes approval that lock
immutable payslip snapshots, loans, one-off items, data-driven tax/contribution rule sets behind the pluggable
`PayrollRuleSet` contract, payment tracking and reports. It consumes only the `Workforce` contracts and HRM events;
see `docs/PAYROLL.md`.

`app/Modules/Talent` is the sixth production optional business module (key `talent`, depends on `core` and `hrm`):
job openings, a configurable candidate pipeline with immutable move history, interview panels with blind scorecards,
offer tracking, onboarding/offboarding checklists started by HRM employee events, probation tracking with
reminders, review cycles with exact basis-point weights and calibration, goals and KPIs, training with certificate
expiry, and HR analytics. It reads employees only through `EmployeeDirectory` and HRM's events and announces
`talent.candidate.hired` for HRM (or an operator) to act on; see `docs/TALENT.md`.

`app/Modules/Automation` is the Workflow Automation app (key `automation`, category Workflow Automation, free,
depends only on `core`): the central engine of spec section 13. Workflows are versioned JSON graphs (a published
version is an immutable, hashed snapshot); `TriggerBridge` follows the domain event of every registered trigger and
starts runs for the event's own tenant, `RunExecutor` advances a run one persisted step at a time on the `automation`
queue under the member who published it (permission and entitlement re-checked before every step), and a per-minute
`Sweeper` (a scheduled job, entitlement-gated like every module's) resumes delays, retries, approvals and abandoned
runs, so nothing waits in memory. Inbound webhooks are HMAC-signed, replay-windowed routes in the stateless
`tenant.callback` group; outbound calls go only to registered endpoints through an SSRF guard. Tenant code and
expressions are never evaluated: conditions use a typed operator registry and data mapping a fixed operation list.
See `docs/WORKFLOWS.md`.

`app/Modules/Ai` is the paid AI Assistant app (key `ai`, category AI Tools, depends only on `core`): a private
assistant chat, text-only drafting and an `ai.text.generate` workflow action over the `AiProvider` contract (one
real Anthropic Messages adapter using a platform-level credential; a scripted fake bound only in `local` and
`testing`). Data access goes through `App\Core\Ai\Contracts\AiTool` implementations tagged in the container, run as
the signed-in member under `AccessChecker` / `RecordAccess`; a tool that would change data only produces a
proposal a person confirms. Every provider call passes `AiGateway` (limits, rate limits, circuit breaker, redaction,
idempotent token metering on the `ai` queue's `ai.tokens` limit). See `docs/AI.md`.

`app/Modules/Social` is the Social Media app (key `social`, depends only on `core`): connected social accounts behind
the `SocialPlatform` contract (OAuth state machine, encrypted tokens, refresh and re-authorisation handling),
posts with per-account variants, a calendar, an approval workflow with four-eyes, a private media library, a
scheduled publisher with retry/backoff and per-account and per-workspace rate limits, CSV bulk scheduling and
analytics that report only what a platform returned. Adapters exist for Facebook Pages, LinkedIn (text) and X (text)
and are unverified against the live platforms; other platforms are listed as unavailable with the reason. See
`docs/SOCIAL.md`.

`app/Modules/SmsMarketing` is the SMS Marketing app (key `sms_marketing`, depends on `core` and `communication`):
bulk and scheduled SMS campaigns to contact groups (lists of shared-contact references), templates with a fixed
variable allow-list, recorded sender ids, a Unicode/Bengali-aware segment counter that drives estimates and the plan
limit, delivery status from the core `MarketingMessageStatusChanged` event and bounded retries. It never imports a
Communication model and never talks to a provider: it sends through the core `ChannelGateway`, asks
`MarketingAudience` and issues opt-out links with `UnsubscribeLinks`. A `RunCampaigns` job on queue `campaigns`
(every minute, entitlement-gated, per-workspace lock) builds recipients and hands out a bounded batch with idempotent
per-attempt keys. No SMS provider adapter exists yet, so the channel is `not_configured` and campaigns cannot be
launched; the screens say so. See `docs/SMS_MARKETING.md`.
`app/Modules/EmailMarketing` is the Email Marketing app (key `email_marketing`, depends on `core` and
`communication`): lists and declarative segments over shared contacts, a block editor that renders escaped HTML and
plain text, campaigns with scheduling, A/B subjects and click measurement, welcome/drip sequences, CSV import and
export, and reports. It never imports a Communication model: it sends through `ChannelGateway`, asks
`MarketingAudience` and `UnsubscribeLinks`, and mirrors the platform's delivery, bounce and unsubscribe events. A
`RunEmailMarketing` job on queue `campaigns` (every minute, entitlement-gated) prepares audiences in chunks and hands
out a bounded number of emails per pass with idempotent per-recipient keys. The platform mailer sends plain text, so
opens are not measured. See `docs/EMAIL_MARKETING.md`.

`app/Modules/WhatsappMarketing` is the WhatsApp Business app (key `whatsapp_marketing`, depends on `core` and
`communication`): approved-template campaigns to lists of shared contacts, a shared inbox that enforces the 24-hour
customer-service window on the server, agent assignment with record scopes, quick replies, declarative chatbot flows,
delivery/read tracking and two reports. It never imports a Communication model: it sends through the core
`ChannelGateway`, asks `MarketingAudience` for opt-in decisions and receives provider events through the core
`ChannelEventListener` feed that the communication app raises after it verified and applied them. The WhatsApp Cloud
API adapter lives in the communication app's provider registry and has not been exercised against live Meta. See
`docs/WHATSAPP.md`.

`app/Modules/Support` is the Customer Support app (key `support`, depends only on `core`): tickets that reference
shared contacts and companies, queues and rule-based or round-robin assignment, a thread with public replies and
internal notes, SLA policies on business-hours calendars (`BusinessCalendar` is pure and unit-tested), single-use
satisfaction links, an internal knowledge base, merge/split and reports. It publishes
`Contracts\InboundTicketReceiver` for future channel apps; see `docs/SUPPORT.md`.

## 5. Module boundaries and the module contract

- A module is one service provider extending `App\Core\Modules\ModuleServiceProvider`, listed in
  `config/modules.php`. `describe()` returns a `ModuleManifest`; `bootModule()` loads routes and migrations.
- The foundation is itself a module: key `core`, `core: true`, declared in `App\Core\CoreServiceProvider`.
- CRM is key `crm`, depends on `core`, and is available only through a healthy plan or effective App Store
  licence. It is the production reference for scoped optional-module records and retained data on uninstall.
- Projects is key `projects`, depends on `core`, uses the same entitlement and retention rules, and is independent
  of CRM so either optional app can be installed alone.
- Invoicing is key `invoicing`, depends on `core` only, and follows the same entitlement and retention rules. It
  reacts to CRM and Projects events when those apps are present and works without them.
- HR Management is key `hrm`, depends on `core` only. Later HR apps declare `hrm` as a dependency, and use its
  `App\Core\Workforce` contracts instead of its models.
- Reports & Analytics is key `reports`, depends on `core` only, and owns no business data. Modules publish reports
  through `App\Core\Reporting\Contracts\ReportProvider` (tagged in their own provider); the app discovers them with
  `ReportRegistry`, runs only those whose module the workspace is entitled to and whose permission the member
  holds, and each provider narrows to the member's record scope. See `docs/REPORTS.md`.
- Being registered makes a module's code available. Whether a tenant may use it is a separate, server-side
  entitlement check (`EntitlementChecker`).
- A module owns its routes, controllers, models, migrations, policies, events, jobs and tests. Modules talk to
  each other through domain events and documented service contracts, never by importing another module's models.
- Shared records and services belong in `app/Core`. `app/Platform` is not a module: it is the operator's panel
  and the only place that reads across tenants on purpose.
- Boot fails with a `RuntimeException` if a manifest names a dependency that is not registered.

The manifest also owns App Store metadata and offers, route/scheduled-job inventories and lifecycle hooks. A
compiled optional manifest is mirrored to immutable catalogue versions by `apps:sync`; it is never remote code.
Field-by-field rules and a worked example are in `docs/MODULES.md`.

## 6. Shared services

| Service | Class | Lifetime | Use it for |
|---|---|---|---|
| Tenant context | `Core\Tenancy\TenantContext` | scoped | `tenant()`, `requireId()`, `run()`, `central()`, `withoutScope()` |
| Host rules | `Core\Tenancy\Support\TenantHost` | singleton | Hosts, reserved labels, workspace URLs |
| Tenant files | `Core\Tenancy\Storage\TenantStorage` | scoped | Every read or write of a tenant file |
| Connection seam | `Core\Tenancy\Support\TenantConnectionResolver` | scoped | Used by `BelongsToTenant`; not called directly |
| Central cache | container key `cache.central` | singleton | Data that must not be tenant-prefixed (handoff tokens, 2FA replay and lockout, operator settings) |
| Rate limiter | `Core\Tenancy\Support\TenantAwareRateLimiter` | singleton; answers for `Illuminate\Cache\RateLimiter` | Per-tenant counts; named limiters survive a tenant switch |
| Provisioning | `Core\Tenancy\TenantProvisioner` | per call | Create a tenant with domain, system roles and owner |
| Module registry | `Core\Modules\ModuleRegistry` | singleton | Manifests and the permission index |
| Entitlements | `Core\Entitlements\Contracts\EntitlementChecker` | bound to `PlanEntitlementChecker` (scoped) | Core + healthy plan modules + effective installed App Store licences, with dependency pruning |
| Authorization | `Core\Access\AccessChecker` | scoped | `allows()`, `scopeFor()`, `effectivePermissions()`, `membership()` |
| Role changes | `Core\Access\RoleManager` | per call | Create / update / delete roles, `canAssign()`, `flush()` |
| Audit trail | `Core\Audit\AuditLogger`, `Audit\Concerns\Auditable` | scoped / trait | `record()` for business events; the trait for attribute changes |
| Navigation, dashboard | `Core\Modules\NavigationBuilder`, `Core\Dashboard\DashboardBuilder` | per call | Entitlement- and permission-filtered sidebar and widgets |
| Automation registry | `Core\Automation\AutomationRegistry` | singleton | Catalogue of triggers and actions; the Workflow Automation app executes it (`registeredTriggers()`, `whenTriggerRegistered()` for the engine) |
| Two-factor | `Core\Identity\TwoFactor\TwoFactorService` | per call | TOTP for both guards |
| Flash messages | `Core\Support\Toast` | static | One-shot toast on the next page |
| Search filters | `Core\Support\Like` | static | `Like::contains($text)` / `Like::escape($text)` for any user text in a `LIKE` |
| Member changes | `Core\Identity\Actions\MemberManager` | per call | Change role, suspend, remove, place in a branch and department; `canManage()` |
| Record scopes | `Core\Access\RecordAccess` | per call | Narrow a query or one record to the scope a permission was granted with |
| Contact directory | `Core\Contacts\ContactDirectory` | per call | The single write path for contacts and companies (audit + event) |
| Workspace profile | `Core\Settings\UpdateWorkspaceProfile`, `WorkspaceLogo` | per call | Company profile, regional settings, logo |
| Operator settings | `Core\Settings\PlatformSettings` | scoped | Registration mode, grace days, invoice lead days, offline payment instructions |
| Subscriptions | `Core\Billing\SubscriptionManager` | per call | Every change to a workspace's subscription; `settle()` is the only place a paid plan starts |
| Billing cycle | `Core\Billing\BillingCycle` | per call | What time does to a subscription; run by `billing:run` |
| App catalogue | `Core\AppStore\AppCatalogSynchronizer` | per call | Mirror compiled optional manifests into immutable catalogue versions (`apps:sync`) |
| App lifecycle | `Core\AppStore\AppStoreManager` | per call | Install, licence, activate/deactivate/uninstall, settle paid app invoices |
| App billing cycle | `Core\AppStore\AppBillingCycle` | per call | Recurring app invoice, grace, cancellation and expiry; also run by `billing:run` |
| Payments | `Core\Billing\PaymentProcessor`, `Gateways\GatewayRegistry` (singleton) | per call | Start, confirm and record payments |
| Current subscription | `Core\Billing\CurrentSubscription` | scoped | The active workspace's subscription, read once per request |
| Limits, metering | `Core\Billing\UsageLimits`, `UsageMeter` | per call | A plan's caps and idempotent usage records |
| Workspace handoff | `Core\Identity\Auth\WorkspaceHandoff` | per call | One-time token that carries a just-authenticated person to another workspace host |
| Workspace lifecycle | `Platform\Tenants\TenantManager` | per call | Create, approve, suspend, reactivate (panel only) |
| Support access | `Platform\Impersonation\ImpersonationManager` | per call | Open, redeem and end a support session |

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
| `BelongsToTenant` | Tenant-owned: read scope, write guards, tenant connection | `Invitation`, `Contact`, `Company` |
| `BelongsToTenant` + `UsesCentralConnection` | Tenant-scoped rows that always stay in the central database | `TenantMembership`, `Branch`, `Department`, `Subscription`, `Invoice`, `Payment`, `UsageRecord`, `AppInstallation`, `AppEntitlement`, `SubscriptionItem` |
| `ScopedToTenant` | Read scope only; `tenant_id` may be null (platform events) | `AuditLog` |
| `UsesCentralConnection` | Platform data, no tenant scope | `User`, `PlatformUser`, `Permission`, `Plan`, `CatalogApp`, `AppVersion`, `WorkspaceRegistration`, `Impersonation` |
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
                  3. EntitlementChecker::allows(tenant, module)      ← plan or effective App Store licence
                  4. the user has an active membership in this tenant
                  5. membership.is_owner (scope "all"), or PermissionResolver::grantsFor(user)
                     contains it - with the scope it was granted for
```

- `PermissionResolver` runs one query (`model_has_roles` ⋈ `roles` ⋈ `role_has_permissions` ⋈ `permissions`,
  filtered by `tenant_id` twice) and caches it for 10 minutes under `access:grants:{version}:{user}` in the
  tenant's cache namespace. `RoleManager::flush()` bumps the version.
- The `permissions` and `navigation` props sent to the browser are a courtesy to the UI. Every route checks again.
- There are no policy classes: checks are `can:` middleware on routes plus rules inside `RoleManager`
  (nobody grants, re-scopes or removes a permission they do not hold at least as widely) and `MemberManager`
  (nobody changes, suspends, places or removes a member who holds a permission they lack).
- **Scopes.** A grant of a scopable permission reaches `own`, `department`, `branch` or `all` records.
  `AccessChecker::scopeFor()` returns the widest scope a person's roles give; `RecordAccess::constrain()` /
  `allows()` / `authorize()` apply it to models using `HasRecordOwner` (today `Contact` and `Company`). The
  controller calls it; the route only names the permission (ADR 0007).
- **Entitlement and paywall are different questions.** `PlanEntitlementChecker` decides which modules a workspace
  has from core, its healthy plan and installed licences. The `subscribed` middleware decides whether a workspace
  whose base subscription ended may be worked in at all. App commercial items do not replace the base plan.

## 9. Events

- `Core\Events\DomainEvent` is the contract: `name()` (`{module}.{entity}.{verb}`, past tense), `tenantId()`,
  `occurredAt()`, `payload()` (ids and scalars, never models).
- `Core\Events\TenantDomainEvent` is the base class. It captures the active tenant at construction (throws without
  one) and implements `ShouldDispatchAfterCommit`.
- Existing events: `core.member.invited`, `core.member.joined`, `core.member.removed`
  (`app/Core/Identity/Events`); `core.contact.created|updated|deleted|merged`, `core.company.created|updated|deleted`
  (`app/Core/Contacts/Events`); `core.subscription.updated`, `core.invoice.paid` (`app/Core/Billing/Events`);
  `core.app.installed|activated|deactivated|upgraded|uninstalled` (`app/Core/AppStore/Events`).
- CRM events: `crm.lead.created`, `crm.lead.qualified`, `crm.deal.created`, `crm.deal.stage_changed`, `crm.deal.won`,
  `crm.activity.created`, `crm.activity.completed`, `crm.quote.accepted` (`app/Modules/CRM/Events`).
- Projects events: `projects.project.created`, `projects.task.created`, `projects.task.completed`,
  `projects.task.reminder_due`, `projects.milestone.completed`, `projects.time_entry.approved`
  (`app/Modules/Projects/Events`).
- Invoicing events: `invoicing.invoice.issued`, `invoicing.invoice.paid`, `invoicing.payment.received`,
  `invoicing.payment.reversed`, `invoicing.credit_note.issued`, `invoicing.invoice.overdue`
  (`app/Modules/Invoicing/Events`). They are the only events with listeners today: Invoicing subscribes to
  `crm.quote.accepted` and `projects.time_entry.approved`.
- The automation catalogue exposes three core triggers (`core.member.joined`, `core.contact.created`,
  `core.company.created`), four CRM triggers (`crm.lead.created`, `crm.lead.qualified`, `crm.deal.won`,
  `crm.activity.completed`) and four Projects triggers (`projects.project.created`, `projects.task.created`,
  `projects.task.completed`, `projects.milestone.completed`), five Invoicing triggers (`invoicing.invoice.issued`,
  `invoicing.invoice.paid`, `invoicing.payment.received`, `invoicing.credit_note.issued`,
  `invoicing.invoice.overdue`) and one Invoicing action (`invoicing.invoice.create_draft`), five HRM triggers (`hrm.employee.created`,
  `hrm.employee.terminated`, `hrm.leave.requested`, `hrm.leave.approved`, `hrm.attendance.day_closed`) and two HRM
  actions (`hrm.leave.grant_balance`, `hrm.employee.assign_manager`).
- HRM events: `hrm.employee.created|updated|terminated`, `hrm.leave.requested|approved`,
  `hrm.attendance.day_closed` (`app/Modules/Hrm/Events`); Payroll's `RefreshEmployeePlacement` listens to the three
  employee events (by class name) to keep its copied placement and account link current.
- Payroll adds three triggers (`payroll.run.approved`, `payroll.payslip.paid`, `payroll.run.reversed`) and two
  actions (`payroll.item.create`, `payroll.run.prepare_draft`); nothing that approves or moves money is an action.

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
- Queued mail today: `InvitationMail`, `ConfirmRegistrationMail` (platform mail, no tenant) and
  `WorkspaceReadyMail` on `notifications` (encrypted payloads: they carry or create a one-time token);
  `InvoiceIssuedMail` and `PaymentReceivedMail` on `billing`. The password-reset link is not queued: it is sent
  after the response with `defer()`, inside the workspace's context.
- Queued module maintenance today: the entitlement-gated app scheduler dispatches Projects'
  `MaintainProjectTasks` every minute on `automation`; it creates due task recurrences and emits reminder events.
  Invoicing's `GenerateRecurringInvoices` (hourly, `automation`) creates due recurring drafts and
  `MaintainReceivables` (hourly, `notifications`) marks invoices overdue and sends payment reminders. `InvoiceMail`
  (customer invoices and reminders, `notifications`) is queued in the workspace's locale. HRM's
  `CloseAttendanceDays` (hourly) closes finished attendance days and `AccrueLeave` (daily) posts leave accruals,
  both on `automation`, both idempotent. Communication's `SendMessage` (one outbox message, `notifications`,
  after commit) is idempotent per row; `DispatchDueMessages` (every minute) re-queues due or stuck messages and
  `CleanupCommunication` (daily) forgets expired tokens and old provider event ids. Its stateless callbacks use
  `ModuleServiceProvider::loadTenantCallbackRoutes()` (the `tenant.callback` group plus the module's entitlement).
  Workflow Automation's `ExecuteRun` (one run, at most ten persisted steps per job, then re-queued) runs on
  `automation`; its `SweepRuns` (every minute, scheduled job `automation.sweep`) re-dispatches due, stalled and
  decided runs and fires cron schedules, which is why a delay of days survives a deploy.
- Scheduled (`routes/console.php`): `billing:run` hourly, which handles base and app subscriptions inside each
  tenant context; `apps:run-scheduled` every minute, which dispatches only compiled jobs of entitled apps through
  a worker-time compiled-definition and entitlement check; and
  `model:prune` daily for sign-ups that were never confirmed.

## 11. Dedicated tenant databases (summary)

Not implemented. What exists is the seam: `tenants.db_connection` (null = shared), `TenantConnectionResolver`,
and `BelongsToTenant::getConnectionName()`, which asks the resolver on every query. Platform models are pinned
with `UsesCentralConnection`. ULID keys mean rows can be copied between databases without re-keying. The full
strategy, and the gaps that must be closed before the first move, are in `docs/DATABASE.md`.

## 12. Not built yet

| Area | Phase | Today |
|---|---|---|
| Email verification for an existing account, changing an email address | — | A new account is verified by its sign-up or invitation link; there is no way to change an address |
| Coupons, refunds, tax, usage-based charge calculation | later | See `docs/BILLING.md` section 7 |
| A verified payment gateway | when credentials exist | SSLCommerz is implemented but has not been run against the sandbox |
| API tokens (Sanctum) | when first needed | Not installed; there are no API routes |
| Integration centre (OAuth connections, webhooks) | not assigned in spec 27 | — |
| Further optional production apps | 7+ | CRM, Projects, Invoicing and HR Management are compiled; Payroll, Talent and later apps remain phased work |
| Customer-facing payment gateway for invoices | owner decision | Invoicing records manual payments only; per-tenant gateway credentials cannot live in the database (CLAUDE.md), see `docs/INVOICING.md` |
| Notification centre, message outbox, email, interaction timeline | 8 | Built as the optional `communication` app (`docs/COMMUNICATION.md`); other modules are not rewired to call its `Notifier` / `InteractionRecorder` contracts yet |
| SMS and WhatsApp providers | 8 | Interfaces and an honest `not_configured` adapter only; no real provider |
| Realtime (Reverb) | later | Not installed; `BROADCAST_CONNECTION=log` |
| Automation engine | 10 | Built as the optional `automation` app, backend and Vue Flow editor (`docs/WORKFLOWS.md`); notification/messaging actions and run-history retention are not built |
| General file manager | — | `TenantStorage` backs workspace logos and CRM/Projects private attachments; no general browser exists |
| Global data search | — | Scout installed (`database` driver), no searchable model; Ctrl/Cmd+K is navigation only. Contacts are searched with `LIKE` on their own page |
| Custom domains | future | `tenant_domains.type` exists, no routing |
| Support access as a member other than the owner | — | Always the owner |
