# ADR 0007 — Permission scopes, branches and departments

- **Status:** Accepted
- **Date:** 2026-10-10
- **Phase:** 2

## Context

The spec asks for scoped access (own / department / branch / all) and for multiple branches and departments per
tenant. Phase 1 stored a `scope` on every role grant but wrote it as `all` and evaluated nothing (ADR 0002).
Phase 2 adds the first records the scopes apply to: the shared contacts and companies.

## Decision

- **Organisation.** `branches` and `departments` are flat lists per tenant. A membership has at most one of each
  (`tenant_memberships.branch_id`, `.department_id`). A department is not tied to a branch.
- **Where they live.** `Branch` and `Department` are tenant-scoped but pinned to the central connection, like
  `TenantMembership`: authorization reads a person's placement on every request, and memberships reference them
  by foreign key.
- **Scopable permissions.** A module marks a permission `scopable: true`. Only those can be narrowed; every other
  grant is `all` whatever is asked for.
- **Ownership on the record.** A model that can be narrowed uses `HasRecordOwner` and has three nullable columns:
  `owner_id` (a membership), `department_id`, `branch_id`. Department and branch are copied from the owner when
  the owner is set, not looked up through the owner at query time.
- **Evaluation.** `RecordAccess::constrain()` narrows a query and `allows()` decides for one record:
  `own` = owner is me; `department` = that, or the record's department is mine; `branch` = those, or the
  record's branch is mine; `all` = everything. Someone without a department (or branch) gets only their own
  records from that scope. A record outside someone's scope answers 404, like a record of another tenant.
- **Editing rules.** `RoleManager` applies "nobody hands out what they do not hold" to scopes: granting,
  re-scoping or removing a grant needs the same permission at least as widely as the grant being touched, and a
  role can only be assigned by someone who holds everything it grants at least as widely.

## Consequences

- Scope checks are plain indexed `WHERE` clauses on the record's own table; no join to memberships, which would
  cross databases for a tenant on a dedicated one.
- A record stays with the department and branch it was made in when its owner later moves. Reassigning the owner
  moves it. That is deliberate: a team's customers do not follow someone who leaves the team.
- The route still names the permission (`can:`); the scope is applied by the controller through `RecordAccess`.
  A module that forgets to call it shows every record to anyone holding the permission. `ContactController` is
  the reference.
- Only someone who manages every record (`all`) can hand a record to another member; for everyone else a new
  record is theirs and the owner is left alone.
- Deleting a branch or a department clears it from memberships and records (`ON DELETE SET NULL`); people who
  relied on it for a `branch` / `department` grant fall back to their own records.
- New foreign keys from tenant-owned tables (`contacts`, `companies`) to central ones (`tenant_memberships`,
  `branches`, `departments`) join the list in `docs/DATABASE.md` section 5 of keys that cannot follow a tenant to
  a dedicated database.
- Existing workspaces keep the grants they had: the larger default grants of the system roles apply to
  workspaces provisioned from now on.
