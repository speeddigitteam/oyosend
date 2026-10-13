# Testing

Backend tests are Pest on a real MySQL database. Frontend tests are Vitest. Playwright covers critical browser
flows against a separate disposable database. Phase 4 CRM has backend, frontend and browser workflow coverage;
Phase 5 Projects has backend, frontend and browser workflow coverage.

## 1. Commands

| Command | Runs |
|---|---|
| `composer test` | `php artisan config:clear`, then `php artisan test` (Pest) |
| `php artisan test --filter="isolates invitations"` | One test by name |
| `vendor/bin/pest tests/Feature/Tenancy` | One folder |
| `composer lint` | `vendor/bin/pint --test`. Fix with `vendor/bin/pint` |
| `composer analyse` | Larastan (`phpstan.neon`: level 6; `app`, `bootstrap/app.php`, `config`, `database`, `routes`) |
| `composer check` | lint, analyse, test, in that order |
| `npm run typecheck` | `vue-tsc --noEmit` (strict; covers `resources/js` and `tests/js`) |
| `npm run test` / `npm run test:watch` | Vitest |
| `npm run build` | Production Vite bundle |
| `npm run test:e2e` | Playwright Chrome flows against `oyosend_e2e_testing` |

A change is done when `composer check`, `npm run typecheck` and `npm run test` all pass.

**One run per database.** The suite starts with `migrate:fresh`, so two runs against the same database destroy
each other. To run tests in a second terminal (or from a second agent), give that run its own database:
`DB_DATABASE=oyosend_b_testing php artisan test tests/Feature/Billing` (create the database first; its name must
end in `_testing`). A variable set in the shell wins over `phpunit.xml`.

- There is no `pint.json`: Pint uses its default Laravel preset.
- `tests/` is not in Larastan's paths. `phpstan/stubs/TenantCollection.stub` restores the generics the tenancy
  package's collection class lacks; it is the only stub.
- **Windows, Git Bash:** start Vitest from a path with an upper-case drive letter (`cd D:/laragon/www/oyosend`).
  Started from `/d/...`, every test file fails with `Cannot read properties of undefined (reading 'config')`.

### Running the browser (Playwright) suite

`npm run test:e2e` needs MySQL, a current `npm run build`, and Google Chrome (`channel: 'chrome'`). The config
(`playwright.config.ts`) serves the app with `php -S` on port 8010 against `oyosend_e2e_testing`, and
`tests/e2e/global-setup.ts` rebuilds that database (`migrate:fresh --seed`) before every run. The specs use
the seeded demo logins (`password`); workspaces are `{slug}.localhost`, so no hosts-file entry is needed.

- Beside another run (or another agent), give yours its own port and database:
  `E2E_PORT=8011 E2E_DATABASE=oyosend_e2e2_testing npm run test:e2e` (the name must end in `_testing`).
- On a slow shared MySQL the rebuild takes many minutes. Build the database once yourself with `migrate:fresh --seed`
  (or `migrate`, then `db:seed`) under the same `DB_DATABASE` and start Playwright with `E2E_SKIP_SETUP=1`.
  A migration added after that build must be applied with `migrate --force` on the same database.
- Most older specs change shared state (an admin enrols two-step verification, the sign-up spec takes an address,
  a contact's email is used). Run them against a freshly built database; `tests/e2e/apps.spec.ts` (one smoke
  flow per optional app, plus two Bengali flows) creates uniquely named records and can be re-run.
- Page titles in `apps.spec.ts` are read from the `Core/i18n/locales/{en,bn}` catalogues, not typed again.
- `PLATFORM_REQUIRE_2FA=true` is forced by `support/env.ts`, so a developer's `.env` cannot change the admin flows.

## 2. The test database

- Tests use MySQL, database **`oyosend_testing`** (`phpunit.xml`), not SQLite: production is MySQL and the code
  uses MySQL-only SQL (ADR 0004). Create it once: `CREATE DATABASE oyosend_testing;`. Under Sail,
  `docker/mysql/create-testing-database.sh` creates it when the MySQL volume is first initialised.
- `phpunit.xml` sets only the connection and the database name. Host, user and password come from `.env`.
- Feature tests use `RefreshDatabase`: the schema is rebuilt with `migrate:fresh` at the start of a run and every
  test runs in a transaction that is rolled back.
- **Guard:** `Tests\TestCase::refreshApplication()` throws unless the configured database is named `testing` or
  ends in `_testing` (or `_testing_test_N`, the names `artisan test --parallel` uses). A cached config or a
  stray shell variable therefore cannot point `migrate:fresh` at a real database. If you hit the guard, run
  `php artisan config:clear`.
- Do not use `php artisan migrate:fresh --env=testing` as a substitute for the test runner. When no
  `.env.testing` file exists Laravel falls back to `.env`, and that standalone migration does not pass through
  the `Tests\TestCase` database-name guard. Use `php artisan test`, or set and visibly confirm an explicit
  `DB_DATABASE=*_testing` value first.

Other settings from `phpunit.xml`:

| Setting | Value | Consequence |
|---|---|---|
| `APP_URL`, `APP_DOMAIN` | `http://localhost`, `localhost` | Workspaces are `http://{slug}.localhost`, the panel `http://admin.localhost`, no port |
| `APP_DEBUG` | `false` | Error responses are rendered the way production renders them |
| `CACHE_STORE` | `array` | No Redis needed. The array store ignores key prefixes and is thrown away on every context switch, so nothing cached survives a tenant switch; use `useDatabaseCache()` when a test needs that |
| `QUEUE_CONNECTION` | `sync` | Queued work runs inline, in the tenant context of the dispatch |
| `SESSION_DRIVER`, `MAIL_MAILER` | `array` | |
| `LOG_CHANNEL` | `null` | Expected failures (failed jobs, 500s under test) stay out of `storage/logs` |
| `PLATFORM_REQUIRE_2FA` | `true` | Platform users need a confirmed second factor to pass `platform.2fa` |
| `BCRYPT_ROUNDS` | `4` | Fast hashing |

`Tests\TestCase` also:

- fakes `Sleep` (the login time-box would otherwise add 250 ms per attempt) and calls `withoutVite()`;
- before **every simulated request** forgets scoped services, shared Inertia props, the default guard and the
  locale, because one test makes several requests on one application instance while production serves each from
  a fresh one. The tenant context is deliberately *not* reset between requests: the context middleware must cope
  with a leftover tenant, and this keeps that under test.

Inertia pages must exist: `inertia.testing.ensure_pages_exist` is on, so `assertInertia()->component('…')`
fails when the Vue file is missing under `resources/js`. Assert the component in at least one test per page.

## 3. Layout

```
tests/
  Pest.php                 Feature: TestCase + RefreshDatabase.  Unit: TestCase, no database.
  TestCase.php             Base class; uses the six traits in Support/
  Support/                 Helpers (traits)
  Support/Fixtures/        A stand-in optional module (Crm/), a test entitlement checker, a test payment
                           gateway, two queued jobs
  Feature/<Area>/*Test.php HTTP and database behaviour
  Unit/<Area>/*Test.php    Pure logic; the application boots but the database is never touched
  js/*.test.ts             Vitest (jsdom); js/setup.ts installs the real i18n catalogue
```

Phase 4 CRM coverage lives in `tests/Feature/CRM` and `tests/js/crm.test.ts`. Backend tests exercise the complete
lead-to-deal and quote-to-order workflows, tenant and record-scope isolation, document arithmetic/status rules,
customer-profile content/timelines, contact/company saved segments, exact lifetime-value recognition, durable
automation action receipts and the Phase 6 quote-accepted event. The route-isolation matrix also probes foreign
and mismatched nested CRM resources. Frontend tests mount the bilingual CRM surfaces and verify their primary
empty, list and action states. The CRM browser spec uses the Business demo plan, which
includes the production `crm` module; the Free workspace remains a useful negative entitlement case.

Phase 6 Invoicing coverage lives in `tests/Feature/Invoicing`: exact money and rounding, the draft-issue-paid state
machine and immutability, gap-free numbering and rollback, manual and partial payments, overpayment and
idempotency, reversal, credit notes, the CRM quotation and Projects time hand-offs, recurring idempotency and
catch-up, reminders and overdue, the receivables report, plan limit enforcement, permission/entitlement/record-scope
gating over HTTP, the printable document, and tenant isolation of every model and route.

Phase 7 HR Management coverage lives in `tests/Feature/Hrm` and `tests/Unit/Hrm`: gap-free employee numbering,
lifecycle and immutable history, reporting-line cycles, plan limit, the attendance minutes arithmetic and
workspace-timezone handling (a pure unit test), server-clock clocking, overnight shifts, day closing and
idempotent scheduled jobs, regularization, leave unit counting, accrual/carry-forward exactness, the append-only
ledger, over-balance and half-day overlap refusals, the two-step approval flow, own/department scoped
self-service, personal-data and document privacy, entitlement 404s, cross-tenant 404s for every id route,
the `App\Core\Workforce` contracts and the two automation actions' receipts. The `InteractsWithHrm` helper
(`tests/Support`) builds workspaces, employees with accounts, scoped roles, shifts, leave types and the local clock.

Phase 7 Payroll coverage lives in `tests/Feature/Payroll` and `tests/Unit/Payroll`: exact half-up arithmetic and
slab boundaries (pure unit tests; the slab tables are labelled EXAMPLE and are not any country's law), proration,
unpaid leave and absence by units, overtime, loan schedules and deferral, the run state machine with four-eyes
approval, refusal of a period with open attendance days, input drift detection at approval, immutability of locked
runs/payslips/revisions, reversal and adjustment runs, idempotent payment recording, formula-injection-safe CSV,
own-scope self-service of payslips, entitlement/permission gating, cross-tenant 404s for every id route, the two
automation actions' receipts and the HRM-event listener. The `InteractsWithPayroll` helper (on top of
`InteractsWithHrm`) builds structures, salaries, periods and closed attendance days.

Phase 7 Talent coverage lives in `tests/Feature/Talent` and `tests/Unit/Talent`: pipeline moves and the immutable
history, duplicate detection by email, hire through an event with headcount closing, plan limits, interview
scheduling in workspace-local time (stored as UTC) with panel conflicts and blind scorecards, onboarding started
exactly once per HRM event (and not for a workspace without Talent), offboarding and probation sync, reminder
idempotency for probation and certificate expiry, the review state machine with exact weight arithmetic and
calibration lock, goal progress, own-scope self-service, candidate-document privacy, entitlement/permission gating,
and cross-tenant 404s for every id route. The `InteractsWithTalent` helper (on top of `InteractsWithHrm`) builds
openings, candidates, applications, checklist templates, review forms and cycles.

Phase 9 Social coverage lives in `tests/Feature/Social` and `tests/Unit/Social`: tenant isolation of every model and
404 for every id route, entitlement/permission gating and own-scope narrowing, the OAuth callback (forged, replayed,
expired and cross-member state refused; encrypted tokens; selection of several pages; re-authorisation), tokens
never present in props, JSON, audit or exceptions, four-eyes approval and return-to-draft on edit, workspace-timezone
scheduling, publishing idempotency (a second run and a racing worker publish nothing twice), retry with backoff,
final failure, unknown-outcome handling, stuck-target flagging, account and workspace rate limits, token refresh and
reauthorisation, plan limits, media privacy and content verification, CSV safety, honest analytics, and the real
scheduled-job inventory. Platform adapters are unit-tested against `Http::fake` request shapes. The
`InteractsWithSocial` helper and `Tests\Support\Fixtures\FakeSocialPlatform` (tests only) build accounts, posts and
scriptable platform failures.

SMS Marketing coverage lives in `tests/Feature/SmsMarketing`, `tests/Unit/SmsMarketing` and
`tests/js/smsMarketing.test.ts`: the segment counter as a table (GSM-7, extension characters, UCS-2, Bengali,
surrogate pairs, boundaries), phone-number normalization, tenant isolation of every model and 404 for every id route,
entitlement and dependency 404, permission and own-scope, consent blocking, idempotency (repeat runs, repeat
materialization, a recovered claim), daily cap, throttle, scheduling bounds, cancel, quiet hours, a switched-off
channel, bounded retries, status webhooks (signed, replayed, late), reconciliation, plan limits, formula-safe CSV,
merge, automation and reports. The `InteractsWithSmsMarketing` helper builds on `InteractsWithCommunication` and the
in-memory SMS provider (testing environment only); the testing queue is synchronous, so a campaign run is one call
(`runCampaigns`).
Email Marketing coverage lives in `tests/Feature/EmailMarketing` and `tests/Unit/EmailMarketing`: tenant isolation of
every model and 404 from another workspace for every id route; entitlement 404, permission 403 and own-scope
narrowing for campaigns; consent blocking (no platform consent, suppression and an unsubscribed address are never
sent and are recorded as blocked), pending members left out, idempotency (repeated passes and a delivery reset after
a crash send once), bounded chunks and the per-minute rate, scheduling, plan-limit pausing, status and unsubscribe
mirroring, A/B determinism and winner (and the honest "not measured" outcomes), test sends to members only,
sequences (enrolment once, delays, stop on unsubscribe), signed click and web-version links (forged, swapped and
foreign-tenant tokens are a bare 404), HTML sanitisation (script tags, event handlers, `javascript:` links, markup in
contact names), CSV import consent rules and bounds, formula-safe export, segment rule validation, automation
receipts, report providers and contact merge. The `InteractsWithEmailMarketing` helper (on top of
`InteractsWithCommunication`) builds an entitled workspace with a postal address, lists, subscribers with platform
consent, campaigns and a manual runner pass (`emRun`). The frontend is covered by `tests/js/emailMarketing.test.ts`.

WhatsApp Business coverage lives in `tests/Feature/WhatsappMarketing` and `tests/Unit/WhatsappMarketing`: tenant
isolation of every model and 404 from another workspace for every id route; entitlement 404, permission 403 and own
scope with the enrolled-agent rule; the signed webhook (handshake, unsigned/wrong/tampered rejection, other phone
number dropped, duplicate and bypassed-duplicate inbound, metadata-only attachments, text cleaning and cap, status
and read receipts never moving backward, template status for this business account only); the 24-hour window
(reply inside and after, just before it closes), approved-only templates, consent blocking, utility-versus-marketing
treatment, unconnected channel, plan limits on windows and agents; campaigns (mapping validation, consent and number
validity at snapshot and at send, bounded chunks, repeated chunks sending nobody twice, schedule, cancel, channel
loss); chatbot flows and their closed vocabulary; automation receipts; reports and "not reported" cost; and the
Cloud API adapter against `Http::fake` (nothing reaches Meta). The `InteractsWithWhatsapp` helper (on top of
`InteractsWithCommunication`) connects the Cloud API channel with operator credential references, builds
templates, lists and agents, and signs Meta-shaped webhook bodies.

Phase 8 coverage lives in `tests/Feature/Communication` and `tests/Unit/Communication`: tenant isolation of every
model, entitlement and permission gating (404 / 403), per-member notification isolation, idempotent and
rate-limited sending, suppression and marketing consent (queue time and worker time), quiet hours, plan limit and
metering, retry with backoff, webhook signature rejection and duplicate-event idempotency, hashed unsubscribe
tokens, template placeholder safety (no code execution), credential references that never store a secret,
timeline scoping and contact merge, and automation receipts. `InteractsWithCommunication` builds an entitled
workspace, members with exact permissions, contacts, recorded consent, a connected `fake_sms` provider
(`InMemoryProvider`, `testing`/`local` only) and signed webhook requests. Run it on its own database:
`DB_DATABASE=oyosend_com_testing php artisan test tests/Feature/Communication tests/Unit/Communication`.

Customer Support coverage lives in `tests/Feature/SupportDesk` and `tests/Unit/SupportDesk` (the folder is not
named `Support` because `tests/Support` holds the helpers): gap-free ticket numbering including rollback and
repeated source keys, the status machine and first-response rules, SLA due times over business hours in the
workspace timezone (and a calendar timezone of its own), pause/resume with remaining working minutes, idempotent
breach detection, priority re-targeting and reopen, round-robin and the rule table, own/department scopes plus the
queue team rule, assignment hierarchy, merge and split integrity (messages, attachments, tags, no chains, rollback),
single-use satisfaction tokens (hash only, expiry, supersession, a second answer is 404), attachment privacy and
download headers, allow-listed canned placeholders, the knowledge base lifecycle and versions, exact report
figures, the inbound contract's idempotency and requester-bound threading, automation receipts, plan limits,
entitlement 404s and cross-tenant 404s for every id route. The pure `BusinessCalendar` and `PlaceholderRenderer`
are unit tests (daylight saving, holidays, several windows, overnight spans). The `InteractsWithSupport` helper
(`tests/Support`) builds an entitled Dhaka workspace, scoped members, enrolled agents, queues, calendars, policies
and tickets.

Phase 5 coverage lives in `tests/Feature/Projects`, the Projects unit contract tests,
`tests/js/projects.test.ts`, and `tests/js/projects-hardening.test.ts`. Backend tests exercise tenant-scoped models,
project/task lifecycle and dependency cycles, recurrence/reminder scheduling, exact budget/expense/time facts,
approval eligibility and atomic decisions, client grants, contact merge, durable automation receipts, record-scope
enforcement, nested-resource isolation, real Projects schedule compilation/dispatch, retained-data uninstall/reinstall,
and the settings-template-to-project HTTP path. Frontend coverage mounts project forms,
Kanban/calendar/Gantt/view-switching, finance and approval actions, widget and read-only portal states,
including stale-form and timezone regressions. The browser suite exercises the production Projects create/detail
and Kanban workflow on the Business demo plan in addition to checking its catalogue entry.

| Suite | Files | Covers |
|---|---|---|
| `Feature/Tenancy` | `HostResolution`, `RoutePipeline`, `TenantContext`, `TenantScope`, `TenantProvisioner`, `CacheIsolation`, `QueueContext`, `TenantStorage` | Subdomain resolution and area separation; the effective middleware order of every route; context switching; the row scope and its write guards; provisioning; per-tenant cache and rate limits; tenant context in queued jobs; tenant file paths |
| `Feature/Auth` | `Login`, `TwoFactorChallenge`, `PasswordReset`, `SecuritySettings`, `SessionBinding`, `WorkspaceSwitch` | Uniform sign-in failures and throttling; second factor, replay and lockout; reset flow; password and 2FA settings; session-to-workspace binding and "remember me"; the handoff |
| `Feature/Access` | `RoleAccess`, `RoleManagement`, `PermissionCache`, `PermissionSynchronizer` | What each system role may reach; role editing rules; grant caching and invalidation; `permissions:sync` |
| `Feature/Members`, `Feature/Invitations` | `MemberManagement`; `InviteMember`, `AcceptInvitation` | Member changes and the hierarchy rule; inviting, mail language, acceptance by new and existing accounts |
| `Feature/Modules` | `Entitlement`, `NavigationAndDashboard`, `DomainEvent` | A non-core module behind entitlement (routes, permissions, sidebar, widgets); domain event conventions |
| `Feature/AppStore` | `AppCatalog`, `AppLifecycle`, `AppBilling`, `AppStorePages`, `ScheduledJobs` | Manifest/version sync, store pages, free install, lifecycle retention, tenant-isolated licences, paid verification, renewal/grace/expiry and scheduled-job gating |
| `Feature/Invoicing` | `InvoiceLifecycle`, `PaymentsAndCreditNotes`, `InvoiceSources`, `RecurringAndReminders`, `ReceivablesReport`, `InvoicingHttp`, `InvoicingModelIsolation` | Invoicing as described above |
| `Feature/Hrm`, `Unit/Hrm` | `EmployeeLifecycle`, `AttendanceFlow`, `AttendanceMath`, `LeaveFlow`, `HrmHttp`, `HrmModelIsolation`, `WorkforceContracts` | HR Management as described above |
| `Feature/Payroll`, `Unit/Payroll` | `PayrollRunFlow`, `PayrollHttp`, `PayrollAutomation`, `PayrollModelIsolation`; `PayMath`, `SlabRuleSet`, `PayCalculator`, `LoanScheduleAndCsv` | Payroll as described above |
| `Feature/Talent`, `Unit/Talent` | `RecruitmentFlow`, `OnboardingProbation`, `PerformanceReview`, `Training`, `TalentHttp`, `TalentModelIsolation`; `ReviewScoring` | Talent & Performance as described above |
| `Feature/Ai`, `Unit/Ai` | `Assistant`, `Guardrails`, `Drafting`, `AutomationRetention`, `AiAppStore`, `AiModelIsolation`, `ToolRegistry`; `Support` | AI Assistant as described in `docs/AI.md`: member permissions and scopes in tools, proposals-only mutation, injection hygiene, metering idempotency and limits, `not_configured`, secret never leaked, retention, paid activation. Helper `InteractsWithAi` (`aiTenant`, `fakeAi`, `aiMember`, `aiContact`); no test calls a real provider |
| `Feature/Automation`, `Unit/Automation` | `WorkflowEngine`, `GraphValidation`, `ExecutionGuards`, `RunLifecycle`, `Approval`, `DryRun`, `Webhook`, `WorkflowHttp`, `AutomationModelIsolation`; `OperatorsAndMapping`, `UrlGuardAndManifest` | Workflow Automation as described in `docs/WORKFLOWS.md`: tenant-only matching of events, idempotent delivery, retries/backoff and error handlers, delay/schedule resume through the sweeper after a simulated restart, abandoned-run recovery, loop/step/depth/rate/concurrency limits, parallel and join, entitlement and permission re-checks mid-run (revoked permission, removed member, unentitled app), approvals (no self-approval, named approver, expiry), dry run with no side effects, inbound webhook signature/replay/tenant isolation and outbound SSRF guard, graph validation codes, publish immutability and quotas, route isolation for every id route. Helper `InteractsWithAutomation` (`flowTenant`, `memberWith`, `changeRole`, `revokeModules`, graph builders `node`/`edge`/`graphOf`/`simpleGraph`, `makeWorkflow`, `raise`, `drain`, `runsOf`) on the `test_flow` fixture app in `tests/Support/Fixtures/Flow` (an event trigger and controllable actions: record, fail, reject, flaky, emit, scoped) |
| `Feature/Audit` | `AuditTrail` | What is recorded, redaction, immutability, the audit page and its filters |
| `Feature/Platform` | `PlatformAuth`, `PlatformPages`, `CreatePlatformAdminCommand`, `HealthEndpoint`, `MessagingProviderAccounts`, `TenantManagement`, `PlanManagement`, `PlatformInvoices`, `SubscriptionAdmin`, `Impersonation` | Super Admin sign-in and mandatory 2FA; dashboard and tenant list; `platform:create-admin`; the `/health` probes; the Messaging providers panel (secrets write-only, per-workspace allow-list, test send); workspace, plan, invoice and support-access management |
| `Feature/Reports`, `Unit/Reporting` | `ReportsPlatform`, `SavedReports`, `ProviderScope`, `ReportsIsolation` | The Reports app against the `reports.fixture` provider (`Fixtures\Reporting\FixtureReport`): entitlement and permission 404s, date and column validation, row cap and truncation, formula-safe CSV, saved-report sharing and ownership |
| `Feature/Hardening`, `Unit/Hardening` | `SecurityHeaders`, `TenantIndexes`, `VerifyBackupCommand`; `QueueDefinitions` | Phase 13 audits that read the whole schema or code base; see below |
| `Feature/Central` | `CentralSite`, `ErrorPages` | Landing page and workspace finder; error pages per area |
| `Unit` | `Tenancy/TenantHost`, `Modules/ModuleRegistry`, `Modules/AutomationRegistry`, `Modules/ProjectsEventContract`, `Modules/ProjectsExactMoney`, `Access/AccessScope` | Host parsing, registries, scope ordering, Projects event/trigger contracts and exact-money arithmetic |
| `tests/js` | `components`, `composables`, `format`, `locales`, `contrast`, `audit`, `projects`, `projects-hardening` | Base components; toasts, theme and labels; number/money formatting; `en`/`bn` parity; WCAG AA contrast; audit rendering; Projects pages and interaction hardening |

Phase 13 hardening checks: `tests/Unit/Hardening/QueueDefinitionsTest.php` (every queued class under `app/` has an
explicit queue, tries, timeout, backoff and tenant context; supervisors cover all queues),
`tests/Feature/Hardening/TenantIndexesTest.php` (every tenant-owned table has an index led by `tenant_id` and every
unique index leads with it, with a reasoned exemption list; reads the migrated schema, so it covers every module),
`SecurityHeadersTest`, `VerifyBackupCommandTest` (needs a mysql client; skipped without one) and
`tests/Feature/Platform/HealthEndpointTest.php`.

`Feature/Tenancy/TenantScopeTest.php` is the model to copy for cross-tenant tests: it points
`assertTenantIsolated()` at every tenant-owned model that exists (`Invitation`, `TenantMembership`, `AuditLog`).

## 4. Helpers (`tests/Support`)

Available on `$this` in every Pest test.

**`InteractsWithTenants`**

| Signature | Does |
|---|---|
| `createTenant(?string $slug = null, array $attributes = [], ?User $owner = null): Tenant` | A fully provisioned workspace (tenant, subdomain, system roles, owner) through `TenantProvisioner`. Random `ws-…` slug by default. `$attributes`: `name`, `status`, `locale`, `timezone`, `currency`, `trial_ends_at` |
| `syncPermissions(): void` | Fills `permissions` from the registered manifests. `createTenant()` calls it once; `registerModule()` calls it again |
| `ownerOf(Tenant $tenant): User` | The workspace owner |
| `addMember(Tenant $tenant, Role\|string $role = Role::EMPLOYEE, ?User $user = null, MembershipStatus $status = MembershipStatus::Active): User` | Puts a person (new, or an existing account) in the workspace with a role |
| `membershipOf(Tenant $tenant, User $user): TenantMembership` | The membership row, e.g. for `{membership}` URLs |
| `roleOf(Tenant $tenant, string $name): Role` | A role of that tenant by name |
| `createRole(Tenant $tenant, string $name, array $permissions = []): Role` | A custom role, created through `RoleManager` as the owner |
| `createInvitation(Tenant $tenant, string $email, Role\|string $role = Role::EMPLOYEE, array $attributes = []): array` | An invitation whose emailed token the test knows. Returns `[Invitation, string $token]` |
| `auditTrail(?Tenant $tenant = null, ?string $event = null): Collection` | The workspace's audit entries, oldest first; with no tenant, the platform-level ones |
| `actingAsMember(Tenant $tenant, User $user): static` | Signs in **and** binds the session to the tenant. Plain `actingAs()` is rejected by `EnsureTenantMember` |
| `actingAsOwner(Tenant $tenant): static` | The same, as the owner |
| `startNewBrowserSession(): static` | Forgets the signed-in user, session and cookies, as if another browser made the next request |
| `useDatabaseCache(): void` | Switches the cache to the shared, prefix-aware `database` store, for tests about cache isolation or cached state that must survive requests to more than one workspace (rate limits, grants). Must be called before the first tenant is created; it throws otherwise |
| `tenantUrl(Tenant\|string $tenant, string $path = '/'): string` | `http://{slug}.localhost/{path}` |
| `centralUrl(string $path = '/'): string`, `adminUrl(string $path = '/'): string` | URLs on the central site and the Super Admin panel |
| `inTenant(Tenant $tenant, Closure $callback): mixed` | Runs the callback with that tenant active, then restores the previous context |
| `inCentral(Closure $callback): mixed` | Runs the callback with no tenant active, then restores |

**`InteractsWithModules`**

| Signature | Does |
|---|---|
| `registerModule(string $provider): ModuleManifest` | Registers a module provider for this test as if it were in `config/modules.php`: manifest, routes, and its permissions synced |
| `entitle(Tenant $tenant, string ...$modules): void` | Adds explicit grants through `Fixtures\GrantsModules`, on top of product rules. Use it for gate tests; commerce tests use `AppStoreManager`, and plan-inclusion tests use a subscribed plan with `modules` |

**`AssertsTenantIsolation`**

| Signature | Asserts |
|---|---|
| `assertTenantIsolated(string $model, Closure $makeInTenant): void` | The whole row-isolation contract. Provisions two tenants and calls `$makeInTenant(Tenant)` once in each (it must create and return one record). Then: stamped with its tenant; invisible from the other tenant to `find`, `get`, `count`, `exists`, route binding, bulk update and bulk delete; every read throws with no tenant; reachable through `withoutTenancy()` and `forTenant()`. For `BelongsToTenant` models also the write guards |
| `assertNotFoundFromOtherTenant(string $method, string $path, Tenant $home, array $data = [], ?Tenant $intruder = null): void` | The route answers 404 when called on another workspace's host by that workspace's owner, and does **not** answer 404 or 5xx in the record's own workspace (the control). With a destructive verb the control really performs the action, so call it last |

**`InteractsWithBilling`**

Billing records are central data scoped to their tenant, so every helper names its tenant. Time only matters
through the cycle: move the clock (`$this->travel(8)->days()`, `$this->travelTo(...)`), then `runBillingCycle()`.

| Signature | Does |
|---|---|
| `createPlan(array $attributes = []): Plan` | A plan through its factory: paid (BDT 1,500.00 a month, 15,000.00 a year, in minor units), public, active, no trial. Pass any column: `trial_days`, `monthly_amount`, `modules`, `limits`, `is_public`, … |
| `createFreePlan(array $attributes = []): Plan` | A plan that costs nothing |
| `subscribe(Tenant $tenant, ?Plan $plan = null, BillingInterval $interval = Monthly, bool $withTrial = true): Subscription` | What sign-up does: `SubscriptionManager::start()` in that tenant |
| `subscribeAndPay(Tenant $tenant, ?Plan $plan = null, BillingInterval $interval = Monthly): Subscription` | A workspace inside a paid period: the starting point for renewal, upgrade and cancellation tests |
| `subscriptionOf(Tenant $tenant): Subscription` | The subscription as it is in the database now, with `plan` and `pendingPlan` |
| `invoicesOf(Tenant $tenant): Collection`, `openInvoiceOf(Tenant $tenant): ?Invoice`, `paymentsOf(Tenant $tenant): Collection` | Oldest first |
| `runBillingCycle(Tenant $tenant): void` | What the hourly `billing:run` does for one workspace |
| `payOpenInvoice(Tenant $tenant, string $method = 'bank_transfer'): Payment` | Pays the open invoice the way an operator records an offline payment |
| `useTestGateway(): TestGateway` | Registers `Fixtures\TestGateway` (key `testpay`) for this test. It never leaves the process; `willFail()`, `willCancel()`, `willConfirm($amount, $currency)`, `$verificationFailure`, `$initiationFailure`, `$configured` decide what the "provider" answers |


| Signature | Does |
|---|---|
| `createPlatformUser(PlatformRole $role = PlatformRole::SuperAdmin, bool $twoFactor = true): PlatformUser` | A platform account, with a confirmed second factor by default |
| `actingAsPlatformUser(PlatformUser $user): static` | Signs in on the `platform` guard |

**`InteractsWithTwoFactor`**

| Signature | Does |
|---|---|
| `enableTwoFactor(TwoFactorAuthenticatable&Model $account): array` | Confirmed TOTP for a user or platform user; returns the recovery codes |
| `twoFactorCode(TwoFactorAuthenticatable $account, int $stepsAhead = 0): string` | The code the authenticator shows now. A code works once: pass `stepsAhead: 1` for a second valid one |
| `wrongTwoFactorCode(TwoFactorAuthenticatable $account): string` | A well-formed code that is not valid now |

**`InteractsWithReports`** (Reports & Analytics; use with `uses(InteractsWithReports::class)`)

| Signature | Does |
|---|---|
| `reportsTenant(string $slug = 'reports-ws', string ...$modules): Tenant` | A Dhaka workspace entitled to `reports` and the given modules |
| `reportsUrl(Tenant $tenant, string $path = ''): string` | A `/reports...` URL on the tenant host |
| `reportsMember(Tenant $tenant, array $permissions, array $scopes = []): array` | `[User, TenantMembership]` holding exactly those permissions (scopes: permission => own / department / branch / all) |
| `savedReport(Tenant $tenant, TenantMembership $owner, array $attributes = []): SavedReport` | A saved report of the `reports.fixture` provider |
| `reportsAt(string $local): void` | Travels to a local Dhaka time |

`Fixtures\Reporting\FixtureReport` is a provider owned by `reports` itself whose rows a test sets
(`FixtureReport::$rows`); tag it in `beforeEach` with `$this->app->tag([FixtureReport::class], ReportProvider::TAG)`.

**`InteractsWithProviderAccounts`** (operator messaging provider accounts; `uses(InteractsWithProviderAccounts::class)`)

| Signature | Does |
|---|---|
| `platformUrl(string $path = '/messaging'): string` | A URL on the Super Admin host |
| `makeAccount(array $overrides = []): ProviderAccount`, `makeHttpSmsAccount(array $settings = [], array $overrides = []): ProviderAccount` | A stored account (`secrets` encrypted) for a channel and provider; the second is a ready generic HTTP SMS gateway |
| `httpSmsSettings(): array`, `accountInput(string $provider, array $overrides = []): array` | Valid non-secret settings / a valid panel form payload for a provider |
| `smsPayload(...)`, `smsConnection(string $provider)`, `credentials(string $secret, array $settings = [])` | An `OutboundPayload`, a workspace connection and a `ChannelCredentials` for adapter tests against `Http::fake` |

The other module helpers (`InteractsWithCommunication`, `InteractsWithEmailMarketing`, `InteractsWithSmsMarketing`,
`InteractsWithWhatsapp`, `InteractsWithSocial`, `InteractsWithSupport`, `InteractsWithAi`, `InteractsWithAutomation`,
`InteractsWithHrm`, `InteractsWithPayroll`, `InteractsWithTalent`) are described with their suites in section 3.

**Fixtures (`tests/Support/Fixtures`)**

| Fixture | Use |
|---|---|
| `Crm\CrmServiceProvider` | A compiled-only stand-in app (`test_crm`): catalogue/offers, permissions, routes, sidebar, widget, trigger and lifecycle recorder. Production `crm` stays registered; the fixture provides a distinct second app for App Store tests |
| `GrantsModules` | The entitlement checker `entitle()` installs: the product's rules plus explicit grants, so a test about what an entitlement does needs no plan |
| `TestGateway` | A `PaymentGateway` whose answers the test controls; see `useTestGateway()` |
| `RecordsTenantJob`, `TenantBoundJob` | Queued jobs that record the tenant they ran in; the second implements `RequiresTenantContext` |

Factories exist for `Tenant`, `User`, `TenantMembership`, `Invitation`, `PlatformUser`, `Branch`, `Department`,
`Contact`, `Company` and `Plan` (`database/factories`). The tenant-owned ones create in the active tenant.
`TenantFactory` makes a bare row; use `createTenant()` when the test needs a working workspace.

## 5. Conventions

**Every new tenant-owned model and every new route gets a cross-tenant test** (spec section 29):

```php
it('isolates notes between tenants', function () {
    $this->assertTenantIsolated(Note::class, fn (Tenant $tenant) => Note::factory()->create());
});

it('does not serve a note to another workspace', function () {
    $tenant = $this->createTenant();
    $note = $this->inTenant($tenant, fn () => Note::factory()->create());

    $this->assertNotFoundFromOtherTenant('GET', "/notes/{$note->id}", $tenant);
});
```

- Hosts are part of the behaviour under test. Always request a full URL: `tenantUrl()`, `centralUrl()`,
  `adminUrl()`.
- Outside `inTenant()` a test runs in the central context. After a request to a workspace host that tenant stays
  active. Do not rely on either: wrap every assertion on tenant-owned models in `inTenant()`.
- Sign in with `actingAsMember()` / `actingAsOwner()` / `actingAsPlatformUser()`. Call
  `startNewBrowserSession()` before acting as someone else in the same test.
- For a permission, test both sides: a role that holds it (`createRole()` + `addMember()`) and one that does not.
  The owner holds everything, so the owner proves nothing about permissions.
- Slugs must be valid, unreserved labels. `test`, `demo`, `dev`, `app`, `api` and the others in
  `platform.reserved_subdomains` are rejected by `TenantProvisioner`.
- A non-core module is denied unless a healthy plan includes it or an effective App Store licence exists. For a
  gate-only test use `registerModule()` + `entitle()`; for store behaviour register, sync the catalogue, and use
  `AppStoreManager`. Always test another tenant, the not-entitled 404, and dependency removal.
- Paid app tests first assert that an open invoice leaves no entitlement, then settle through `PaymentProcessor`
  (test gateway or audited offline payment) and assert activation. Never call `AppStoreManager::settle()` as a
  shortcut in an integration test intended to prove payment verification.
- Uninstall/expiry tests assert that installation, subscription and business rows remain while effective access
  disappears. Manifest tests sync twice and reject changed versioned fields without a version bump.
- Dispatch inside `inTenant()` with a block closure: `function (): void { SomeJob::dispatch(); }`. An arrow
  function returns the `PendingDispatch`, which only reaches the queue when it is destroyed, after the tenant
  context has ended.
- Under `Queue::fake()` the tenancy package does not add `tenant_id` to payloads; to test tenant propagation
  use the `sync` queue.
- Fakes for outside services are bound only in tests (`Mail::fake()`, `Http::fake()`, `useTestGateway()`, …). Never
  add a fake to a production code path.
- A JSON column comes back from MySQL with its keys in MySQL's order: compare with `toEqual`, not `toBe`.
- Nobody can manage a member who holds a permission they lack, and the system roles hold contact and
  organisation permissions. A custom role that is meant to manage Employees has to carry what Employee holds
  (`EMPLOYEE_PERMISSIONS` in `Feature/Members/MemberManagementTest.php`).
- A permission that can be scoped is tested per scope: see `Feature/Access/RecordAccessTest.php` and
  `Feature/Contacts/ContactScopeTest.php`.
- `Feature/Tenancy/RoutePipelineTest.php` checks every registered workspace route: tenant resolved first, then
  sign-in, session check and membership, and only tenant-scoped models bound. A route meant for signed-out
  visitors has to be added to its `PUBLIC_WORKSPACE_ROUTES` list, which makes the exception visible in review.
- Frontend: specs live in `tests/js`, mounted with `@vue/test-utils`. A new design token used for text or a
  surface must be `#rrggbb` or `rgb(r g b / a)` so `contrast.test.ts` can read it.

## 6. CI

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests. **It has never run on GitHub**; treat
it as unverified until the first run is green.

| Job | Environment | Steps |
|---|---|---|
| `php` | PHP 8.4, MySQL 8.0 service with database `oyosend_testing`, Redis 7 service | `cp .env.example .env`, `composer install`, `key:generate`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` |
| `js` | Node 22 | `npm ci`, `npm run typecheck`, `npm run test`, `npm run build` |

The workflow points the Sail-flavoured `.env.example` at its service containers through real environment
variables (`DB_HOST=127.0.0.1`, `DB_USERNAME=root`, …), which win over `.env`. The test run itself does not need
Redis (cache `array`, queue `sync`, session `array`).
