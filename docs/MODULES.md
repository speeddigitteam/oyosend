# Modules

How to add a first-party module. The contract is in `app/Core/Modules`; the production build registers `core` and
the optional `crm` and `projects` business apps. They are documented in `docs/CRM.md` and `docs/PROJECTS.md`.
A small non-core App Store fixture remains in `tests/Support/Fixtures/Crm` under the distinct
module key `test_crm`.

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
`AutomationRegistry` throws. Register from Phase 1 onwards even though nothing executes workflows until Phase 10.
An action that mutates state must make its mutation and durable idempotency result atomic; CRM's tenant/action/key
receipt store is the reference implementation for returning the same output across process retries.
See `docs/AUTOMATION.md`.

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
   declared from the first release. Scheduled work goes through the entitlement-gated runner.
8. Lifecycle hooks only when needed; dependency/dependent handling; uninstall retains business data. Typed app
   settings reject undeclared keys and never expose secret values.
9. Tests: a cross-tenant test for every new model and every new route (`docs/TESTING.md`), plus catalogue sync,
   not-entitled 404, dependencies, lifecycle rollback, free/paid licensing and retained-data reinstall. The `Crm`
   fixture and `tests/Feature/AppStore` show the patterns.
10. `composer check`, `npm run typecheck`, `npm run test`, `npm run build`, and the relevant Playwright flow.
