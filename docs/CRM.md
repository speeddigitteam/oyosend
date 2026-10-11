# CRM and sales

The first production optional app is `crm`, implemented under `app/Modules/CRM` and registered by
`CRMServiceProvider`. Its code and migrations ship with Oyosend, while each workspace still needs an effective
plan or App Store entitlement. A workspace without that entitlement receives 404 responses and no CRM
permissions, navigation, dashboard data or automation definitions.

## Customer identity

CRM does not duplicate people or organisations. Leads, deals, activities, quotations, orders, contracts and
customer lifecycle profiles reference the shared core `contacts` and `companies` tables. The CRM profile is only
sales-specific enrichment (stage, follow-up dates and lifetime values); the contact/company remains the source of
truth. Core detects normalized email/phone duplicates and provides an explicit field-by-field merge. A merge
soft-deletes the duplicate only after core and registered modules transactionally repoint their references; CRM
also resolves customer-profile, tag and custom-field collisions.

Every CRM table is tenant-owned and uses ULIDs. Models use `BelongsToTenant`; records governed by a scopable
permission also use `HasRecordOwner`, with `owner_id`, `department_id` and `branch_id`. Controllers constrain
lists with `RecordAccess` and return 404 for an out-of-scope bound record.

## Lead and pipeline workflow

1. Create a source and a pipeline with ordered stages in CRM settings.
2. Create a lead linked to a shared contact or company. Leads carry a 0–100 score, owner and source.
3. Qualify the lead, or convert it by opening a deal. Conversion retains the shared customer links.
4. Move a deal through stages. Every move writes immutable stage history and a unified timeline entry; terminal
   stages set won/lost state and timestamps. A won or lost deal cannot be reopened or commercially edited, so
   lifecycle value is recognized once.
5. Schedule calls, meetings, tasks and follow-ups against the lead, deal, contact or company. Activities support
   reminders, priorities, assignment, completion and cancellation. Tenant-local inputs are stored as UTC.

Notes, tags, custom fields and private attachments can be applied to leads, deals and the shared contact/company
behind a customer profile. Their detail views aggregate a unified customer timeline from directly linked and CRM
work events. Attachment bytes are stored through `TenantStorage`; downloads are short-lived signed URLs and
require access to the parent record. Saved segments filter lead, deal, activity, contact and company lists without
copying shared customer data into CRM.

## Sales workflow

- Products and services use bounded integer minor-unit prices and basis-point tax rates. Quantities use
  thousandths in `quantity_milli`; shared overflow-checked arithmetic keeps line, document, report and lifetime
  value totals exact without floating point.
- Price lists can override a product price by minimum quantity and validity date.
- Quotations and proposals snapshot every line, price, discount and tax. Once sent, their commercial snapshot is
  immutable. Acceptance creates an idempotently linked sales order. A quotation may reference only a deal within
  the actor's deal scope and with the same customer and currency.
- A linked deal is the authoritative lifetime-value source: winning that deal recognizes its amount once, while
  accepting its quotation does not add the order again. An accepted quotation without a deal recognizes its order
  total directly. This keeps standalone sales useful without double-counting opportunity-backed sales.
- `crm.quote.accepted` is the stable Phase 6 hand-off. Its event payload contains identifiers, currency, integer
  totals and immutable order-line snapshots. The Invoicing module (`docs/INVOICING.md`) creates an itemized draft
  invoice from it without importing or querying CRM models, once per quotation.
- Sales orders have explicit status transitions. Contracts may be linked to an order or created for a shared
  customer. Targets and reports expose actual sales, weighted forecast, conversion, order trend and attainment.

## Permissions

The module declares separate view/manage permissions for leads, deals, activities, customers, products,
quotations, orders, contracts, targets, reports and settings. Customer-facing records are scopable to own,
department, branch or all. Catalogue configuration, targets, reports and CRM settings are workspace-wide.

The workspace owner has every permission implicitly. Existing roles receive none during deployment. Run
`php artisan permissions:sync`, then explicitly grant the required permissions and scopes. A member who creates a
scoped record becomes its owner unless an actor with all-scope assignment permission selects another active
workspace member.

## App Store and operations

The compiled manifest publishes a free `included` offer, catalogue metadata, route inventory, morph aliases,
navigation, a scope-aware pipeline dashboard widget, and these automation definitions:

- Triggers: lead created, lead qualified, deal won and activity completed.
- Actions: assign a lead, move a deal stage and create a follow-up activity.

Mutating actions persist their result in `crm_automation_action_receipts`, uniquely keyed by tenant, action and
idempotency key. A retry returns the stored output, so assignment and stage movement are not repeated. The future
engine still owns workflow execution, but the Phase 4 action boundary is already durable across processes.

Deployments load both CRM migrations before running `permissions:sync` and `apps:sync --prune`. Deactivation or
uninstall removes effective access but deliberately retains CRM business data. No CRM job calls an external CRM,
calendar, email or accounting service; those integrations remain later-phase consumers of domain events.

## Important contracts

- Money is an integer number of minor units; tax and attainment rates are integer basis points.
- A quote may link only to a visible deal for the same customer and currency. A won linked deal is the canonical
  lifetime-value recognition point; accepting its quote creates the order but does not count the sale twice.
- Tenant IDs never come from request data.
- Search text is escaped through `App\Core\Support\Like`.
- Route access is layered: tenant resolution, sign-in, active membership, healthy subscription, CRM entitlement,
  permission and record scope.
- Events dispatched from transactional managers implement the platform domain-event contract and are delivered
  only after commit.
