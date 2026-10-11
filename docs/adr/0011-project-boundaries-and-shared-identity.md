# ADR 0011: Project boundaries, shared identities and future-module hand-offs

- Status: accepted
- Date: 2026-10-10

## Context

Project management connects customer identity, workspace members, schedules, files, time, budgets and eventually
CRM, Invoicing, HRM and notifications. Copying contacts or users into the module would create conflicting sources
of truth. Directly importing future modules would also make Projects impossible to install independently. Task
dependencies, recurring work, automation retries and client links need explicit integrity rules.

## Decision

1. Projects reference core `contacts`, `companies` and `tenant_memberships`. They do not own duplicate customer,
   employee or user identity. The module registers a contact-merge participant for every contact reference it owns.
2. Projects and tasks are tenant-owned, ULID-backed scoped records. Lists and route-bound records apply
   `RecordAccess`; nested writes verify the tenant, project and actor independently.
3. List, Kanban, calendar and Gantt are projections of one task model. Dependencies are project-local directed
   edges and a write is rejected when it would introduce a cycle.
4. Money is stored as bounded integer minor units with currency, time as integer minutes, and datetimes as UTC.
   Server calculations are authoritative.
5. Client collaboration is an explicit revocable grant. Its opaque invite token is stored only as a hash, expires,
   and never implies workspace membership or visibility of internal comments and financials.
6. Mutating automation actions write a durable tenant/action/idempotency-key receipt atomically with their state
   change. Recurrence also uses a stable key so a retry cannot duplicate the next task.
7. CRM-to-project creation, project-to-invoice revenue, project-to-HRM time sheets and project-to-notification
   delivery cross only versioned events or documented core contracts. Projects never imports another optional
   module's models.
8. Uninstall or entitlement loss removes executable access and retains project history, files and commercial
   facts.

## Consequences

- One contact merge can preserve the same customer across core, CRM and Projects.
- An independent Projects entitlement remains meaningful; later modules can be absent without breaking it.
- Projections may evolve without duplicating task state, while dependency and recurrence rules remain centralized.
- Portal access can be revoked without changing a contact or creating a global login.
- Phase 6, 7 and 8 integrations must implement the documented hand-offs instead of reaching into project tables.
