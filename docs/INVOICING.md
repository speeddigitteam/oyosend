# Invoicing

The third production optional app is `invoicing`, implemented under `app/Modules/Invoicing` and registered by
`InvoicingServiceProvider`. It is **customer invoicing**: invoices a workspace sends to its own customers. It is
unrelated to the platform's own subscription billing in `app/Core/Billing` (`docs/BILLING.md`), which has its own
`invoices` table. Tables here are prefixed `invoicing_`.

The code ships with Oyosend; a workspace needs an effective plan or App Store entitlement for `invoicing` before
its routes, navigation, permissions, automation definitions or scheduled jobs are usable. A workspace without it
receives 404 responses. Removing the entitlement hides the app and retains every record.

State of the build: **backend and Vue pages complete, Phase 6.** The pages named in this document are what the
controllers render; see "Frontend" below.

## Customers

An invoice references the shared core `contacts` and/or `companies` by id (`contact_id` / `company_id`). There is no
customer table of its own. Pickers are narrowed by the actor's `core.contacts.view` scope, as in CRM. The module
registers a contact-merge participant (`InvoicingContactMergeReference`) that repoints invoices and recurring
templates to the canonical contact.

At **issue** time the customer's name, contact name, email, phone, address and tax id, and the seller's (the
workspace's) legal details, are copied onto the invoice (`customer_*`, `seller_snapshot`). Later edits of the shared
contact or company never change an issued document. A draft shows the live customer.

## Invoice lifecycle

```
draft --issue--> issued --payment--> partially_paid --payment--> paid
                    \                     \
                     +-- (due date passed, scheduled job) --> overdue --payment--> paid
 issued / overdue --void (no payments, no credit notes)--> void
 paid --payment reversed--> issued | partially_paid | overdue
```

| Status | Meaning |
|---|---|
| `draft` | Editable, deletable, has no number. Lines and totals are recalculated by the server on every save |
| `issued` | Numbered, customer/seller snapshot taken, lines and commercial fields frozen (model guard + manager checks) |
| `partially_paid` | At least one non-reversed payment, balance remains |
| `overdue` | Due date passed with a balance. Set once by the scheduled job (or by reopening a paid invoice past its date); it still accepts payments |
| `paid` | Balance is zero: paid, or paid together with credit notes. A fully credited invoice reads as `paid` with `paidAmount` 0 and `creditedAmount` = total |
| `void` | Cancelled after issue. Keeps its number (gap-free numbering); balance 0 |

`paid_amount`, `credited_amount` and `balance_amount` are **recomputed from the payment and credit-note rows** by
`InvoiceLedger`, never incremented, with the invoice row locked (`FOR UPDATE`).

## Money

Integer minor units everywhere, with a `currency`. `InvoiceMath` is the only arithmetic:

- quantity is in thousandths (`quantity_milli`), a unit price is integer minor units, rates are basis points;
- `gross = round_half_up(unit_price x quantity_milli / 1000)`;
- discount is either a fixed amount or a percentage in basis points of gross (rounded half up), never both;
- `tax = round_half_up(net x tax_bps / 10000)` **per line**; document totals are the sum of the rounded lines;
- intermediate products use `Brick\Math`, so nothing overflows or passes through a float. Bounds: unit price
  <= 1,000,000,000,000, quantity <= 10,000,000 thousandths, <= 200 lines, tax and percentage <= 10000 bps;
- totals are never read from the request.

A line takes either `tax_rate_id` (an active workspace tax rate; its name and rate are **snapshotted** on the line) or
an explicit `tax_bps`. Editing or deactivating a tax rate never changes an existing invoice. Different currencies
are never added together (reports are per currency).

## Numbering

`NumberAllocator` keeps one counter row per tenant, kind (`invoice`, `credit_note`) and period in
`invoicing_number_sequences`, `UNIQUE(tenant_id, kind, period)`. The row is read with `FOR UPDATE` and incremented
**inside the issuing transaction**, so concurrent issuers queue and a rollback gives the number back: committed
documents form an unbroken sequence. Drafts have no number. Format: `{prefix}[{fiscal year}-]{zero padded number}`,
e.g. `INV-0001`, `INV-2026-0001`, `INV-2026-27-0001`. Prefix, padding, fiscal-year numbering and the fiscal year
start month are workspace settings (`invoicing_settings`). With fiscal-year numbering each fiscal year restarts at 1.
Changing the prefix does not renumber issued documents.

## Payments (manual only)

`PaymentManager::record` records cash, bank transfer, mobile banking, cheque, card (terminal/offline) or other
payments **entered by a member**: method, reference, date, amount, notes and an optional `idempotency_key`
(`UNIQUE(tenant_id, idempotency_key)`; repeating the same key and amount returns the existing payment, a conflicting
repeat is refused). Partial payments are allowed; a payment above the outstanding balance, a zero amount, a future
date, and a payment on a draft, void or settled invoice are refused. `reverse` flags the payment with a reason
(`reversed_at`, `reversal_reason`, `reversed_by`), keeps the row, restores the balance and writes an audit entry.

**Gateway payments are deliberately not built.** No online card/wallet collection, no payment links and no webhook
exist, and nothing is faked. Connecting a workspace's own payment gateway needs credentials per tenant, which
CLAUDE.md forbids storing in the database, so it needs an owner decision about where tenant-supplied secrets may
live (and a verified server-side confirmation boundary like `PaymentProcessor::confirm()`). Until then a payment
counts only because a member recorded it.

## Credit notes

`CreditNoteManager::issue` creates a numbered, immutable credit note (own sequence, `CN-` prefix by default) with
its own lines and exact tax, against one open invoice. Its total cannot exceed the invoice's outstanding balance;
it is applied to the balance immediately and the invoice becomes `paid` when the balance reaches zero. Refunding
money already paid is out of scope (reverse the payment, or credit only what is outstanding).

## Where invoices come from

| Source | How | Idempotency |
|---|---|---|
| Manual | `POST invoicing/invoices` | n/a |
| Accepted CRM quotation | Listener on `crm.quote.accepted` (`CreateInvoiceFromAcceptedQuote`) reads the event payload (ids, currency, integer totals, line snapshots) and creates a **draft**; never imports a CRM model. Skipped when the workspace turned the setting off or lacks the entitlement. A failure never fails the acceptance | `source_key = crm_quote:{quote_id}` unique per tenant |
| Approved billable project time | Listener on `projects.time_entry.approved` (`RecordBillableTime`) stores plain ids, minutes and rate in `invoicing_billable_time`. The billable-time page groups unbilled time per project and currency; `POST invoicing/billable-time/invoice` creates one draft with a line per rate (hours in thousandths, rounded half up) | `UNIQUE(tenant_id, time_entry_id)`; billed rows carry `invoice_id` and are locked while billing. Voiding or deleting the invoice releases them |
| Recurring template | Scheduled job (below) | `source_key = recurring:{template_id}:{run date}` |
| Automation action | `invoicing.invoice.create_draft` | durable action receipt + `automation:{key}` source key |

**Known gap (Projects contract).** Projects publishes no project name or customer update event usable for
invoicing: `projects.time_entry.approved` carries only the project id. The billing form therefore takes the customer
and an optional label from the user, and the project is shown by id. Invoicing never reads a Projects table. A richer
contract (`projects.project.updated` with name and client) would be a Projects change.

## Recurring invoices

`invoicing_recurring_templates`: customer, frequency (`weekly`, `monthly`, `quarterly`, `yearly`) x `every` (1-12),
start, optional end, due days, optional auto-issue and auto-send, and the normalized line items (tax resolved at
save, so a later rate change does not alter it). The schedule is anchored on the start date and computed from it
each time, so a 31st does not drift to the 28th. `RecurringRunner` (job `invoicing.recurring_invoices`, hourly,
through the entitlement-gated `apps:run-scheduled` runner) creates one draft per due period in the workspace
timezone, catching up at most 12 periods per run, and advances the schedule in the same transaction. The unique
source key makes a retry or a second worker incapable of billing a period twice. With auto-issue the invoice is
issued after the period commits; if issuing is refused (plan limit, customer gone) the draft stays and
`last_error` explains why. Auto-send queues the email.

## Documents and email

`GET invoicing/invoices/{invoice}/document` (and the credit-note equivalent) returns **server-rendered,
print-optimised, self-contained HTML**: inline CSS with an A4 `@page` and print rules, the workspace logo embedded
as a data URI read through `TenantStorage`, tenant locale (en/bn). Use the browser's "Print / Save as PDF". There is
no PDF library: the permitted licences are MIT/BSD/Apache-2.0/ISC and the usual PDF generators are LGPL. The app
sends `X-Frame-Options: DENY`, so open the document in its own tab rather than an iframe. `?download=1` returns it
as an attachment.

`InvoiceMail` is a queued, tenant-bound mailable (queue `notifications`, `RequiresTenantContext`, workspace
locale) with the document attached. There is **no public view link**: the document travels with the message, so no
unauthenticated route exists.

## Reminders and overdue

`MaintainReceivables` (job `invoicing.receivables`, hourly) first marks past-due invoices `overdue` (announcing each
once) and then, when the workspace turned reminders on (off by default), queues reminders: `before_due` (within N
days), `on_due`, then `overdue:1..6` every N days. Each is reserved in `invoicing_reminder_logs`
(`UNIQUE(tenant_id, invoice_id, reminder_key)`) before it is queued, so reruns and overlapping workers send nothing
twice. Only open invoices with a balance and a customer email are reminded.

## Receivables report

`Receivables::report(from, to, currency)`: ageing buckets (not yet due, 1-30, 31-60, 61-90, over 90 days past due),
outstanding by customer, collected in the range (and by method, reversed payments excluded), invoiced and credited
in the range, all per currency and computed as SQL aggregates over integer columns.

## Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `invoicing.invoices.view` | yes | list, show, document |
| `invoicing.invoices.edit` | yes | create, edit and delete drafts, bill project time |
| `invoicing.invoices.issue` | yes | draft to issued; consumes a number and plan allowance |
| `invoicing.invoices.send` | yes | email invoice |
| `invoicing.invoices.void` | yes, sensitive | |
| `invoicing.payments.record` | yes | |
| `invoicing.payments.reverse` | yes, sensitive | |
| `invoicing.credit_notes.issue` | yes, sensitive | |
| `invoicing.recurring.view` / `.manage` | yes | |
| `invoicing.reports.view` | no | workspace-wide |
| `invoicing.settings.manage` | no, sensitive | settings and tax rates |

Invoices and recurring templates have `owner_id`, `department_id` and `branch_id` (`HasRecordOwner`); payments and
credit notes follow their invoice's scope. A member needs `core.contacts.view` to choose a customer.

## Limits and metering

`invoicing.invoices_per_month` (metered). `InvoiceManager::issue` asks `UsageLimits::ensure()` before taking a
number and records `UsageMeter::record()` with key `invoice:{id}` in the same transaction; drafts and credit notes
do not count. The period is the plan's billing period (a calendar month without one).

## Domain events and automation

Events (after commit, ids and scalars): `invoicing.invoice.issued`, `invoicing.invoice.paid`,
`invoicing.payment.received`, `invoicing.payment.reversed`, `invoicing.credit_note.issued`,
`invoicing.invoice.overdue`. Triggers are registered for all but `payment.reversed`. One action,
`invoicing.invoice.create_draft`, creates a draft with a durable receipt
(`invoicing_automation_action_receipts`, `UNIQUE(tenant_id, action, idempotency_key)`). Recording a payment,
issuing, voiding and sending are deliberately **not** automation actions: they stay human decisions.

## Audit

Models use `Auditable`, and business events are written inside the transaction that caused them:
`invoicing.invoice.issued|voided|overdue|sent|reminder_sent`, `invoicing.payment.recorded|reversed`,
`invoicing.credit_note.applied`, `invoicing.recurring.generated|paused|active|ended`, `invoicing.settings.saved`.

## Routes

All under `{tenant}.APP_DOMAIN/invoicing`, behind `auth:web`, `auth.session`, `member`, `subscribed`, `entitled:invoicing`
and a `can:` permission. No route is public.

| Name | Verb and path |
|---|---|
| `invoicing.invoices.index` | GET `/invoices` |
| `invoicing.invoices.create` | GET `/invoices/create` |
| `invoicing.invoices.store` | POST `/invoices` |
| `invoicing.invoices.show` | GET `/invoices/{invoice}` |
| `invoicing.invoices.update` | PUT `/invoices/{invoice}` |
| `invoicing.invoices.destroy` | DELETE `/invoices/{invoice}` |
| `invoicing.invoices.issue` | POST `/invoices/{invoice}/issue` |
| `invoicing.invoices.send` | POST `/invoices/{invoice}/send` |
| `invoicing.invoices.void` | POST `/invoices/{invoice}/void` |
| `invoicing.invoices.document` | GET `/invoices/{invoice}/document` |
| `invoicing.invoices.payments.store` | POST `/invoices/{invoice}/payments` |
| `invoicing.invoices.payments.reverse` | POST `/invoices/{invoice}/payments/{payment}/reverse` |
| `invoicing.invoices.credit-notes.store` | POST `/invoices/{invoice}/credit-notes` |
| `invoicing.invoices.credit-notes.document` | GET `/invoices/{invoice}/credit-notes/{creditNote}/document` |
| `invoicing.billable-time.index` / `.store` | GET `/billable-time`, POST `/billable-time/invoice` |
| `invoicing.recurring.index` / `.store` / `.update` / `.status` | GET/POST `/recurring`, PUT `/recurring/{template}`, PATCH `/recurring/{template}/status` |
| `invoicing.reports.index` | GET `/reports` |
| `invoicing.settings.index` / `.update` | GET/PUT `/settings` |
| `invoicing.settings.tax-rates.store` / `.update` | POST `/settings/tax-rates`, PUT `/settings/tax-rates/{taxRate}` |

## Frontend

Pages live in `resources/js/Modules/Invoicing/Pages` (`Invoices/{Index,Create,Show}`, `BillableTime/Index`,
`Recurring/Index`, `Reports/Index`, `Settings/Index`), shared pieces in `Components` and strings in the `invoicing`
namespace of `locales/{en,bn}/invoicing.ts`. There is no separate edit page: a draft is edited in place on `Show`
(`abilities.edit`), with the same `InvoiceForm` that `Create` uses. The line editor keeps what the person typed as text
and converts it to integers in `invoiceMath.ts` (quantity thousandths, unit price in the currency's minor units, rates
in basis points), previewing every line and the document with the same half-up rounding as `InvoiceMath`. The preview
is only a preview: the form never sends totals and the server recalculates. Controls follow `abilities` (invoice
actions) or the route permission (`useAccess()`); the server authorises each request again. The record-payment dialog
defaults the amount to the balance and the date to today in the workspace timezone, and generates a new
`idempotency_key` each time it is opened. The document opens in a new tab (`window.open`, it cannot be framed) with a
plain link to `?download=1`. Credit notes use free lines (tax by typed rate) because workspace tax rates are only sent
with editable drafts. Billable time shows the project as a short id because Projects announces nothing else (see the
known gap above). The reports page takes the currency as a typed code: the report payload carries no currency list.

## Deployment

Run migrations (`app/Modules/Invoicing/Database/Migrations`), then `php artisan permissions:sync` and
`php artisan apps:sync --prune`. New permissions are granted to no existing role; owners hold them implicitly.

## Not built

Gateway payments and payment links (see Payments), PDF files, a public invoice view, refunds of paid amounts,
multi-currency conversion, tax-inclusive pricing, invoice-level (as opposed to line-level) discounts, a dashboard
widget, and customer-facing statements.
