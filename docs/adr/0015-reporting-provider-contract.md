# ADR 0015 — Reports are published by modules as tagged providers; the Reports app owns no data

- **Status:** Accepted
- **Date:** 2026-10-13
- **Phase:** 12 (Reports & Analytics)

## Context

Every optional app has its own report page, but a member also needs one place to run, save, share and export reports
across apps, and the operator wants that without a cross-module data warehouse. A reporting app that queried other
modules' tables would import their models (forbidden by CLAUDE.md), would have to re-implement each module's record
scopes, and would be a single place where a missing scope check leaks data between members. Accepting a free-form
query or expression from the request would add SQL injection and cross-tenant risk for no product gain.

## Decision

1. **A report is a declaration plus a read-only runner, owned by the module that owns the data.**
   `App\Core\Reporting\Contracts\ReportProvider` has two methods: `definition(): ReportDefinition` (key
   `{module}.{report}`, module, label, description, permission, columns, filters, `usesDateRange`) and
   `run(ReportContext): ReportResult`. A module registers providers in its own service provider with
   `$this->app->tag(ReportProviders::ALL, ReportProvider::TAG)` (`reporting.providers`) and imports nothing of the
   `reports` module.

2. **The Reports app discovers, gates and runs; it owns no business data.** `ReportRegistry::availableFor($user)`
   offers a provider only if the workspace is entitled to the provider's module **and** the member holds the
   provider's own permission (`AccessChecker`, read from the database). Unknown, not entitled and not permitted are
   all the same 404. A duplicate key is a `LogicException` at registration.

3. **The request names only declared things.** Columns must be declared (none chosen means defaults; a money column
   brings its currency column). Filters must be declared and typed (`select`, `text`, `currency`, `id`, `integer`,
   `boolean`); an undeclared filter is a validation error and values are bound parameters inside the provider. The
   date range is `Y-m-d` in the workspace timezone, defaults to the last 30 days, and is at most 366 days. There is
   no expression, free-form query or class-name input.

4. **Scope is the provider's duty and is part of the contract.** A provider whose permission is scopable narrows
   every query with `RecordAccess::constrain()` (and `allows()` for derived figures) for `$context->user`. The
   runner runs the provider as the member, so a report never shows more than the module's own screen; a figure the
   member may not see is `null`.

5. **Typed, honest output.** Columns are `text`, `integer`, `money` (integer minor units plus a currency column,
   never summed across currencies), `basis_points`, `hundredths`, `minutes`, `date`, `datetime` (UTC). Null means
   "not reported", never zero. A provider stops at `rowLimit + 1`; the runner cuts at `ROW_LIMIT` (2000) and flags
   `truncated`. A snapshot report sets `usesDateRange: false`.

6. **Saved reports store a setup, never data.** `reports_saved_reports` holds provider key, columns, filters, range
   and visibility (`private`, `role`, `department`). Whoever opens a shared setup runs the provider with their own
   permission and scope; it is re-validated on every open and save. Owner or `reports.saved.manage_all` may change
   it; anyone else gets 404. Limit: gauge `reports.saved_reports`.

7. **Export is the screen.** CSV is built from the same output for the signed-in member, needs
   `reports.reports.export` (sensitive) plus the provider's permission, is throttled (20/min) and audited
   (`reports.report.exported`). Cells that could start a formula get a leading apostrophe; the header names units;
   a truncated result ends with an explicit row and `X-Report-Truncated: 1`.

## Consequences

- A new module gains cross-app reporting by writing providers and one `tag()` line; the Reports app does not change.
  Twelve modules publish 24 providers today; Communication and AI publish none.
- Correctness of record scope rests on each provider. The registry and runner cannot prove a provider narrowed its
  query. `ProviderScopeTest` checks scope narrowing for the CRM, Projects and HRM providers and runs every shipped
  provider once; the other providers' scope behaviour has no dedicated test. A new provider needs its own scope test.
- A permission that is not scopable (for example `email_marketing.reports.view`) makes its report workspace-wide for
  holders, like the module's own page.
- Reports run synchronously inside the request with a 2000-row cap. There is no scheduled or emailed delivery, no
  charts, no cross-module joins and no XLSX/PDF. Large aggregations belong in the provider's own SQL; the
  benchmark found a PHP-bound aggregation in the support report (`docs/PERFORMANCE.md`).
- Report keys are a public contract for saved reports: renaming one orphans saved setups (they list as
  unavailable).

## Alternatives considered

- **Reports app queries every module's tables.** Rejected: model imports, duplicated scope logic, one leak point.
- **Each module only keeps its own report page.** Rejected: no shared saving, sharing or export; inconsistent CSV
  safety and audit.
- **A query builder or expression language in saved reports.** Rejected: injection and tenant-isolation risk; the
  declared-columns and declared-filters model covers the shipped reports.
- **A materialised warehouse.** Rejected for now: a second source of truth and a tenant-isolation surface for no
  measured need; revisit with real report volumes.
