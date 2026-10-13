# Integrations

State: **there is no integration centre**: no general OAuth connections, no outgoing webhooks, no API tokens.
The outside services the application talks to are a payment gateway (once its credentials are in the
environment), the messaging providers of the Communication module (below), the Social platforms and the AI
provider, each with its own documented adapters.

## Communication providers (Phase 8)

The `communication` module (`docs/COMMUNICATION.md`) is the first user of the rules below: provider adapters sit
behind `EmailChannel`, `SmsChannel` and `WhatsAppChannel` interfaces in a registry; a workspace connection stores
only non-secret settings and a **credential reference** (the secret stays in the environment, or is an
operator-owned provider account stored encrypted, see below); the state is `configured` or `not_configured`; webhooks are stateless, signature-verified before parsing and idempotent by
provider event id; the module's own entitlement is re-checked on every route, queued send and callback.

| Channel | Adapter | State |
|---|---|---|
| Email | `mail` (platform Laravel mailer, no delivery receipts) | Real. `not_configured` in production when the mailer is `log` / `array` |
| Email | `smtp` (operator SMTP account) | Implemented; **faked tests only, never run against a live server**. No receipts |
| SMS | `twilio` (Twilio Messages API) | Implemented; **faked tests only, never run against Twilio**. Delivery receipts not consumed |
| SMS | `http_sms` (generic one-request-per-message gateway) | Implemented; **faked tests only, never run against a live gateway**. Receipts not consumed |
| SMS, WhatsApp | `none` | Honest `not_configured` until a provider is selected |
| WhatsApp | `whatsapp_cloud` (Meta Cloud API) | Implemented; **never run against live Meta** (`docs/WHATSAPP.md`) |

**Platform provider accounts (ADR 0013).** The operator creates messaging provider accounts in the Super Admin
panel (**Messaging providers**). The secrets are stored **encrypted** in `communication_provider_accounts`
(central table), are write-only (never shown again, never in audit entries or logs) and are readable only by
`CredentialResolver` at send time. Workspaces choose an account by name. Entries in
`config('communication.credentials')` (environment) still work. ADR 0013 records this as the single exception to
"credentials only in the environment": payment gateways and Social platform credentials remain
environment-only. **AI provider accounts** (`ai_provider_accounts`, Super Admin > AI providers, `docs/AI.md`
section 9) are a second operator account store with encrypted, write-only keys. ADR 0013 named AI keys as staying in
the environment, so that store is not covered by its approval: see ADR 0018.

There is still no integration *centre* (OAuth, API tokens, outgoing webhooks).

## What exists today

| Piece | State |
|---|---|
| Outgoing mail | Laravel mailer only. Mail that exists: workspace invitation, sign-up confirmation, "workspace ready", invoice issued, payment received (all queued, each in the recipient's language) and the framework's password-reset notification (sent after the response). Local: Mailpit under Sail, or `MAIL_MAILER=log` |
| Payment gateway | `App\Core\Billing\Contracts\PaymentGateway` with two implementations, SSLCommerz and aamarPay, **neither yet run against its provider's sandbox**. Incoming notifications arrive at stateless routes on the workspace host and are verified by asking the provider (`docs/BILLING.md` section 5) |
| Object storage | `TenantStorage` works on any Laravel disk (`TENANT_FILESYSTEM_DISK`). The S3 driver package (`league/flysystem-aws-s3-v3`) is not installed |
| API | No API routes. `laravel/sanctum` is verified compatible but not installed |
| Realtime | `laravel/reverb` is verified compatible but not installed; `BROADCAST_CONNECTION=log` |
| Search | `laravel/scout` installed with the `database` driver; no searchable model. Meilisearch is the intended later engine |
| Queue | `webhooks` exists as a queue name (Horizon `interactive` supervisor); nothing dispatches to it |
| `config/services.php` | Stock Laravel file, unused |

Patterns already in the code that integrations should reuse:

- Encrypted casts for stored secrets (as for `two_factor_secret`; `ProviderAccount::$secrets` is
  `encrypted:array` and hidden).
- `AuditLogger` redacts `api_key`, `access_token`, `refresh_token`, `client_secret`, `credentials`, `token` and
  `secret` at any depth.
- `RequiresTenantContext` for queued work that must run for one tenant.
- `app('cache.central')` for platform-level data that must not land in a tenant's cache namespace.
- Module domain events and documented contracts for CRM-to-Projects creation and future Projects-to-Invoicing,
  Projects-to-HRM and Projects-to-notification hand-offs; optional modules never import each other's models.

Social platforms (Phase 9) are the first OAuth integrations: `docs/SOCIAL.md` documents the `SocialPlatform`
contract, the Facebook Pages, LinkedIn and X adapters (unverified against the live platforms), encrypted token
storage, refresh and re-authorisation, the central OAuth redirect broker (one registered URI for every workspace)
and the platforms listed as unavailable.

AI providers (Phase 11): `AiProvider` adapters for Anthropic and for any OpenAI-compatible chat-completions server,
chosen by an operator account or the environment (`docs/AI.md`); both are covered by faked HTTP tests only.

## Planned

Spec section 21 describes the Integration Centre; spec section 27 does not give it a phase of its own. Related
phases: API tokens with Sanctum (when first needed), social platforms (Phase 9), AI providers (Phase 11).
The email / SMS / WhatsApp provider adapters are built (Communication module) but unverified against live providers.

Scope from the spec: OAuth connections, encrypted credential storage, webhook registration and signature
verification, delivery logs, API access tokens with scoped abilities and expiry, provider configuration,
integration status, rate-limit monitoring, health reporting, reconnection workflows.

Rules the spec fixes:

- Every integration sits behind an interface and is replaceable.
- A module-owned integration is connectable, dispatchable and callable only while the workspace is entitled to
  that module. Every interactive route, queued delivery and incoming callback re-checks server-side entitlement.
- Adapters handle expired credentials, API failures, network errors, revoked permissions and duplicate webhooks
  (idempotency by provider event id).
- Incoming webhooks are signature-verified before anything else, then the tenant is resolved.
- If a service needs credentials or approval that do not exist, build the interface, document the missing
  configuration and show an honest "not configured" state. Unsupported API actions are never simulated.
- Fakes are allowed only in clearly separated test/dev code, bound in `testing` / `local`.
- Only official APIs and the permissions a platform has actually granted.
