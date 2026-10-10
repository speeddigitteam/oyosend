# Security

State: end of Phase 2. Each mechanism is listed with the file that enforces it. Layers that have nothing to
protect yet are marked **not applicable yet** rather than described as done. Section 8 lists what is still
open.

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
| Cache | Enforced | For the life of the context the key prefix of **every** configured store gets `tenant_{id}_` appended (a store's own prefix is kept in front); all stores and their `Cache::memo()` wrappers are dropped and rebuilt. A store that ignores prefixes (`array`) is rebuilt empty. The rate limiter is handed the new store, so counts are per tenant and limiters named with `RateLimiter::for()` survive the switch. `cache.central` is never prefixed | `Bootstrappers/CachePrefixBootstrapper.php`, `Support/TenantAwareRateLimiter.php` |
| File storage | Enforced | Every path is forced under `tenants/{tenant_id}/` on a private disk; `..` and NUL are rejected; access is by short-lived signed URL; no tenant → throws. The workspace logo is the one stored file: png / jpg / webp up to 1 MB, 2000 px a side, SVG refused | `Core/Tenancy/Storage/TenantStorage.php`, `Core/Settings/WorkspaceLogo.php` |
| Records inside a tenant | Enforced | A scopable permission reaches a person's own, their department's, their branch's or all records. The controller narrows lists and single records through `RecordAccess`; a record outside the scope is a 404 | `Core/Access/RecordAccess.php`, `Concerns/HasRecordOwner.php`, ADR 0007 |
| Search results | **Not applicable yet** | Scout is installed (`database` driver) but no model is searchable. The first searchable model needs a cross-tenant search test before it ships | — |
| Exports | **Not applicable yet** | No export feature | — |
| WebSocket channels | **Not applicable yet** | Reverb is not installed; `BROADCAST_CONNECTION=log`; there is no `routes/channels.php` | — |
| Notifications | Partly | Workspace mail is queued with `RequiresTenantContext` and built inside the tenant context (invitation, invoice, receipt, workspace ready); password-reset links are generated with the active tenant's host. The sign-up confirmation is platform mail: no tenant exists yet. No notification centre | `Core/Identity/Mail`, `Core/Billing/Mail`, `Platform/Mail`, `Core/Registration/Mail` |
| Automation executions | **Not applicable yet** | Contracts only; nothing executes | `Core/Automation` |
| Unique constraints | Enforced | Tenant-first on every tenant-owned table (memberships, roles, branches, departments, usage records), validation scoped the same way. Deliberate exceptions, listed in `docs/DATABASE.md`: `users.email`, token hashes, and the platform-wide billing identifiers (invoice number, payment references) | `database/migrations`, `docs/DATABASE.md` |

Deliberate cross-tenant reads, all of them: `User::memberships()` (a person's own workspaces),
`Tenant::memberships()` and `Tenant::subscription()` (keyed by the tenant they are asked for),
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
  and reset password 6/min, invitation acceptance 10/min, handoff 20/min, password change 6/min, two-factor
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
  account with one, otherwise the workspace's (`InviteMember`).
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
  password, two-factor, language and workspace switch answer 403. Start and end are written to the platform
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
  database, shown on a page, put in an exception message or written to `payments.details`.
- Offline payments are recorded by an operator with `platform.billing.manage`, under their name, in the
  workspace's own audit trail.

## 5b. Browser security headers

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
- `APP_KEY` encrypts 2FA secrets, recovery codes and the queued mail that carries a token (invitation, sign-up
  confirmation, workspace ready). Rotate it with
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

Open at the end of Phase 2. Decide and fix, or accept and record.

| # | Limitation | Where |
|---|---|---|
| 1 | A password-reset token can be redeemed on any workspace host, not only the one the link was requested from; the `auth.password_reset` audit entry is filed under that host's tenant. Low impact: the token is still bound to the email it was sent to | `NewPasswordController::store()` |
| 2 | After a 404 for an unknown workspace, a tenant initialised earlier in the same process stays initialised. No effect under PHP-FPM; it matters only on a long-lived server such as Octane | `InitializeTenancy.php` |
| 3 | Signed file URLs on the `local` disk are served by the framework route `/storage/{path}` on any host with no session; the signature is the only check | `config/filesystems.php` (`serve => true`) |
| 4 | The Content-Security-Policy is report-only until it has been watched in a browser and switched to `enforce`. `style-src` allows inline styles | `SecurityHeaders`, `config/platform.php` |
| 6 | SSLCommerz has not been run against its sandbox. Until it has, treat it as unverified | `Core/Billing/Gateways/SslCommerzGateway.php` |
| 7 | A payment confirmed for an invoice that was voided meanwhile is kept and flagged (`billing.payment_unapplied`); there is no refund or credit feature to put it right | `PaymentProcessor::applyTo()` |
| 8 | Sign-up has no CAPTCHA or proof of work: the throttle (6 a minute per address) and the email confirmation are the only brakes on scripted sign-ups | `routes/central.php` |
| 9 | Support access is always as the owner, and a workspace cannot refuse or pre-approve it | `ImpersonationManager` |
| 5 | `SESSION_SECURE_COOKIE` is off unless set (`.env.example` carries it as a commented hint) and `SESSION_ENCRYPT` is false. Set the first wherever the site is served over HTTPS | `.env.example` |

## 9. Deferred

Changing an email address, and verifying one outside sign-up and invitation; API tokens with scoped abilities
(Sanctum, when first needed); login session management screen; channel authorization (Reverb, Phase 8); webhook
signature verification and encrypted integration credentials; a general file manager on top of `TenantStorage`;
per-tenant queue quotas; audit retention; custom domains; a gateway with a global webhook (it needs a central
endpoint that resolves the tenant from its own record of the payment).
