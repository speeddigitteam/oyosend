# App Store

State: Phase 3 is implemented and verified. The store is a
catalogue and licence manager for **first-party modules compiled into this build**. It is not a remote plugin
marketplace: a tenant cannot upload, download or execute code.

The production catalogue is intentionally empty until the first optional business module is added in Phase 4.
The complete flow is exercised with `tests/Support/Fixtures/Crm`; registering that fixture in a test makes it a
compiled app for that test only. Do not seed a fake app into production to make the catalogue look populated.

## 1. Model

```text
compiled ModuleManifest
        | apps:sync
        v
apps --current_version_id--> app_versions (immutable manifest snapshots)
  |                              |
  +-- app_installations ---------+       one per tenant + app
  |          |
  |          +-- subscription_items      separately billed offer/renewal state
  |          +-- app_entitlements        effective licence grants
  |
  +-- invoices.app_id / subscription_item_id
```

- `apps` and `app_versions` are the central catalogue. A row is useful only while its module is also registered
  in `ModuleRegistry`; a stale database row cannot expose missing code.
- `app_installations`, `subscription_items` and `app_entitlements` are central records carrying `tenant_id` and
  the normal fail-closed tenant scope. One workspace cannot see or mutate another workspace's app state.
- Installation, licence and billing state are separate. Installing records lifecycle state; an entitlement
  answers whether use is allowed; a subscription item records the commercial offer and its renewal dates.
- `PlanEntitlementChecker` combines healthy plan-included modules with effective App Store licences, then removes
  optional modules whose dependencies are not entitled. Core remains available so billing can be reached.

## 2. Catalogue and immutable versions

Every optional `ModuleManifest` supplies description, category and at least one `AppOffer`, plus optional icon,
featured/popular flags, features, screenshots, compatibility, release notes, owned route names, scheduled jobs,
typed settings and a lifecycle class. `php artisan apps:sync` mirrors those compiled manifests into the catalogue.

For each `(app, version)`, the synchronizer stores the versioned fields (offers, compatibility, features,
screenshots, dependencies, permissions, routes, scheduled jobs, settings and release notes) plus a SHA-256 hash. Running it
again is idempotent. Changing those released fields without changing the semantic version fails; bump the module
version instead. App-level display metadata remains updateable by sync. `--prune` can unpublish a missing module
only when it has no live installation, active licence or live commercial item; it never deletes versions,
installations, licences or tenant business records.

Catalogue pages show only rows that are both published and backed by a currently registered optional module:

- `/apps`: search, category and offer-type filters;
- `/apps/{module-key}`: features, version, dependencies, permissions and available offers;
- `/apps/my`: installed apps, installation state and app subscription state.

Access uses `core.apps.view`; lifecycle changes use `core.apps.manage`. Paid purchase, subscription
cancel/resume and uninstalling an app with a live commercial commitment also require `core.billing.manage`.

## 3. Offers and billing

`AppOffer` supports `free`, `monthly`, `yearly`, `one_time`, `usage_based` and `enterprise`. Prices are integer
minor units with an ISO 4217 currency code. Free, monthly, yearly and one-time offers are self-serve;
usage-based and enterprise offers may be advertised but need an operator-led agreement outside this flow.

App billing is independent of the workspace's base-plan subscription:

| Action | Result |
|---|---|
| Install a free offer | Installation, active free entitlement and lifecycle hooks are committed together |
| Start a paid offer with a trial | Trial subscription item and time-limited entitlement become active without an invoice |
| Buy a paid offer without a trial | Pending installation + subscription item + `app_purchase` invoice; no entitlement yet |
| Provider verifies payment, or operator records it | `InvoiceSettlement` routes the paid invoice to `AppStoreManager::settle()`; only then are the item, installation and entitlement activated |
| Recurring boundary approaches | Hourly `billing:run` issues an `app_renewal` invoice using the app item's own amount and period |
| Renewal is late | Item becomes `past_due`; entitlement remains valid through the configured grace period |
| Grace or cancellation boundary ends | Licence expires/cancels and the installation becomes inactive; data stays in place |

A gateway redirect, notification body or browser input never activates a paid app. `PaymentProcessor` first
verifies the provider transaction server-to-server (exact reference, amount and currency); offline settlement is
an explicit audited Super Admin action. The base subscription keeps its own open-invoice slot, while each
separately billed app item can have one open purchase or renewal invoice.

## 4. Lifecycle and dependencies

All writes go through `AppStoreManager`. An optional module can provide `ModuleLifecycle`; otherwise
`NullModuleLifecycle` is used. The hooks are:

`installing`, `installed`, `activating`, `activated`, `deactivating`, `deactivated`, `uninstalling`,
`uninstalled`, `upgraded`.

Hooks run inside the lifecycle database transaction. They must be local and idempotent; they must not make a
remote call that cannot be rolled back. A user can move an installed app to the current compiled catalogue
version through the update action; automatic upgrade orchestration is deliberately deferred.

- Install and activation require every non-core dependency to be entitled first.
- Deactivation and uninstall are refused while an entitled dependent app still needs this app.
- A stale or manually inserted licence never bypasses dependency checks: entitlement resolution prunes the
  dependent chain recursively.
- Uninstall disables executable access, cancels an unpaid purchase, voids open app invoices and marks renewable
  items to end. A valid free, trial, purchased or plan licence is retained so reinstall can reuse the installation
  record and business data. Uninstall never drops or deletes the app's business tables or rows.

Lifecycle operations emit tenant events (`core.app.installed`, `core.app.activated`, `core.app.deactivated`,
`core.app.uninstalled`) and write `app_store.*` audit entries.

### Per-workspace settings

An app may declare typed `AppSettingDefinition`s: text, integer, boolean, select or secret. Definitions carry
validation, defaults and display metadata in the immutable version snapshot. Values live on the tenant's
installation using Laravel's `encrypted:array` cast; even non-secret values share the encrypted envelope.

Only declared keys are accepted. Secret fields have no compiled default, are never returned to the browser,
and a blank submission means keep the stored value. The presenter returns only `hasValue`; audit entries record
changed and secret **keys**, never their values. Settings can be edited only for an installed, non-uninstalled app
by someone with `core.apps.manage`.

## 5. Where entitlement is enforced

One server-side answer, `EntitlementChecker::allows(tenant, module)`, drives every exposure point:

| Surface | Enforcement |
|---|---|
| HTTP routes / APIs | `loadTenantRoutes()` adds `entitled:{module}` to every optional module; denial is 404 |
| Permissions | `AccessChecker` makes a non-entitled module permission ineffective; `RoleManager` will not assign it |
| Navigation | `NavigationBuilder` drops the module's entries |
| Dashboard widgets | `DashboardBuilder` never invokes or exposes the provider |
| Automation definitions | Registry entries declare `module()`; the future engine must re-check entitlement before execution |
| Scheduled jobs | `apps:run-scheduled` dispatches an encrypted tenant-bound wrapper only for entitled workspaces; the worker re-checks the compiled definition and entitlement before running a `ShouldQueue` + `RequiresTenantContext` job |
| Integrations | A future integration centre must check the same contract before connection, dispatch and callbacks |

Frontend visibility is only a convenience. Every route, queued/scheduled execution and domain action checks again
on the server. Deactivation, expiry and uninstall flush the request's entitlement memo immediately.

## 6. Adding an app

1. Add a first-party provider under `app/Modules/<Module>`, register it in `config/modules.php`, and declare its
   complete manifest. Follow `docs/MODULES.md`.
2. Add tenant-owned migrations and models for its business data. Installation tables never replace business
   data and uninstall code must not delete it.
3. Add translations, Inertia pages, route permissions, dependency and cross-tenant tests.
4. Deploy the code and migrations, then run `permissions:sync` followed by `apps:sync --prune`. Bump the module
   version when a versioned field changes. Run sync only after the release's module providers can boot.
5. Verify free install, paid verification (when applicable), lifecycle rollback, dependency/dependent failures,
   entitlement gating, cancellation/expiry and reinstall with retained data.

## 7. Deliberately not built

- Tenant or vendor uploads, Composer installs at runtime, eval, remote code loading or a third-party marketplace.
- Operator catalogue editing that could claim database-only code exists; catalogue truth comes from manifests.
- Coupons, refunds, tax, stored-payment auto-charge and usage-based invoice calculation.
- Automated app-version migration/orchestration. Versions are published and installations point to a version,
  but an upgrade runner is a later concern.

Decision record: `docs/adr/0009-first-party-app-store.md`. Tables: `docs/DATABASE.md`. Billing details:
`docs/BILLING.md`.
