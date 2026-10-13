# 0029 — Social and Meta Ads credentials in the panel

Status: accepted (follows ADR 0028, extends ADR 0013). Date: 2026-10-13.

## Context

ADR 0028 moved payment gateway credentials into the Super Admin panel — stored encrypted and
write-only, with the environment as the fallback — and recorded that the Social platform apps
(Facebook, LinkedIn, X) and the Meta Ads app secret should follow the same pattern in follow-up
work. The owner has decided every integration must be configurable in the panel without editing
`.env` and redeploying. This ADR covers those operator-level Social and Meta Ads credentials.

The Social and Meta Ads credentials are read in many files through `config('social.platforms.*')`,
`config('social.lead_ads.webhook_verify_token')` and `config('meta_ads.*')`. Editing every reader to
consult the database would be large and error-prone, and those modules had in-flight work at the
time.

## Decision

1. **A new `integration_credentials` table** (central connection, no `tenant_id`, one row per
   `(group, name)`) holds the operator-set values, the same way messaging accounts (ADR 0013) and
   payment gateways (ADR 0028) do: `secrets` is `encrypted:array`, in `$hidden`, never returned to a
   page, never logged, never put in an exception message. `settings` holds the **non-secret public
   identifiers** (`client_id` / `app_id`), which may be shown back to the operator so they can match
   the OAuth redirect URIs. The managed keys are:
   - `social/facebook` — secrets `client_secret`, `webhook_verify_token`; public `client_id`
   - `social/linkedin` — secret `client_secret`; public `client_id`
   - `social/x` — secret `client_secret`; public `client_id`
   - `meta_ads/app` — secret `app_secret`; public `app_id`

2. **Config-override, not consumer edits.** `App\Core\Settings\IntegrationCredentials::apply()`
   overlays the stored values onto the live config repository in `CoreServiceProvider::bootModule()`:
   for each enabled row, a stored non-empty value replaces its config key (e.g. the Facebook
   `client_secret` row value is written to `social.platforms.facebook.client_secret`). Every existing
   `config(...)` reader then transparently gets the panel value; no Social or Meta Ads consumer file
   changed. A key with no stored value — or a disabled row, or an unreadable table during bootstrap —
   keeps its `config/*.php` environment default. Decrypted secrets are read only inside the overlay,
   are not cached across requests, and the boot call is wrapped so a failure can never break boot.

3. **Write-only secrets, shown-back public ids.** The panel page (Super Admin → Integrations) shows,
   per integration, whether it is managed in the panel or using the environment, the public id
   (prefilled), write-only secret inputs that may be left blank to keep the stored value, and an
   enabled flag. A "clear" reverts an integration to the environment. `platform.integration.updated` /
   `platform.integration.cleared` audit events record which integration changed and its enabled flag,
   never the secret values.

## Consequences

- An operator sets or rotates the Social and Meta Ads app credentials from the panel with no deploy,
  exactly like the payment gateways. The security posture matches ADR 0013 / ADR 0028: a database
  breach exposes *encrypted* secrets, not plaintext, and no page or log carries them.
- The public client/app ids are intentionally readable (they are not secret and appear in OAuth
  redirect URIs); only `client_secret`, `app_secret` and `webhook_verify_token` are write-only.
- `APP_KEY` (which decrypts the stored secrets), the database/cache/queue connections and
  `APP_DOMAIN` remain environment-only by necessity, as ADR 0028 established. API versions, media
  flags and the redirect-broker toggles stay environment settings.
- `docs/SOCIAL.md` and `docs/META_ADS.md` record that credentials are set in the panel, encrypted and
  write-only, with env fallback.
