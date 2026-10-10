# App Store

State: end of Phase 1. **There is no App Store**: no catalogue, no install flow, no tables, no UI. What exists is
the contract every app is built against and the entitlement check in front of it.

## What exists today

| Piece | Where | Role |
|---|---|---|
| Module manifest | `app/Core/Modules/ModuleManifest.php` | What the platform knows about an app: key, name, version, `core`, dependencies, permissions, navigation, widgets, triggers, actions, morph map |
| Module registry | `app/Core/Modules/ModuleRegistry.php` | The set of modules compiled into the build; permission index; dependency check at boot |
| Module provider | `app/Core/Modules/ModuleServiceProvider.php` | Base class; `loadTenantRoutes()` adds `entitled:{key}` for non-core modules |
| Entitlement contract | `app/Core/Entitlements/Contracts/EntitlementChecker.php` | `allows(Tenant, moduleKey)`, `entitledModules(Tenant)` |
| Phase 1 implementation | `app/Core/Entitlements/CoreModulesEntitlementChecker.php` | Core modules for every accessible tenant; **everything else denied** |
| Route gate | `app/Core/Access/Http/Middleware/EnsureModuleEntitled.php` | `entitled:{module}` → 404, so an app the tenant does not have is not disclosed by its URLs |

Only one module is registered: `core` (`config/modules.php`). The tenant dashboard counts the optional modules
the workspace is entitled to and shows an "apps" empty state while that is zero, which today is always.

The whole gate is exercised in tests with a stand-in optional app: `tests/Support/Fixtures/Crm` (a non-core
`crm` module) and `tests/Support/Fixtures/GrantsModules` (an entitlement checker with explicit grants), driven by
`tests/Feature/Modules/EntitlementTest.php`.

### Where entitlement is enforced

Spec section 15 requires entitlements to gate backend access, routes, navigation, APIs, scheduled jobs,
integrations, widgets and automation triggers/actions.

| Surface | Today |
|---|---|
| Routes | `entitled:{module}` middleware |
| Permissions (backend access) | `AccessChecker`: a permission of a non-entitled module is never effective |
| Role editor | `RoleManager::assignablePermissionNames()`: such permissions cannot be put on a role |
| Navigation | `NavigationBuilder` |
| Dashboard widgets | `DashboardBuilder` |
| Automation triggers and actions | Each definition declares `module()`; nothing checks it yet because nothing executes |
| APIs, scheduled jobs, integrations | Not applicable yet |

### Gaps against the spec's manifest

The spec's manifest lists routes and scheduled jobs, and lifecycle hooks (`installing`, `installed`,
`activating`, `deactivated`, `uninstalling`, `upgraded`). `ModuleManifest` has none of these: routes are loaded
in `bootModule()`, and there is nothing to install yet.

## Planned

- **Phase 2:** the entitlement store (plans, usage limits). `CoreServiceProvider` binds `EntitlementChecker`; that
  one binding is what gets replaced. The code comments place this in Phase 3; spec section 27 lists entitlements
  under Phase 2.
- **Phase 3 (App Store):** app registry and manifests, categories, detail pages, free install, paid checkout,
  licensing, activation, My Apps, app permissions, dependencies, entitlement-driven navigation.

Rules the spec fixes (section 15), to hold the implementation to:

- Apps are first-party Laravel modules. Tenants cannot upload or execute third-party code.
- Installing an app activates existing functionality for that tenant only.
- A paid app creates an entitlement only after server-side payment verification.
- Activation validates dependencies; deactivation warns about dependents.
- Uninstalling never deletes business data; it is retained and restored on reinstall.
- A third-party marketplace is to be designed, not built, and never with remote plugin execution.
- Sidebar menu name: "App Store".

How to write a module: `docs/MODULES.md`.
