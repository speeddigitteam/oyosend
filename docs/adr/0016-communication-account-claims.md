# ADR 0016 — A provider account is claimed by one workspace, in a platform-wide registry without `tenant_id`

- **Status:** Accepted
- **Date:** 2026-10-13
- **Phase:** 9 (WhatsApp Business) and review hardening of Phase 8

## Context

A workspace's channel connection holds a non-secret setting that names an account at the provider (the WhatsApp Cloud
API `phone_number_id`) and a *reference* to a credential (an environment entry or an operator provider account,
ADR 0013). A credential reference with `tenants: null` or an account with no `tenant_ids` is usable by every
workspace the operator allows. Without a further control, workspace B could type workspace A's `phone_number_id`,
select the shared token and send from, or receive, A's number.

The rule "every unique index on a tenant-owned table leads with `tenant_id`" cannot express "this value is unique
across all workspaces". A per-tenant unique index only stops one workspace from using a value twice.

## Decision

1. **A platform-wide claim registry.** Table `communication_account_claims` (model `AccountClaim`) has no
   `tenant_id`: ULID, `connection_id`, `provider`, `account_key`, `account_value`, with
   `UNIQUE(provider, account_key, account_value)` and an index on `connection_id`. The owner is the connection (a
   globally unique ULID), `cascadeOnDelete`, so a claim disappears with its connection and with its workspace.

2. **Which fields are claimed.** A provider marks a field `account: true` in `fields()`. Today that is only the
   WhatsApp Cloud `phone_number_id`. Other fields and other providers claim nothing.

3. **Claimed on save, atomically.** `ChannelConnections::save()` replaces the connection's claims inside the
   connection's transaction. A `UniqueConstraintViolationException` becomes the form error "This account is already
   connected to another workspace" on that field, and nothing is saved. The database's own uniqueness is the check;
   the application never lists claims across workspaces and never exposes which workspace holds a value.

4. **Access.** The model is read and deleted only through `connection_id` (a tenant-scoped model's id). It is not
   `BelongsToTenant` on purpose and is a documented exception in `docs/DATABASE.md`.

5. **Defence in depth, not a replacement.** The webhook route already drops an event whose account is not the
   connection's own setting; operators should still scope credentials with `tenants` / `tenant_ids` allow-lists.

## Consequences

- One value, one workspace, enforced by a unique index rather than by a read-then-write check, so two simultaneous
  saves cannot both win.
- First come, first served: the first workspace to save a value holds it. A workspace can reserve a number it does
  not own; there is **no operator screen to release a claim** (no code path outside `ChannelConnections` touches the
  table). Releasing means the holder saves another value or its workspace is deleted.
- A rename of the provider key or field key would orphan nothing (claims are replaced per save) but would let a
  value be claimed again under the new key; keep `provider`/`account_key` stable.
- `TenantIndexesTest` (every `tenant_id` table has a tenant-led unique index) does not see this table because it has
  no `tenant_id` column. The exception is therefore visible only here and in `docs/DATABASE.md`; a similar table
  must be justified the same way.
- Only the WhatsApp `phone_number_id` is protected. An SMS sender number or an SMTP identity is not claimed.

## Alternatives considered

- **A unique index on the tenant-owned connections table.** Rejected: a unique index there must lead with
  `tenant_id`, so it cannot be platform-wide; a functional index on JSON settings would also break the tenant-first
  rule and would be provider-specific.
- **Check for an existing connection with `withoutTenancy()` before saving.** Rejected: a cross-tenant read from
  workspace code is forbidden, and it is racy.
- **Rely only on operator allow-lists (`tenants`).** Rejected as the sole control: it depends on correct operator
  configuration per credential and does not protect a shared credential.
- **A per-provider-account `tenant_ids` binding stored with the operator account.** Possible later; it would move
  the decision to the operator and does not replace a self-service uniqueness guarantee.
