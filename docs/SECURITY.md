# Security

State: every registered module is covered (foundation, Core SaaS, App Store, business apps, Communication,
marketing, Social, Automation, AI, Support, Reports); the marketing, Social, Automation, AI, Support and Reports apps
have had no independent review. Each mechanism is listed with the file
that enforces it. Layers with nothing to protect yet are marked **not applicable yet**. Section 8 lists open work.

Principles the code follows: the tenant comes from the hostname only; tenant, membership, entitlement and
permissions are always read from the database, never from the request; missing tenant context is an exception,
not an empty result; what the browser receives (`permissions`, `navigation`) is presentation, not access control.

## 1. Tenant isolation, layer by layer (spec 3.3)

| Spec bullet | Status | Mechanism | Where |
|---|---|---|---|
| Database queries and relationships | Enforced | Global scope adds `tenant_id = ?` and throws `MissingTenantContextException` when no tenant is active. Creating stamps `tenant_id`; a foreign `tenant_id` or changing it throws `CrossTenantAccessException` | `Core/Tenancy/Scopes/TenantScope.php`, `Concerns/BelongsToTenant.php`, `Concerns/ScopedToTenant.php` |
| | By hand | `Role` has no automatic scope (ADR 0002): `forCurrentTenant()`, plus `tenant_id` filters in raw queries | `Core/Access/Models/Role.php`, `PermissionResolver.php`, `RoleManager.php` |
| Route model binding | Enforced | The tenant context is set before `SubstituteBindings` (middleware priority), so bound models resolve through the scope and another tenant's id is a 404. `Role` overrides `resolveRouteBinding()`. The `{tenant}` parameter is removed. `User` is never bound | `bootstrap/app.php`, `Http/Middleware/InitializeTenancy.php`, `routes/tenant.php` |
| API endpoints | **Not applicable yet** | No API routes; Sanctum is not installed | — |
| Background jobs | Enforced | Payloads carry `tenant_id`; the context is restored before the job. A class implementing `RequiresTenantContext` is failed before `handle()` if the tenant is missing or does not match. A job without a tenant runs in the central context, where any tenant-owned query throws | package `QueueTenancyBootstrapper`, `Core/Tenancy/Jobs/EnforceTenantContextOnJobs.php` |
| App scheduled jobs | Enforced | `apps:run-scheduled` checks before dispatch; its encrypted tenant-bound wrapper checks the compiled definition and entitlement again at worker time. Jobs also require tenant context | `Core/AppStore/Console/RunModuleSchedulesCommand.php`, `Core/AppStore/Jobs/RunScheduledAppJob.php` |
| Cache | Enforced | For the life of the context the key prefix of **every** configured store gets `tenant_{id}_` appended (a store's own prefix is kept in front); all stores and their `Cache::memo()` wrappers are dropped and rebuilt. A store that ignores prefixes (`array`) is rebuilt empty. The rate limiter is handed the new store, so counts are per tenant and limiters named with `RateLimiter::for()` survive the switch. `cache.central` is never prefixed | `Bootstrappers/CachePrefixBootstrapper.php`, `Support/TenantAwareRateLimiter.php` |
| File storage | Enforced | Every path is forced under `tenants/{tenant_id}/` on a private disk; `..` and NUL are rejected; access is by short-lived signed URL; no tenant → throws. The workspace logo is the one stored file: png / jpg / webp up to 1 MB, 2000 px a side, SVG refused | `Core/Tenancy/Storage/TenantStorage.php`, `Core/Settings/WorkspaceLogo.php` |
| Records inside a tenant | Enforced | A scopable permission reaches a person's own, their department's, their branch's or all records. The controller narrows lists and single records through `RecordAccess`; a record outside the scope is a 404 | `Core/Access/RecordAccess.php`, `Concerns/HasRecordOwner.php`, ADR 0007 |
| Search results | **Not applicable yet** | Scout is installed (`database` driver) but no model is searchable. The first searchable model needs a cross-tenant search test before it ships | — |
| Exports | Enforced | CSV exports (reports, email and SMS campaign recipients, subscriber lists and groups, payroll reports, social posts) are built by the same code and for the same member as the screen, are formula-safe (`=`, `+`, `-`, `@`, tab, CR get an apostrophe), narrowed to the viewer's `core.contacts.view` scope where they list people, throttled where they are on-demand and audited for reports (`reports.report.exported`). There is no whole-workspace data export (section 9) | `Core/Reporting/Support/CsvWriter.php`, module `*Exporter` / `CsvSafe` classes, `docs/REPORTS.md` |
| WebSocket channels | **Not applicable yet** | Reverb is not installed; `BROADCAST_CONNECTION=log`; there is no `routes/channels.php` | — |
| Notifications | Partly | Workspace mail is queued with `RequiresTenantContext` and built inside the tenant context (invitation, invoice, receipt, workspace ready); password-reset links are generated with the active tenant's host. The sign-up confirmation is platform mail: no tenant exists yet. The optional Communication app adds a per-member notification centre and the outbox; every notification query is narrowed to the signed-in membership (section 5d.4) | `Core/Identity/Mail`, `Core/Billing/Mail`, `Platform/Mail`, `Core/Registration/Mail` |
| Automation executions | Enforced | A run executes inside its workflow's tenant context; each step re-checks the workspace's entitlement to `automation` and to the step's module and the permission of the member the workflow runs as; triggers match only workflows of the event's tenant; inbound webhook tokens are looked up inside the hostname's tenant; run idempotency keys are tenant-first (section 5d.7) | `Modules/Automation`, `docs/WORKFLOWS.md` |
| Unique constraints | Enforced | Tenant-first on tenant-owned identities/names, with validation scoped the same way. Deliberate global exceptions are listed in `docs/DATABASE.md`: identity email, token hashes, app licence id and platform-wide billing identifiers | `database/migrations`, `docs/DATABASE.md` |

Deliberate cross-tenant reads, all of them: `User::memberships()` (a person's own workspaces),
`Tenant::memberships()`, `Tenant::subscription()` and `Tenant::subscriptionItems()` (keyed by the tenant asked for),
`TenantMembership::forTenant()` in the workspace handoff, and `withoutTenancy()` / `forTenant()` in `app/Platform`
(dashboard, workspace page, invoices, audit log, the owner lookups of `TenantManager` and `ImpersonationManager`).
`billing:run` does not read across tenants: it enters each workspace's context in turn.

The enforced rows are under test: `tests/Feature/Tenancy/TenantScopeTest.php` (rows), `RoutePipelineTest.php`
(context before auth and binding on every workspace route), `QueueContextTest.php` (jobs),
`CacheIsolationTest.php` (cache and rate limits), `TenantStorageTest.php` (files), and
`tests/Feature/Auth/SessionBindingTest.php` (sessions).

User text that goes into a `LIKE` filter is escaped with `App\Core\Support\Like`, so `%` and `_` are searched
for as typed (member search, audit search, the Super Admin tenant list).

## 2. Authentication

| | Workspace | Super Admin |
|---|---|---|
| Guard / table | `web` / `users` | `platform` / `platform_users` |
| Host | `{tenant}.APP_DOMAIN` | `admin.APP_DOMAIN` |
| Password step | `VerifyWorkspaceCredentials` | `Platform\...\Auth\LoginController` |
| Second factor | Optional per user | Mandatory (`EnsurePlatformTwoFactor`) |

- **Uniform failures.** Wrong password, unknown email and "not a member of this workspace" return the same
  message and take the same minimum time (`CredentialVerifier`, a 250 ms `Timebox`). The forgot-password form
  always answers the same way; for a member the link is created and sent after the response has gone out
  (`defer()`), so neither the answer nor its timing reveals membership (`PasswordResetLinkController`).
- **Throttling.** `LoginThrottle`: 5 failures per minute per (area or tenant, email, IP). Route throttles: forgot
  and reset password 6/min, sign-in and second-factor challenge 30/min per IP, invitation acceptance 10/min (an
  existing account's password there goes through the same `LoginThrottle` lockout as the sign-in form), handoff 20/min, password change 6/min, two-factor
  settings 10/min, platform enrolment 10/min, workspace finder 20/min, sign-up 6/min, sign-up confirmation
  10/min, support-session entry 20/min, starting a support session 10/min, starting a payment 10/min, logo upload
  10/min, gateway callbacks 60/min. Counts are per workspace. Second-factor
  codes have their own per-account lockout (section 3).
- **Passwords.** Hashed by cast (bcrypt, `BCRYPT_ROUNDS=12`). `Password::defaults()` in `AppServiceProvider`:
  production requires 12+ characters, mixed case, numbers and an uncompromised password; elsewhere 8+.
- **Reset.** Laravel's broker with hashed tokens. Changing or resetting a password signs the account out of its
  other sessions (`auth.session`, remember-token rotation).
- **Invitations.** 64-character random token, stored only as SHA-256, valid 7 days, single use under a row lock,
  bound to the invited email (compared case-insensitively). An existing account must prove its password and
  still passes its second factor. The mail is written in the invitee's own language if they already have an
  account with one, otherwise the workspace's (`InviteMember`). Withdrawing an invitation follows the role
  hierarchy like creating it: nobody withdraws one for a role they could not hand out.
- **Workspace addresses.** Lower-case RFC 1123 labels; a label starting `xn--` (punycode) is refused
  (`TenantHost::LABEL_PATTERN`).
- **First administrator.** `php artisan platform:create-admin {email}` creates a Super Admin panel account. It
  runs only in an interactive terminal and asks for the password; it is never an argument. The account still has
  to enrol in 2FA at its first sign-in.
- **Sign-up** (ADR 0008). The form on the central site only records a pending registration and emails a link;
  the answer is the same whether or not the address has an account. The link (64 random characters, SHA-256
  stored, 24 hours, single use under a row lock) opens a page with one button, so a mail scanner cannot use it
  up. Confirming creates the account and the workspace. An address that already has an account is not asked for
  its password - the emailed link is the proof, as for a password reset - and its password and second factor are
  not touched; it then signs in to the new workspace the ordinary way. Registration can be `open`, `approval`
  (the workspace is created `pending`) or `closed` (the routes answer 404).
- **A workspace created from the panel** mails its owner. A new account gets a link to choose a password, made
  when the mail is built (never stored in the queue payload); nobody ever knows the random password it was
  created with.

## 3. Two-factor authentication

`Core/Identity/TwoFactor/TwoFactorService.php`, shared by both guards.

- TOTP (RFC 6238) through `pragmarx/google2fa`; the QR code is rendered server-side as inline SVG.
- Secret and recovery codes (8, `platform.two_factor.recovery_codes`) use encrypted casts and are hidden from
  serialization. Recovery codes are single use and shown once.
- Enrolment is two steps: `enable()` stores an unconfirmed secret, `confirm()` activates it with a valid code.
  For workspace users, starting, disabling and regenerating codes require the current password.
- Replay protection: the last accepted time step is kept in `cache.central` for 5 minutes and every check is
  made against it, so a code is accepted once, the first one included.
- Lockout: wrong codes are counted per account in `cache.central`, across sign-ins and workspaces, for workspace
  users and platform users alike. After `platform.two_factor.max_failed_codes` (10) wrong codes, codes are refused for
  `lockout_seconds` (900), whatever is entered; the window starts with the first wrong code. Recovery codes
  keep working during a lockout. A correct code clears the count.
- Sign-in: after the password step the login is only *pending* in the session (`PendingTwoFactorLogin`), expires
  after `platform.two_factor.challenge_ttl` (600 s) and is discarded after 5 wrong codes. Repeating the password
  step does not reset the account's lockout count.
- Platform accounts without 2FA get a session that can reach only the enrolment screen and logout.
  `PLATFORM_REQUIRE_2FA=false` is ignored in production (`config/platform.php`).

## 4. Sessions

- Driver `database`; cookie is HTTP-only, `SameSite=lax`, **host-only** (`SESSION_DOMAIN=null`). Do not change that.
  It is `secure` automatically when `APP_ENV=production`; `SESSION_SECURE_COOKIE` overrides either way.
- A workspace session stores `auth.tenant_id`. `EnsureTenantMember` requires it to equal the active tenant and
  the user to hold an active membership, on every request; otherwise the session is destroyed.
- Session id is regenerated on sign-in (`CompleteWorkspaceLogin`, `CompletePlatformLogin`), invalidated on logout.
- "Remember me" is bound to the workspace that issued it. Sign-in with the box ticked also sets an encrypted,
  host-only companion cookie, `remember_workspace`, holding the tenant id
  (`EnsureTenantMember::REMEMBER_COOKIE`). A session restored from the framework's remember cookie is bound to
  the workspace only if that companion cookie names this tenant, and the membership check still applies. A copy
  of both cookies presented to another workspace the person belongs to is refused. Both are cleared on sign-out
  and whenever `EnsureTenantMember` ends a session. A remembered session is restored without asking for the
  second factor again; a password change elsewhere invalidates it.
- Workspace switch: `WorkspaceHandoff` issues a 64-character token, stored hashed in `cache.central`
  for `platform.workspace_handoff_ttl` (60 s), bound to one user and one target tenant, consumed with `pull()`.
  The target checks tenant and membership again and starts its own session. Sign-up uses the same handoff to take
  a brand-new account into its workspace.
- **Support access** (ADR 0008). A Super Admin opens a workspace as its owner from the panel, with a written
  reason. The session reaches the workspace host through a one-time token (64 characters, SHA-256 stored, 60 s,
  bound to that tenant) and is marked in the session (`impersonation`). `EnforceSupportSession` ends it after
  `platform.impersonation.ttl_minutes` (30) and refuses the routes that act on the impersonated person: profile,
  password, two-factor, language and workspace switch answer 403, and so do the changes that would outlive the
  session: inviting or withdrawing invitations, changing or removing members, creating, changing or deleting
  roles, and every app install / licence / settings change. Each blocked attempt is written to the workspace's
  trail as `support.action_blocked`. Start and end are written to the platform
  trail and to the workspace's own; every audit entry made meanwhile carries `properties.support_session`.
- CSRF protection is the framework's, in the `web` group. The two payment gateway callbacks are outside that
  group on purpose (section 5a). Workspace pages only receive their own area's route table (`config/ziggy.php`).

## 5. Authorization

- One decision point: `Gate::before` → `AccessChecker` (`docs/ARCHITECTURE.md` section 8). No entitlement means no
  permission, whatever the role says.
- Routes declare `can:{permission}`. Non-core module routes also get `entitled:{module}`, which answers 404.
- `RoleManager`: nobody can grant, re-scope or remove a permission they do not hold at least as widely; roles
  carry only permissions of entitled modules; the Owner role cannot be edited or assigned; system roles cannot be
  deleted or renamed.
- A scopable permission is narrowed to a person's own, their department's or their branch's records by
  `RecordAccess` in the controller. Only someone who manages every record can hand one to another member.
- A workspace whose subscription has ended is locked down to billing by the `subscribed` middleware; a workspace
  that is not entitled to a module gets a 404 from `entitled:{module}`. Both read the database, never the request.
- Optional-module entitlement is an active installation plus an effective server-side licence, or inclusion in a
  healthy plan. Dependency pruning prevents a forged/stale child licence from bypassing its required app.
- `MemberManager`: the owner cannot be changed; nobody can change, suspend, place or remove themselves (the owner
  alone may set their own branch and department); and nobody can
  act on a member who holds a permission they lack (`canManage()` / `guardMutable()`), so `core.members.manage`
  alone is not enough to demote or remove an Admin. The members list sends `manageable` per row for the UI; the
  rule is enforced again on every change.
- Super Admin abilities are `platform.*` (`PlatformRole::ABILITIES`), answered by `PlatformRole::allows()`.
  `support_admin` is read-only: it can view workspaces, plans, invoices and the audit log, and can neither change
  anything nor enter a workspace.
- Horizon is on the admin host behind `auth:platform` + `platform.2fa`, and its gate admits super admins only.

## 5a. Payments

- A payment counts only when `PaymentProcessor::confirm()` got the provider's own answer, server to server, for
  our reference, the invoice's exact amount and its currency. What arrives at the return or notification URL is
  used to find the payment and nothing else.
- The return and notification routes are stateless: tenant resolution from the hostname and a throttle, no
  session, no cookie, no CSRF token. A gateway returns the payer with a cross-site POST, which carries no session
  cookie; starting a session there would replace the one the payer is signed in with. They answer the same for a
  known and an unknown reference.
- A provider transaction is recorded once (`UNIQUE(gateway, gateway_reference)`); confirming is idempotent under a
  row lock, so duplicate notifications change nothing.
- Gateway credentials come from the environment (`config/platform.php`, `gateways`). They are never stored in the
  database, shown on a page, put in an exception message or written to `payments.details`. ADR 0013 does not
  change this: only messaging provider accounts (section 5d.4) may hold encrypted secrets in the database. (AI provider accounts, section 5d.6, are a second encrypted-secret store that ADR 0013 did not approve; ADR 0018 records the open decision.)
- Offline payments are recorded by an operator with `platform.billing.manage`, under their name, in the
  workspace's own audit trail.
- App purchase and renewal invoices use this same path. `InvoiceSettlement` creates/extends an app entitlement
  only after verified success; an open invoice leaves the installation `pending_payment` and grants no access.

## 5b. App code and lifecycle

- The catalogue contains first-party modules already compiled and registered in this deployment. A tenant cannot
  upload packages, PHP, scripts, expressions or manifests, and no runtime Composer/install/eval path exists.
- `apps:sync` records metadata only. The catalogue controller also requires the module in `ModuleRegistry`, so a
  database row cannot make absent code executable. Changing released versioned fields needs a version bump.
- Free/paid install, activation, deactivation, cancellation, expiry and uninstall go through `AppStoreManager`
  under tenant scope; multi-write lifecycles use transactions. Paid purchase/cancel/resume and uninstalling a
  live commercial commitment also require `core.billing.manage`. Hooks cannot take a tenant id from request input.
- Dependencies are checked on activation and during entitlement resolution; entitled dependents block removal.
- Uninstall disables executable access but retains a valid licence and never deletes module business tables or
  rows. This supports safe reinstall and prevents a lifecycle hook from becoming a disguised destructive data
  operation.
- Per-workspace app settings accept only compiled typed definitions and are stored as one encrypted array. Secret
  values are never returned to Inertia or audit properties; a blank secret update preserves the stored value.

## 5c. CRM records and files

- The `crm` route group runs after tenant resolution and the `entitled:crm` gate, then requires sign-in, session
  authentication, active membership, a healthy subscription and a route-specific CRM permission.
- Leads, deals, activities, quotes, orders, contracts and customer profiles apply `RecordAccess`. A record outside
  the grant's own/department/branch scope answers 404 rather than disclosing its existence.
- Only an all-scope assignment grant can choose another record owner. Owner changes copy the owner's department
  and branch, preventing stale organisational scope columns.
- CRM customer selectors are constrained again by the actor's `core.contacts.view` scope. A CRM permission does
  not disclose a shared contact the same person cannot open in core.
- Attachment input is restricted by size and MIME extension and stored by `TenantStorage`; the database stores no
  client-selected path. Download/delete verifies both parent linkage and record scope, and download URLs expire.
- Product prices, quote/order totals and tax rates are bounded integers. Overflow-checked totals are calculated
  server-side; client-submitted totals and tenant IDs are never trusted. A quote's optional deal is independently
  scope-checked and must match its customer and currency.
- CRM's mutating automation actions persist the output under a tenant/action/idempotency-key unique constraint in
  the same transaction as the mutation. Cross-process retries return that receipt instead of applying work twice.

## 5d. Project records, files and client grants

- The `projects` route group requires tenant resolution, `entitled:projects`, authentication, active membership,
  a healthy subscription and its route-specific permission. Project/task lists and bound records apply
  `RecordAccess`; inaccessible and mismatched nested records answer 404.
- Client/company selectors and every owner/member/assignee/watcher/approver id are revalidated as active records
  in the current tenant. Team work points to `TenantMembership`, never a global `User`.
- Task parent, milestone, sprint and dependency targets must belong to the same project. Dependency writes reject
  self-links and cycles.
- Attachment names, paths, types and sizes are server-controlled and bytes use `TenantStorage`. A download
  inherits current parent access.
- Client access is an explicit revocable row. Invite secrets are random and only their SHA-256 hashes are stored;
  expiry, project/contact linkage, internal-comment visibility and financial visibility are checked server-side.
- Approving time or expenses (directly or through an approval request) needs the matching `projects.time.approve` /
  `projects.expenses.approve` permission with record scope over that entry, and self-approval is always refused.
  Task mutation routes also require project view scope; the activity feed omits metadata and filters by area.
- On a CRM customer page, notes and timeline entries tied to a lead, deal or activity appear only to a viewer who
  can open that record.
- Project money, rates, durations, progress and allocations are bounded integers. Automation actions persist a
  durable tenant/action/idempotency receipt atomically with their mutation.

## 5d.1. Invoicing records, documents and payments

- The `invoicing` route group requires tenant resolution, `entitled:invoicing`, authentication, active membership,
  a healthy subscription and a route-specific permission. No invoicing route is public: there is no public invoice
  link, the document is attached to the email instead.
- Invoices and recurring templates apply `RecordAccess`; payments and credit notes are authorized through their
  invoice, and a payment or credit note id that belongs to another invoice answers 404.
- Customers are re-validated against the actor's `core.contacts.view` scope. Customer and seller details are
  snapshotted at issue; an issued invoice's commercial fields are guarded in the model and the lines are immutable.
- Totals, balances and statuses are server-derived from integer rows and recomputed under a row lock. A payment
  counts only because a member with `invoicing.payments.record` entered it; no gateway or redirect can settle an
  invoice. Overpayment, future-dated payments and duplicate idempotency keys are refused; reversal needs
  `invoicing.payments.reverse` and a reason, and is audited.
- Document HTML escapes every user value. The embedded logo is read through `TenantStorage`, size- and
  MIME-checked, and never referenced by URL.
- The CRM-quote and Projects-time hand-offs read only the event payload, check the entitlement and the active tenant
  at delivery, and are idempotent.

## 5d.2. HR records, attendance and leave

- The `hrm` route group requires tenant resolution, `entitled:hrm`, authentication, active membership, a healthy
  subscription and a route-specific permission. No HR route is public.
- Employee, leave, attendance and correction records carry `owner_id` (the employee's own membership),
  `department_id` and `branch_id`, and every list and record route applies `RecordAccess`; employee self-service is
  the `own` scope. A person cannot change their own status, their own employment fields (without `all` scope),
  their own attendance entries (without `all` scope), or decide their own leave request or correction.
- Personal data (date of birth, national id, address, personal email, emergency contacts, documents and the
  cost rate) is returned only with `hrm.personal.view` over the record or to the employee. The national id is
  encrypted at rest and never written to the audit trail. Documents live in `TenantStorage` and are only served
  through an authorized route as a `nosniff` attachment.
- Server time is the only attendance clock: clock endpoints take no timestamp, and manual, regularized and
  device-fed entries must be in the past and cannot overlap. Leave balances move only through append-only ledger
  rows keyed for idempotency; approval re-checks status under a row lock, so a request is decided once.
- Other modules read employees only through `App\Core\Workforce` snapshots, which carry no personal data.

## 5d.2b. Talent: candidates, reviews and training

- The `talent` route group requires tenant resolution, `entitled:talent` (which needs `hrm`), authentication,
  active membership, a healthy subscription and a route-specific permission. Nothing is public: there is no
  candidate-facing application form.
- Candidate data is sensitive: records are owned by the recruiter and scoped; contact details are returned only
  with `talent.candidates.view`; resumes and other documents live in `TenantStorage`, are served only through an
  authorized route as a `nosniff`, `private, no-store` attachment and are deleted with the record. Candidates have
  consent and retention fields; `talent.retention` anonymizes the expired ones and deletes their files. Contact
  details, expected pay and offer amounts are never written to the audit trail.
- Review ratings are sensitive: employees reach their own review through the `own` scope and never see the
  manager's ratings, comments, score or the final score before calibration; the manager reaches it as the
  designated reviewer; nobody reviews or calibrates themselves; a calibrated review is locked. Interview
  feedback is blind until a panelist has submitted their own scorecard.
- Events carry ids and scalars, never review text, notes or scorecards. `talent.candidate.hired` carries the
  candidate's name, email and phone because the HR hand-off needs them; automation workflows subscribed to it
  hold the same sensitivity.
- Talent never writes HR tables and reads employees only through `App\Core\Workforce` snapshots; a failing HRM-event
  listener is reported and never breaks the HRM action that raised the event.

## 5d.2c. Support: tickets, attachments and satisfaction links

- The `support` route group requires tenant resolution, `entitled:support`, authentication, active membership, a
  healthy subscription and a route-specific permission. `support.tickets.*` (except create) are scopable and the
  controller narrows with `TicketAccess` (`RecordAccess` plus the unassigned tickets of the agent's own queues);
  a ticket outside the scope is a 404. Handing a ticket to someone else needs `support.tickets.assign` for `all`.
- Attachments are private `TenantStorage` files, listed with a hidden path and served only by an authorized
  download (`attachment`, `nosniff`, `application/octet-stream`, `no-store`) after the attachment is checked to
  belong to the ticket in the URL. Uploads are limited by count, size and an extension allow-list.
- The satisfaction link is a 256-bit random token; only its SHA-256 is stored, it is nulled once the survey is
  answered, superseded or expired, and the answer is a conditional update, so a link works once. The two survey
  routes are stateless callbacks (`tenant.callback`: tenant from the host, `entitled:support`, throttled `60/min`,
  `10/min` for the POST; no session, no cookies, no CSRF token, no Inertia); every failure is the same 404 page and
  the page exposes only the workspace name, ticket number and subject. The emailed link travels in an encrypted queued mailable and is never logged,
  audited or placed in an event.
- Canned responses accept only allow-listed placeholders and are rendered by plain string substitution on the
  server: no expression language, no eval, no recursion. Messages are stored and shown as text.
- Inbound messages (`InboundTicketReceiver`) are idempotent on `channel + externalId` and are threaded into an
  existing ticket only when the sender is that ticket's requester: sequential ticket numbers are not a credential.
- Events carry ids and scalars, never message text, requester names or email addresses. Automation can create
  tickets, assign them and add internal notes; no action replies to a customer, resolves, merges or closes.

## 5d.3. Payroll

- The `payroll` route group requires tenant resolution, `entitled:payroll`, authentication, active membership, a
  healthy subscription and a route-specific permission; every salary, run, payslip, payment and report permission is
  declared sensitive. Salary, item, loan and payslip records carry `owner_id` / `department_id` / `branch_id`
  and every list and record route applies `RecordAccess`; self-service is the `own` scope, and an out-of-scope
  payslip is a 404 on the page, the document and the download. Draft payslips are visible only to people holding
  `payroll.runs.view`.
- **Four-eyes**: a payroll run, a reversal and a loan are approved by someone other than the person who prepared,
  submitted or requested it (a workspace setting can relax this; the change is sensitive and audited). Approval
  re-checks status under a row lock and refuses when the inputs changed since the calculation.
- Locked runs, payslips, lines, salary revisions and payment records are immutable at the model level; money columns
  of a payslip can never be updated, corrections are new adjustment or reversal runs.
- Payroll never moves money and never settles on a payload: a payment counts only when an authorized person records
  it, with a unique idempotency key. No action in the automation catalogue approves, pays or reverses.
- The payment account number is encrypted at rest, never written to the audit trail, shown masked, and decrypted only
  in the payment-sheet export (`payroll.payments.export`, audited). Exports are CSV with a BOM and formula-injection
  neutralization; the payslip document is `no-store` and `nosniff`.
- Tax and contribution rules are workspace data evaluated by first-party engines behind the `PayrollRuleSet`
  contract; no tenant-supplied code or expression is ever executed.

## 5d.4. Communication: messages, consent, provider callbacks

Details: `docs/COMMUNICATION.md`.

- **A workspace holds no secret.** A channel connection stores a provider key, non-secret settings and the *name*
  of a credential (`credential_ref`, `webhook_secret_ref`). The value is read at send time into a
  `ChannelCredentials` object that cannot be serialized, dumped or cast to a string, either from
  `config('communication.credentials')` (environment) or from an operator **provider account** (ADR 0013).
  A reference is usable only if the operator listed it for the workspace (`tenants` / `tenant_ids`); the settings
  screen shows names, never values; the audit trail records the channel and provider only.
- **Platform provider accounts (the one place messaging secrets sit in the database).**
  `communication_provider_accounts` is a central table written only from the Super Admin panel
  (`platform.settings.manage`, platform guard with mandatory 2FA). `secrets` is an `encrypted:array` cast
  (`APP_KEY`) and `$hidden`; a dump of the table alone reveals no secret, **a leaked `APP_KEY` reveals all of
  them** (rotate with `APP_PREVIOUS_KEYS`, and rotate the secrets at the provider). Secrets are write-only: no
  page, prop, JSON response, audit entry, log, queue payload or exception message carries one, and an empty value
  on edit keeps the stored one. An account answers only to the provider key it was created for (its secret is
  never sent to another endpoint) and only to workspaces in `tenant_ids`. `http_sms` calls an
  operator-supplied URL: https only (unless `communication.sms.allow_insecure_http`), no IP literal, local or
  private host, no embedded credentials. Treat database backups as secret-bearing. Payment gateways and
  Social credentials are not covered by this and remain environment-only. AI provider keys may now also be held
  as operator accounts: see 5d.6.
- **Honest providers.** SMS and WhatsApp use a `none` adapter that is always `not_configured` and refuses
  sends until a provider is selected. The in-memory test doubles are registered only in `testing` / `local` and
  are flagged as test doubles. Twilio, the generic HTTP gateway and SMTP adapters are covered by faked tests only.
- **Callbacks** (`communication/webhooks/{provider}`, `communication/unsubscribe/{token}`) are on the stateless
  `tenant.callback` group plus `entitled:communication`. The webhook verifies an HMAC over the raw body with the
  workspace's own secret before parsing (401 otherwise), caps the body, applies each provider event id once and
  never discloses whether a provider or message exists. The unsubscribe token is hashed at rest, GET never
  changes state, and every bad token answers alike.
- **Consent and suppression are enforced twice**, at queue time and again at worker time. Marketing needs a
  recorded opt-in and respects quiet hours; transactional messages ignore consent but not `all`-scope
  suppressions. A workflow can send only transactional templates.
- **Templates** are text with allow-listed placeholders substituted in one regex pass: no Blade, PHP, filters
  or expressions from tenant input; email text is escaped by a fixed view; subjects are single-line.
- **Scope.** A timeline is visible only for contacts the viewer may see (`core.contacts.view` scope); a
  notification is visible only to its member; messages follow `communication.messages.view` scope.

## 5d.5. Social: tokens, publishing and media

- The `social` route group requires tenant resolution, `entitled:social`, authentication, the membership check and the
  paywall; every route names its permission. Posts use `HasRecordOwner` and `RecordAccess`; a post outside the
  viewer's scope is a 404.
- Platform tokens are stored only through the `encrypted` cast on `social_accounts`, are `$hidden`, never in
  props, JSON, audit entries, events, logs or exception messages (`TokenSet` / `AccountCredentials` redact
  themselves; platform error text has the live token stripped). Platform app credentials are operator environment
  variables read in `config/social.php`, never database rows or settings.
- The OAuth `state` is random, bound to the session, the signed-in member and a 10-minute expiry, checked with
  `hash_equals` and consumed on first use; PKCE verifiers live in the session. A granted-pages selection is kept
  encrypted in the workspace cache for 10 minutes, bound to the member, and deleted when used.
- **Central redirect broker** (`GET social/oauth/callback/{platform}` on the central host, `central.callback`: no
  session, no cookies, throttled). The workspace is learned only from an HMAC-signed envelope the workspace put in
  front of `state` (key derived from `APP_KEY`; covers workspace, platform, expiry, nonce and inner state; 10
  minutes), the target host is built from `APP_DOMAIN` and a workspace that exists and is accessible, only
  `code`/`state`/`error*` are forwarded, redirects carry `no-store` and `no-referrer`, and every failure is a bare 404
  (no workspace probing). The workspace callback still performs the session-bound state check and token exchange.
- Publishing never trusts the browser: the publish runs in the entitlement-gated scheduled job, claims each target
  atomically, and treats an uncertain outcome as a failure for a person to check instead of retrying blindly.
  Approval is four-eyes (approver is neither author nor submitter) and a post whose content changes returns to draft.
- Media is verified by content and allow-listed (no SVG), stored through `TenantStorage` under a generated name and
  served only by an authorised route with `nosniff` and a sandboxing CSP. CSV imports are size/row capped, UTF-8
  only, column-whitelisted and never evaluated; CSV output is neutralised against spreadsheet formulas.
- No platform adapter has been run against its live API here; each needs the platform's app review. Instagram,
  YouTube, TikTok and Pinterest are refused with a reason, not simulated.

## 5d.5b. SMS Marketing: consent, opt-out and numbers

- The route group requires tenant resolution, `entitled:sms_marketing` (and so `communication`), authentication, the
  membership check and the paywall; every route names its permission. Campaigns use `HasRecordOwner` and
  `RecordAccess`; one outside the viewer's scope is a 404, as are all records of another workspace.
- The app holds no provider credential and calls no provider; it uses the three core contracts. Consent and
  suppression are decided at hand-over time by `MarketingAudience` and re-checked by the platform at send time, so no
  consent record means nothing is sent (the recipient is recorded as `blocked`). A contact is never created, and
  consent never recorded, by an import or an automation action.
- Every message carries the workspace's opt-out wording and a hashed, single-purpose one-click link from
  `UnsubscribeLinks`; if no link can be issued the recipient fails instead of being sent without one.
- Templates are data: variables come from a fixed allow-list and are looked up in an array of plain strings (control
  characters stripped, length bounded). Nothing tenant-supplied is evaluated; a one-time-code variable is refused in
  marketing text and a transactional template cannot be used in a campaign.
- Sends are bounded: a per-minute rate, a hard daily cap, a segment limit per message, a campaign audience bound, at
  most three attempts per recipient with permanent failures never retried, and an idempotency key per attempt so a
  retried step cannot message anyone twice.
- Sender-id registration is the workspace's own note and is never presented as verified. CSV import is size/row
  capped, UTF-8 only and column-checked; CSV output neutralises spreadsheet formulas.

## 5d.9. Email Marketing: consent, content and public links

- The route group requires tenant resolution, `entitled:email_marketing`, authentication, the membership check and
  the paywall; campaigns use `HasRecordOwner` and `RecordAccess` (a campaign outside the scope is a 404). Sending,
  importing, exporting, managing subscribers and activating sequences are sensitive permissions granted to no role.
- Nothing in this app reaches a mailer. Every email goes through `ChannelGateway`, which re-checks consent and
  suppression at send time; the app's own list state can never override the platform's answer. An import, an
  automation or a manual add without evidence is `pending` and is never sent to; importing never writes consent.
- Email content is typed blocks, not HTML. All text is escaped on output, every link must be http/https with a host
  and no credentials (or `mailto:` for a button), variables come from a fixed list and an unknown one is refused, and
  contact values are substituted after formatting is parsed so a name cannot inject markup. The preview and the web
  version render the same escaped output; the web version is served with `default-src 'none'`, `nosniff`,
  `X-Frame-Options: DENY` and `no-store`.
- Tracking links are HMAC-signed (key derived from the app key) over the tenant, the delivery and the destination, so
  they cannot be turned into an open redirect or reused across workspaces, and they are stateless callback routes
  with no session. An invalid token is a bare 404.
- CSV import is size/row capped, UTF-8 only, column-whitelisted, refuses an address that starts with a formula
  character and is never evaluated; CSV output is neutralised against spreadsheet formulas.
- Test sends go only to active members of the workspace and obey the same consent rules; free addresses cannot be
  typed in. A/B and campaign counters are metered idempotently; the audit trail records ids and counts, never message
  content or addresses.

## 5d.10. WhatsApp Business: webhooks, window and customer text

- The app has no webhook route of its own. Meta's callbacks arrive on the communication app's stateless route: tenant
  from the hostname, connected and enabled channel required, signature (`X-Hub-Signature-256`, HMAC-SHA256 of the raw
  body with the app secret from a credential reference) verified in constant time **before** the body is parsed,
  body size capped, each provider event id applied once. An event naming another `phone_number_id` (or business
  account id) than the connection's own is dropped, so a Meta app shared by workspaces cannot cross-feed them. The
  GET handshake needs the reference's `verify_token`, compares in constant time and echoes only an alphanumeric
  challenge.
- The access token and app secret never enter the database, props, logs, exceptions, queue payloads or audit entries
  (`ChannelCredentials` cannot be serialised, dumped or cast to a string; the adapter strips provider error text to
  the error code and a short detail). The settings page shows connection state and reference names only.
- Inbound text is untrusted: valid UTF-8, control characters removed, 4096-character cap, rendered as text (never
  `v-html`); attachments are metadata only and are never fetched, so no file type or size can be abused. Provider
  facts in webhooks are stored as scalars with fixed lengths. Notification text never contains message bodies.
- Free-form replies are refused outside the 24-hour window on the server (and by the adapter at send time); only an
  approved template can be sent then; a marketing template needs a recorded opt-in and the platform re-checks consent
  and suppression at send time. A hint to the adapter can only use the channel prefix and cannot override connection
  settings or the marketing references.
- Chatbot flows are data from a closed vocabulary; keywords are compared as plain text, placeholders come from an
  allow-list and nothing a tenant writes is evaluated. Bot replies are rate guarded and stop when a person owns the
  conversation.
- Logs never contain message text, names or numbers (listener failures log the exception class and event type only).

## 5d.6. AI Assistant: provider credential, tools and prompts

Full design in `docs/AI.md`; the security rules:

- The provider credential is platform-level. It comes from either an operator AI provider account (Super Admin >
  AI providers, extends ADR 0013) or, when no account exists, from `ANTHROPIC_API_KEY` in config. An account key is
  stored in `ai_provider_accounts.secrets` with the `encrypted:array` cast (`APP_KEY`; rotate with
  `APP_PREVIOUS_KEYS` and at the provider), is hidden from serialization, is write-only (no page, prop, JSON
  response, audit entry, log or exception message carries it; empty on edit keeps it) and is decrypted only inside
  the adapter that makes the call, where it goes only into the provider request header. It is never taken from a
  tenant or request; failures log status and error type only; "test connection" reports `ok` or a reason code
  (`unauthorized`, `unreachable`, ...), never provider text. The endpoint URL of an OpenAI-compatible account is
  operator-supplied and trusted at Super Admin level (http or https, no embedded credentials); it is not a
  tenant-reachable input. No usable credential means `not_configured`; the fake provider is bound only in
  `local` and `testing`. Treat database backups as secret-bearing.
- Record content is untrusted data: tool results and drafting inputs are JSON-encoded inside data blocks (tag
  characters escaped), the system prompt is versioned in code and never built from tenant input, drafting and
  automation calls have no tools, and whatever the model asks for is re-checked against the tools available to the
  member (permission in an entitled module, enabled data scope, record scope).
- Tools are read-only wrappers over documented contracts and run as the member. A mutating tool only creates an
  encrypted, expiring proposal; confirming it needs the member's own click, `ai.proposals.confirm`, and re-checks
  the underlying permission and record scope when the module action runs. `ToolRegistry` refuses any tool that
  touches money or access control. Conversations are private to their membership (404 for others, owner included).
- Every call is rate limited (workspace and member), output-capped, metered idempotently and guarded by a
  platform-wide circuit breaker. The audit trail stores tool names, statuses and record ids, never content.
  Optional PII redaction (default off) masks email, phone and card-like numbers before text leaves.

## 5d.7. Workflow automation: execution authority, webhooks and expressions

Full design in `docs/WORKFLOWS.md`; the security rules:

- **No tenant code, ever.** A workflow is JSON that names registered first-party triggers and actions. Conditions use
  a closed operator registry (no regex, no expression operator); data mapping is a fixed list of operations
  (`Transformer::OPERATIONS`); a template is only a dotted path into the run context and is never evaluated.
  Graph size, nodes, steps, loop iterations, parallel branches, depth and delay length are hard limits.
- **Execution authority is a person, re-checked every step.** A workflow runs as the member who last published it.
  At publish, and before every step, `ActorGuard` verifies the workspace's entitlement to `automation` and to the
  step's module, that the member's membership is active and that they still hold the step's permission for all
  records (a scopable permission held for less fails with `permission_scope`) and `automation.workflows.publish`.
  A denial fails the run without running the step and never routes to an error handler. Nothing is cached across
  steps. Starting a run by hand does not lend the starter's permissions.
- **Approvals are human.** An approval can be decided only by a member holding `automation.approvals.decide` who
  is the named approver (or holds the named permission) and, when flagged (default), is not the person the run
  acts for; the decision is taken under a row lock, audited, and applied by the executor.
- **Inbound webhooks** are signed: `sha256=HMAC(secret, timestamp + "." + rawBody)` compared in constant time, a
  +-300 s replay window, redelivery recognised by delivery id/signature (one run), a 64 KB body cap, tenant from
  the hostname only, no session or cookie. The URL token is stored as a SHA-256 hash; the signing secret is
  encrypted at rest (HMAC needs it) and shown once; rotation invalidates the old pair immediately. Unknown tokens,
  another workspace's tokens, paused workflows and unentitled workspaces all answer 404.
- **Outbound webhooks** go only to endpoints a holder of `automation.webhooks.manage` registered (a workflow
  never carries a URL). `UrlGuard` requires https on 443 with no credentials and a host name, resolves it and
  refuses loopback, private, link-local, reserved and carrier-grade-NAT addresses (IPv4-mapped IPv6 included),
  pins the connection to the approved addresses, does not follow redirects and caps time at 10 s. The body is
  signed with the endpoint's secret. Secrets are encrypted, write-only and never in logs, audit or exceptions.
- **Logs are redacted.** Step input/output keep real values only in the run context (actions need them); logged
  and displayed data masks secret-looking keys (`password`, `token`, `secret`, `authorization`, `api_key`,
  `signature`, ...) and cuts long strings.
- **Loop prevention.** Maximum steps per run, loop and parallel caps, a depth limit for workflows that trigger
  workflows through domain events, per-workflow and per-workspace start rates, and a per-workspace concurrency cap.

## 5d.8. Reports & Analytics: providers, scope and exports

- The `reports` route group requires tenant resolution, `entitled:reports`, authentication, active membership, a
  healthy subscription and `reports.reports.view`. A report runs only if `ReportRegistry` finds it for the member:
  the workspace is entitled to the provider's module **and** the member holds the provider's own permission.
  Unknown, not entitled and not permitted are the same `404`.
- A report is declarative. Dates are bounded (at most 366 days), columns must be declared, filters must be declared
  and typed (an undeclared filter is refused), and values are bound parameters inside the provider. No request
  value is ever SQL, an expression or a class name.
- A provider over a scopable permission narrows its records with `RecordAccess` for the requesting member, so a
  report never shows more than the module's own screen; a derived figure the member may not see is `null`.
- Results are capped at 2000 rows and flagged `truncated`; exports are throttled (`20/min`), need
  `reports.reports.export` (sensitive) plus the provider's permission, are built from the same output as the screen,
  and are audited (`reports.report.exported`: report, range, filters, columns, row count, scope).
- CSV cells that could start a formula (`=`, `+`, `-`, `@`, tab, CR, LF, also after leading spaces) are prefixed
  with an apostrophe; numbers are written as numbers.
- Saved reports store a setup, never data. Sharing with a role or department shares the setup only: the person who
  opens it runs it with their own permission and scope. Only the owner, or a holder of `reports.saved.manage_all`,
  may change or delete one; anyone else gets a `404`.

## 5d.11. Operator tooling: health probes, backup verification, benchmark

Decision record: ADR 0019. Procedures: `docs/DEPLOYMENT.md` section 8, `docs/BACKUP_RESTORE.md`,
`docs/PERFORMANCE.md`.

- `GET /health` answers `{"status":"ok"}` to anyone and nothing else. `GET /health/ready` is readable only from an
  address in `HEALTH_ALLOWED_IPS` or with the `HEALTH_TOKEN` bearer (constant-time compare); everyone else gets a
  **404**, so the endpoint's existence is not disclosed. It carries platform-wide counts and service names only: no
  tenant names or ids, no credentials, no exception text. Both are outside the `web` group (no session, no
  cookies). Behind a proxy `TRUSTED_PROXIES` must be set first or the IP gate sees the proxy.
- `ops:verify-backup` never touches the application's database: it creates a scratch database whose name must end in
  `_restore_check`, refuses an existing one, refuses a dump that contains `USE`, `CREATE|DROP DATABASE|SCHEMA`,
  `SOURCE`, `SYSTEM` or a `\!` client command (also inside version comments), passes the database password in
  `MYSQL_PWD` not on the command line, strips credentials from error text and drops only a database it created.
  A dump is secret-bearing (every tenant, encrypted columns): store and transfer it accordingly.
- `ops:benchmark` seeds a workspace into a database and so refuses production and any database not named
  `*testing`. It writes synthetic data only.
- None of these commands is reachable from the web.

## 5d.12. Telephony: PBX credentials, softphone password, events (ADR 0036)

- Each workspace enters its own PBX. The ARI password, the TURN credential, the webhook secret and every member's SIP
  password are encrypted at rest, hidden from serialization and write-only: pages learn only whether one is stored, an
  empty field keeps it, and the webhook secret is shown once when it is generated.
- **A member's SIP password reaches that member's browser**, because a browser softphone cannot register without it.
  It is served only by `GET /telephony/softphone/credentials`, only for the signed-in member's own extension, as JSON
  with `Cache-Control: no-store`, never in shared page props and never logged. The PBX should restrict each extension to
  its own calls; changing the password is one action.
- The ARI URL is server-side request forgery checked (`PbxUrlGuard`): https only, no credentials, query or fragment in
  the URL, and every resolved address public (loopback, private, link-local, reserved and IPv6 transition ranges are
  refused) unless the environment sets `TELEPHONY_ALLOW_PRIVATE_PBX`. The connection is pinned to the checked
  addresses and redirects are not followed. A PBX error never reaches the page: the member sees a fixed message.
- PBX events arrive on a stateless `tenant.callback` route: HMAC-SHA256 over `{timestamp}.{body}` with the workspace's
  secret, a 300-second replay window, a 16 KB cap and an event-id table that makes redelivery a no-op. The body cannot
  pick the tenant (the host does) or a member outside the workspace (an extension maps to one inside it).
- Browser reports about a call are accepted only from the call's owner and are ignored once the PBX confirmed the
  call, so the log of consequence is the PBX's. No call is recorded.

## 5e. Browser security headers

`App\Core\Http\Middleware\SecurityHeaders`, appended to the `web` group:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` (and `frame-ancestors 'none'` in the policy) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` - a referrer never carries the path, where invitation and confirmation tokens are |
| `Permissions-Policy` | camera, microphone, geolocation and payment off |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`, when the request arrived over HTTPS |
| `Content-Security-Policy` | Scripts from this origin or carrying this response's nonce only; no objects; forms post to this origin only |

**The Content-Security-Policy is sent report-only by default** (`SECURITY_CSP=report-only`): the browser lists
violations in its console and blocks nothing. It has not been watched in a browser yet. Set `SECURITY_CSP=enforce`
after doing that; `off` removes the header. Horizon's dashboard is excluded from the policy.

**Deploy checklist (browser hardening):** set `SECURITY_CSP=enforce` once the console has been watched clean
(`style-src` still allows `'unsafe-inline'`, limitation 4); serve over HTTPS so HSTS is sent; leave
`SESSION_SECURE_COOKIE` unset or `true` (see section 4).

Reverse proxies are trusted from `TRUSTED_PROXIES` (`config/trustedproxy.php`). With it unset, forwarded headers
are ignored.


- `AuditLogger::record()` writes to `audit_logs`: tenant (null for platform events), actor type/id/label, event,
  subject, properties, IP, user agent. The model refuses updates and deletes.
- `Auditable` (on `Role`, `TenantMembership`) records created / updated / deleted with old and new values,
  skipping hidden attributes and timestamps.
- Keys such as `password`, `token`, `secret`, `two_factor_*`, `api_key`, `credentials` are replaced with
  `[redacted]` at any depth.
- Recorded today: `auth.*`, `member.*`, `role.*`, `tenant_membership.*`; `branch.*`, `department.*`, `contact.*`,
  `company.*` (through `Auditable`); `workspace.registered`, `.onboarded`, `.settings_updated`, `.logo_*`,
  `.created`, `.approved`, `.suspended`, `.reactivated`; `billing.*` (every subscription change, every invoice
  issued, paid and voided, every payment started, received, recorded, failed and unapplied);
  `support.session_started` / `_ended`; and at platform level `platform.auth.*`, `platform.admin.created`,
  `platform.workspace.*`, `platform.impersonation.*`, `platform.settings.updated`, `plan.*`. The full list with
  its wording is `resources/js/Core/i18n/locales/en/catalogue.ts`.
- What an operator does to or inside a workspace is written in that workspace's own trail under the operator's
  name, so its owner can read it; the panel's audit page shows platform-level entries plus every entry whose
  actor is a platform user.
- Workspace members with `core.audit.view` read their tenant's trail. Its date filters are read as days on the
  workspace's clock, and its event filter matches one event (`member.invited`) or a whole family (`member`), never
  a name that merely starts with the same letters. Platform entries are shown on the Super Admin dashboard. There
  is no retention job.

## 7. Secrets and data handling

- `.env` is git-ignored; `.env.example` holds no secrets. No credentials are hardcoded. Demo accounts exist only
  through `DemoSeeder`, which refuses to run outside `local`.
- `APP_KEY` encrypts 2FA secrets, recovery codes, App Store installation settings, the secrets of messaging
  provider accounts (`communication_provider_accounts`, ADR 0013) and queued mail that carries a
  token (invitation, sign-up confirmation, workspace ready). Rotate it with
  `APP_PREVIOUS_KEYS`, never by replacing it.
- Tokens are never stored in the clear: invitations, sign-ups, support sessions and handoffs keep a SHA-256;
  password resets use the broker. A sign-up's password is hashed the moment the form is submitted.
  Sensitive arguments are marked `#[SensitiveParameter]`.
- `MAIL_MAILER=log` writes invitation and reset links to `storage/logs/laravel.log`. Local development only.
- Guard rails in `AppServiceProvider`: `Model::preventLazyLoading()` outside production,
  `Relation::requireMorphMap()`, `DB::prohibitDestructiveCommands()` in production.
- The only `v-html` is the server-generated 2FA QR code (`TwoFactorEnrolment.vue`). Do not add another with user
  content.

## 8. Known limitations

Open after Phase 5 implementation. Decide and fix, or accept and record.

| # | Limitation | Where |
|---|---|---|
| 1 | A password-reset token can be redeemed on any workspace host, not only the one the link was requested from; the `auth.password_reset` audit entry is filed under that host's tenant. Low impact: the token is still bound to the email it was sent to | `NewPasswordController::store()` |
| 2 | After a 404 for an unknown workspace, a tenant initialised earlier in the same process stays initialised. No effect under PHP-FPM; it matters only on a long-lived server such as Octane | `InitializeTenancy.php` |
| 3 | Signed file URLs on the `local` disk are served by the framework route `/storage/{path}` on any host with no session; the signature is the only check | `config/filesystems.php` (`serve => true`) |
| 4 | The Content-Security-Policy is report-only until it has been watched in a browser and switched to `enforce`. `style-src` allows inline styles | `SecurityHeaders`, `config/platform.php` |
| 6 | SSLCommerz and aamarPay have not been run against their sandboxes. Until they have, treat them as unverified | `Core/Billing/Gateways/SslCommerzGateway.php`, `AamarPayGateway.php` |
| 7 | A payment confirmed for an invoice that was voided meanwhile is kept and flagged (`billing.payment_unapplied`); there is no refund or credit feature to put it right | `PaymentProcessor::applyTo()` |
| 8 | Sign-up has no CAPTCHA or proof of work: the throttle (6 a minute per address) and the email confirmation are the only brakes on scripted sign-ups | `routes/central.php` |
| 9 | Support access is always as the owner, and a workspace cannot refuse or pre-approve it | `ImpersonationManager` |
| 5 | `SESSION_ENCRYPT` is false. The session cookie is `secure` by default when `APP_ENV=production` (`SESSION_SECURE_COOKIE=false` overrides it; other environments leave it off unless set) | `config/session.php`, `.env.example` |

## 9. Deferred

Changing an email address, and verifying one outside sign-up and invitation; API tokens with scoped abilities
(Sanctum, when first needed); login session management screen; channel authorization (Reverb, Phase 8); webhook
signature verification and encrypted integration credentials; a general file manager on top of `TenantStorage`;
per-tenant queue quotas; audit retention; custom domains; a gateway with a global webhook (it needs a central
endpoint that resolves the tenant from its own record of the payment).
