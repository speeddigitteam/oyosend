# Security

State: end of Phase 1. Each mechanism is listed with the file that enforces it. Layers that have nothing to
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
| File storage | Primitive only | Every path is forced under `tenants/{tenant_id}/` on a private disk; `..` and NUL are rejected; access is by short-lived signed URL; no tenant → throws. No feature stores files yet | `Core/Tenancy/Storage/TenantStorage.php` |
| Search results | **Not applicable yet** | Scout is installed (`database` driver) but no model is searchable. The first searchable model needs a cross-tenant search test before it ships | — |
| Exports | **Not applicable yet** | No export feature | — |
| WebSocket channels | **Not applicable yet** | Reverb is not installed; `BROADCAST_CONNECTION=log`; there is no `routes/channels.php` | — |
| Notifications | Partly | The two mails that exist are sent inside the tenant context: the invitation mail is queued with `RequiresTenantContext`; password-reset links are generated with the active tenant's host. No notification centre | `Core/Identity/Mail/InvitationMail.php`, `CoreServiceProvider::bootModule()` |
| Automation executions | **Not applicable yet** | Contracts only; nothing executes | `Core/Automation` |
| Unique constraints | Enforced | `UNIQUE(tenant_id, user_id)` on memberships, `UNIQUE(tenant_id, name, guard_name)` on roles, validation scoped the same way. Deliberate exceptions: `users.email`, `invitations.token_hash` | `database/migrations`, `docs/DATABASE.md` |

Deliberate cross-tenant reads, all of them: `User::memberships()` (a person's own workspaces), `Tenant::memberships()`,
`TenantMembership::forTenant()` in the workspace handoff, and `withoutTenancy()` in `Platform/Http/Controllers/DashboardController.php`.

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
  settings 10/min, platform enrolment 10/min, workspace finder 20/min. Counts are per workspace. Second-factor
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
- Workspace switch: `WorkspaceHandoffController` issues a 64-character token, stored hashed in `cache.central`
  for `platform.workspace_handoff_ttl` (60 s), bound to one user and one target tenant, consumed with `pull()`.
  The target checks tenant and membership again and starts its own session.
- CSRF protection is the framework's, in the `web` group. Workspace pages only receive their own area's route
  table (`config/ziggy.php`).

## 5. Authorization

- One decision point: `Gate::before` → `AccessChecker` (`docs/ARCHITECTURE.md` section 8). No entitlement means no
  permission, whatever the role says.
- Routes declare `can:{permission}`. Non-core module routes also get `entitled:{module}`, which answers 404.
- `RoleManager`: nobody can grant or remove a permission they do not hold; roles carry only permissions of
  entitled modules; the Owner role cannot be edited or assigned; system roles cannot be deleted or renamed.
- `MemberManager`: the owner cannot be changed; nobody can change, suspend or remove themselves; and nobody can
  act on a member who holds a permission they lack (`canManage()` / `guardMutable()`), so `core.members.manage`
  alone is not enough to demote or remove an Admin. The members list sends `manageable` per row for the UI; the
  rule is enforced again on every change.
- Super Admin abilities are `platform.*`, answered by `PlatformRole::allows()`. `support_admin` is read-only.
- Horizon is on the admin host behind `auth:platform` + `platform.2fa`, and its gate admits super admins only.

## 6. Audit

- `AuditLogger::record()` writes to `audit_logs`: tenant (null for platform events), actor type/id/label, event,
  subject, properties, IP, user agent. The model refuses updates and deletes.
- `Auditable` (on `Role`, `TenantMembership`) records created / updated / deleted with old and new values,
  skipping hidden attributes and timestamps.
- Keys such as `password`, `token`, `secret`, `two_factor_*`, `api_key`, `credentials` are replaced with
  `[redacted]` at any depth.
- Recorded today: `auth.login`, `auth.login_failed`, `auth.logout`, `auth.password_changed`,
  `auth.password_reset`, `auth.two_factor_enabled` / `_disabled`, `auth.recovery_codes_regenerated`,
  `member.invited`, `member.invitation_revoked`, `member.joined`, `member.role_changed`,
  `role.permissions_changed`, `role.*`, `tenant_membership.*`, `platform.auth.*` and `platform.admin.created`.
- Workspace members with `core.audit.view` read their tenant's trail. Its date filters are read as days on the
  workspace's clock, and its event filter matches one event (`member.invited`) or a whole family (`member`), never
  a name that merely starts with the same letters. Platform entries are shown on the Super Admin dashboard. There
  is no retention job.

## 7. Secrets and data handling

- `.env` is git-ignored; `.env.example` holds no secrets. No credentials are hardcoded. Demo accounts exist only
  through `DemoSeeder`, which refuses to run outside `local`.
- `APP_KEY` encrypts 2FA secrets, recovery codes and the queued invitation payload. Rotate it with
  `APP_PREVIOUS_KEYS`, never by replacing it.
- Tokens are never stored in the clear: invitations and handoffs keep a SHA-256; password resets use the broker.
  Sensitive arguments are marked `#[SensitiveParameter]`.
- `MAIL_MAILER=log` writes invitation and reset links to `storage/logs/laravel.log`. Local development only.
- Guard rails in `AppServiceProvider`: `Model::preventLazyLoading()` outside production,
  `Relation::requireMorphMap()`, `DB::prohibitDestructiveCommands()` in production.
- The only `v-html` is the server-generated 2FA QR code (`TwoFactorEnrolment.vue`). Do not add another with user
  content.

## 8. Known limitations

Open at the end of Phase 1. Decide and fix, or accept and record.

| # | Limitation | Where |
|---|---|---|
| 1 | A password-reset token can be redeemed on any workspace host, not only the one the link was requested from; the `auth.password_reset` audit entry is filed under that host's tenant. Low impact: the token is still bound to the email it was sent to | `NewPasswordController::store()` |
| 2 | After a 404 for an unknown workspace, a tenant initialised earlier in the same process stays initialised. No effect under PHP-FPM; it matters only on a long-lived server such as Octane | `InitializeTenancy.php` |
| 3 | Signed file URLs on the `local` disk are served by the framework route `/storage/{path}` on any host with no session; the signature is the only check | `config/filesystems.php` (`serve => true`) |
| 4 | Trusted proxies, security headers (HSTS, frame options) and a Content-Security-Policy are not configured in the application. Behind a proxy, audit and throttle IPs are the proxy's | `bootstrap/app.php` |
| 5 | `SESSION_SECURE_COOKIE` is off unless set (`.env.example` carries it as a commented hint) and `SESSION_ENCRYPT` is false. Set the first wherever the site is served over HTTPS | `.env.example` |

## 9. Deferred

Email verification flow and self-registration (Phase 2); branches and departments, so `department` / `branch`
scopes are stored but not evaluated (Phase 2); impersonation with recorded reason and time limit (Phase 2);
API tokens with scoped abilities (Sanctum, Phase 2+); login session management screen; channel authorization
(Reverb, Phase 8); webhook signature verification and encrypted integration credentials; file upload validation
(MIME, size) on top of `TenantStorage`; per-tenant queue quotas; audit retention; custom domains.
