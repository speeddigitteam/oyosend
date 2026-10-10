# ADR 0006 — Platform billing

- **Status:** Accepted
- **Date:** 2026-10-10
- **Phase:** 2

## Context

The spec (sections 16, 27) asks for plans, subscriptions with trials, renewal, upgrade and downgrade with a
proration policy, failed payments, grace periods, suspension, cancellation, a payment gateway abstraction
(SSLCommerz, bKash, Nagad, Stripe), entitlements, usage limits and one idempotent metering service. It fixes two
rules: payment success is confirmed only by backend verification through the gateway's own API, and no gateway is
simulated in production code. The owner has not yet chosen gateways or prices, and no merchant credentials exist.

The gateways named for Bangladesh are redirect checkouts. None of them gives the platform a stored payment method
it could charge on its own schedule.

## Decision

**Billing is invoice-driven.** The platform never charges anybody. It issues an invoice; the workspace pays it
through a gateway, or an operator records money received outside one. A paid plan is switched on in exactly one
place, `SubscriptionManager::settle()`, and only by an invoice that became paid.

| Concern | Decision |
|---|---|
| Data | Central, scoped to the tenant: `plans`, `subscriptions` (one per tenant), `invoices`, `payments`, `usage_records`. Models use `BelongsToTenant` + `UsesCentralConnection`, so workspace code is scoped automatically and the panel crosses tenants explicitly |
| Money | Integer minor units in `amount` / `*_amount` columns next to a `currency` code. `Money` converts to a decimal string for a gateway and to text for mail; nothing else on the server needs either |
| What people ask for | `SubscriptionManager` (start, change plan, cancel, resume, settle; grant and extend trial for operators) |
| What time does | `BillingCycle`, run for every tenant by `billing:run` (hourly, idempotent): next invoice `invoice_lead_days` before a trial or period ends → past due at the boundary → expired after `grace_days` |
| Invoices | A document: lines and amounts are fixed when it is issued; paid in full by one payment, or voided and replaced. At most one open base-plan invoice per workspace; Phase 3 gives each separately billed app item its own open-invoice slot |
| Proration | Moving to a more expensive plan inside a paid period issues an invoice for the new price minus the unused value of the old period (by the second, rounded down); the new period starts when it is paid. That invoice is valid for 3 days, because its credit is worked out for the day it was issued. Moving to a cheaper plan waits for the paid period to end. No refunds are computed |
| Price changes | A subscription renews at the price it was taken at. Changing a plan's price affects new subscribers and plan changes only |
| No trial | A paid plan taken without a trial is invoiced at once and gets the grace period to pay |
| Ended subscription | `expired` (unpaid) and `canceled` lock the workspace down to billing, personal settings and signing out (`subscribed` middleware). Data is kept. Core modules stay entitled so the owner can sign in and pay |
| Gateways | `PaymentGateway` contract: `initiate()` hands the payer over, `verify()` asks the provider server to server. `PaymentProcessor::confirm()` settles only on that answer, for exactly the invoice's amount and currency, once per provider transaction (`UNIQUE(gateway, gateway_reference)`) |
| Callbacks | On the workspace's own host, so the tenant still comes from the hostname, in a stateless route group (no session, cookies or CSRF token). A gateway returns the payer with a cross-site POST, which carries no session cookie; under the `web` group that request would start a new session and sign the payer out |
| First gateway | `SslCommerzGateway`, written against the published v4 API and covered with recorded responses. **Not run against the SSLCommerz sandbox**: that needs a store id and password. Without them it reports `isConfigured() === false` and is not offered |
| Offline payments | An operator records a bank transfer, cash or cheque against an invoice from the panel. It is a real payment path, audited under the operator's name in the workspace's own trail |
| Entitlements | `PlanEntitlementChecker`: core modules for every accessible workspace; a plan's optional modules while the subscription is trialing, active or past due inside grace. A workspace without a subscription has core only and is not locked |
| Limits | A module declares a `LimitDefinition` (gauge or metered) in its manifest; a plan sets the maximum per key. `UsageLimits` answers and refuses; `UsageMeter` records metered usage once per idempotency key (`UNIQUE(tenant_id, idempotency_key)`) |

## Consequences

- There is no automatic renewal charge. A workspace that does not pay goes past due, then expires. Card-on-file
  gateways (Stripe) can be added behind the same contract and would settle the renewal invoice themselves.
- A provider with one global webhook URL (Stripe) cannot use the per-workspace callback routes; it will need a
  central endpoint that resolves the tenant from its own record of the payment. Not built.
- Money that arrives for an invoice that was voided while the payer was at the gateway is kept as a succeeded
  payment and audited as `billing.payment_unapplied`. Putting it right is an operator's decision; there is no
  refund or credit-note feature.
- Not built, although spec section 16 lists them: coupons and discounts, refund records, tax, per-seat and
  usage-based charges on an invoice, app subscriptions independent of the plan (`subscription_items`, Phase 3),
  dunning mail beyond the invoice and the receipt.
- The only limit that is enforced today is `core.members`. Nothing consumes `UsageMeter` yet; the first metered
  module (SMS, Phase 8) will.
- Plan prices, names and limits are data, managed in the Super Admin panel. The four plans in `DemoSeeder` are
  placeholders for local development and exist nowhere else.
