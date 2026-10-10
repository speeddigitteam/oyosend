# Billing

State: end of Phase 1. **Nothing is built**: no plans, subscriptions, invoices, payments, gateways or metering,
and no tables for them.

Two different things carry this name in the spec. Keep them apart:

| | Platform billing | Invoicing app |
|---|---|---|
| Spec section | 16 | 9 |
| Who bills whom | Oyosend bills tenants | A tenant bills its own customers |
| Data | Central | Tenant-owned |
| Phase | 2 | 6 |

This page is about platform billing.

## What exists today that billing will use

| Piece | Where | Note |
|---|---|---|
| Tenant lifecycle | `tenants.status` (`pending`, `active`, `suspended`), `EnsureTenantIsActive` | A non-active tenant gets a 403 page on every route, and the entitlement checker denies everything |
| Trial marker | `tenants.trial_ends_at`, `tenants.suspended_at` | Columns only; no code reads them |
| Entitlement contract | `App\Core\Entitlements\Contracts\EntitlementChecker` | The seam plans and paid apps plug into (`docs/APP_STORE.md`) |
| Queue | `billing`, worked by Horizon's `background` supervisor | Nothing dispatches to it |
| Money convention | `docs/DATABASE.md` | Integer minor units plus currency code; default currency `BDT` |
| Defaults | `config/platform.php` (`defaults.currency`) | Per-tenant `tenants.currency` |

## Planned (Phase 2)

From spec sections 16 and 27, without design beyond what the spec states:

- Plans: Starter, Business, Enterprise, Custom; monthly and yearly; trials, renewal, upgrade, downgrade, proration
  policy, coupons, failed payments, grace periods, suspension, cancellation, refund records.
- App billing independent of the base plan where permitted: one-time purchases, licences, per-seat and
  feature-based pricing.
- One central metering service for SMS, email, WhatsApp, AI credits, automation executions, storage and extra
  users, with idempotent usage records (a unique idempotency key per event).
- A payment gateway abstraction: SSLCommerz, bKash merchant, Nagad merchant, Stripe where supported.
- Entitlements and usage limits; Super Admin plan management.

Rules the spec fixes:

- Payment success is confirmed only by backend verification through the gateway's official validation, IPN or
  webhook. A frontend redirect alone never confirms a payment.
- Do not assume merchant credentials or gateway approvals exist. Build the interface, document the missing
  configuration and show an honest "not configured" state.
- No fake payment verification in production code paths. Fakes are bound only in `testing` / `local`.
- Billing status and entitlements are never trusted from the frontend.

Outline of the tables: `docs/DATABASE.md` section 4.
