# 0028 — Operator-managed integration credentials in the panel

Status: accepted (supersedes the "payment gateways stay environment-only" part of the credentials
decision in spec section 0 and ADR 0013). Date: 2026-10-13.

## Context

ADR 0013 let an operator type **messaging** provider credentials into the Super Admin panel, stored
encrypted and write-only, while **payment gateway** credentials, Social platform credentials and
`APP_KEY` stayed environment-only. The owner has since decided that an operator must be able to
configure every integration from the panel without editing `.env` and redeploying — starting with
the payment gateways shown on the billing settings page. Requiring a deploy to turn on a gateway, or
to change the invoice issuer printed on invoices, was the friction this removes.

## Decision

1. **Payment gateway credentials may be stored in the database**, the same way messaging accounts are
   (ADR 0013): encrypted at rest (`encrypted:array`), in `$hidden`, never returned to a page, never
   logged, never put in an exception message. The new `payment_gateway_accounts` table (central
   connection, no `tenant_id`, one row per compiled gateway key) holds them. `GatewayCredentials`
   resolves a gateway's credentials **panel-first, environment-fallback**: a saved, enabled row
   replaces `config/platform.php`; a saved but disabled row offers nothing (the gateway is not
   configured and is not offered); with no row — or if the table cannot be read, as during bootstrap
   or a migration — the environment defaults are used. Decrypted secrets are read only inside the
   gateway and are never cached across requests.

2. **Invoice issuer details and the invoice-number prefix are runtime settings.** `invoice_prefix`,
   `issuer_name`, `issuer_address`, `issuer_email` and `issuer_tax_id` join the existing
   `PlatformSettings` keys (`platform_settings` table), editable on the billing settings page. The
   `config/platform.php` values are the defaults until the operator changes them.

3. **The environment stays the floor that cannot move.** `APP_KEY` (the key that decrypts everything
   stored in the database — it cannot itself live in the database), the database connection, the
   cache/redis/session/queue connections and `APP_DOMAIN` (needed to route a request before any
   database row is read) remain environment-only, by necessity, not policy.

Social platform credentials and the Meta Ads app secret are to move to the same panel-managed,
encrypted, env-fallback pattern in follow-up work; this ADR establishes the pattern for them.

## Consequences

- An operator turns a gateway on or off, switches sandbox/live and rotates its credentials from the
  panel, with no deploy. The billing settings page now shows, per gateway, whether it is configured,
  where its credentials come from (panel or environment), its sandbox flag, and write-only inputs that
  may be left blank to keep the stored secret. A "clear" reverts a gateway to the environment.
- The security posture is unchanged in spirit from ADR 0013: a database breach exposes *encrypted*
  secrets, not plaintext, and no page or log ever carries them. The one new exposure over
  environment-only storage is the same one already accepted for messaging accounts.
- `platform.gateway.updated` / `platform.gateway.cleared` audit events record which gateway changed
  and its non-secret flags, never the secret values.
- `CLAUDE.md` and `docs/BILLING.md` are updated: the "payment gateways stay environment-only" rule is
  replaced by this decision; the "never store gateway credentials in the database" rule keeps its
  force for *plaintext* and now has this encrypted, write-only exception, exactly like ADR 0013.
