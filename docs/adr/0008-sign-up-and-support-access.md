# ADR 0008 — Sign-up and support access

- **Status:** Accepted
- **Date:** 2026-10-10
- **Phase:** 2

## Context

Two ways into a workspace were missing after Phase 1: a company signing up for one, and an operator entering one
to help. Both cross a boundary the identity model draws on purpose (ADR 0003): a user is a platform-wide identity
with a unique email, the central site has no sign-in, and a session belongs to one workspace's host.

The owner had not decided whether sign-up is open or approved by an operator. The spec lists "registration
approval settings" for the panel, so it is a setting, not a decision to hard-code.

## Decision

### Sign-up

1. The form on the central site records a `workspace_registrations` row and emails a confirmation link. Nothing
   else exists yet. The answer is the same whether or not the address already has an account.
2. The link opens a page with one button. It is a page, not an action, so a mail scanner that follows links cannot
   use the link up.
3. Pressing the button creates the account (unless the address has one), the workspace it owns, and its
   subscription to the chosen plan.

- The token is 64 random characters, stored only as SHA-256, valid for 24 hours, single use under a row lock. The
  workspace address stays reserved for as long as the link can be confirmed.
- **An existing account is not asked for its password.** Opening the emailed link proves control of the address,
  which is the same proof a password reset rests on. The account, its password and its second factor are left as
  they are, and it is then sent to the new workspace's ordinary sign-in.
- **A brand-new account is taken straight in** through the one-time workspace handoff: it was proven by the link a
  moment ago and cannot have a second factor yet.
- Registration mode is a platform setting: `open` (usable at once), `approval` (created `pending`; an operator
  approves it, which also restarts the trial) or `closed` (the routes answer 404).
- A workspace someone signed up for goes through first-run setup once (`tenants.onboarded_at`).

### Support access (impersonation)

A Super Admin opens a workspace as its owner. Spec section 17 sets the terms, and each is enforced in code:

| Requirement | How |
|---|---|
| A recorded reason | Required (10+ characters), stored on the `impersonations` row and in both audit entries |
| Time-limited | The session ends by itself after `platform.impersonation.ttl_minutes` (30); `EnforceSupportSession` checks on every request |
| Visible to the tenant | `support.session_started` / `_ended` in the workspace's own audit trail, under the operator's name; every entry written during the session carries `properties.support_session` |
| Fully audited | The same two events in the platform trail (`platform.impersonation.*`) |

- The session crosses from the admin host to the workspace host with a one-time token (60 s, hash only), like a
  workspace switch. The second factor is not asked for again: the operator passed the panel's mandatory one.
- It works on the workspace, not on the person it signs in as: profile, password, two-factor, language and
  switching to that person's other workspaces answer 403.
- Only `super_admin` holds `platform.tenants.impersonate`. `support_admin` stays read-only.

## Consequences

- Email is the root of trust for creating workspaces. Someone who controls a mailbox can create a workspace owned
  by that mailbox's account; they cannot enter the account's existing workspaces.
- Sign-up cannot be used to learn whether an address has an account, at the cost of a second step for everybody.
- Unconfirmed registrations are pruned daily (`model:prune`); a hashed password sits in that table for at most a
  day beyond the link's lifetime.
- Support access is always as the owner. Entering as a less privileged member, and letting a workspace approve or
  refuse access, are not built.
- `AuditLogger` reads the session to mark entries, so the marker is only present for work done through HTTP.
