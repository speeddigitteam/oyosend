# Reports & Analytics

Module key `reports`, category Reporting, free offer, depends on `core` only. It is the one place a member runs,
saves, shares and exports the reports that other apps publish. It owns no business data and imports no other
module's class: a module offers a report by implementing a small contract from `app/Core` and tagging it in its
own service provider. Spec: `MASTER_PROMPT.md` section 26 and Phase 12.

## 1. The contract (`App\Core\Reporting`)

Decision record: ADR 0015.

- `Contracts\ReportProvider`: `definition(): ReportDefinition`, `run(ReportContext): ReportResult`. Tag
  `ReportProvider::TAG` (`reporting.providers`).
- `ReportDefinition`: key (`{module}.{report}`), module, label, description, permission, columns, filters,
  `usesDateRange`.
- `ReportColumn`: key, label, type (`ColumnType`), `currencyColumn` (money only), default.
- `ReportFilter`: key, label, type (`FilterType`), options (select), min/max (integer).
- `ReportContext`: user, scope, from, to (local `Y-m-d`), fromAt, toAt (UTC), timezone, filters, rowLimit.
- `ReportResult`: rows (arrays keyed by column key), truncated.
- `ReportRegistry`: `all()`, `availableFor($user)`, `find($key, $user)`: the entitlement and permission gate.
- `Support\CsvWriter`: formula-safe CSV.

Column types: `text`, `integer`, `money` (integer minor units plus a currency column), `basis_points`
(10000 = 100%), `hundredths` (450 = 4.50), `minutes`, `date`, `datetime` (UTC ISO-8601).
Filter types: `select`, `text` (max 100), `currency` (3 letters), `id` (26-char ULID), `integer`, `boolean`.
There is no expression or free-form query type, by design.

Registering a provider (any module):

```php
// app/Modules/<M>/Reporting/ReportProviders.php
final class ReportProviders { public const array ALL = [PipelineReport::class]; }

// <M>ServiceProvider::register()
$this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
```

Rules a provider keeps:

- Read only. Never changes data and never runs SQL taken from the request; filter values are bound parameters.
- Permission and scope. `definition()->permission` must be held (effective, entitlement included; checked by
  `ReportRegistry`). When it is scopable the provider narrows every query with
  `RecordAccess::constrain($query, $context->user, $permission)` (and `RecordAccess::allows()` per record for
  derived data such as financials). A provider over a non-scopable report permission is workspace-wide for its
  holders, like the module's own report page.
- Money is integer minor units next to a currency column; currencies are never added together.
- Null means "not reported", never zero.
- Stop at `$context->rowLimit + 1` rows; the runner cuts at the limit and flags `truncated`.
- A snapshot report sets `usesDateRange: false`.

Communication and AI do not publish providers (24 providers are registered by 12 modules); a module that does
later follows the same two steps and nothing in the reports app changes.

## 2. Running a report (`ReportRunner`)

1. The provider must be available (`ReportRegistry::find`): unknown, not entitled and not permitted are all 404.
2. `from`/`to` (`Y-m-d`, workspace timezone). Default: the last 30 days ending today. `to >= from` and at most
   366 days inclusive, else a validation error on `to`.
3. `columns[]` must be declared columns; none chosen means the default columns. A money column brings its
   currency column.
4. `filters[key]` must be declared and valid for its type; an undeclared key is a validation error.
5. The provider runs; rows are cut at `ReportRunner::ROW_LIMIT` (2000) and `truncated` is set.
6. Rows are projected to the chosen columns and paged in memory, 50 per page (`rows_page`).

A saved or shared setup is re-validated every time it is opened or saved.

## 3. Saved reports

Table `reports_saved_reports` (tenant-owned, ULID): provider key, columns, filters, range (`last_days` N or fixed
from/to), visibility (`private` | `role` | `department`). It stores no data and no query.

- The owner (a membership) and holders of `reports.saved.manage_all` may update or delete; opening needs
  visibility; anything outside the visible set is 404.
- Sharing shares the setup, not data: whoever opens it runs the provider with their own permission and record
  scope; a provider they cannot run is listed unavailable and opening it is 404.
- Deleting a shared role or department clears the link (`nullOnDelete`): the report becomes owner-only.
- Limit: gauge `reports.saved_reports`, checked on create.

## 4. CSV export

`GET reports/run/{provider}/export` and `GET reports/saved/{saved}/export`; throttle 20/min; needs
`reports.reports.export` (sensitive) and the provider's own permission. Built from the same output as the screen
for the signed-in member, so it can never carry more than the screen.

- UTF-8 BOM, every field quoted, CRLF.
- Text cells whose first non-space character is `= + - @` TAB CR LF get a leading apostrophe; integers stay
  numbers.
- Numbers are raw; the header names the unit ("Amount (minor units)", "(basis points)", "(hundredths)",
  "(minutes)"); the currency column is in the file. Null is an empty cell.
- A result cut at the row limit ends with an explicit "Truncated" row and the header `X-Report-Truncated: 1`.
- Audit event `reports.report.exported` (report, from, to, filters, columns, rows, truncated, scope; subject is the
  saved report when exported from one).

## 5. Routes, pages, keys

All routes: `auth:web`, `auth.session`, `member`, `subscribed`, `entitled:reports`.

| Name | Verb + URL | Permission |
|---|---|---|
| `reports.index` | GET `/reports` | `reports.reports.view` |
| `reports.run` | GET `/reports/run/{provider}` | `reports.reports.view` + the provider's permission; throttle 60/min |
| `reports.export` | GET `/reports/run/{provider}/export` | `reports.reports.export` + the provider's permission; throttle 20/min |
| `reports.saved.show` | GET `/reports/saved/{saved}` | `reports.reports.view` + visibility |
| `reports.saved.export` | GET `/reports/saved/{saved}/export` | `reports.reports.export` |
| `reports.saved.store` | POST `/reports/saved` | `reports.saved.manage` |
| `reports.saved.update` | PUT `/reports/saved/{saved}` | `reports.saved.manage` + owner or `manage_all` |
| `reports.saved.destroy` | DELETE `/reports/saved/{saved}` | same as update |

Pages: `Modules/Reports/Pages/Index` and `Modules/Reports/Pages/Run` (used for both `reports.run` and
`reports.saved.show`). Permissions: `reports.reports.view`, `reports.reports.export`, `reports.saved.manage`,
`reports.saved.manage_all`. Navigation key `reports` (section `apps`, order 70). Limit `reports.saved_reports`
(gauge). Morph alias `reports_saved_report`. Audit events `reports.saved.created|updated|deleted`,
`reports.report.exported`.

## 6. Providers shipped

| Report key | Permission |
|---|---|
| `crm.pipeline` | `crm.deals.view` (scopable) |
| `crm.lead_sources` | `crm.leads.view` (scopable) |
| `crm.sales_conversion` | `crm.reports.view` |
| `projects.performance` | `projects.projects.view` (scopable; money null without `manage_financials`) |
| `projects.timesheets` | `projects.time.view` (scopable) |
| `invoicing.receivables_ageing` | `invoicing.reports.view` (snapshot) |
| `invoicing.revenue` | `invoicing.reports.view` |
| `hrm.headcount` | `hrm.reports.view` |
| `hrm.attendance` | `hrm.attendance.view` (scopable) |
| `hrm.leave` | `hrm.reports.view` |
| `payroll.run_totals` | `payroll.reports.view` (sensitive; reversal runs shown negative) |
| `talent.recruitment_funnel` | `talent.analytics.view` (sensitive) |
| `support.volume`, `support.sla`, `support.csat` | `support.reports.view` |
| `email_marketing.campaigns` | `email_marketing.reports.view` (not scopable: workspace-wide for holders; sent campaigns only) |
| `email_marketing.lists` | `email_marketing.reports.view` (snapshot) |
| `sms_marketing.campaigns`, `sms_marketing.daily` | `sms_marketing.analytics.view` |
| `whatsapp_marketing.campaign_delivery`, `whatsapp_marketing.conversation_activity` | `whatsapp_marketing.analytics.view` |
| `social.publishing`, `social.engagement` | `social.analytics.view` (null = platform did not report) |
| `automation.run_outcomes` | `automation.runs.view` (live runs only) |

## 7. Not built

Scheduled or emailed delivery; charts and dashboards; cross-module joins; XLSX/PDF export; a workspace-wide
share option; Communication and AI providers.
