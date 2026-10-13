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
   lifecycle value is recognized once. Deals can be moved from the deal page or by dragging a card on the deal
   board (`/crm/deals?view=board`), which shows up to 200 open deals of one pipeline, scoped like the list. Both
   use the same stage route. Dropping on a won or lost stage asks for confirmation first, and the note becomes
   the lost reason.
   CRM shows a contact's leads, deals and tasks/follow-ups in the Customer 360 panel through three providers in
   `app/Modules/CRM/CustomerRecords` (ADR 0020). The panel offers "Create lead", "Create deal" and "Add task or
   follow-up" to members who may create them. The links open the CRM list with `?create_for={contact}`, which opens
   the create form with that contact chosen; CRM still validates and authorises the save.
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
  A target may belong to a member, department, branch or the whole company (API fields `scope`, `owner_id`,
  `department_id`, `branch_id`; default is the creator) and attainment honours that scope. A contract linked to an
  order cannot change its customer, currency or value, and a price list's currency is fixed once it has items.

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

- Triggers: lead created, lead qualified, deal won, activity completed, quotation accepted (`crm.quote.accepted`,
  from the existing `QuoteAccepted` event: the sales order is confirmed; filter `minimum_total_amount`) and sales
  order fulfilled or canceled (`crm.sales_order.status_changed`, a new `SalesOrderStatusChanged` event raised by
  `SalesOrderManager::transition()`; filter `status`). Both need `crm.orders.view` and carry ids, number, contact,
  currency and total: an order confirmation or delivery message is a workflow with a WhatsApp, SMS or email action.
- Actions: assign a lead, move a deal stage, create a follow-up activity and create a lead (`crm.lead.create`,
  `crm.leads.edit`: `contact_id`, `title`, optional `source_id` and `owner_id`; with `skip_if_open` (default true) a
  contact who has a new, contacted or qualified lead gets that lead back with `created: false` instead of a second
  one). Lead capture from a first WhatsApp message is this action behind `whatsapp_marketing.message.received`
  with `new_conversation: true` (`docs/WHATSAPP.md` section 8).

Mutating actions persist their result in `crm_automation_action_receipts`, uniquely keyed by tenant, action and
idempotency key. A retry returns the stored output, so assignment and stage movement are not repeated. The future
engine still owns workflow execution, but the Phase 4 action boundary is already durable across processes.

Deployments load both CRM migrations before running `permissions:sync` and `apps:sync --prune`. Deactivation or
uninstall removes effective access but deliberately retains CRM business data. No CRM job calls an external CRM,
calendar, email or accounting service; those integrations remain later-phase consumers of domain events.

## Lead intake and audience contracts

Other apps reach the CRM through two core contracts (ADR 0023), never through CRM models. Both are bound to null
objects in core and rebound by the CRM module; a workspace without the CRM entitlement gets `unavailable` / empty.

**`App\Core\Crm\Contracts\LeadIntake::capture(LeadCapture): LeadCaptureResult`** hands over one enquiry as a
system integration (WhatsApp, Facebook Lead Ads, web form). `LeadCapture` validates a source key
(`^[a-z][a-z0-9_]{1,39}$`), a required idempotency key (up to 160 characters), name, email, a phone normalized to
E.164 by `PhoneNumber`, an optional company name, up to 30 plain-text answers, campaign and ad references, a
consent-evidence flag and the time. The CRM:

1. finds the shared contact by email, then by canonical phone (any spelling), or creates it through
   `ContactDirectory`; an existing contact only gets a missing email or phone filled in;
2. registers the source once as a lead source (`WhatsApp`, `Facebook Lead Ads`, `Web form`, or the headlined key);
3. reuses the oldest open (new, contacted, qualified) lead of that contact and source, or creates one, unassigned
   and never defaulted to a signed-in member. `crm.lead.created` fires only for a new lead and now carries
   `source_id` and `source_key`;
4. records a `crm.lead.captured` timeline entry, a note of the campaign, ad and answers, and an audit entry.

The outcome is stored once per (workspace, source, idempotency key) in `crm_automation_action_receipts` under the
action `crm.lead_intake.{source}`: a retry returns the same ids with status `replayed` and writes nothing.
Consent is never written by the CRM. Assignment is not automatic (the CRM has no default-owner or round-robin
setting): use a workflow on `crm.lead.created` with the assign action.

**`App\Core\Crm\Contracts\CrmAudience`** lists customer groups and their contacts for campaign audience
building: `segments(?TenantMembership $viewer)` returns `lead_status:*`, `deal_stage:*`, `tag:*`, `saved:*` and
`lifecycle:*` groups with a size; `contactIds($segmentId, $limit, $afterId, $viewer)` returns a cursor page (at
most 1000) of shared contact ids. With a viewer, each source is narrowed by that member's record scope for the
permission that governs it (`crm.leads.view`, `crm.deals.view`, `crm.customers.view`, `core.contacts.view`,
`crm.activities.view`); a member without it, from another workspace or no longer active sees nothing. Without a
viewer the whole workspace is returned: only for trusted system callers. Consent and suppression are still decided
by `MarketingAudience` at queue and send time.

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
