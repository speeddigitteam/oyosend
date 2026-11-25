# 0040. Limited-time offers on plans

Date: 2026-10-17. Status: accepted (owner's request; the two choices below were the owner's).

## Context

The owner wants to give discounts on plans. Discounts were listed as "not built" (ADR 0006, `docs/BILLING.md`
section 7). The owner chose to start with a limited-time offer on a plan, as one of several discount kinds (coupons
and per-workspace discounts may follow), and answered two questions:

- After the offer ends, a workspace that took the plan during it **keeps the offer price** on renewal.
- The offer has **separate monthly and yearly prices**, either or both, with one last day.

## Decision

- `plans` gets `offer_monthly_amount`, `offer_yearly_amount` (unsigned minor units, nullable) and `offer_ends_at`
  (UTC). The Super Admin plan form takes the prices and a last day; the offer ends at 23:59:59 of that day in
  `platform.defaults.timezone` (Asia/Dhaka). Each offer price must be at least 1 and below the plan's own price for
  that interval, which the plan must be sold for; a last day is required with a price, today or later. Leaving both
  prices empty clears the offer.
- `Plan::amountFor()` returns the offer price while the offer runs (and is below the list price), else the list
  price; `Plan::listAmountFor()` always the list price. Every billing path already reads `amountFor()`
  (`SubscriptionManager::price()`, the pending-plan price in `BillingCycle`), and a subscription stores the price it
  was taken at in `subscriptions.amount`, so "keeps the offer price on renewal" needs no new billing code. Invoices,
  settlement and payment verification are unchanged: the invoice simply carries the offer amount.
- `BillingPresenter::plan()` sends `monthlyAmount` / `yearlyAmount` as today's price (what choosing the plan bills)
  plus `listMonthlyAmount` / `listYearlyAmount` and `offerEndsAt`. The plan editor overrides the first two with the
  stored list prices so that saving never writes an offer price over the plan's own.
- The plan list, the workspace billing page, the sign-up page and the website show the offer price with the list
  price struck through (`<del>`, with a screen-reader label), the offer's last day, and, on yearly prices, what paying
  yearly saves against twelve months (rounded down). The website's JSON-LD offer uses today's price.

## Consequences

- An offer is a price, not an invoice line: the invoice shows the plan at the offer price, with no separate discount
  line. Coupons and per-workspace discounts, if built, will need invoice discount lines and a decision of their own.
- Workspaces already on the plan keep the price they took it at; an offer does not lower it. Changing plan during the
  offer takes the offer price (and the upgrade/downgrade comparison uses it).
- Changing an offer never changes a subscription already taken.
