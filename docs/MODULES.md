# Modules

How to add a first-party module. The contract is in `app/Core/Modules`; the build registers `core` and seventeen
optional apps (section 0). `hrm` is the reference for a module that other apps build
on: it publishes read contracts under `App\Core\Workforce` so that dependents never import its models.
`payroll` (`docs/PAYROLL.md`) is the reference for a dependent app: it declares `hrm` in `dependencies`, reads HR
data only through those contracts, and consumes HRM events by class name through a documented listener.
`talent` (`docs/TALENT.md`) is the second dependent app (recruitment, onboarding, performance, training); it adds
no HRM model import either and shows the employee-bound record pattern (`Placed` trait: owner, department and
branch copied from an `EmployeeSnapshot` so `own` / `department` / `branch` scopes work without an HRM model).
`communication` (`docs/COMMUNICATION.md`) is the reference for a module that exposes **core contracts**
(`Notifier`, `InteractionRecorder`) so that other apps use it without importing its models, for **provider
adapters** behind interfaces with an honest `not_configured` state, and for **stateless callback routes**
(`loadTenantCallbackRoutes()`: tenant from the hostname, throttled, no session, plus the module's entitlement).
`support` (`docs/SUPPORT.md`) is the reference for a module that exposes a *write* contract to future channel apps
(`Contracts\InboundTicketReceiver`), for a pure, unit-tested domain helper (`BusinessCalendar`), for a public
stateless single-use token page (`loadTenantCallbackRoutes()`, a Blade view) next to authenticated ones, and for assignment-by-queue records: its ticket model uses the
record-owner column contract without the "creator becomes owner" hook (`Models\Concerns\HasAssignee`), because the
assignee is not the person who filed the ticket.
`ai` (`docs/AI.md`) is the reference for a paid app and for AI access to data: it depends on `core` only, declares
monthly/yearly/trial offers and a metered `ai.tokens` limit, and reads other modules through
`App\Core\Ai\Contracts\AiTool` implementations that a module tags in its own provider (`AiTool::TAG`); a tool is
read-only and runs as the member, a change is a `ProposalTool` confirmed by a person.
`automation` (`docs/WORKFLOWS.md`) is the reference for a module that **consumes other modules' contracts**: it
depends only on `core`, never imports another module's class, learns triggers and actions from
`AutomationRegistry` at run time, subscribes to their domain events, re-checks each definition's permission and
entitlement before every step it executes, and registers its own engine-raised triggers (`event()` is null),
a per-minute scheduled sweep and stateless inbound webhook routes through `loadTenantCallbackRoutes()`.
A small non-core App Store fixture remains in `tests/Support/Fixtures/Crm` under the distinct
module key `test_crm`.

`social` (`docs/SOCIAL.md`) is the reference for a module that talks to outside services: a provider contract
(`SocialPlatform`) with capability flags, adapters bound by configuration, an honest `unavailable` state, encrypted
tokens and a scheduled publisher that goes through the entitlement-gated runner.
`sms_marketing` (`docs/SMS_MARKETING.md`) is the reference for a **marketing app on the communication contracts**:
it depends on `core` and `communication`, imports no Communication class, sends only through
`ChannelGateway` / `MarketingAudience` / `UnsubscribeLinks`, learns outcomes from the core
`MarketingMessageStatusChanged` event, shows an honest `not_configured` state until a provider adapter exists,
meters its own metered and gauge limits, bounds every send (rate, daily cap, attempts) and publishes report
providers and an automation action with receipts.`email_marketing` (`docs/EMAIL_MARKETING.md`) is the reference for a **marketing app that sends only through the core
communication contracts** (`MarketingAudience`, `UnsubscribeLinks`, `ChannelGateway`): its recipients are shared
contacts (a subscriber is a list membership, never a second identity), consent and suppression are decided by the
platform at send time and mirrored back from `communication.marketing.message_status_changed` and
`communication.contact.unsubscribed`, and campaigns run in bounded, idempotent chunks on the `campaigns` queue from a
minute runner. It also shows a structured block editor with no free HTML, signed stateless tracking links and CSV
import that never grants consent.
`reports` (`docs/REPORTS.md`) is the reference for a module that **aggregates other modules' read models**
without importing them: it depends on `core` only and runs `App\Core\Reporting\Contracts\ReportProvider`
implementations that each module tags in its own provider (`ReportProvider::TAG`). A provider declares its columns,
filters and permission; the app validates a request against that declaration, runs the provider as the member
(only for entitled modules and held permissions, narrowed by `RecordAccess` when the permission is scopable), caps
the rows and exports a formula-safe CSV.
`whatsapp_marketing` (`docs/WHATSAPP.md`) is the reference for a **marketing app built only on core contracts**: it
depends on `core` and `communication`, sends through `ChannelGateway`, decides audiences with `MarketingAudience`,
hears inbound messages, read receipts and template status through `ChannelEventListener` (tagged in its own
provider) and has no connection, credential or webhook route of its own. It also shows the assignee-owned record
pattern (`Models\Concerns\HasAssignee`, an enrolled agent also reaches unassigned records), a closed declarative
vocabulary for tenant-authored bot flows, and a chunked, per-recipient-idempotent sender on the `campaigns` queue.

## 0. Registered modules

The registry (`config/modules.php`) holds **`core` plus 19 optional modules and 275 declared permissions**. `php artisan permissions:sync`
writes the permissions; `apps:sync` writes the catalogue. Only `ai` is a paid app; every other optional app has one
free offer (`included` or `free`). Counts below come from the compiled manifests.

| Key | Name | Depends on | Permissions | Limits | Scheduled jobs (key, frequency) | Triggers / actions | Report providers | Doc |
|---|---|---|---|---|---|---|---|---|
| `core` | Workspace | none (always on) | 16 | `core.members` | none | 3 / 0 | none | `ARCHITECTURE.md` |
| `crm` | CRM & Sales | `core` | 24 | none | none | 4 / 3 | 3 | `CRM.md` |
| `projects` | Project Management | `core` | 19 | `projects.active_projects` | `projects.task_maintenance` (minute) | 4 / 3 | 2 | `PROJECTS.md` |
| `invoicing` | Invoicing | `core` | 12 | `invoicing.invoices_per_month` | `invoicing.recurring_invoices`, `invoicing.receivables` (hourly) | 5 / 1 | 2 | `INVOICING.md` |
| `hrm` | HR Management | `core` | 20 | `hrm.employees` | `hrm.attendance_close` (hourly), `hrm.leave_accrual` (daily) | 5 / 2 | 3 | `HRM.md` |
| `payroll` | Payroll | `core`, `hrm` | 22 | `payroll.payslips` | none | 3 / 2 | 1 | `PAYROLL.md` |
| `talent` | Talent & Performance | `core`, `hrm` | 30 | `talent.job_openings`, `talent.candidates` | `talent.reminders`, `talent.retention` (daily) | 9 / 2 | 1 | `TALENT.md` |
| `automation` | Workflow Automation | `core` | 8 | `automation.runs`, `automation.workflows` | `automation.sweep` (minute) | 3 / 0 | 1 | `WORKFLOWS.md`, `AUTOMATION.md` |
| `ai` | AI Assistant (paid) | `core` | 5 | `ai.tokens` | `ai.retention` (daily) | 1 / 1 | none | `AI.md` |
| `communication` | Communication | `core` | 13 | `communication.messages` | `communication.dispatch_due` (minute), `communication.cleanup` (daily) | 4 / 2 | none | `COMMUNICATION.md` |
| `email_marketing` | Email Marketing | `core`, `communication` | 18 | `email_marketing.campaigns_per_month`, `email_marketing.subscribers` | `email_marketing.run` (minute) | 2 / 1 | 2 | `EMAIL_MARKETING.md` |
| `sms_marketing` | SMS Marketing | `core`, `communication` | 13 | `sms_marketing.segments_per_month`, `sms_marketing.contacts` | `sms_marketing.run_campaigns` (minute), `sms_marketing.reconcile` (hourly) | 2 / 1 | 2 | `SMS_MARKETING.md` |
| `whatsapp_marketing` | WhatsApp Business | `core`, `communication` | 16 | `whatsapp_marketing.conversations_per_month`, `whatsapp_marketing.agents` | `whatsapp_marketing.campaigns` (minute), `whatsapp_marketing.maintenance` (daily) | 4 / 3 | 2 | `WHATSAPP.md` |
| `social` | Social Media | `core` | 23 | `social.scheduled_posts_per_month`, `social.connected_accounts` | `social.publish_due` (minute), `social.lead_ads_sweep` (minute), `social.maintenance` (hourly) | 7 / 1 | 2 | `SOCIAL.md` |
| `meta_ads` | Meta Ads | `core`, `social` | 7 | `meta_ads.ad_accounts` | `meta_ads.sync` (hourly) | 1 / 0 | 2 | `META_ADS.md` |
| `support` | Customer Support | `core` | 16 | `support.tickets_per_month`, `support.agents` | `support.sla` (minute), `support.maintenance` (daily) | 6 / 3 | 3 | `SUPPORT.md` |
| `reports` | Reports & Analytics | `core` | 4 | `reports.saved_reports` | none | 0 / 0 | consumes all | `REPORTS.md` |
| `notes` | Notes | `core` | 2 | none | none | 1 / 0 | 1 | `NOTES.md` |
| `telephony` | Telephony | `core` | 3 | none | none | 1 / 0 | 1 | `TELEPHONY.md` |
| `links` | Links | `core` | 2 | none | none | 1 / 0 | 1 | `LINKS.md` |

Cross-module rules this build established (each is enforced by code and tests, not by convention alone):

- **Optional apps never import another app's models.** They talk through core contracts under `App\Core`
  (`Workforce`, `Communication`, `Reporting`, `Ai`, `Automation`, `Contacts`) and through domain events.
- **Marketing apps (`email_marketing`, `sms_marketing`, `whatsapp_marketing`) send only through
  `App\Core\Communication` contracts** (`ChannelGateway`, `MarketingAudience`, `UnsubscribeLinks`,
  `ConsentRecorder`, `ChannelEventListener`). They hold no provider credential and import no Communication class;
  see ADR 0014.
- **Handing an outside enquiry to the CRM, or reading a CRM customer group as an audience, goes through
  `App\Core\Crm\Contracts\LeadIntake` / `CrmAudience`** (ADR 0023; `docs/CRM.md` "Lead intake and audience
  contracts"). Core binds null objects (`unavailable` / empty); `CRMServiceProvider` rebinds the real ones. Fake
  them with `Tests\Support\InteractsWithCrmContracts`.
- **A module that has reports registers a `ReportProvider`** in its own provider with
  `$this->app->tag(ReportProviders::ALL, ReportProvider::TAG)`; the Reports app discovers it (ADR 0015).
- **A module that exposes AI access registers an `AiTool` / `ProposalTool`** with `AiTool::TAG`
  (`docs/AI.md` section 6).
- **A module whose records belong to a contact registers a `CustomerRecordProvider`** with
  `CustomerRecordProvider::TAG`; its section then appears in the Customer 360 panel (section 5b, ADR 0020).
- **Stateless public endpoints** (webhooks, tracking links, single-use token pages) use
  `loadTenantCallbackRoutes()`: tenant from the hostname, throttle, no session, plus the module's entitlement
  (the unsubscribe page is deliberately not entitlement-gated, `docs/COMMUNICATION.md` section 6).

## 1. What a module is

- One service provider that extends `App\Core\Modules\ModuleServiceProvider` and is listed in
  `config/modules.php` (`providers`). Nothing is discovered by scanning.
- `describe()` returns a `ModuleManifest`. `bootModule()` loads the module's routes and migrations.
- Registration makes the code available to the platform. Access per tenant is decided by
  `App\Core\Entitlements\Contracts\EntitlementChecker`, on the server, every time.

> **How a tenant gets a module.** `PlanEntitlementChecker` entitles every accessible workspace to `core: true`
> modules. It adds optional modules from a healthy plan and effective, active App Store licences, then recursively
> removes modules whose dependencies are missing. A non-entitled app returns 404, contributes no navigation or
> widgets, and its permissions are ineffective. In tests, use the real App Store manager after catalogue sync,
> a plan with `modules`, or `registerModule()` + `entitle()` when the test is about a gate rather than commerce.

## 2. Folder layout (spec 3.1)

```
app/Modules/<Module>/
  <Module>ServiceProvider.php
  routes.php
  Models/  Http/Controllers/  Actions/  Events/  Jobs/  Policies/
  Automation/Triggers/  Automation/Actions/  Widgets/
  Database/Migrations/
resources/js/Modules/<Module>/Pages/        Inertia pages, e.g. Modules/Notes/Pages/Index.vue
resources/js/Modules/<Module>/Components/
tests/Feature/<Module>/
```

## 3. The manifest

`new ModuleManifest(...)` — constructor arguments, all named:

| Field | Type | Rules |
|---|---|---|
| `key` | string | `^[a-z][a-z0-9_]*$`. Stable. Prefix of every permission, route name and automation key. Must be unique |
| `name` | string | Human name shown in the role editor and App Store |
| `version` | string | Semantic version; bump it when a versioned catalogue/contract field changes |
| `core` | bool, default `false` | `true` = part of every tenant, no entitlement. Optional apps leave it `false` |
| `dependencies` | list of module keys | Every key must be registered or the application fails to boot |
| `permissions` | list of `PermissionDefinition` | Each name must start with `{key}.` (checked in the constructor); duplicates across modules throw |
| `navigation` | list of `NavigationItem` | Sidebar entries |
| `widgets` | list of `WidgetDefinition` | Dashboard widgets |
| `triggers` | list of class-strings implementing `TriggerDefinition` | Automation triggers |
| `actions` | list of class-strings implementing `ActionDefinition` | Automation actions |
| `morphMap` | `alias => class` | Morph aliases for the module's models |
| `limits` | list of `LimitDefinition` | What a plan can cap for the module (section 5c) |
| `description`, `category`, `icon` | catalogue metadata | Description and `AppCategory` are required for optional apps; default icon is `package` |
| `featured`, `popular` | bool | Catalogue badges controlled by the compiled manifest |
| `features`, `screenshots` | list of strings | Short feature labels and public local asset paths |
| `offers` | list of `AppOffer` | At least one for an optional app; offer keys are unique |
| `compatibility`, `releaseNotes` | string / nullable string | Stored in the immutable app-version snapshot |
| `routes` | list of route names | Inventory only; each starts with `{key}.`. Routes are still loaded in `bootModule()` |
| `scheduledJobs` | list of `ScheduledJobDefinition` | Dispatched only for entitled tenants by `apps:run-scheduled` |
| `settings` | list of `AppSettingDefinition` | Typed, validated, encrypted per-workspace installation settings |
| `lifecycle` | `ModuleLifecycle` class or null | Transactional install, activate, deactivate, uninstall and upgrade hooks |

### App Store metadata and offers

An optional module must be deployable code before it can be an app. Register the provider, deploy its migrations,
then run `permissions:sync` and `apps:sync --prune`. The sync stores immutable versioned offers, compatibility,
features, screenshots, dependencies, permissions, routes, schedules, settings and release notes, and refuses a
different hash under the same version. App-level display metadata may be updated by sync. Database-only apps are
not shown.

`new AppOffer(key, name, type, currency: null, amount: null, trialDays: 0, public: true)` uses
`AppBillingType::{Free, Monthly, Yearly, OneTime, UsageBased, Enterprise}`. Money is integer minor units.
Self-serve paid offers need a positive amount and three-letter currency. Usage-based and enterprise offers are
catalogue-only until an operator-led billing flow exists.

`new AppSettingDefinition(key, label, type, required, description, default, options, min, max, maxLength)` supports
text, integer, boolean, select and secret types. Keys are unique in the manifest. Select values/labels are
compiled; integer bounds and text length become server validation. A secret cannot have a compiled default.
Installation values are stored as one encrypted array; never read secrets from the presented App Store props.

### Lifecycle

Implement `App\Core\AppStore\Contracts\ModuleLifecycle` only when the module needs hooks; otherwise the store uses
`NullModuleLifecycle`. Hooks run in the App Store transaction, so keep them local, deterministic and idempotent.
Do not call an external service that cannot roll back. Uninstall hooks may disable module-owned state but must
never delete business records. The platform retains the installation, commercial and entitlement history.

The hooks are `installing`, `installed`, `activating`, `activated`, `deactivating`, `deactivated`, `uninstalling`,
`uninstalled` and `upgraded`. A user-triggered update to the current compiled catalogue version invokes the
upgrade hook; automatic upgrade orchestration is not built.

### Permissions

`new PermissionDefinition(name, group, scopable: false, sensitive: false)`

- Name: `{module}.{resource}.{action}`, e.g. `crm.leads.edit`. The module is the first segment.
- `group` groups permissions in the role editor. `sensitive` shows a warning there. `scopable` declares that a
  grant may be narrowed to own / department / branch: the role editor then offers the choice, and the module's
  controllers must apply it (section 5a).
- Run `php artisan permissions:sync` after adding or renaming one. Until then `RoleManager` refuses to save roles
  (`The permission catalogue is out of date`). `--prune` deletes permissions no module declares any more,
  together with every grant of them in every tenant.
- New permissions are granted to no role. The workspace owner holds everything implicitly; the system roles'
  grants are listed in `App\Core\Access\DefaultRoles` and cover `core.*` only. They are applied when a
  workspace is provisioned: an existing workspace's roles are not changed by a release.
- Client labels: add `permissions`, `permissionGroups` and `modules` entries to
  `resources/js/Core/i18n/locales/en/catalogue.ts` and to its `bn` mirror. The Bengali catalogue is typed against
  the English one and `tests/js/locales.test.ts` compares their keys, so a missing translation fails the checks.
- Server-side strings (`__()` in toasts, validation messages, mail) are English keys with their Bengali text in
  `lang/bn.json`. Follow the glossary at the top of `resources/js/Core/i18n/locales/bn/index.ts`.

### Navigation

`new NavigationItem(key, route, icon, permission: null, section: 'workspace', order: 100, activePattern: null)`

- `section` is `workspace`, `apps` or `manage` (`NavigationBuilder::SECTIONS`). Optional apps use `apps`.
- Within a section a module's entries stay together: modules are ordered by their lowest `order` there, entries
  by their own. `order` therefore only needs to be unique within the module, and two apps never interleave.
- Each item is sent with `module` (the manifest key). In the `apps` section the workspace shell shows the entries
  of every non-core module under one collapsible header named `catalogue.modules.{module}`; core entries and the
  `workspace` / `manage` sections stay flat. The collapsed state is a per-viewer localStorage preference, and the
  group holding the current page is always open. The command palette lists every entry regardless.
- An item is sent to the browser only if the tenant is entitled to the module, the user holds `permission` (when
  set) and the named route exists. That is a convenience; the route enforces access again.
- Label: `shell.nav.{key}` in `resources/js/Core/i18n/locales/*/shell.ts`. Icon: a name registered in
  `resources/js/Core/Components/Icon.vue` (unknown names render a neutral dot).

### Widgets

`new WidgetDefinition(key, component, provider, permission: null, span: 4, order: 100)`

- `provider` is an invokable class; `DashboardBuilder` calls it through the container with `user` and expects an
  array. It is not invoked for a user who may not see the widget.
- `component` is a name in the `WIDGETS` map of `resources/js/Core/Pages/Dashboard.vue`; add the module's
  component there. `span` is columns out of 12; the page knows 3, 4, 5, 6, 7, 8 and 12.

### Automation triggers and actions

Implement `App\Core\Automation\Contracts\TriggerDefinition` / `ActionDefinition` (static `key()`, `label()`,
`module()`, `requiredPermission()`, `inputSchema()`, `outputSchema()`; triggers add `event()`, actions add
`execute(array $input, string $idempotencyKey): array`). Keys must start with `{module}.` and be unique, or
`AutomationRegistry` throws. The Workflow Automation app executes what you register: a trigger's `event()` is
subscribed to automatically, and an action is called with validated input and a stable idempotency key after the
engine re-checks `requiredPermission()` (held for all records when scopable) and the module's entitlement for the
workflow's actor. An action that mutates state must make its mutation and durable idempotency result atomic; CRM's
tenant/action/key receipt store is the reference implementation for returning the same output across process
retries. An action must not read the signed-in user (there is none in a worker). See `docs/AUTOMATION.md` and
`docs/WORKFLOWS.md`.

### Morph map

`Relation::requireMorphMap()` is on (`AppServiceProvider`). Any model that is audited (`Auditable`), assigned
roles, or used in a polymorphic relation needs an alias here, or the first save throws
`ClassMorphViolationException`. Aliases are global: keep them unique across modules.

## 4. Routes

Call `$this->loadTenantRoutes($path)` from `bootModule()`. It wraps the file in:

```php
Route::domain('{tenant}.'.config('platform.domain'))
    ->middleware(['web', 'tenant', 'entitled:'.$key])   // no `entitled:` for core modules
```

**It does not add authentication, the membership check or permissions. The routes file must:**

```php
Route::middleware(['auth:web', 'auth.session', 'member'])->group(function () {
    Route::get('notes', [NoteController::class, 'index'])
        ->middleware('can:notes.notes.view')
        ->name('notes.index');
});
```

- Name routes `{key}.*`. There is no automatic name prefix. Anything not starting with `central.` or `platform.`
  is exposed to workspace pages by Ziggy (`config/ziggy.php`).
- Never declare the `{tenant}` parameter in a controller signature; `InitializeTenancy` removes it. `route()`
  fills it from the active tenant.
- Bind tenant-scoped models only. Never bind `User`: use `TenantMembership` (`{membership}`).
- Every state-changing route names its permission with `can:`.

### Scheduled jobs

Declare `new ScheduledJobDefinition('{module}.{job}', Job::class, ScheduledJobFrequency::Hourly)` in
`scheduledJobs`. Supported frequencies are every minute, hourly and daily, evaluated in the tenant's timezone.
`apps:run-scheduled` runs every minute, enters active tenant contexts and dispatches only when that tenant is
entitled. The encrypted tenant-bound wrapper re-checks the compiled definition and entitlement at worker time.
A central-cache slot key prevents duplicate dispatch in the same minute/hour/day.

The job class must implement both `ShouldQueue` and `RequiresTenantContext` and have no required constructor
arguments. Do not create a parallel cron entry that skips entitlement. See `docs/APP_STORE.md` and
`docs/DEPLOYMENT.md`.

## 5. Models and migrations

```php
final class Note extends Model
{
    use BelongsToTenant;   // App\Core\Tenancy\Concerns
    use HasUlids;
}
```

```php
Schema::create('notes', function (Blueprint $table) {
    $table->ulid('id')->primary();
    $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->string('title');
    $table->timestamps();

    $table->unique(['tenant_id', 'title']);          // tenant_id first, always
    $table->index(['tenant_id', 'created_at']);
});
```

- Load migrations with Laravel's `$this->loadMigrationsFrom(__DIR__.'/Database/Migrations')`.
- `BelongsToTenant` scopes reads, stamps `tenant_id`, and refuses cross-tenant writes. Do not set `tenant_id`
  from request input. Conventions: `docs/DATABASE.md`.
- `DB::table()` and other raw queries are not scoped. Avoid them on tenant-owned tables.
- Search filters: put user text into `LIKE` only through `App\Core\Support\Like::contains()` / `escape()`.
- Cross-module data goes through events or a documented contract, not through another module's model.

## 5a. Records that belong to someone (permission scopes)

A permission declared `scopable: true` can be granted for a person's **own** records, their **department**'s,
their **branch**'s, or **all** (ADR 0007). Declaring it is a promise the module has to keep:

```php
final class Lead extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;   // App\Core\Access\Concerns\HasRecordOwner
}
```

```php
// migration: the three columns HasRecordOwner needs, each with an index led by tenant_id
$table->foreignUlid('owner_id')->nullable()->constrained('tenant_memberships')->nullOnDelete();
$table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
$table->foreignUlid('branch_id')->nullable()->constrained('branches')->nullOnDelete();
```

```php
// controller: the route names the permission, RecordAccess applies its scope
$leads = $records->constrain(Lead::query(), $actor, 'crm.leads.view')->paginate(25);   // lists
$records->authorize($actor, 'crm.leads.edit', $lead);                                  // one record: 404 outside the scope
```

- A record created by a signed-in member becomes theirs; department and branch are copied from the owner
  whenever the owner is set (`assignOwner()`).
- Let only someone who holds the manage permission for `all` hand a record to another member.
- `app/Core/Contacts/Http/Controllers/ContactController.php` is the reference implementation, and
  `tests/Feature/Contacts` shows how to test it.

## 5b. Customers: contacts and companies

There is one customer identity per workspace: `App\Core\Contacts\Models\Contact` (a person) and `Company` (an
organisation). **A module never creates its own customer table.** It stores `contact_id` / `company_id` on its
own records and reads the shared ones.

- Write through `App\Core\Contacts\ContactDirectory` (`createContact`, `updateContact`, `deleteContact` and the
  same for companies), so the audit entry and the domain event always follow.
- React to `core.contact.created|updated|deleted` and `core.company.created|updated|deleted`.
- Contacts are soft-deleted: keep rows that point at one, and expect `contact` relations to be null.
- Access is governed by `core.contacts.view` / `.manage` / `.delete`, each scopable. A module that shows a
  customer picker must narrow it with `RecordAccess` for the person looking.

### Customer 360

`App\Core\Contacts\Contracts\CustomerRecordProvider` lets a module show what it holds about one contact, without
any other module importing its models (ADR 0020).

- **Registering.** Tag the provider in the module's own provider:
  `$this->app->tag([MySection::class], CustomerRecordProvider::TAG)`.
- **Gating.** `CustomerRecordRegistry` offers a section only when the workspace is entitled to the provider's module
  and the member holds its permission. The endpoint `GET /contacts/{contact}/records` first checks that the member
  may see the contact.
- **What a provider does.** It reads only its own records, narrows them as its own list does (`RecordAccess` or the
  module's access class), and returns at most `$query->limit` items. It returns `total` and offers actions only to
  members who may use them.
- **Actions.** An action is a link to the module's own screen, never a write from the panel. The CRM create forms
  accept `?create_for={contact}`.
- **Labels.** Labels and statuses are English strings translated with `__()`, with their Bengali in `lang/bn.json`.

The six sections that exist today are `crm.leads`, `crm.deals`, `crm.activities`,
`whatsapp_marketing.conversations`, `support.tickets` and `communication.timeline`. The `CustomerPanel` component
(`resources/js/Core/Components/Contacts`) renders them. Each new provider needs a scope test and a cross-workspace
test (`tests/Feature/Contacts/CustomerRecordsTest.php` is the pattern).

## 5c. Limits and metering

Declare what a plan may cap in the manifest:

```php
limits: [
    new LimitDefinition('sms.messages'),                                   // metered: counted from UsageMeter
    new LimitDefinition('crm.pipelines', LimitKind::Gauge, static fn (): int => Pipeline::query()->count()),
],
```

- The key is prefixed with the module key and must be unique. A plan sets the maximum per key in the panel; no
  entry means unlimited. Label it in `catalogue.ts` under `limits` (both languages).
- Before using more: `app(UsageLimits::class)->ensure('sms.messages', $count)` - a validation error at the limit.
- After using: `app(UsageMeter::class)->record('sms.messages', $count, "sms:{$message->id}")`. The idempotency key
  must identify the event, so a retried job or a duplicate webhook counts nothing.
- See `docs/BILLING.md` section 6.

## 6. Common needs

| Need | Use |
|---|---|
| Announce something happened | A class extending `App\Core\Events\TenantDomainEvent` with `name()` (`notes.note.created`) and `payload()` (ids and scalars). It is dispatched after commit |
| Queue work for a tenant | Implement `App\Core\Tenancy\Contracts\RequiresTenantContext` on the job, mailable, notification or listener and pick a queue from `docs/ARCHITECTURE.md` section 10 |
| Do something as another tenant | `TenantContext::run($tenant, fn)`. When dispatching inside it use a block closure (`function (): void { Job::dispatch(); }`): an arrow function returns the `PendingDispatch`, which is only queued after the context has ended |
| Rate-limit | `throttle:` middleware or `RateLimiter`; counts are per tenant automatically |
| Store a file | `App\Core\Tenancy\Storage\TenantStorage`. Never `Storage::disk()` with a hand-built path |
| Cache | `Cache::` inside a tenant context is already namespaced per tenant. Platform-wide data goes to `app('cache.central')` |
| Audit a change | `use Auditable` on the model, or `AuditLogger::record('notes.note.archived', $note)` inside the same transaction |

## 7. Worked example

A minimal `notes` module (illustrative; not in the repository). It matches the real constructors.

```php
// app/Modules/Notes/NotesServiceProvider.php
namespace App\Modules\Notes;

use App\Core\AppStore\Enums\{AppBillingType, AppCategory};
use App\Core\Modules\{AppOffer, ModuleManifest, ModuleServiceProvider, NavigationItem, PermissionDefinition, WidgetDefinition};

final class NotesServiceProvider extends ModuleServiceProvider
{
    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'notes',
            name: 'Notes',
            version: '1.0.0',
            dependencies: ['core'],
            description: 'Shared notes linked to customers and work.',
            category: AppCategory::Productivity,
            features: ['Private workspace notes', 'Customer links'],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            permissions: [
                new PermissionDefinition('notes.notes.view', 'notes', scopable: true),
                new PermissionDefinition('notes.notes.manage', 'notes', scopable: true),
            ],
            navigation: [
                new NavigationItem('notes', 'notes.index', 'list-checks', 'notes.notes.view', 'apps', 10, 'notes.*'),
            ],
            widgets: [
                new WidgetDefinition('notes.recent', 'RecentNotesWidget', Widgets\RecentNotesWidget::class, 'notes.notes.view', span: 6),
            ],
            triggers: [Automation\Triggers\NoteCreatedTrigger::class],
            morphMap: ['note' => Models\Note::class],
        );
    }

    protected function bootModule(): void
    {
        $this->loadTenantRoutes(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
```

```php
// config/modules.php
'providers' => [
    App\Core\CoreServiceProvider::class,
    App\Modules\Notes\NotesServiceProvider::class,
],
```

Override `register()` only to add container bindings, and call `parent::register()` first (see
`CoreServiceProvider`): the parent is what registers the manifest.

## 8. Checklist

1. Provider, complete manifest (catalogue metadata, offers and dependencies), entry in `config/modules.php`.
2. Migrations with `tenant_id` and tenant-first unique indexes; models with `BelongsToTenant` + `HasUlids`.
   Customers are `contact_id` / `company_id`, never a table of the module's own (section 5b). Records with a
   scopable permission use `HasRecordOwner` and `RecordAccess` (section 5a).
3. Morph aliases for audited or polymorphic models.
4. Routes through `loadTenantRoutes()`, with `auth:web`, `auth.session`, `member` and a `can:` on each.
5. `php artisan permissions:sync`, then `php artisan apps:sync --prune` (also on every deploy).
6. Pages under `resources/js/Modules/<Module>/Pages`, rendered as `Inertia::render('Modules/<Module>/Pages/…')`;
   labels in the i18n catalogue; icon and widget component registered.
7. Domain events for what other modules or workflows may react to; triggers/actions, scheduled jobs and limits
   declared from the first release. Scheduled work goes through the entitlement-gated runner. Anything a member
   may want to report on is published as a `ReportProvider` tagged in the module's provider (`docs/REPORTS.md`
   section 1); a marketing or messaging app sends only through the `App\Core\Communication` contracts.
   Every queued class declares its queue, tries, timeout, backoff and `RequiresTenantContext`
   (`tests/Unit/Hardening/QueueDefinitionsTest.php` fails otherwise).
8. Lifecycle hooks only when needed; dependency/dependent handling; uninstall retains business data. Typed app
   settings reject undeclared keys and never expose secret values.
9. Tests: a cross-tenant test for every new model and every new route (`docs/TESTING.md`), plus catalogue sync,
   not-entitled 404, dependencies, lifecycle rollback, free/paid licensing and retained-data reinstall. The `Crm`
   fixture and `tests/Feature/AppStore` show the patterns.
10. `composer check`, `npm run typecheck`, `npm run test`, `npm run build`, and the relevant Playwright flow.
