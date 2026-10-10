# ADR 0003 — User identity and sessions

- **Status:** Accepted
- **Date:** 2026-10-08
- **Phase:** 1

## Context

The spec requires that one user may belong to several tenants (3.2), that Super Admins live in a separate
`platform_users` table behind a separate guard on `admin.APP_DOMAIN` (section 0), a workspace switcher (22),
TOTP two-factor with recovery codes (5), and mandatory 2FA for the Super Admin panel (17). Custom domains are a
future feature that must not be designed out.

Checked on Packagist before installing: `pragmarx/google2fa` 9.1 (MIT), `bacon/bacon-qr-code` 3.1 (BSD-2-Clause).
`laravel/sanctum` 4.3 is compatible but deliberately not installed until API tokens are built (Phase 2+).

## Decision

**Identity.** `users` is a platform-wide identity with a globally unique email. `tenant_memberships`
(unique `tenant_id, user_id`) is the tenant-scoped handle for "this person in this workspace". `platform_users` is
a separate table, behind the separate `platform` guard, on a separate host. Workspace routes never bind `User`,
only `TenantMembership`.

**Sessions.** Session cookies are host-only (`SESSION_DOMAIN=null`). A workspace session is additionally bound to
a tenant id stored in the session (`auth.tenant_id`), and `EnsureTenantMember` re-checks that binding and the
active membership on every request. Switching workspace uses a single-use handoff token, valid 60 seconds for one
user and one target tenant, kept in the central cache (`WorkspaceHandoffController`). "Remember me" is bound to
the workspace that issued it through an encrypted companion cookie (`remember_workspace`): the framework's own
remember cookie names a person and no workspace, so on its own it would open every workspace they belong to.

**Sign-in.** Responses are uniform: a wrong password, an unknown email and a real account that is not a member
of this workspace fail identically and take the same minimum time (`CredentialVerifier`, `VerifyWorkspaceCredentials`).
The forgot-password form answers the same way for everyone and sends the link after the response.

**Email verification.** The verification flow for self-registration arrives with Phase 2. Accepting an invitation
counts as verifying the address, because the link was delivered to it.

**Two-factor.** TOTP via `pragmarx/google2fa`, with recovery codes. Secret and codes are encrypted at rest
(encrypted casts). An accepted code cannot be replayed: the last accepted time step is remembered in the central
cache. Wrong codes are counted per account there too, and after 10 of them codes are refused for 15 minutes
while recovery codes keep working. 2FA is optional for workspace users and mandatory for platform users
(`EnsurePlatformTwoFactor`), where `PLATFORM_REQUIRE_2FA=false` relaxes it outside production only.

## Consequences

- A session in one workspace is never valid in another, and removing or suspending a member takes effect on their
  next request, not when the session expires.
- The model works unchanged for future custom domains, which could never share a cookie with `APP_DOMAIN`.
- One password and one second factor per person across all their workspaces. A password reset requested in one
  workspace changes it for all of them, and its token can be redeemed on any workspace host.
- A remembered session comes back without asking for the second factor, in the issuing workspace only.
- Because email is globally unique, tenant code must not use `User` to list people: `User` has no tenant scope.
  Go through `TenantMembership`.
- Signing in to a second workspace is either a fresh login or a handoff; there is no single sign-on cookie. The
  handoff token travels in a query string, so it is single use, short lived and stored only as a hash.
- The central cache (`cache.central`) is a hard dependency of handoff, replay protection and the 2FA lockout; all
  three must stay out of the tenant-prefixed cache.
- Recovery codes are encrypted, not hashed, and are shown once at enrolment or regeneration.
- No email verification is enforced in Phase 1: seeded users are marked verified, invited users become verified
  on acceptance.
