# ADR 0039 — Typed addresses for companies and contacts

- **Status:** Proposed, built. Not reviewed by the owner. Tested with Pest and Vitest; not yet looked at in a browser.
- **Date:** 2026-10-15
- **Changes:** adds one tenant-owned table (`contact_addresses`, migration
  `2026_11_22_000300_create_contact_addresses_table`), five routes under the existing contact permissions, and an
  "Addresses" section on a company's page and in a contact's details. Changes no locked decision.

## Context

A company or a person has one `address` / `city` / `country` on its own row. The owner asked for what Odoo's
"Contacts & Addresses" tab offers: separate **invoice**, **delivery** and **other** addresses under a company (and
under a contact), each with its own name, phone, email and full address. Invoicing, delivery notes and projects will
want "the invoice address of this customer" without a module keeping a customer table of its own.

## Decision

1. **One core table, `contact_addresses`, owned by the shared directory.** Tenant-owned (`BelongsToTenant`, ULID,
   `tenant_id` FK with cascade), soft-deleted like `contacts` and `companies`. Columns: `company_id` or `contact_id`,
   `type` (`invoice` | `delivery` | `other`, a `VARCHAR` cast to `AddressType`), `label` (a name for the place),
   `name` (the person to address it to), `email`, `phone`, `address` (text), `city`, `postal_code`, `country`
   (ISO-2), `is_default`. Indexes `(tenant_id, company_id)` and `(tenant_id, contact_id)`; no unique index.

2. **Exactly one parent.** Every address belongs to one company or one contact, never both, never neither. The parent
   is the record it is added to (`ContactDirectory::addAddress(Company|Contact $parent, …)`); the parent ids are not
   taken from the attributes, and an unsaved parent or another workspace's is refused. The rule is not a database
   CHECK constraint: MySQL refuses a CHECK on a column whose foreign key has a referential action, and both parent
   keys cascade on a hard delete (a workspace being purged).

3. **One default per type per parent, kept by the directory.** MySQL has no partial unique index, so
   `ContactDirectory` keeps the invariant "a type that has addresses has exactly one default", under a row lock on the
   parent: the first address of a type becomes its default; adding or saving one with `is_default` takes the default
   over; deleting the default, or moving it to another type, hands it to the oldest address left of that type.
   Unticking the default changes nothing on its own; another address is made the default instead.

4. **Written only through `ContactDirectory`, audited on the parent.** `addAddress`, `updateAddress`,
   `makeDefaultAddress`, `deleteAddress`, each in a transaction with its audit entry: `contact_address.created`,
   `.updated`, `.deleted`, with the company or contact as the subject (so the entry shows on that record's history and
   the address model needs no morph alias). The entry holds the address id, the type, the default flag and, for an
   update, the names of the changed fields; never the address, person, phone or email. No domain event is published
   yet: nothing listens, and a module that needs one adds it then.

5. **Authorized through the parent.** Whoever may change the company or contact may change its addresses:
   `core.contacts.manage` on the route, then `RecordAccess::authorize()` on the parent with its scope. An address
   whose parent is outside the scope, soft-deleted or another workspace's answers 404. Reading follows the parent's
   `core.contacts.view`.

   | Route | Name |
   |---|---|
   | `POST companies/{company}/addresses` | `core.contacts.addresses.company.store` |
   | `POST contacts/{contact}/addresses` | `core.contacts.addresses.contact.store` |
   | `PUT addresses/{address}` | `core.contacts.addresses.update` (the whole address; a field left out is cleared) |
   | `POST addresses/{address}/default` | `core.contacts.addresses.default` |
   | `DELETE addresses/{address}` | `core.contacts.addresses.destroy` |

6. **Where they are shown.** A company's page has an "Addresses" card between its details and its people: cards
   grouped by type, invoice first, the default of each type first with a "Default" badge, each with a menu (edit,
   make default, delete) for someone who may change the company. A contact's addresses are read and changed from its
   details dialog, on the people list and on the company page. `DirectoryPresenter` adds `addresses` only when they
   were eager-loaded: the company page loads them for the company and for its people, the people list for the page
   of contacts (one query each, never one per row); the companies list does not carry them.

7. **Merging and deleting.** A contact merge moves the duplicate's addresses to the kept contact inside the merge
   transaction (`ContactDirectory::moveAddresses`); a moved address stays the default of its type only where the kept
   contact had none of that type, and the count appears under `moved_references.contact_addresses` in the
   `contact.merged` audit entry. Deleting a company or a contact is a soft delete and leaves its addresses in place,
   hidden with it and back if it is restored, as its other data is.

## Consequences

- Modules that need a customer's invoice or delivery address read `contact_addresses` through the directory's
  contract when one is added; until then they keep using the parent's own `address` fields.
- The single `address` / `city` / `country` on `companies` and `contacts` stay; they are the record's main address.
  Folding them into typed addresses would be a data migration and needs the owner's say.
