# ADR 0010: CRM customer identity and immutable sales hand-offs

- Status: accepted
- Date: 2026-10-10

## Context

CRM is the first production optional module. It must enrich the shared customer directory without creating a
second identity store, apply tenant and record scope consistently, calculate commercial values without floating
point, and give the future Invoicing module enough immutable data to create an invoice without reaching into CRM
tables.

Customer lifetime value also needs one unambiguous recognition point. Reopening a won deal or changing its money
after recognition would require reversal accounting that Phase 4 does not otherwise need.

## Decision

1. `contacts` and `companies` in core remain the canonical customer identity. CRM records hold nullable foreign
   keys to those rows, and core owns duplicate detection and explicit, transaction-safe contact merging.
2. Leads, deals, activities and sales documents are tenant-owned ULID records. Customer-facing resources use
   `HasRecordOwner` and every list or record action is narrowed through `RecordAccess` after permission checks.
3. Money is integer minor units, quantity is thousandths and rates are integer percentages or basis points.
   Intermediate multiplication uses arbitrary-precision integer math and returns a bounded PHP integer; floats
   and client-submitted totals are never authoritative.
4. Quote and sales-order lines are immutable commercial snapshots. A selected valid price list is authoritative
   for catalogue lines; linked contracts inherit their customer, currency and value from the order.
5. `crm.quote.accepted` is the Phase 6 boundary. Its scalar payload includes document/customer identifiers,
   totals and complete immutable order-line snapshots, so Invoicing does not import CRM models.
6. Won and lost deals are terminal. Their stage and commercial fields cannot be reopened or edited after close;
   this makes lifecycle recognition and lifetime-value updates one-way and idempotent.
7. Stored datetimes remain UTC. `datetime-local`, validity dates and report ranges are interpreted in the active
   tenant's timezone before persistence or querying; month grouping also uses that timezone.
8. A deal is the lifetime-value recognition source for every quote linked to it. Quote acceptance recognizes its
   order total only when the quote is standalone; linked acceptance never recognizes the same sale a second time.

## Consequences

- CRM uninstall or entitlement loss removes access but does not delete shared identities or CRM business data.
- A contact merge must repoint both direct and polymorphic module references through registered merge handlers;
  new modules that reference contacts participate through the same core extension contract.
- Phase 6 can consume accepted sales without querying or coupling to CRM. Changes to the event contract require a
  deliberate versioned compatibility decision.
- Reversals, returns and accounting adjustments belong to later invoicing/accounting workflows; Phase 4 does not
  simulate them by reopening a won deal.
