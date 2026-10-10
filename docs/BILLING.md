# Billing

State: Phase 3 is implemented and verified. This page is about **platform billing**:
the base plan and separately licensed App Store apps that Oyosend charges to tenants. The reasoning is in ADRs
0006 and 0009; this page is how it works and where the code is.

Two different things carry this name in the spec. Keep them apart:

| | Platform billing | Invoicing app |
|---|---|---|
| Spec section | 16 | 9 |
| Who bills whom | Oyosend bills tenants | A tenant bills its own customers |
| Data | Central, scoped to the tenant | Tenant-owned |
| Phase | 2 (built) | 6 (not built) |

## 1. The model in one paragraph

A workspace has at most one **subscription** to a base **plan**, plus zero or more App Store
`subscription_items`. The two commercial lifecycles are independent but use the same invoices, payment
verification and hourly `billing:run`. The platform never charges automatically: it issues an invoice, and the
workspace pays through a gateway or an operator records the money. A paid plan or app starts only after that
invoice is verified as paid. Late recurring items enter grace and then expire; data is kept.

## 2. Where things are

| Piece | Class | Notes |
|---|---|---|
| Plans | `Core\Billing\Models\Plan` | Price per interval (`monthly_amount`, `yearly_amount`; null = not sold, 0 = free), `trial_days`, `modules` (optional module keys), `limits` (limit key → maximum), `is_public`, `is_active`. Managed in the panel; never deleted, only archived |
| Subscription | `Core\Billing\Models\Subscription` | One per tenant. `status`, `interval`, `amount` (the price it was taken at), trial / period / grace dates, `cancel_at_period_end`, `pending_plan_id` |
| Everything a person asks for | `Core\Billing\SubscriptionManager` | `start`, `changePlan`, `cancel`, `resume`, `settle`; `grant` and `extendTrial` for operators |
| Everything time does | `Core\Billing\BillingCycle` | Run per tenant by `php artisan billing:run [--tenant=slug]`, scheduled hourly in `routes/console.php`. Safe to run twice |
| App purchases/lifecycle | `Core\AppStore\AppStoreManager` | Free install, paid checkout, verified fulfilment, activation, cancellation, expiry and uninstall |
| App billing cycle | `Core\AppStore\AppBillingCycle` | App trials, renewal invoices, grace, cancellation and expiry; run by the same `billing:run` command |
| Invoices | `Core\Billing\InvoiceIssuer`, `Models\Invoice` | Number `INV-{year}-{6 digits}` from one platform-wide counter (`platform_sequences`). `lines` and `billed_to` are snapshots |
| Settlement routing | `Core\Billing\InvoiceSettlement` | Sends base-plan invoices to `SubscriptionManager`, app purchase/renewal invoices to `AppStoreManager` |
| Payments | `Core\Billing\PaymentProcessor`, `Models\Payment` | `begin` (online), `confirm` (asks the gateway), `recordOffline` (operator); only verified success reaches settlement |
| Gateways | `Core\Billing\Contracts\PaymentGateway`, `Gateways\GatewayRegistry` | Registered in `CoreServiceProvider`. `SslCommerzGateway` is the only one |
| Entitlements | `Core\Entitlements\PlanEntitlementChecker` | Merges plan-included modules with effective App Store licences and validates dependencies |
| Paywall | `Core\Billing\Http\Middleware\EnsureWorkspaceIsSubscribed` (`subscribed`) | On every workspace route that is part of working in the workspace |
| Limits | `Core\Billing\UsageLimits`, `Core\Modules\LimitDefinition` | Declared in module manifests |
| Metering | `Core\Billing\UsageMeter`, `Models\UsageRecord` | Idempotent per `(tenant, idempotency_key)` |
| Settings | `Core\Settings\PlatformSettings` | `billing.grace_days` (7), `billing.invoice_lead_days` (7), `billing.offline_instructions`; changed in the panel, defaults in `config/platform.php` |
| Mail | `Core\Billing\Mail\InvoiceIssuedMail`, `PaymentReceivedMail` | Queue `billing`, to the workspace owner, in the owner's language |
| Events | `core.subscription.updated`, `core.invoice.paid` | `Core\Billing\Events` |

Workspace pages: `billing` (plan, usage, plans to choose, invoices) and `billing/invoices/{invoice}` — permissions
`core.billing.view` and `core.billing.manage`, held by the owner only unless a role is given them. Panel pages:
plans, invoices across workspaces, and the subscription actions on a workspace's page.

## 3. Subscription states

| Status | Meaning | Workspace usable | Plan modules |
|---|---|---|---|
| `trialing` | Using the plan before the first payment is due | yes | yes |
| `active` | Free plan, or inside a paid period | yes | yes |
| `past_due` | A payment is overdue; grace period running | yes | yes |
| `expired` | Unpaid past the grace period | locked | no |
| `canceled` | Ended by choice | locked | no |

A workspace **without** a subscription is not locked and has the core modules only. That is how workspaces
created in tests, by `TenantProvisioner` directly, or before plans existed behave.

"Locked" means: signing in, personal settings, signing out and the billing pages work; everything else redirects
people who can see billing to it and shows everyone else a "workspace locked" page (HTTP 402).

## 4. What happens when

| Event | Result |
|---|---|
| Sign-up or `start()` on a free plan | `active`, no period, no invoice |
| … on a paid plan with trial days | `trialing` until `trial_ends_at`; first invoice issued `invoice_lead_days` before it, due at the trial's end |
| … on a paid plan without a trial | `past_due` at once, invoice due now, `grace_days` to pay |
| Invoice paid | `active`; the period is the one the invoice was issued for — or starts at the payment if the workspace had been locked meanwhile, or the invoice is a change of plan |
| Trial or period ends unpaid | `past_due`, grace until boundary + `grace_days` |
| Grace ends unpaid | `expired`. The invoice stays open; paying it brings the workspace back |
| Renewal | Invoice `invoice_lead_days` before `current_period_end`, at the price the subscription was taken at |
| Change plan while trialing | Immediate; the trial's end does not move |
| More expensive plan inside a paid period | Invoice for the new price minus the unused value of the old period; applied, with a new period, when paid. Withdrawn after 3 days |
| Cheaper plan inside a paid period | Scheduled (`pending_plan_id`); the next period is billed for it |
| To a free plan | Immediate, or at the end of a paid period that is still running |
| Change plan while past due, locked or on a free plan | New invoice (the open one is voided); the plan starts when it is paid |
| Cancel | Runs to the end of the trial or paid period, then `canceled`. Past due: `canceled` at once |
| Resume | Takes a cancellation back before it took effect |
| Operator: set plan | Immediate, no invoice, any plan including non-public ones; optional end date |
| Operator: extend trial | Back to `trialing` until the given day; the open invoice is voided |

"More expensive" compares the price per month (a yearly price divided by twelve).

## 5. Payments

**Online.** `core.billing.invoices.pay` → `PaymentProcessor::begin()` creates a `pending` payment whose
`reference` (a ULID) is the transaction id given to the gateway, and redirects the payer to the provider. The
provider sends the payer back to `billing/payments/{reference}/return` and notifies
`billing/gateways/{gateway}/notify`. Both only find the payment and call `confirm()`, which asks the provider
server to server and settles the invoice if and only if the provider confirms a payment of the invoice's exact
amount and currency. Both can arrive any number of times.

These two routes are stateless (`routes/tenant-callbacks.php`, middleware group `tenant.callback`): tenant
resolution and a throttle, nothing else. See ADR 0006 for why.

**Offline.** In the panel, on an open invoice: method (bank transfer, cash, cheque, mobile banking, other),
optional reference and note, date received. Recorded as gateway `offline` with `recorded_by`, audited as
`billing.payment_recorded` in the workspace's trail under the operator's name.

**Gateways.**

| Gateway | Status |
|---|---|
| SSLCommerz (`sslcommerz`) | Implemented against the published API v4 (session, order validation, transaction query). **Unverified against the sandbox** — no store credentials exist yet. Offered only when `SSLCOMMERZ_STORE_ID` and `SSLCOMMERZ_STORE_PASSWORD` are set; `SSLCOMMERZ_SANDBOX=false` switches to the live host. It needs the company's phone, address, city, postal code and country, and refuses to start a payment without them rather than send placeholders |
| bKash, Nagad, Stripe | Not built. SSLCommerz's hosted checkout already carries bKash, Nagad and cards |

With no gateway configured the invoice page says so and shows the offline payment instructions, if the operator
wrote any.

Adding a gateway: implement `PaymentGateway`, register it in `CoreServiceProvider::register()`, put its
credentials in `config/platform.php` (`gateways`) from the environment. `verify()` must get its answer from the
provider's own API, never from the payload it is handed.

## 6. App subscriptions, entitlements and limits

- A plan's `modules` lists optional modules included in the base price. The App Store can also create an
  independent entitlement backed by a free licence, trial, one-time purchase or recurring `subscription_item`.
- The production build currently has no optional business module, so its catalogue and plan-module choices are
  empty until Phase 4. The App Store flow is tested with the compiled-only `Crm` fixture.
- App offer types are `free`, `monthly`, `yearly`, `one_time`, `usage_based` and `enterprise`. The latter two are
  visible catalogue offers but not self-serve. Amounts are integer minor units; app items copy the offer amount,
  currency and quantity so later manifest price changes do not rewrite an agreement already taken.
- A no-trial paid purchase creates an `app_purchase` invoice while its installation remains `pending_payment`.
  Verified settlement activates it and creates the entitlement. Monthly/yearly items renew with `app_renewal`
  invoices, use the same lead/grace settings as the plan, and deactivate on expiry without deleting app data.
- App cancellation means `cancel_at_period_end`; it can be resumed before the boundary. A one-time licence does
  not renew. Uninstall revokes access and ends renewal, but preserves installation, invoice and business history.
- A module declares what can be capped in its manifest (`limits: [new LimitDefinition(key, kind, count)]`). A
  **gauge** is counted from what exists (`core.members`: memberships plus invitations that can still be accepted).
  A **metered** limit is counted from `UsageMeter` records since the start of the billing period (or of the
  calendar month when there is no period).
- `UsageLimits::ensure($key)` refuses with a validation error at the limit. A plan that does not name a key, and
  a workspace without a subscription, are not limited.
- `UsageMeter::record($meter, $quantity, $idempotencyKey)` returns false, and counts nothing, when that key was
  recorded before in the same workspace.

Enforced today: the seat limit when inviting a member. Nothing records metered usage yet.

## 7. Not built

Coupons and discounts; refund records and credit notes; tax; charging for usage or extra seats on an invoice;
calculation for usage-based app offers; a revenue dashboard beyond the totals on the panel's invoice list; mail
for past due and expiry (the invoice and the receipt are the only billing mail); PDF invoices (the invoice page
prints); automatic charging of a stored payment method.

Rules that hold for anything added later:

- Payment success is confirmed only by backend verification through the gateway's official API. A redirect or a
  notification alone never confirms a payment.
- No gateway is simulated in production code. The test suite's `TestGateway` lives in `tests/Support/Fixtures`.
- Billing status and entitlements are never taken from the request.
