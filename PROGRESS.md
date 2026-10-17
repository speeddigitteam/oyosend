# PROGRESS

Where the project stands, what was decided and what comes next. Update it at the end of every session.
Rules for working in this repository are in `CLAUDE.md`; the product specification is `MASTER_PROMPT.md`.

## Status

**Phases 1 (Architecture & Foundation), 2 (Core SaaS & Billing), 3 (App Store), 4 (CRM), 5 (Projects),
6 (Invoicing), 7 (HRM & Payroll) and 8 (Communication Platform) are complete and were signed off on 2026-10-12.
Phase 9 (Marketing Apps: Email Marketing, SMS Marketing, WhatsApp Business, Social Media) is built and passes every
automated gate but has had no independent review and no browser pass. Phases 10 (Automation engine), 11 (AI
Assistant) and 12 (Support Desk, Reports & Analytics) exist in the tree on the same terms. Phase 13 (production
hardening) has begun: health probes, queue and index audits, backup verification and a benchmark exist and were run
on one Windows machine; it is not signed off (see the 2026-10-13 section at the end).**

Modules built after Phase 8 and not yet reviewed or signed off: `app/Modules/{Social,Support,Automation,Ai,Reports,
EmailMarketing,SmsMarketing,WhatsappMarketing}` with `docs/{SOCIAL,SUPPORT,WORKFLOWS,AI,REPORTS,EMAIL_MARKETING,
SMS_MARKETING,WHATSAPP}.md`. Treat them as present, not as completed phases. Provider integrations (social
platforms, Anthropic, WhatsApp Cloud API) have never been exercised against the live service; SMS has no provider
adapter and reports `not_configured`.
CRM, Projects, Invoicing, HR Management, Payroll and Talent & Performance are compiled production business
applications and catalogue entries. App Store tests retain a separate
`test_crm` fixture so production CRM and generic multi-app behaviour can be exercised independently.

## What exists

### Phase 1 — Architecture & Foundation

| Area | What exists |
|---|---|
| Tenancy | Workspace per subdomain (`{tenant}.APP_DOMAIN`), resolved from the hostname only. Fail-closed row scope, tenant-prefixed cache, tenant-aware rate limiter, tenant file storage, tenant context carried by queued work |
| Identity | Platform-wide users with memberships in several workspaces; host-only workspace-bound sessions and remember cookies; password reset; TOTP two-step verification and recovery codes |
| Team and access | Invitations, member management, tenant roles, module permissions, permission hierarchy rules, append-only audit trail |
| Platform | Separate Super Admin host, guard, users and layout; mandatory two-step verification; dashboard and workspace directory |
| Module foundation | Manifests, registry, entitlement contract, navigation and dashboard builders, domain-event and automation-definition contracts |
| Product shell | Warm frosted-glass design system, light/dark themes, command palette, responsive layouts, English/Bengali UI and configurable numeral display |
| Tooling | Pest, Vitest, Pint, Larastan level 6, vue-tsc, GitHub Actions workflow and an unverified Sail environment |

### Phase 2 — Core SaaS & Billing

| Area | What exists |
|---|---|
| Registration | Configurable open/approval/closed sign-up, email confirmation before provisioning, one-use token, first-run workspace setup, and welcome mail |
| Workspace settings | Company/legal profile, address and tax fields, locale, timezone, currency, and private logo storage through `TenantStorage` |
| Organisation | Tenant-scoped branches and departments, member placement, and invalid-placement guards |
| Shared customers | One tenant-scoped contacts/companies directory for future modules, soft deletion, duplicate-email protection, audit/events, and ownership assignment |
| Scoped permissions | `own`, `department`, `branch` and `all` grants evaluated by `RecordAccess`; contacts and companies are the reference implementation |
| Plans and subscriptions | Monthly/yearly/free plans, trials, upgrades/downgrades, cancellation, renewals, grace periods, expiry and the hourly `billing:run` cycle |
| Platform billing | Immutable invoice snapshots and lines, payments, offline payment recording, SSLCommerz adapter, server-side verification, idempotent callbacks and payment audit events |
| Entitlements and usage | Plan-based optional-module entitlements, route/navigation/widget/permission gating, gauge and metered limits, idempotent usage records and workspace paywall |
| Super Admin | Workspace creation/approval/suspension/reactivation, plan management, subscription overrides, invoice/payment views, operator settings and panel audit log |
| Support access | Time-limited, reason-required, audited access as the owner; persistent workspace banner; personal/security routes remain blocked |
| Hardening brought forward | Trusted-proxy configuration, security headers, report-only CSP and stricter tenant-context restoration |
| Frontend | Registration/onboarding, organisation, contacts, workspace settings, billing, and complete platform management pages in both languages |
| Browser coverage | Playwright flows for workspace sign-in/authorization/language, registration, contacts/isolation, billing, platform 2FA and support access |

### Phase 3 — App Store

| Area | What exists |
|---|---|
| Catalogue | Optional manifest metadata, 16 categories and six offer types; `apps:sync [--prune]`; immutable version snapshots protected by a manifest hash; only published and compiled modules are exposed |
| Workspace UI | Search/filter catalogue, app details and My Apps; CRM appears after catalogue sync, while the bilingual empty state still covers builds with no exposed apps |
| Installation and licences | Tenant-scoped installation, entitlement and commercial-item records; free install; activate/deactivate/uninstall/reinstall/update; typed encrypted app settings; retained data/history and lifecycle events/audit |
| Paid apps | Trial, monthly/yearly/one-time checkout, separate app invoices/items, activation only after verified settlement, renewals, grace, expiry, cancel/resume |
| Dependencies | Dependencies required on install/activate and recursively in entitlement resolution; active dependents block deactivate/uninstall |
| Gating | One entitlement answer for routes/APIs, permissions, role assignment, navigation, widgets and compiled scheduled jobs; scheduled work re-checks at worker time, and automation execution must re-check it when Phase 10 is built |
| Safety | First-party compiled modules only; no tenant uploads, runtime Composer install, eval or remote plugin execution |
| Operations | `apps:sync --prune` in deploys; `apps:run-scheduled` every minute; `billing:run` advances both base and app subscriptions |

### Phase 4 — CRM

| Area | What exists |
|---|---|
| Shared customer identity | Leads, deals, activities and sales documents reference core contacts/companies; normalized email/phone duplicate detection and an explicit field-by-field contact merge repoint core and registered module references before soft deletion |
| Leads and pipelines | Sources, scored leads, configurable pipelines/stages, qualification and conversion; stage history, terminal won/lost deals and scope-aware pipeline dashboard data |
| CRM work | Calls, meetings, tasks and follow-ups with tenant-local scheduling stored as UTC; notes, tags, custom fields, private attachments and unified timelines on leads, deals and shared contact/company customer profiles; saved segments cover leads, deals, activities, contacts and companies |
| Product catalogue | Products/services, integer minor-unit prices, quantity thousandths, basis-point tax, validity-aware tiered price lists and overflow-safe exact arithmetic |
| Sales documents | Exact, overflow-checked integer quote/proposal line snapshots, scoped deal selection, send/accept/reject/cancel transitions, idempotent order creation, sales orders and customer/order-linked contracts |
| Performance | Customer lifecycle profiles, canonical one-way deal lifetime-value recognition without quote/order double-counting, sales targets, conversion/forecast/order-trend reports and tenant-timezone report boundaries |
| Boundaries | `crm.quote.accepted` carries immutable itemized order snapshots for Phase 6; module data is retained when entitlement is removed |
| Access and UI | 24 declared permissions, record scopes, entitlement-gated routes/navigation/widgets/definitions, and complete English/Bengali Inertia surfaces |
| Automation catalogue | Four triggers (lead created/qualified, deal won, activity completed) and three actions (assign lead, move stage, create follow-up) registered for the future Phase 10 engine; mutating actions persist tenant/action/key receipts so retries return the original output without repeating work |
| Deal pipeline board | `Deals/Index` has a list/board toggle; board view loads all open deals for the selected pipeline (`boardDeals`, max 200, via `DealController::index` when `view=board`) and renders `DealBoard.vue` with HTML5 drag-and-drop — dropping a card calls `POST /crm/deals/{deal}/stage`; only `deal.editable` cards are draggable; a drop on a won/lost stage opens a confirmation with an optional note (the lost reason) because it closes the deal; refused moves show the server error. Tests: `tests/Feature/CRM/CrmDealBoardTest.php`, `tests/js/crm.test.ts` (CRM deal board). Not yet tried in a real browser: the Acme demo workspace has no pipeline or deals |

### Phase 5 — Project Management

| Area | What exists |
|---|---|
| Project setup | Categories, reusable task templates, scoped projects, owners and internal membership; shared core contacts/companies are client identity and `TenantMembership` is team identity |
| Work planning | Tasks/subtasks, ordered checklists, assignment, watchers, priorities, milestones, sprints, reminders, recurrence and cycle-safe project-local dependencies |
| Views and UI | List, Kanban board (drag-and-drop status change via `PATCH /projects/{project}/tasks/{task}/status`; completed/cancelled cards are not draggable since they cannot be reopened; refused moves show the server error), calendar and custom Gantt projections over one task model; responsive bilingual project, time, expense, approval, reporting, dashboard and settings surfaces |
| Collaboration | Comments, explicit mentions, private attachments, activity history and revocable hashed-token client access with client-visible work boundaries |
| Time and resources | Integer-minute timers/timesheets, submitted/approved workflow, workload and allocation summaries using workspace memberships |
| Budget and delivery | Integer minor-unit budgets, rates and expenses; project profitability facts, approval workflows and portfolio/project reporting |
| Boundaries | CRM project creation, future Invoicing revenue, HRM timesheets and Phase 8 notifications use domain events/documented contracts; Projects imports no optional-module model and retains data on uninstall |
| Access and automation | 19 declared permissions, project/task record scopes, entitlement-gated routes/navigation/widget/scheduled maintenance, four triggers and three durable receipt-backed actions |

## Verification

Earlier whole-repository results remain below as historical evidence. Each phase's final totals are recorded only
after every gate has returned, so they are not guessed.

### Phase 6 final — 2026-10-11

Run natively on Laragon/Windows against `oyosend_testing`; Invoicing and sidebar work was done by delegated agents on
their own `*_testing` databases and then re-run together here.

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `vendor/bin/phpstan analyse` (level 6) | no errors |
| Backend tests | `php artisan test --compact` | 2,277 passed, 1 failed, 22,590 assertions in 628s |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test` | 19 files, 354 tests passed |
| Production build | `npm run build` | built |
| End-to-end tests | `tests/e2e/invoicing.spec.ts` | written, **not run** |

The one failure is `ProjectsDomainTest › it creates recurring tasks and emits reminders once per due occurrence`
(line 306: expected 0 recurrences, got 1). It fails identically on the committed Phase 5 tree with every Phase 6
change stashed, and passed in three full runs earlier the same day, so it is not caused by Invoicing. It depends on
the real clock somewhere around the tenant-timezone `until` boundary; the cause was not found. Fix it before relying
on the Projects recurrence boundary.
### Review pass — 2026-10-11 (after Phase 5 hardening)

Same working tree as Phase 5 plus the sidebar and dashboard-copy fixes in the session log.

| Check | Result |
|---|---|
| `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` | passed · no errors |
| `php artisan test --compact` | 2,212 passed, 21,210 assertions in 728.61s |
| `npm run typecheck` · `npm run test` · `npm run build` | no errors · 16 files, 311 tests passed · built |

The full run immediately before this one reported 43 failures, among them `ProjectsClientPortalTest` answering
429 on its first portal comment and `ProjectsApprovalTest` refusing a `projects.approvals.decide` grant as not
available to the workspace. The identical tree then passed in full, and the cause was not found. Shortly before,
another process had been migrating `oyosend_testing` at the same time as the dev database, so a concurrent run
on the shared test database is the likeliest explanation. Re-run the suite if those failures come back.

### Phase 5 final — 2026-10-11

Run natively on Laragon/Windows. The final backend rerun used the disposable `oyosend_codex_testing` database to
remain isolated from another local test runner; browser tests use `oyosend_e2e_testing`.

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=1G --no-progress` (level 6) | no errors |
| Backend tests | `php artisan test --compact` | 2,212 passed, 21,210 assertions in 585.19s |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test -- --run` | 16 files, 311 tests passed |
| Production build | `npm run build` | built, 3,392 modules transformed |
| End-to-end tests | `npm run test:e2e` | 21 passed in Chrome in 6.3m; the focused Projects owner-create smoke was re-run after hardening and passed in 3.1m |
| Fresh database and seed | Playwright global setup against `oyosend_e2e_testing` | `migrate:fresh --seed` succeeded before the browser run; compiled apps were synchronized into the catalogue |
| Projects isolation | Full backend suite, including the representative Projects route matrix | project/task/planning/collaboration/finance/approval cross-tenant and mismatched bindings returned 404 without mutation |

The final review also tightened record-scoped financial visibility, rate mutation, client delegation, tenant-local
dates, exact large-money transport, lifecycle/approval locking and active-project limit serialization. Vitest and
Playwright required permission to spawn their worker/browser processes in the managed Windows environment; both
elevated runs passed.

The final workflow-closure and hardening passes exposed project team maintenance, task comments and mentions,
watcher controls, multi-assignee allocation and clearing, checklist/dependency creation, private project/task
attachments, approval requests, project lifecycle controls, and milestone/sprint transitions in the production UI.
They also tightened recurrence scheduling, transactional lifecycle edits, approval eligibility and finance summary
accuracy. A final adversarial pass added requester cancellation and stranded-approval recovery, member-option privacy,
terminal-project creation guards, bounded report/time/expense pagination, tenant-local report windows, chunk-bounded
exact-money aggregation, report/list indexes and client-portal token redaction regressions. The focused Projects
regression suite passed 48 tests with 1,272 assertions, the complete frontend suite passed all 311 tests, and the
expanded Projects create/detail browser smoke passed after clearing every task assignee and starting the project.

### Phase 4 final — 2026-10-10

Run natively on Laragon (Windows 10): PHP 8.4.13, MySQL 8.0.30, Redis 5.0.14 and Node 24.19.

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=1G` (level 6) | no errors across 390 files |
| Backend tests | `php artisan test --compact` | 2,163 passed, 19,247 assertions in 495.79s |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test -- --reporter=dot` | 14 files, 264 tests passed |
| Production build | `npm run build` | built, 3,334 modules transformed |
| End-to-end tests | `npm run test:e2e` | 20 passed in Chrome in 4.0m, including the production CRM catalogue and lead flow |
| Fresh database and seed | Playwright global setup against `oyosend_e2e_testing` | `migrate:fresh --seed` succeeded before the browser run; compiled apps were synchronized into the catalogue |
| CRM isolation | Full backend suite, including the parameterized CRM route matrix | foreign tenant route bindings and mismatched nested resources returned 404 without mutation |

Vitest and Playwright needed permission to spawn their bundled worker/browser processes in this managed Windows
environment. Their elevated reruns passed; the initial `spawn EPERM` errors were environment startup failures,
not failing assertions.

### Phase 3 final — 2026-10-10

Run natively on Laragon (Windows 10): PHP 8.4.13, MySQL 8.0.30, Redis 5.0.14 and Node 24.19.

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` (level 6) | no errors |
| Backend tests | `php artisan test --compact` | 2,117 passed, 16,924 assertions |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test -- --reporter=dot` | 13 files, 255 tests passed |
| Production build | `npm run build` | built, 3,273 modules transformed |
| End-to-end tests | `npm run test:e2e` | 19 passed in Chrome, including the App Store catalogue/My Apps flow |
| Fresh database and seed | Playwright global setup against `oyosend_e2e_testing` | `migrate:fresh --seed` succeeded before the browser run |
| App operations | App Store feature tests | `apps:sync --prune`, scheduled dispatch and worker-time entitlement re-check passed against `oyosend_testing` |

Vitest needed permission to spawn its bundled esbuild worker in this managed Windows environment. The elevated
rerun passed; the first `spawn EPERM` was an environment startup error, not a failing test.

### Still not verified

- **Laravel Sail / Docker.** `compose.yaml` exists, but Docker is not installed on this machine. It builds PHP
  8.5 and MySQL 8.4; the successful native run used PHP 8.4 and MySQL 8.0.
- **GitHub Actions.** The workflow has never run because the repository has no remote.
- **Horizon.** Windows lacks `pcntl` / `posix`; production queue supervision remains untested.
- **SSLCommerz sandbox or production.** The adapter is extensively tested with fake HTTP responses, but no real
  merchant account has been connected. Treat it as unverified until a sandbox transaction completes.
- **Real mail and S3.** Mail has used test/log transports; the S3 driver is not installed.
- **Production proxy/TLS/CSP behaviour.** Security headers are tested, but CSP is deliberately report-only until
  it has been observed on the deployed origins.

## Phase 2 decisions

Longer reasoning is in ADRs 0006–0008 and the domain documents.

| Decision | Why |
|---|---|
| Invoice-driven billing; no automatic charging | A workspace changes to a paid plan only after a verified payment settles an invoice |
| SSLCommerz as the first adapter; offline payments remain an operator action | It supplies the required abstraction without pretending unverified credentials work |
| Gateway redirects and notifications never prove payment | `PaymentProcessor::confirm()` asks the provider's validation API; callbacks are stateless and idempotent |
| One subscription row per workspace, with invoices and audit logs as history | Keeps current access inexpensive while preserving financial history |
| Core is always entitled; optional modules come from a healthy plan | Deny-by-default behaviour is usable before the App Store introduces per-app licences |
| Usage limits are declared in module manifests | Future apps share one gauge/metered enforcement contract and idempotency model |
| Record scopes live on role grants and are applied explicitly by controllers | A route-level permission cannot decide whether one particular record is in scope |
| Registration is configurable: open, approval or closed | The owner had not fixed one launch policy; the operator can choose without a code change |
| Sign-up creates nothing until the emailed link is confirmed | Avoids abandoned tenants and treats possession of the mailbox as verification |
| Support access is a short-lived, reason-required handoff rather than password impersonation | It is auditable, cross-origin, expires, and cannot open personal/security settings |

Phase 1 decisions remain in ADRs 0001–0005 and `CLAUDE.md`.

## Phase 3 decisions

Longer reasoning is in ADR 0009 and `docs/APP_STORE.md`.

| Decision | Why |
|---|---|
| Apps are first-party modules compiled into the deployment | A catalogue entry can never introduce tenant-supplied or remote executable code |
| Manifests are synced to immutable version snapshots | Store pages are queryable while released offer/contract fields cannot silently change under one version |
| Installation, entitlement and subscription item are separate | Operational state, access truth and commercial renewal have different lifecycles |
| Base-plan inclusion and separately acquired licences share `EntitlementChecker` | Every route, permission and exposure point receives the same deny-by-default answer |
| App billing reuses verified invoice settlement but has its own items/periods | Paid app activation is not weaker than base billing and does not alter the plan subscription |
| Dependencies are enforced both on lifecycle writes and entitlement reads | A stale/manual database grant cannot expose a child whose required app is unavailable |
| Uninstall retains module business data | Reinstall is reversible and commercial state changes never become destructive data deletion |

## Phase 4 decisions

Longer reasoning is in ADR 0010 and `docs/CRM.md`.

| Decision | Why |
|---|---|
| Core contacts and companies remain the canonical customer identity | CRM enrichment must not create a second customer directory; contact merging can therefore preserve one identity across modules |
| Contact merge is explicit, field-by-field and extensible through registered module handlers | The user chooses the canonical values while core and every participating module repoint references in one transaction |
| Customer-facing CRM records use normal tenant and record scopes | Optional-module entitlement never replaces row isolation or own/department/branch/all authorization |
| Money, quantity and rates use bounded exact integer arithmetic | Quotes, orders, forecasts and targets remain deterministic without floating-point or overflow corruption |
| Quote/order lines are immutable commercial snapshots | Later catalogue changes cannot rewrite an issued document, and Phase 6 can consume accepted itemized values safely |
| Won and lost deals are terminal; linked deals own lifetime-value recognition | A linked quote can create its order without counting the same sale again; standalone accepted quotes still recognize their order total |
| Tenant-local inputs and reports are normalized around UTC persistence | Scheduling and date-bound reports remain correct for each workspace without mixing server timezone assumptions |
| CRM data survives deactivation or uninstall | Entitlement changes remove access, not business history; reinstall remains reversible like every App Store lifecycle |

## Phase 5 decisions

Longer reasoning is in ADR 0011 and `docs/PROJECTS.md`.

| Decision | Why |
|---|---|
| Core contacts/companies and tenant memberships remain canonical | A client or teammate keeps one identity across core and optional modules; Projects never creates a shadow customer, employee or global-user binding |
| Project and task access composes entitlement, permission and record scope | Installing the module never weakens tenant isolation or own/department/branch/all authorization |
| All task views project one task model and dependencies are project-local acyclic edges | List, board, calendar and Gantt cannot drift into separate sources of truth; invalid graphs are rejected at write time |
| Money is integer minor units, time is integer minutes and datetimes persist as UTC | Budget, cost, workload and schedules stay deterministic across locales/timezones without floating point |
| Client collaboration is an explicit revocable hashed-token grant | A contact can collaborate on published work without becoming a workspace user or seeing internal/financial data by default |
| Optional-module integrations cross events/contracts only | CRM, Invoicing, HRM and notifications can evolve or be absent without coupling Projects to their tables/models |
| Recurrence and automation mutations are idempotent | Scheduler and workflow retries cannot duplicate work or repeat assignments |
| Project data survives deactivation or uninstall | Entitlement changes remove execution/access, not delivery history or commercial facts |

## Known issues and debt

Security and correctness:

- A password-reset token can be redeemed on another workspace host where the person is a member; the password is
  still bound to the emailed account, but the audit entry belongs to the host used.
- An unknown-workspace 404 does not clear a tenant left in the same long-lived PHP process. This does not affect
  PHP-FPM; fix it before using Octane.
- Local-disk signed file URLs rely on their signature alone and answer on any host.
- CSP is report-only and `style-src` permits inline styles. Observe reports and tighten it before enforcement.
- `SESSION_SECURE_COOKIE` must be enabled wherever HTTPS is used.
- Sign-up has throttling and email confirmation but no CAPTCHA or proof of work.
- Support access is always as the owner; a workspace cannot pre-approve or refuse an individual session.

Billing and product:

- SSLCommerz has not been exercised against its sandbox.
- A verified payment for an invoice voided in the meantime is retained and flagged as unapplied; refunds and
  credits are not built.
- Coupon, refund/credit-note flow, a general tax engine, usage-based app charge calculation and automatic payment
  methods are not built. CRM calculates document tax from its own snapshotted basis-point line rates; that is not
  a platform accounting/tax engine.
- Changing an account email, platform password recovery and login-session management are not built.
- The dedicated-database seam (`tenants.db_connection`) is reserved but not operational; the migration plan and
  gaps are documented in `docs/DATABASE.md`.

Frontend and operations:

- Wide data tables scroll horizontally on small screens rather than becoming record cards.
- App catalogue text is translated on the client by app key (`appStore.catalog.{key}` in the locales, with the
  server text as fallback). A new app must add its block to both locales. Offer labels (for example "Included")
  still come from the manifest in English.
- Phase 6 Invoicing: tenant payment gateways are not built (credentials may not be stored in the database; needs an
  owner decision), invoice PDFs are print-to-PDF from a standalone HTML document (no LGPL PDF library), and the
  Projects time event carries only ids, so billable time shows a project id. Not built: invoice-level discounts,
  tax-inclusive pricing, refunds of paid amounts, a dashboard widget. The Bengali invoicing wording and the
  Playwright spec (`tests/e2e/invoicing.spec.ts`, not run) still need a native read and a run.
- Laravel Sail, Horizon, CI, S3, real mail and production deployment have not received an end-to-end run.
- During the 2026-10-10 Phase 3 verification, `migrate:fresh --env=testing` was accidentally run without an
  `.env.testing` file and rebuilt the local development `oyosend` database. Its schema is present, but any prior
  local data requires recovery from a backup or MySQL binary log. Pest and Playwright subsequently ran only
  against the isolated `oyosend_testing` and `oyosend_e2e_testing` databases. Never run a destructive command
  unless the resolved database name is visibly a disposable `*_testing` database.

## Deferred, by phase

- **Phase 8:** notification centre and communication providers. **Phase 9:** marketing apps.
- **Phase 10:** the automation engine; only its definition registry exists today.
- **Phase 11–12:** AI applications, Support and advanced reporting.
- **Phase 13:** final production hardening, backups, load testing and deployment verification.
- Whenever first needed: Sanctum, Reverb, Meilisearch, S3 and custom domains.

## Needs the owner's input

1. Production `APP_DOMAIN`, wildcard DNS and wildcard certificate.
2. Launch registration mode: `open`, `approval` or `closed`.
3. Production plans, prices, included modules and limits; SSLCommerz merchant credentials and a sandbox account;
   whether offline payment instructions should be shown.
4. Transactional mail provider and sender domain.
5. Git remote so the CI workflow can run.
6. Docker if Sail is to be the supported local environment.
7. A native Bengali speaker's review of the complete interface wording.
8. Final name and brand mark approval.
9. Production support-access policy and whether tenants must explicitly approve a session.
10. Voice commands (ADR 0038, proposed, not built): whether stage 1 may use the browser's recognition (audio
    processed by the browser vendor). Decided: part of the paid AI app; "run without Enter" is the member's own
    preference with no workspace override.

## Phase 8 completion

Communication is a compiled optional app (`app/Modules/Communication`, `docs/COMMUNICATION.md`): notification
centre with per-member preferences, outbox with idempotency/suppression/consent/quiet hours/rate limits and a
metered limit, templates, consent and suppression, signature-verified provider webhooks, unsubscribe, the shared
interaction timeline and a bilingual UI (nine pages and the dashboard widget). **Honest limits:** no SMS or WhatsApp
provider exists (both channels report `not_configured`), email goes through the platform mailer, there is no header
notification bell yet (the summary endpoint exists, no component calls it), and existing modules have not been
rewired to call `Notifier` / `InteractionRecorder`. Not verified in a browser (360px, dark theme) and no Playwright
spec was written. Stop for review; do not begin Phase 9 in this session.

## Phase 7 completion

HR is three compiled apps: `hrm` (employees, attendance, leave; `docs/HRM.md`), `payroll` (salary structures, periods,
four-eyes runs, payslips, loans, data-driven rule sets; `docs/PAYROLL.md`) and `talent` (recruitment, onboarding,
probation, reviews, goals, training, analytics; `docs/TALENT.md`). Payroll and Talent depend on `hrm` only through
the `App\Core\Workforce` contracts and events. Stop for review; do not begin Phase 8 in this session.

## Phase 6 completion

Invoicing is a compiled optional app (`app/Modules/Invoicing`, `docs/INVOICING.md`): exact integer money, gap-free
per-tenant numbering, tax lines, credit notes, manual and bank payments with idempotency, recurring invoices,
reminders, quote and billable-time hand-offs through events, receivables reports, print-ready documents, queued
email and a bilingual UI. The sidebar groups each app under a collapsible header, My Apps lists plan-included
apps and the App Store text is translated. Stop for review; do not begin Phase 7 in this session.

## Phase 5 completion

All Phase 5 Project Management implementation and documentation are complete. Projects reuses shared client and
membership identity, provides scoped planning/collaboration/time/resource/budget/approval/reporting workflows,
persists retry-safe automation receipts, and exposes honest event/contract boundaries for CRM, Invoicing, HRM and
notifications without importing their models. The normal seeder includes Projects for Business/Enterprise and
synchronizes both production apps into the catalogue. Stop for review; do not begin Phase 6 Invoicing in this
session.

### aamarPay gateway — 2026-10-12

Added `Core\Billing\Gateways\AamarPayGateway` (`aamarpay`, BDT only), registered beside SSLCommerz in
`CoreServiceProvider`, with credentials from `config/platform.php` `gateways.aamarpay` (`AAMARPAY_STORE_ID`,
`AAMARPAY_SIGNATURE_KEY`, `AAMARPAY_SANDBOX`, default true). Environment only; offered only when configured. A payment
counts only on aamarPay's verification API answer for our own reference. Tests: `tests/Feature/Billing/AamarPayGatewayTest.php`
(Http::fake only). **Unverified against the aamarPay sandbox or live API**; see `docs/BILLING.md` section 5.
Not done: storing gateway credentials in the database from the Super Admin panel (a requested scope change
was not implemented in this session; the environment-only rule still stands).

### Phase 8 final — 2026-10-12

Run natively on Laragon/Windows, one process per database (an earlier attempt with eight agents migrating at once
starved MySQL and produced false failures; a second run on the same database deadlocked).

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `vendor/bin/phpstan analyse` (level 6) | no errors |
| Backend tests | `php artisan test --compact` | 3,077 passed, 0 failed, 36,561 assertions in 1,304s |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test` | 28 files, 577 tests passed |
| Production build | `npm run build` | built |
| End-to-end tests | Playwright | not run |

Fixes made while verifying: the shared `HasRecordOwner` creating hook gave a workspace-, department- or branch-wide
CRM sales target to its creator (`keepUnowned()` added); `AiAppStoreTest` overwrote the test case's `$this->app`;
two Projects tests and the impersonation durable-access test were stale against the stricter approver guard and
route-model binding order.

## Session log

- **2026-10-08** — Phase 1 built and initially verified: tenancy, identity, RBAC, audit, platform guard, module
  contracts, design system, localisation, tooling and documentation.
- **2026-10-10** — Phase 1 final checks and documentation completed.
- **2026-10-10** — Phase 2 completed: organisation and shared customer records, record scopes, registration and
  onboarding, workspace settings, plans/subscriptions/invoices/payments, entitlements and usage, platform
  management, audited support access, complete bilingual UI and Playwright coverage. Final verification: Pint,
  Larastan, 2,086 Pest tests, vue-tsc, 242 Vitest tests, production build and 18 Chrome E2E tests all passed.
- **2026-10-10** — Phase 3 completed: first-party catalogue/version sync, bilingual workspace App Store, free
  and verified paid licensing, independent app renewals, encrypted typed settings, lifecycle/dependency/data-
  retention and reinstall rules, and dispatch-time plus worker-time entitlement-gated scheduled jobs. Final
  verification: Pint, Larastan, 2,117 Pest tests, vue-tsc, 255 Vitest tests, production build and 19 Chrome E2E
  tests all passed.
- **2026-10-10** — Phase 4 completed: shared-identity CRM, duplicate-contact detection and transactional merge,
  scoped leads/pipelines/deals/activities, customer content/timelines and contact/company segments, products and
  tiered prices, exact immutable quotes/proposals/orders/contracts, canonical deal LTV, bilingual UI, durable
  automation action receipts and the itemized Phase 6 quote-accepted boundary.
- **2026-10-10** — Phase 5 completed: scoped projects/tasks with list, Kanban, calendar and Gantt views; templates,
  dependencies, sprints, milestones, collaboration and client grants; exact budgets/expenses, timesheets,
  workload, approvals and reports; bilingual UI, scheduled recurrence/reminders and durable automation actions.
  Phase 6 Invoicing was not started.
- **2026-10-11** — Phase 5 workflow hardening completed: project-team editing, task collaboration, multi-assignee
  allocation/clearing, attachments, approval requests, and valid project/milestone/sprint lifecycle transitions are
  reachable in the bilingual production UI. Focused Projects backend, full frontend, build and expanded browser
  verification passed. Phase 6 Invoicing remains intentionally unstarted.
- **2026-10-11** — Review of the running app with CRM and Projects (no new phase). Fixed: the sidebar interleaved
  the two apps' entries because `NavigationBuilder` sorted a whole section by `order`; each module's entries now
  stay together (test added, `docs/MODULES.md` updated). The dashboard's no-apps text no longer says the App Store
  is not open yet or names apps that do not exist. Recorded the English-only catalogue text, the My Apps gap and
  the sidebar length under known issues. The local dev database also had the CRM/Projects migrations pending and
  lacked three App Store columns added to an already-run migration; the migrations were applied and the empty
  `app_versions`/`app_installations` tables aligned by hand.
- **2026-10-11** — Phase 6 Invoicing completed: `app/Modules/Invoicing` (exact integer money, gap-free numbering,
  tax lines, credit notes, manual/bank payments with idempotency, recurring invoices, reminders, quote and billable-time
  hand-offs, receivables reports, print-ready documents, queued email), bilingual UI, `docs/INVOICING.md`. Also
  shipped: collapsible per-app sidebar groups, translated App Store text, plan-included apps in My Apps. Gateway
  payments deliberately not built. Phase 7 was not started.
- **2026-10-12** - Messaging provider accounts (owner-approved change to a locked rule, ADR 0013). Built: operator
  provider accounts in the Super Admin panel (**Messaging providers**, `platform.settings.manage`) stored in the
  central table `communication_provider_accounts` with `secrets` encrypted (`encrypted:array`), write-only and
  referenced by workspaces as `acct_{id}`; `CredentialResolver` resolves them next to environment entries (a
  platform account answers only to the provider it was created for and to workspaces in `tenant_ids`); new
  adapters `smtp` (email), `twilio` and `http_sms` (SMS) beside `whatsapp_cloud`; `PlatformAccountProvider`
  (`accountFields()`, `supportsTestSend()`); test send for email and SMS; workspaces pick an account in
  Communication > Settings > Channels. Rule carved out: CLAUDE.md "never store gateway credentials in the
  database" now excepts these accounts only; payment gateways, AI and Social credentials remain environment-only.
  Docs updated: ADR 0013, COMMUNICATION, INTEGRATIONS, SMS_MARKETING, WHATSAPP, SECURITY, ARCHITECTURE, DATABASE.
  **Unverified:** Twilio, the generic HTTP gateway and SMTP are covered by faked tests only, never run against a
  live provider; the WhatsApp Cloud adapter is still unrun against Meta. **Remains:** SMS delivery receipts
  (Twilio status callbacks, gateway DLR) are not consumed, so SMS stays `sent`; no provider balance call; no
  gateway-specific Bangladeshi adapter (owner to name the gateway); no per-workspace own account; no per-account
  usage report or health check; secret rotation is a manual edit; `APP_KEY` custody now protects messaging
  secrets too. The panel, adapters and tests were written in parallel by other engineers, so confirm this entry
  against the code once they land.

## Phase 9 and later modules: what was added (2026-10-13)

- **Marketing contracts** (`App\Core\Communication`): `MarketingAudience` (consent/suppression/availability, batch),
  `UnsubscribeLinks`, `ChannelGateway` (idempotent send, status), `ChannelEventListener`, with Null objects; the
  Communication app implements them. Marketing apps never import Communication models. See `docs/COMMUNICATION.md` 13-14.
- **Email Marketing** (`email_marketing`): lists on shared contacts, consent evidence, segments, structured block
  editor, A/B subject test, scheduled campaigns, sequences, signed click tracking, CSV import/export, reports. Emails are
  sent as plain text by the platform mailer; the HTML version is the web-version page. Opens are not measured.
- **SMS Marketing** (`sms_marketing`): groups, templates, GSM-7/UCS-2 segment counting incl. Bengali, estimates, sender
  ids, campaigns with daily cap. **No SMS provider adapter exists**: the channel is `not_configured` and campaigns cannot launch.
- **WhatsApp Business** (`whatsapp_marketing`): template registry, opt-in, campaigns, shared inbox with 24h-window
  rule, assignment, quick replies, declarative flows, Cloud API adapter in Communication (never run against Meta).
- **Reports & Analytics** (`reports`): provider contract in `App\Core\Reporting`, saved/shared reports, CSV export,
  providers in every business module (including the three marketing apps).
- Count-test numbers: 16 registered modules, 250 permissions.

Owner decisions waiting: SMS gateway choice and transactional-send contract; HTML email sending (needs `htmlBody` in the
outbox/mailer); a `ConsentRecorder` contract (public sign-up forms); whether STOP replies opt out directly; central
OAuth redirect broker for Social; AI prices and `ANTHROPIC_API_KEY`; native Bengali review of all machine-written
strings; Playwright runs; Sail/Docker/CI never executed.

## Operator-managed credentials and the omnichannel inbox (2026-10-13 to 2026-10-14)

Owner decisions this session: every integration credential is configured from the Super Admin panel, nothing in
`.env` but the bootstrap floor (`APP_KEY`, database/cache/session/queue connections, `APP_DOMAIN`); and every chat
channel is read and answered from one screen.

- **Payment gateways and invoice issuer in the panel (ADR 0028).** `payment_gateway_accounts` (central, no `tenant_id`,
  `secrets` encrypted and write-only) with `GatewayCredentials` resolving panel-first, environment-fallback; the
  billing settings page edits SSLCommerz/aamarPay credentials, sandbox and enabled flags, and "clear" reverts to the
  environment. Invoice prefix and issuer name/address/email/tax id joined `PlatformSettings`.
- **Social and Meta Ads app credentials in the panel (ADR 0029).** `integration_credentials` (central, encrypted
  secrets, public ids shown back); `IntegrationCredentials::apply()` overlays them on the config at boot, so no
  `config('social.*')` / `config('meta_ads.*')` reader changed. Page `Platform/Pages/Integrations`.
- **Unified inbox is a chat workspace (ADR 0030, amends ADR 0020 decision 4).** Optional contracts `InboxReplies`
  (thread + reply) and `MarksInboxRead` beside `InboxSource`; the inbox never sends itself, each source calls its own
  app's action. Split view, 15-second polling of the open thread (no Reverb), mark-read only by an explicit POST.
  Sources with chat: WhatsApp conversations, Facebook comments (new source), Messenger, Instagram, website chat and
  Support tickets (requester messages and public replies, never internal notes; a reply is a public reply).
- **WhatsApp-Web-style inbox screen.** Full-height list + reading pane, always side by side on a wide screen (one or
  the other below 768px), initials avatars, day separators, grouped bubbles, delivery ticks, jump-to-latest, older
  messages on scroll-up, auto-growing composer. The list refreshes every 20 seconds through
  `communication.inbox.poll` (read-only JSON, own throttle; Communication 1.3.0). Reply, read and poll each throttle on
  their own key (a mark-read no longer spends the reply budget). "Unread" filters only the loaded rows.
- **Messenger and Instagram direct messages (ADR 0031).** Through the existing Page webhook and Page claims (one Meta
  callback URL), off by default (`SOCIAL_FACEBOOK_MESSAGING`, `SOCIAL_INSTAGRAM_MESSAGING`), per-Page capture switch
  that merges subscribed fields (lead ads no longer drops other fields on switch-off), text-only replies inside the
  24-hour window. Social "Messages" page; permissions `social.messages.view|reply`.
- **Website live chat (ADR 0032).** Inside Support: `widget.js` loader, framed page with CSP `frame-ancestors` from the
  workspace's allowed origins, same-origin visitor API with a hashed visitor token, throttles, polling. Support "Live
  chat" page and settings; reuses the ticket permissions. Off by default.
- **New migrations** (none edited in place): `2026_11_05_000100_create_payment_gateway_accounts_table`,
  `2026_11_06_000100_create_integration_credentials_table`, `2026_11_07_000100_add_inbox_index_to_social_audience_comments`,
  `2026_11_08_000100_create_social_direct_message_tables`, `2026_11_09_000100_create_support_live_chat_tables`. Applied
  to the dev database with `permissions:sync` and `apps:sync` (Social 1.5.0, Support 1.1.0, Communication 1.2.0).
- **Count tests reconciled** with the registry: 270 permissions, `meta_ads` and `notes` in the module lists, new
  automation triggers/actions, Social scopable permissions and scheduled jobs, the stateless webhook and chat routes in
  `RoutePipelineTest`, the read-only `whatsapp_conversation_get` AI tool. Two Lead Ads test bugs fixed (a closure
  called as a method; arrow functions capturing a flag by value).
- **One post to every channel (ADR 0033).** New adapters `InstagramPlatform` (photo, carousel up to 10, Reels; via the
  Meta app and Page-linked professional accounts; panel switch, default off), `TikTokPlatform` (video, chunked
  `FILE_UPLOAD`; `SELF_ONLY` until TikTok audits the app), `YouTubePlatform` (video, resumable upload; private until
  Google's audit) and `PinterestPlatform` (image/carousel/video Pins; one connected account per board). Shared:
  `requiresMedia` / `requiresVideo` / video bounds in `PlatformCapabilities`, optional `ValidatesMedia`, enforced in the
  composer preflight and on submit/approve/schedule; a signed, expiring, stateless public media route
  (`social.media.public`, Instagram only); TikTok/YouTube/Pinterest credentials and the Instagram switch in Super Admin →
  Integrations. Social 1.6.0. No migration.
- **Composer redesign.** `Posts/Form.vue` is a "Create post" screen: composer left, live per-platform preview right
  (tabs per ticked platform, our tokens and lucide icons only), sticky "Publish now" / "Schedule" / "Submit for
  approval" that save the post and then call the existing `schedule`/`submit` actions (a failed second step leaves a
  saved draft and a toast).
- **Inbox: server-side Unread filter and "Suggest a reply".** `InboxQuery::$unread` narrows every source by its own
  unread rule (page, board, poll); the AI draft (`communication.inbox.suggest`) goes through the core `TextAssistant`
  only, fills the composer and sends or saves nothing. Communication 1.4.0.
- **Live chat → contact and ticket (amends ADR 0032).** "Save as contact" (via `ContactDirectory`, matched by email,
  `core.contacts.manage`) and "Create ticket" (channel `chat`, the transcript as internal notes so nothing is emailed,
  one ticket per chat, optional close). Migration `2026_11_10_000100_add_contact_and_ticket_to_support_chat_conversations`.
  Support 1.2.0.
- **Composer: account pictures and creator-chosen TikTok settings.** Avatars (CSP `img-src` widened to the platforms'
  avatar CDNs through `platform.security.img_hosts`); TikTok privacy (no default, required), comment/duet/stitch and
  commercial-content disclosure per target in `social_post_targets.options` (migration
  `2026_11_10_000200_add_options_to_social_post_targets`), re-checked against TikTok at publish. Social 1.7.0 (its dev
  catalogue row was rewritten once after a release-note typo fix; nothing released).
- **Realtime inbox, wired but off (ADR 0035).** Every source signals `inbox.changed` `{source, id}` after commit through
  the core `InboxSignals` contract on a private per-tenant channel (`POST /broadcasting/auth`, workspace host only,
  `communication.inbox.view`); the page reloads through its existing endpoints and slows polling to 60 s while
  connected. Added `pusher/pusher-php-server` 7.3.0, `laravel-echo` 2.5.0, `pusher-js` 8.6.0 (MIT; pusher-js pulls
  `tweetnacl`, Unlicense, not bundled). `laravel/reverb` 1.12.0 is NOT installed: it would downgrade Guzzle 8→7 and
  PSR-7 3→2 app-wide; owner decision pending (downgrade, wait for the next Reverb release, or run Reverb as a separate
  install). Smoke-tested against a scratch Reverb server only. Communication 1.5.0.
- **Browser check (Playwright, against the dev server).** Inbox, composer, Social accounts, Live chat (+ chat frame
  and embedded widget) and Super Admin Integrations at 1440/1024/360 in both themes: no sideways scroll, no console
  errors. Fixed: Button icons placed in the default slot (stacked above the label, or invisible inside the `sr-only`
  label of icon-only buttons) moved to `#icon` in 19 files; Instagram's Integrations card now says it uses the Meta
  app instead of "no credentials". Specs in `tests/e2e/session-verification/` (run only against :8000 so far).
- **Notification bell.** `Core/Components/Shell/NotificationBell.vue` in the workspace top bar: unread badge, the
  latest unread from `communication.notifications.summary`, mark one read and open it, mark all read, see all. Hidden
  without `communication.notifications.view` and in a support session.
- **Contacts: list and cards (Odoo style).** The people page switches between a dense list (initials avatar, a
  Location column, the name opens the details, server-side sorting from the headers) and cards (Odoo's kanban:
  avatar, job and company, email, phone, place, owner, the same row menu), remembered per browser. `?sort=` takes
  `name|company|email|city|created` with `?direction=asc|desc` (blanks last, id as the tie-break) and is kept in the
  page links. Checked in a browser at 1440 and 360 and in dark mode.
- **A company's own page (Odoo's company form).** `GET /companies/{company}` (`core.contacts.companies.show`,
  `core.contacts.view`, 404 outside the viewer's scope or workspace): the company's details with Edit/Delete, and its
  people as cards under "Contacts" (at most 60; the rest via the filtered people list) with "Add a contact" that opens
  the contact form with the company already chosen. The name on the companies list links to it; deleting from the page
  returns to the list. Contact and company rows, and the form choices, now come from one `Contacts\Http\DirectoryPresenter`
  shared by the three pages.
- **Typed addresses (Odoo's invoice/delivery/other, ADR 0039).** `contact_addresses` (tenant-owned, one parent: a
  company or a contact; migration `2026_11_22_000300_create_contact_addresses_table`, applied to dev) written only
  through `ContactDirectory` (one default per type per parent under a lock, audited on the parent without address
  text), five routes under `core.contacts.manage` authorized on the parent; merging contacts moves the duplicate's
  addresses. "Addresses" section on the company page, and in a contact's details dialog. Not built yet: choosing an
  invoice or delivery address on Invoicing/CRM documents.
- **Incident (2026-10-15):** another session's script emptied `app/Core/CoreServiceProvider.php` (13:37); it was
  restored from this session's Claude Code file-history snapshot of 14 Oct 20:48, checked against every later change
  by that session (none after a Pint run), and the app and the core-dependent tests came back green.
- **Per-route throttles.** `ThrottleRequestsPerRoute` replaces the `throttle` alias: a plain `throttle:N,M` now has one
  counter per route and limit (before, all routes of a user shared one counter and a route limit inside the throttled
  callback group counted each request twice). Two tests that leaned on the old double counting were corrected.

Final gates (2026-10-14, fresh clone of the dev schema `oyosend_final3_testing`): Pest 4,716 tests, 4,715 passed; the
one failure is `Platform/WebsiteAdminTest` from a concurrent, unfinished Website feature built in another session, not
this work. Larastan clean; Pint clean except `HandleInertiaRequests.php` (that same Website work); vue-tsc 0; Vitest
934 passed; `npm run build` ok. 149 more Bengali server strings merged; none missing.

Earlier gates on a fresh schema clone (`oyosend_int_testing`): Pint and Larastan clean on the whole project; full Pest run
4,471 tests, 4,464 passed first time and the 7 failures were stale test expectations (new modules, report providers,
the WhatsApp read-only AI tool, the documented Page-claim unique indexes now listed in `TenantIndexesTest`), fixed and
re-run green; vue-tsc 0 errors; Vitest 825 passed; `npm run build` ok. 377 Bengali server strings merged from
`storage/app/pending-bn` into `lang/bn.json`; every `__()` string in `app/` now has a Bengali translation.

Not verified: no live call to SSLCommerz, aamarPay, Facebook, Instagram or the Meta Marketing API; the chat widget was
not embedded on a real second origin; the new pages were not checked in a browser at 360px or in dark mode. The
Facebook comment inbox list reads every comment of a workspace per request (fine now, re-measure at volume). The
stateless callback group allows 60 requests a minute per IP and host: Meta and WhatsApp deliver webhooks from few IPs,
so a busy Page or number can exceed it (429, then platform retries and eventually a disabled subscription); the
webhook routes verify signatures anyway, so their limit should be raised or keyed differently before production.

Owner decisions taken on 2026-10-14:
- AI provider keys in the database are approved (ADR 0018, ADR 0013 amended).
- Reverb: wait for the `laravel/reverb` release that allows PSR-7 3; until then realtime stays off and the inbox polls.
- `tweetnacl` (Unlicense, a transitive, unbundled dependency of `pusher-js`) is accepted.
- Website-chat visitors may become contacts by an explicit member action (built).

Still waiting: Messenger message tags beyond 24 hours (need Meta approval); whether to commit this work separately
from the concurrent Website work.

## Operator AI provider accounts (2026-10-12)

- **Super Admin > AI providers** (`platform.ai.*`, `Platform/Pages/Ai/Providers`, behind `platform.settings.manage`):
  the operator adds several AI provider accounts (Anthropic, OpenAI-compatible chat-completions), each with name,
  model, optional endpoint, enable/disable, one platform default and a "test connection" (one 8-token call, stored
  as `ok` or a safe reason code). Extends ADR 0013; documented in `docs/AI.md` section 9 and `docs/SECURITY.md` 5d.6.
- **Storage**: `ai_provider_accounts` (central connection), key in `secrets` with `encrypted:array`, hidden,
  write-only (empty on edit keeps it), never in props, audit rows, logs or exception messages.
- **Resolution**: `ProviderManager` uses the enabled default account; accounts that exist without an enabled default
  give an honest `not_configured`; with no account the environment (`config/ai.php`) behaves exactly as before.
  Guardrails and the circuit breaker are unchanged; the breaker is reset when an account changes.
- **Tests**: `tests/Feature/Ai/ProviderAccountsTest.php`. Adapters run only against `Http::fake`; **not verified
  against any live Anthropic, OpenAI, Azure, OpenRouter or Ollama endpoint**, and the OpenAI-compatible adapter
  sends `max_tokens` (some newer OpenAI models want `max_completion_tokens`). Bengali strings are machine-written
  and need native review. No composer dependency was added.

## WhatsApp Inbox Kanban board (2026-10-12)

Added a Kanban board view to the WhatsApp Business Inbox (`whatsapp_marketing.inbox.index`). A List / Board toggle
appears above the filter bar; `?view=kanban` is the URL parameter. The board shows three columns fixed by workflow
position: **Unassigned** (open, no owner), **In progress** (open, has owner), **Closed** — each up to 50/50/25 items.
Search and tag filters still apply across columns in board mode; status/assignee/unread are replaced by the columns
themselves and hidden when the board is active.

Changed files: `InboxController::index()` (validates `view`, branches on `kanban` to build columns without running
the paginated list query), `Components/types.ts` (`KanbanColumn` type), `en/whatsapp.ts` and `bn/whatsapp.ts`
(new `inbox.viewList`, `inbox.viewBoard`, `inbox.col.*`, `inbox.boardMore` keys), `Pages/Inbox/Index.vue`
(view toggle, conditional filter bar, kanban board layout).

Gates: `vue-tsc` — no errors; Vitest — 692 passed; Larastan on `InboxController` — no errors.

## Phases 9-13 verification session (2026-10-13)

What was done: independent adversarial reviews of Social/Automation/AI, Support/Reports and
Communication/Email/SMS/WhatsApp (no HIGH issue in the first two; two HIGH consent bypasses in the third), a
regression review of the fixes, a browser QA pass over every new app (62 URLs per workspace, 1440 and 360 px, both
themes, English and Bengali), an accessibility pass, a Playwright run (41 specs) and the Phase 13 hardening work.
Every verified finding was fixed with a test; the lists are in the module docs and ADRs 0014-0019.

| Check | Command | Result |
|---|---|---|
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `composer analyse` (Larastan level 6, 1,4xx files) | no errors |
| Backend tests | `vendor/bin/pest` on a dedicated database | 3,885 passed, 0 failed, 43,985 assertions, 31 min |
| Type check | `npm run typecheck` | no errors |
| Frontend tests | `npm run test` | 33 files, 698 tests passed |
| Production build | `npm run build` | built |
| End-to-end | Playwright (`tests/e2e`, 41 specs) | passed on a freshly built database; three older specs fail when the database is reused (they need a fresh build; setup does that) |

Pre-release schema note: two migrations that had already been committed were edited in place and then restored;
the two columns now ship as new guarded additive migrations. Migrations never run on any production database may be
edited in place (CLAUDE.md); that ends with the first deploy.

### Honest limits (nothing here was run against a live service or in production)
- No provider adapter was exercised live: SSLCommerz, SMTP, Twilio/HTTP SMS, WhatsApp Cloud API, Facebook,
  LinkedIn, X, Anthropic/OpenAI-compatible. SMS has no adapter unless an operator configures one.
- Not run: GitHub Actions CI, Sail/Docker, Horizon (Windows has no pcntl), Nginx/TLS/HSTS, the CSP in enforce mode,
  `route:cache`, S3 storage, multi-tenant load and queue contention, production-size backups and PITR.
- Bengali is machine-written and has had no native review; uncertain terms are listed in the reviews.
- The AI page was not exercised by Playwright (Business plan has no AI).
- Reviews were by agents, not by a human; none of the Phase 9-12 modules is signed off.

### Needs the owner's input (new)
1. **Operator AI provider accounts store an encrypted key in the database** (`ai_provider_accounts`). ADR 0013
   covers messaging accounts only; ADR 0018 records AI as an open decision. Approve (amend ADR 0013) or remove.
2. Which SMS gateway(s) to support (each needs its documented API and signed webhooks).
3. Real HTML email (needs `htmlBody` through the contract, outbox and mailer).
4. Whether a confirmed double opt-in may lift a contact's platform unsubscribe (currently refused); whether a lapsed
   workspace keeps its confirmation links; whether the confirmation mail should use the outbox.
5. Whether bare "stop"/"unsubscribe"/"বন্ধ" WhatsApp messages opt out directly (they do now, exact match only).
6. The eight unique indexes that do not lead with `tenant_id` (`invoices.number`, `tenant_domains.domain`, token
   hashes, ...) are documented exceptions; confirm.
7. `automation.runs.start` holders choose the payload of a manual workflow; runs act as the publisher with `all` scope.
8. No operator UI to release a communication account claim; `automation.run.failed` and
   `automation.approval.requested` have no listener; the `webhooks` queue is supervised but nothing dispatches to it.
9. Final app prices (placeholders) and `ANTHROPIC_API_KEY`.
10. **ADR 0020 (accepted 2026-10-13): Customer 360, unified inbox and Messenger.** Still open, needed before
    Messenger: a central Messenger webhook route that resolves a Page id to a workspace; storing a workspace's
    Messenger Page token encrypted in Communication. Also open: whether Support tickets join the unified inbox;
    whether the inbox lives in the Communication app. **Rollout step 1 built:** an inbound WhatsApp message the
    platform could not match to a contact now reaches the contact's timeline (`ConversationTimeline`, queued
    `BackfillConversationTimeline` when a conversation without a contact gets one). Tests:
    `tests/Feature/WhatsappMarketing/ConversationTimelineTest.php`. Not covered: outbound replies sent while a
    conversation had no contact.
    **Rollout step 2 built (Customer 360):** core `CustomerRecordProvider` + `CustomerRecordRegistry`, endpoint
    `GET /contacts/{contact}/records`, six sections (CRM leads/deals/activities, WhatsApp conversations, Support
    tickets, Communication timeline), `CustomerPanel` on the CRM customer page and beside a linked WhatsApp
    conversation, and `?create_for=` prefill on the CRM lead/deal/activity lists. Tests:
    `tests/Feature/Contacts/CustomerRecordsTest.php`, `tests/js/customer-panel.test.ts`, `tests/js/crm.test.ts`.
    Not built: company subjects, a core contact page. Not tried in a browser.
    **Rollout step 3 built (Unified inbox):** owner decided Support joins and the inbox lives in Communication.
    Core `InboxSource` contract; `UnifiedInbox` merge with one paging position per source; `/communication/inbox`
    list + board; sources WhatsApp conversations and Support tickets; new permission `communication.inbox.view`
    (granted to no role; 252 permissions; Communication bumped to 1.1.0 because its manifest changed). Tests:
    `tests/Feature/Communication/UnifiedInboxTest.php`, `tests/js/unified-inbox.test.ts`. Not built: reply pane,
    live updates. Not tried in a browser. Step 4 (Messenger) waits on owner decisions 1–2 and Meta app review.
11. **ADR 0021 (proposed 2026-10-13): WhatsApp Business 1.1 and six larger gaps.** Built (WhatsApp 1.1.0, new
    migration `2026_10_29_000100_add_whatsapp_engagement_tables`, no migration edited in place): reply buttons and
    list messages from agents and chatbot menus (`whatsapp_interactive` provider hint, `Support\Interactive`);
    internal notes; optional customer ratings after a person closes a conversation (`FeedbackCollector`, trigger
    `whatsapp_marketing.feedback.received`, analytics); lists filled once from contact rules (`ContactRules`);
    AI reply drafts through the AI app's drafting service and a read-only `whatsapp_conversation_get` tool behind the
    opt-in data scope `whatsapp_conversations`; CRM action `crm.lead.create` and triggers `crm.quote.accepted`,
    `crm.sales_order.status_changed` (new `SalesOrderStatusChanged` event); `new_conversation` filter on the WhatsApp
    message trigger; `maximum_x` trigger filters in the workflow engine. No new permission. Tests:
    `tests/Feature/WhatsappMarketing/EngagementTest.php`, `AiReplyDraftTest.php`, `tests/Feature/CRM/CrmLeadCaptureTest.php`,
    a new case in `WorkflowEngineTest`, `tests/js/whatsapp.test.ts` ("whatsapp engagement"); `FlowsAndBotTest`'s menu
    test now expects buttons. Not tried in a browser or against live Meta / a live AI provider. **Owner decisions
    (ADR 0021 Part B):** several WhatsApp numbers per workspace; AI replying to customers by itself; templates through
    Meta's Management API; product catalogue / carts (needs a commerce app); appointment reminders (needs a booking
    app or a CRM activity-due trigger); Meta WhatsApp Flows.
12. **Layout pass (2026-10-14).** The workspace and panel content is capped at `max-w-[88rem]` (was 78rem). Settings
    and form pages that sat in a narrow column pinned to the left (`max-w-xl` to `max-w-4xl`) now use the full width:
    a `lg:grid-cols-2 xl:grid-cols-3` grid of cards, one save row under them, fields laid out by each card's own width
    (container queries). Pages: Platform Settings, Integrations, Messaging providers, Plans edit, New workspace; Profile,
    Security, Onboarding; AI, Communication (with its channel cards), Notification preferences, Compose, Support and
    Live chat settings; Email (settings, imports, sequence form, block editor), SMS, Social (settings, imports), HRM and
    Talent settings. Names beside a badge wrap instead of being cut (AI features and tools, Meta Ads connections, ad
    accounts and pages, Social comment pages, rules, message capture, account access). **Behaviour change in
    `Input`/`Select`/`Textarea`:** `class` and `style` now go on the field wrapper, so the ~45 `sm:col-span-*` and the
    `flex-1` search fields that silently did nothing now take effect; control styling moved to the new `control-class`
    prop (11 places). Also fixed: the Email import "Download template" opened an Inertia visit to a CSV (now a plain
    link, like Social); a bad Social AI daily cap no longer marks the hourly-limit field invalid. Checks: vue-tsc,
    Vitest 46 files (new "form fields" case in `components.test.ts`), `npm run build`. Not checked in a browser.
13. **Public website (2026-10-14, ADR 0034, `docs/WEBSITE.md`).** The root domain is now the company website, not the
    one Inertia welcome page: home, apps overview, one page per optional module (17), pricing, security, about, FAQ,
    contact (queued mail to info@oyosend.com), sign-in finder, privacy, terms and refund policy, each in English at the
    root and Bengali under `/bn`. Server-rendered Blade (`resources/views/site`, `App\Core\Website`) so crawlers that
    run no script read every word; one 1.5 kB script. Per page: canonical, `hreflang`, Open Graph/Twitter, JSON-LD
    (Organization with the Uttara address and both phones, WebSite, WebPage, breadcrumbs, SoftwareApplication with offers
    from the plans or the app's own App Store offer, FAQPage) and an "In short" answer box for answer engines.
    `robots.txt` (static file removed), `sitemap.xml`, `llms.txt`; `X-Robots-Tag: noindex` on workspaces and the admin
    panel; CSP `form-action` also allows `*.APP_DOMAIN`. Copy in `lang/{en,bn}/site*.php`, checked against manifests and
    docs (features marked not built are left out); Bengali follows the glossary and is NFC. `Welcome.vue`, the `central`
    vue-i18n namespace and `WelcomeController` were removed (`FindWorkspaceController` keeps the finder); sign-up pages
    link to the website with full page loads (`Button` gained `native`). Tests: `tests/Feature/Central/WebsiteTest.php`;
    old root-page assertions moved to `/register`. Checked in Chrome at 1440px and 360px, light and dark, no sideways
    scroll. **Owner decisions:** the legal pages are drafts for a lawyer (refund window of 7 days and the cases refunded,
    payout in 7-10 working days, liability capped at 12 months' fees, notice periods, the legal entity and trade licence,
    provider names, when a closed workspace is deleted, whether gateway fees are deducted from refunds); plan names and
    descriptions show as typed (English) on Bengali pages; SSLCommerz and aamarPay are named but not yet run against
    their sandboxes. Not measured: Lighthouse, Search Console, Rich Results Test.
14. **Website managed from the Super Admin panel (2026-10-14, ADR 0034 amendment).** Super Admin > Website: Messages
    (contact-form enquiries now stored in `website_enquiries`, searchable, marked answered or deleted, audited),
    Company (name, email, phones, English and Bengali address, map link, contact recipient, Facebook/LinkedIn/YouTube/
    Instagram/X pages as `website.*` platform settings over `config/site.php`; footer icons and `sameAs`), Words (every
    word of every page in both languages, stored in `website_texts` and laid over `lang/*/site*.php` by a translation
    loader decorator, so templates are unchanged; words only, placeholders kept, reset per part). Plans gained
    `name_bn`/`description_bn` for the Bengali website. New migration `2026_11_20_000100_create_website_tables` (applied
    to the dev database), abilities `platform.website.view` (also Support Admin) and `platform.website.manage`, morph
    alias `website_enquiry`, mailable renamed `WebsiteEnquiryMail`. Tests: `tests/Feature/Platform/WebsiteAdminTest.php`.
    The panel pages were not opened in a browser (the panel needs a two-factor sign-in).
15. **Logo and favicon upload (2026-10-14).** Super Admin > Website > Company: logo, logo for dark backgrounds and
    favicon, each uploaded on its own (`SiteBrand`, `BrandController`), used by the website (header, footer, structured
    data, favicon and touch icon) and by every Inertia area (shared `app.brand`, `Logo.vue`; favicon in `app.blade.php`).
    Raster only (SVG refused), size/dimension checks, square favicon; stored on `site.brand.disk` under
    `platform/brand/` with content-hash names, served by the stateless central route `/brand/{file}` (`central.brand`)
    with immutable caching and a sandboxing CSP; replacing or removing deletes the old file. Tests in
    `WebsiteAdminTest` ("the logo and favicon") and `tests/js/platformWebsite.test.ts`.
16. **Home hero redesigned (2026-10-14).** After a reference the owner shared: a split hero (headline with an underlined
    phrase, true figures, a glass "start your workspace" card) over drifting brand light, then a workspace drawn in HTML
    that tilts up into place on scroll with a floating "the assistant drafted, you decide" card. The card posts to the new
    `central.register.start`, which carries company and email to the sign-up form in the session (`prefill` prop).
    The reference's invented metrics were not copied. Checked in Chrome at 1440px and 360px, light and dark, English and
    Bengali, with motion on and off; no sideways scroll.

## Notes app added (2026-10-13)

New first-party optional module `notes` (category Productivity, one free offer, depends on `core` only). It follows
the standard module shape in `docs/MODULES.md`; no new pattern and no locked decision touched. Doc: `docs/NOTES.md`
(linked from `docs/INDEX.md`; table row and tables in `docs/MODULES.md` / `docs/DATABASE.md`).

- **What it is.** Short notes a member can pin and optionally link to a shared `Contact` / `Company`. One table
  `notes` (migration `2026_10_13_000100_create_notes_tables.php`, new file, never run in production). The model uses
  `BelongsToTenant`, `HasUlids` and `HasRecordOwner`, so reads honour the `own` / `department` / `branch` / `all`
  scope through `RecordAccess`. Customers stay the shared identity — no notes-owned customer table.
- **Permissions (2, both scopable):** `notes.notes.view`, `notes.notes.manage`. Routes `notes.index|store|update|
  destroy` under `['auth:web','auth.session','member','subscribed']` + `entitled:notes`, each with its `can:`.
  Writes go through `Actions\NoteManager` (audit inside the transaction, `NoteCreated` after commit).
- **Published to other apps:** automation trigger `notes.note.created` (no action, by design); report provider
  `notes.by_author`; Customer 360 section `notes.notes` (`NotesSection`). Morph alias `notes_note`.
- **Frontend:** one page `resources/js/Modules/Notes/Pages/Index.vue` (pinned-first list, create/edit modal, delete
  confirm). i18n namespace `notes` (en + bn); catalogue/automation/shell labels mirrored in both languages;
  server strings added to `lang/bn.json`.
- **Tests:** `tests/Feature/Notes/NotesTest.php` (tenant isolation, cross-workspace 404 on the id routes,
  not-entitled 404, owner CRUD/pin, viewer-cannot-create, and the registered trigger / report / customer section).
- **Stale aggregate counts corrected in the same change** (they had already drifted when `meta_ads` was added, not
  by this work): `ModuleRegistryTest` module-key list (now includes `meta_ads` and `notes`) and permission total
  (`252` -> `268`); `AppCatalogTest` app/version counts (`16` -> `18`, i.e. 17 optional modules + the `test_crm`
  fixture); and the module/permission counts in `CLAUDE.md`, `docs/MODULES.md` and `docs/INDEX.md`.
- **Not done:** no browser pass; no dashboard widget (easy to add later); `apps:sync`/`permissions:sync` must be run
  on deploy as usual.
- **Google-Keep-style redesign (same session).** The page is now a Keep board: inline "take a note" composer,
  pinned section above others, responsive masonry card grid, per-note `color` (contrast-safe `*-soft` tokens only,
  no raw palette), quick pin/delete on hover, labelled edit modal. This added a nullable `color` column and made
  `title`/`body` nullable (a note needs at least one). **The `create_notes` migration was edited in place**
  (pre-release rule); the `notes` table had already run on the local dev DB, so that one DB got the same change by an
  additive `ALTER TABLE` (demo data kept). The demo `business`/`enterprise` plans now include `notes`, so the Acme
  demo workspace shows it. Catalogue synced on the dev DB (`permissions:sync`, `apps:sync`).

## Invoice document templates and letterhead (2026-10-13)

Invoicing gained a choice of document layout and an uploadable letterhead ("pad"). No locked decision touched; it
reuses existing patterns (settings row, `TenantStorage`, browser-print documents). Doc: `docs/INVOICING.md`
("Template and letterhead").

- **What it is.** `invoicing_settings.document_template` (`Enums\DocumentTemplate`: `classic` default, `modern`,
  `compact`, `letterhead`) picks one of four layouts for the print document. `letterhead` lays the invoice on the
  workspace's own header and footer image bands (`letterhead_header_path` / `letterhead_footer_path`), hiding the
  typed sender block because the pad carries it.
- **Why inline bands, not a full-page background.** Bands are inline `<img>` embedded as data URIs (like the logo),
  so they print through the browser without "background graphics" and the no-PDF-library decision stays intact (a
  true full-page pad would need a server-side renderer; dompdf/mpdf are LGPL/GPL, outside the permitted licences).
- **Columns / migration.** Three columns added to `invoicing_settings`: `document_template`, `letterhead_header_path`,
  `letterhead_footer_path`, via a **new additive migration** `2026_10_13_000300_add_document_template_to_invoicing_
  settings.php` (reversible), so existing dev databases take it with `php artisan migrate` without a `migrate:fresh`.
  No new permission.
- **Code.** `Actions\LetterheadAssets` (upload/replace/remove through `TenantStorage` under `invoicing/letterhead/`,
  PNG/JPG/WebP ≤ 2 MB, ≤ 3000×3000, audited `invoicing.letterhead.updated` / `.removed`); `InvoiceDocument` now
  reads the template and bands and exposes them to the Blade; `document.blade.php` branches per template; routes
  `invoicing.settings.letterhead.upload` / `.destroy` (`POST`/`DELETE /settings/letterhead/{header|footer}`, under
  `invoicing.settings.manage`). Frontend: `Components/LetterheadBandCard.vue` and a template Select on the settings
  page; i18n en + bn; server toasts in `lang/bn.json`.
- **Tests.** `tests/Feature/Invoicing/DocumentTemplateTest.php` (template saved + validated, band upload stored and
  audited, non-image and unknown-slot rejected, remove deletes the file, and the document renders the bands only
  under the letterhead template). Existing `InvoicingHttpTest` settings-update payload updated for the new required
  field.
- **Not done:** no browser pass; per-template visual polish is deliberately light (accent / spacing only); the
  letterhead has no adjustable content margins yet (bands sit flush, content flows between).

## Inbox section rail (2026-10-14)

On the unified inbox's chat screen the Communication sections moved from the tab row into a WhatsApp-style icon
rail on the card's left edge (md and up): `CommunicationNav` gained `variant="rail"` (Inbox, Notifications,
Messages, Templates, Consent and suppression; Settings pinned to the bottom; permission-filtered;
`aria-current="page"`; icon-only with an sr-only name and a hover title). Below `md`, in the board view and on
the other Communication pages the tab row is unchanged. No server change, no new string. Test:
`tests/js/communication-rail.test.ts`. Doc: `docs/COMMUNICATION.md` ("The chat screen"). Not committed; made on
top of the uncommitted ADR 0020 inbox work in `Inbox/Index.vue` (two small template edits).

## Dark sidebar (2026-10-14)

The shell sidebar is now always dark, in the workspace and the Super Admin panel, on desktop and in the mobile
drawer, whatever theme the person chose for the page. `AppShell.vue` sets `data-theme="dark"` on the `<aside>` and
the drawer's `DialogContent`, both drawn with `glass-solid` (92% opaque; the 62% `glass` looked muddy grey over a light page); the existing `[data-theme='dark']` token block then applies inside them (colours and
`color-scheme`), so no new colour token and no contrast change. Relies on `@theme inline` and on no `:root` token
being derived from another (both noted in `resources/js/README.md`). Menus opened from the sidebar render in a portal
and keep the page theme. Not committed; `AppShell.vue` also holds uncommitted concurrent work.

## Rotating hero title (2026-10-15)

The home page title's highlighted ending now takes turns with other endings every 2.8 s (`site.home.title_rotate`
in `lang/en|bn/site.php`; `[data-rotate]` in `home.blade.php`, `.site-rotate` in `app.css`, the loop in
`resources/js/site.ts`). Screen readers get the first ending only (the visible one is `aria-hidden`, a hidden
static copy sits beside it); reduced motion and no JavaScript keep the original text; a hidden tab skips its turns.
Not committed; the site is still uncommitted work.

## Telephony app added (2026-10-15)

New first-party optional module `telephony` (category Customer Support, one free offer, depends on `core`): call log,
click-to-call, a dialer and a browser softphone, through each workspace's own PBX (Asterisk/FreeSWITCH) that holds a
licensed Bangladeshi IPTSP's SIP trunk. Decision record ADR 0036 (owner chose: own PBX, each workspace configures its
own, build now against fakes). Doc: `docs/TELEPHONY.md` (linked from `docs/INDEX.md`; rows in `docs/MODULES.md`,
`docs/DATABASE.md` section 3k4, `docs/SECURITY.md` 5d.12). **Built, unreviewed, never run against a live PBX.**

- **Tables** (new migration `2026_10_15_000100_create_telephony_tables.php`): `telephony_settings`,
  `telephony_extensions`, `telephony_calls`, `telephony_webhook_events`. Secrets encrypted and write-only. No recording.
- **Permissions (3):** `telephony.calls.view` (scopable), `telephony.calls.make`, `telephony.settings.manage`. Totals
  now `core` + 18 optional modules and 273 permissions (counts updated in CLAUDE.md, docs/MODULES.md and the tests).
- **Softphone:** new dependency `jssip` 3.13.8 (MIT; its dependencies debug, events and sdp-transform are MIT). The
  member's own SIP password is handed only to their browser on request (ADR 0036, SECURITY 5d.12).
- **Bridge calls:** Asterisk ARI adapter behind `PbxClient`, with an SSRF guard (`PbxUrlGuard`, https, public addresses,
  pinned) and a fake bound only in local/testing (`driver = fake`). New env flag `TELEPHONY_ALLOW_PRIVATE_PBX`.
- **Call log:** PBX events (HMAC-signed, `tenant.callback`, replay window, idempotent) are the record; the member's
  browser keeps unconfirmed rows live. Customer 360 section `telephony.calls` with a "Call" action, report
  `telephony.calls_by_member`, trigger `telephony.call.ended`.
- **Frontend:** `resources/js/Modules/Telephony/Pages/{Dialer,Calls/Index,Settings/Index}.vue`, `lib/{softphone,phone,http}.ts`;
  i18n namespace `telephony` (en + bn), catalogue/shell/automation labels, server strings in `lang/bn.json`; `phone`
  and `history` icons added to `Core/Components/Icon.vue`.
- **Tests:** `tests/Feature/Telephony/TelephonyTest.php`, `tests/js/telephony.test.ts`; list/count updates in
  RoutePipelineTest (stateless `telephony.events`), ModuleRegistryTest, AutomationRegistryTest, AppCatalogTest,
  EntitlementTest and PlanManagementTest.
- **Not done:** no live PBX/browser/IPTSP run; no hold, transfer, queues or IVR; no usage limit.

## Website blog added (2026-10-15)

A blog for the public website, written in Super Admin > Website > Blog. Decision record ADR 0037; behaviour in
`docs/WEBSITE.md` (section "Blog"); tables in `docs/DATABASE.md` section 2. **Built and tested, unreviewed, not yet
checked in a browser.**

- **Tables** (new migration `2026_11_21_000100_create_blog_tables.php`): `blog_posts`, `blog_post_translations`,
  platform-wide (no `tenant_id`), central connection. Morph alias `blog_post`.
- **Public:** `/blog` (topics, app filter, featured lead, pages of twelve), `/blog/{slug}` (only in the languages it is
  written in), `/blog/feed.xml` (Atom), the same under `/bn`; stateless `/blog/media/{file}` for pictures. Sitemap,
  `llms.txt`, header, footer, home page and app pages link to it once there is an article in that language.
  `BlogPosting` / `Blog` / `FAQPage` structured data; `PageMeta` gained a share picture, article times, language
  fallbacks and a feed link; the shared page frame moved to `App\Core\Website\SitePages` (SiteController delegates).
- **Safety:** Markdown rendered on the server with raw HTML escaped, unsafe links dropped and only the blog's own
  pictures kept; uploads redrawn with GD (metadata dropped, upright, at most 2400 px wide); the admin preview is drawn
  by the server into an iframe with an empty `sandbox`; no `v-html`.
- **Admin:** `Platform/Pages/Website/Blog/{Index,Edit}.vue`, helpers in `Platform/Components/Website/blogEditor.ts`,
  a "Blog" tab in `WebsiteNav`, i18n `platformWebsite.blog` (en + bn), server strings in `lang/bn.json`, words of the
  blog pages editable under Words > "Blog pages". `platform.website.manage` only; saves audited
  (`platform.website.post_*`).
- **Tests:** `tests/Feature/Central/BlogTest.php`, `tests/Feature/Platform/BlogAdminTest.php`,
  `tests/js/platformBlog.test.ts`; `tests/js/platformWebsite.test.ts` now expects the Blog tab.
- **Not done:** pictures are never deleted automatically; no tags, comments, search or analytics; no browser or
  Lighthouse pass. Not committed.
- **Incident:** while this was built, a patch script in this session wrote `app/Core/CoreServiceProvider.php` as
  0 bytes (an unchecked `preg_replace` result). It was restored by session oyosend-f9 from Claude's file history
  (the 2026-10-14 snapshot, which every later change was checked against: only a no-op pint run came after it), and
  the blog's two lines were then added with the Edit tool. No other file was affected (checked for empty files).

## Voice commands proposal (2026-10-15)

The owner asked for voice commands. ADR 0038 proposes them (not built; no code changed): speech becomes a transcript
for the command palette or the AI assistant box and never acts on its own; per the owner, "run without pressing
Enter" is a member preference (off by default). Stage 1 uses the browser's Web Speech API, stage 2 transcription
through `AiGateway`. Side finding recorded in the ADR: `SecurityHeaders` sends `microphone=()` on every response,
which also blocks the Telephony softphone's `getUserMedia`. The owner then decided that voice is part of the paid AI app (new permission
`ai.voice.use`, switch on `ai_settings`) and that the member preference needs no workspace override. Still waiting
for the stage 1 engine decision before building.

## Whole-app check and fixes (2026-10-15)

A review of this session's contacts, inbox, bell and palette work found three defects, now fixed:

- `ContactDirectory::updateAddress` / `deleteAddress` decided from the address as bound to the request, read before the
  parent lock, so two requests at once (delete the default while deleting the address that inherits it) could leave a
  type with no default. Both now re-read the row under the lock (`reread()`, a 404 if it was deleted meanwhile);
  `makeDefaultAddress` no longer short-circuits on the stale copy. Test in `ContactAddressesTest`.
- The contacts grid's sort select lacked choices the list headers can make (email, company and city descending), so
  it showed blank after switching views. It now offers all ten (`contacts.sort.*` in en and bn).
- The notification bell let a summary already on its way overwrite "mark read"/"mark all read" with the old items;
  those actions now abort it. Test in `tests/js/notification-bell.test.ts`.

A crawl of every page reachable by links (Playwright, signed in as the Globex owner, the Acme owner, a Globex employee
and the Super Admin) found three more, fixed on 2026-10-17:

- The App Store detail page passed its app as the page prop `app`, which replaced the shared `app` prop (locale,
  numerals, timezone, currency) on `/apps/{key}`: every number, date and amount there was formatted without them, and
  an app with a trial offer (`/apps/ai`) threw `Invalid language tag: en-bd-u-nu-undefined`. The prop is now
  `storeApp`; `AppStorePagesTest` asserts the shared `app.numerals` survives.
- Super Admin > Website > Messages called `route('central.home')`, which is not in the panel's route list, so the page
  threw on load. The controller now passes the website address as `website` (as the Company page already did).
- Development only: Vite bound `localhost` to `[::1]`, which a Content-Security-Policy source cannot name, so every
  page logged five CSP warnings and enforcing the policy would block the dev server. `vite.config.ts` pins
  `server.host` to `127.0.0.1`.
- `ReportsPlatformTest` entitled a fixed list of modules, so the reports Links, Telephony and Calendar register asked
  for permissions the test workspace lacked; it now entitles every optional module of the build.

Gates on 2026-10-17: Pint, Larastan (0), vue-tsc (0), Vitest 999 passed, build OK; full Pest (4 processes)
4,852 tests, 4,849 passed. The three failures came from apps added by concurrent sessions: the Reports one above
(fixed), and two counts that the new Links/Telephony/Calendar apps changed (`PlatformPagesTest` `stats.modules`,
`AppCatalogTest` app totals) plus `WebsiteTest` wanting a site page for every module (Calendar has none yet). Those
are left to the session building Calendar, which was editing the same files at the time.

**Local MySQL incident (2026-10-15 to 2026-10-17).** A Pest run with `--parallel` was migrating its worker databases
when the session that had started `mysqld` ended and took the server down mid-DDL. InnoDB then refused to start
(tablespace ids of those test databases no longer matched the data dictionary), and after a force-recovery start its
undo tablespace proved corrupt (the server crashed again under load). With the owner's approval the server was
rebuilt: the old data directory is kept at `D:/laragon/data/mysql-8-corrupt-20261015` (and a byte-for-byte copy from
before any recovery at `D:/laragon/data/mysql-8-backup-20261015`), every non-test database was dumped to
`D:/laragon/data/dumps-20261015` while the server ran with `--innodb-force-recovery=1`, a fresh data directory was
initialised and the 37 dumps imported; table and row counts match the dumps. The `*_testing` databases were not
carried over (tests recreate them); MySQL accounts other than `root` would need recreating. Lesson: never start
`mysqld` from an agent session; let Laragon own it.

## Links app (2026-10-15)

New optional app `links` (owner's request: one organised place for the team's tools, email addresses and useful
website links). Free, Productivity, depends on `core` only; same shape as Notes (`docs/LINKS.md`).

- One `links` table: `kind` (website / tool / email / document / other), `title`, `address`, optional `description`
  and free-text `folder`, `is_pinned`, record-owner columns. Two scopable permissions `links.links.view|manage`
  (one more optional app, two more permissions). Trigger `links.link.created`, report `links.by_kind`, no Customer
  360 section.
- Security: an email link takes only an address, every other kind only an `http`/`https` URL (validated server-side;
  `linkHref()` checks again before any `href`). No secret field; the form tells people to use a password manager.
  Nothing is fetched from a saved address.
- Page `Modules/Links/Pages/Index`: search, kind filters with counts, folder filter, pinned section, cards with copy /
  pin / edit / delete, one add/edit modal. en + bn strings, App Store text in both languages, `link` icon.
- New migration `2026_11_23_000100_create_links_tables` (nothing edited in place). The development database needs
  `php artisan migrate --path=app/Modules/Links/Database/Migrations` and `php artisan permissions:sync` /
  `apps:sync` before a workspace can install it; until then the app is simply not entitled anywhere.
- Demo Business and Enterprise plans include `links`. Count tests updated (module lists, permission total, catalogue
  app total, plan module list, trigger list, `DECLARED_BY_LINKS`).
- Not verified in a browser.
