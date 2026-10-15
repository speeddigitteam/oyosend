# ADR 0013 — Platform messaging provider accounts

- **Status:** Accepted (the platform owner approved the change to the locked rule explicitly)
- **Date:** 2026-10-12
- **Phase:** 9 (Marketing Apps; Communication platform)

## Context

Until now every messaging credential lived only in the environment. A workspace connection stored a provider key,
non-secret settings and the *name* of an entry of `config('communication.credentials')`; the secret came from
`.env` (CLAUDE.md: "never store gateway credentials in the database"). That left two practical problems:

- An operator who has to add or rotate an SMTP password, a Twilio token or an SMS gateway key needs shell access
  and a deploy. The Super Admin panel, the operator's only working surface, could not do it.
- No SMS provider adapter existed because there was no way to give one a credential without editing config.
  Email marketing was limited to the one platform mailer.

The owner asked for the operator to type provider details in the Super Admin panel, and approved changing the
rule for this purpose.

## Decision

An operator can create **provider accounts** in the Super Admin panel (`Messaging providers`, permission
`platform.settings.manage`). An account holds non-secret settings and its secrets **encrypted in the database**.
A workspace still chooses a provider per channel in Communication → Settings → Channels; the credential dropdown
now also lists the accounts the operator made available to it, by name.

| Concern | Decision |
|---|---|
| Table | `communication_provider_accounts` (central connection, ULID, no `tenant_id`): `channel`, `provider`, `name`, `is_enabled`, `settings` (non-secret JSON), `secrets` (encrypted), `tenant_ids` (null = every workspace, otherwise the allowed workspace ids). `UNIQUE(channel, name)` |
| Model | `App\Modules\Communication\Models\ProviderAccount`, `UsesCentralConnection`, `secrets` cast `encrypted:array`, `$hidden`. It is platform data; it is not `BelongsToTenant` on purpose |
| Reference | A workspace's `credential_ref` is `acct_{ulid lowercased}`. A workspace row holds only that name, as before |
| Resolution | `CredentialResolver` answers `acct_*` references in addition to `config('communication.credentials')` entries. It returns `null` when the account is missing, disabled, not available to the workspace (`tenant_ids`), or was created for a different provider key: **a platform account answers only to the provider it was created for**, so its secret can never be sent to another provider's endpoint |
| Secret keys | `secret` (the API credential or password), `webhook_secret` (signs inbound webhooks), `verify_token` (subscription handshake). `ChannelCredentials` still cannot be serialized, dumped or cast to a string; it now also carries the account's non-secret `settings()` |
| Adapters | `SmtpProvider` (`smtp`, email), `TwilioSmsProvider` (`twilio`, SMS), `HttpSmsProvider` (`http_sms`, SMS: a generic one-request-per-message gateway), beside the existing `WhatsAppCloudProvider` and `LaravelMailProvider`. Those that take an account implement `PlatformAccountProvider` (`accountFields()`, `supportsTestSend()`) |
| Panel | Secrets are **write-only**: never returned to a page, never in props, audit entries, logs or exception messages; an empty value on edit keeps the stored one. Audit entries record the account, channel and provider, never a secret value. A test send to one address exists for email and SMS |
| Outbound HTTP | `http_sms` accepts https only (plain http only when `communication.sms.allow_insecure_http` is set), refuses IP-literal, local and private hosts and URLs with embedded credentials, and treats a response as success only when it passes the account's `success_contains` check |

### What stays

- **Environment references still work** exactly as before. An operator who prefers `.env` keeps using
  `config('communication.credentials')`; nothing was migrated. Both kinds of reference are listed together.
- **Payment gateways remain environment-only.** SSLCommerz and any future gateway read `config/platform.php`
  and are never stored in the database. This ADR does **not** relax that rule, nor the rule for Social platform
  app credentials or `APP_KEY`. AI provider accounts follow the same encrypted, write-only pattern as this ADR
  (`ai_provider_accounts`, docs/AI.md section 9); with no account, `ANTHROPIC_API_KEY` from the environment still
  works unchanged.
- A workspace still cannot read, list or learn any value; it sees names and labels. A workspace cannot create
  accounts. Workspaces' own per-workspace credentials are not introduced here.
- One provider account (a WhatsApp `phone_number_id`) is still claimable by one workspace only.
- The tenant, permissions and entitlements still never come from the request.

### Threat model

| Threat | Result |
|---|---|
| Database dump, backup or SQL-injection read of the table alone | Secrets are ciphertext (Laravel `Crypt`, AES-256 with a MAC). Not readable without `APP_KEY` |
| `APP_KEY` leaks (with or without a dump) | **All stored provider secrets are exposed.** This is the cost of the decision. `APP_KEY` already protects 2FA secrets, recovery codes, App Store settings and Social tokens, so the blast radius of that key grows rather than appears. Rotate with `APP_PREVIOUS_KEYS`, then re-enter the secrets the provider should also rotate |
| A workspace member or owner | Cannot read an account. The only path is `CredentialResolver`, called at send time inside the queue worker and the webhook handler; a page never receives a secret |
| A workspace uses another workspace's account | Refused by `tenant_ids` and by the provider-key binding. Per-workspace allow-lists are the operator's control |
| A compromised operator account | Can create an account whose endpoint they control (`http_sms`) and so receive the secrets of nothing but that account, and can replace a secret. Mitigations: `platform.settings.manage`, mandatory 2FA on the platform guard, audit entries on every change, SSRF guards on the endpoint. An operator who can edit an account can also point messages of every workspace that uses it at a different gateway; that is inherent in a shared account and is an operator privilege |
| Log, queue payload, exception, error text | A secret is never put in any of them. Queued sends carry the message id and are resolved at run time; a provider's error body is reduced to a code before storage |
| Test send abuse | Needs `platform.settings.manage` and sends to one address; it is not routed through any workspace's outbox, consent or plan limit |

## Consequences

- Operators can onboard SMTP, Twilio and an SMS gateway without a deploy. SMS marketing's "no provider adapter"
  blocker is lifted, but only to the extent of these three adapters.
- Secrets in the database are a new class of data to protect: backups must be treated as secret-bearing, and
  `APP_KEY` custody matters more. `docs/SECURITY.md` records this.
- Twilio, the generic HTTP gateway and SMTP are covered by **faked tests only**. None has been run against a live
  provider, and SMS delivery receipts (Twilio status callbacks, gateway DLR) are **not consumed**: SMS messages stay
  `sent`. The WhatsApp Cloud adapter is still unverified against live Meta.
- The generic HTTP adapter can express only gateways that accept one request per message and answer with a
  recognisable success body. A gateway that needs OAuth, a signed request body, batch submission or XML is not
  covered.
- There is no per-workspace provider account: all accounts are platform-owned. A workspace that brings its own
  SMS contract still needs the operator to create an account restricted to it with `tenant_ids`.
- Rotating a secret is a manual edit in the panel; there is no expiry, no usage report per account and no
  automatic health check.
- Superseded rule: CLAUDE.md "Never store gateway credentials in the database" applies, after this ADR, to payment
  gateways and every non-messaging credential only. Messaging provider accounts are the single exception, and
  only in the encrypted write-only form described above.

**Amendment (2026-10-14).** The owner later widened the exception: payment gateways (ADR 0028), Social and Meta Ads app
credentials (ADR 0029) and operator AI provider accounts (ADR 0018) are stored the same way, encrypted and write-only,
with the environment as the fallback. This ADR's statement that AI provider keys stay in the environment no longer
holds.
