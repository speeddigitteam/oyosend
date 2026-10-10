# ADR 0009: First-party compiled App Store and verified licences

- Status: accepted
- Date: 2026-10-10

## Context

Oyosend needs optional applications that can be included in a plan or licensed separately. The product also
needs catalogue pages, install/activation state, paid renewals and a stable entitlement check across routes,
permissions, navigation, widgets, automation and scheduled work. Allowing tenant-uploaded PHP or installing code
at runtime would make isolation, review, rollback and dependency safety unmanageable.

The Phase 2 payment path already treats gateway redirects and notifications as untrusted. An App Store must not
create a second, weaker payment truth.

## Decision

1. Apps are first-party `ModuleManifest`s compiled into the deployment and explicitly registered in
   `config/modules.php`. The catalogue never loads tenant or vendor code.
2. `apps:sync` copies catalogue metadata from compiled manifests. Versioned offer/contract fields in
   `app_versions` are immutable snapshots: the same module version with a different hash is rejected. `--prune`
   unpublishes a missing module only when no live installation, licence or commercial item depends on it.
3. Installation, commercial item and entitlement are separate tenant-scoped records. Base-plan inclusion and
   separately acquired app licences are merged by the one `EntitlementChecker` contract.
4. Paid app entitlement is created only by the existing verified invoice settlement path. App invoices and
   `subscription_items` are independent of the base plan subscription, but share invoices, payments and the
   hourly billing command.
5. Dependencies are validated on install/activation and when entitlement is resolved. Active dependents block
   deactivation/uninstall.
6. Uninstall is a reversible access/state operation. It disables execution and ends renewal, while retaining a
   valid licence and never removing the module's business data.
7. All exposure and execution surfaces use server-side entitlement: routes/APIs, permissions, navigation,
   widgets, automation definitions and scheduled jobs.

## Consequences

- A database catalogue row cannot make absent code executable; both a published row and registered module are
  required for catalogue access.
- Releases that add or change an optional module run migrations, `permissions:sync`, then `apps:sync --prune`.
- A released manifest payload requires a version bump. Old version records and tenant commercial history remain.
- Tenants can safely deactivate, expire or uninstall an app and later recover its retained data by reinstalling.
- There is no third-party marketplace or runtime plugin installation. Adding one would require a new security
  model and ADR; this decision does not authorize it.
- The base subscription and each separately billed app item have independent open-invoice slots. There is still
  no stored-payment or automatic-charge system.
