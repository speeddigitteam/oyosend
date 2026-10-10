# Integrations

State: end of Phase 2. **There is no integration centre**: no OAuth connections, no provider adapters for
messaging, no outgoing webhooks, no API tokens, no tables for any of it. The one outside service the application
can talk to is a payment gateway, and only once its credentials are configured.

## What exists today

| Piece | State |
|---|---|
| Outgoing mail | Laravel mailer only. Mail that exists: workspace invitation, sign-up confirmation, "workspace ready", invoice issued, payment received (all queued, each in the recipient's language) and the framework's password-reset notification (sent after the response). Local: Mailpit under Sail, or `MAIL_MAILER=log` |
| Payment gateway | `App\Core\Billing\Contracts\PaymentGateway` with one implementation, SSLCommerz, **not yet run against the provider's sandbox**. Incoming notifications arrive at stateless routes on the workspace host and are verified by asking the provider (`docs/BILLING.md` section 5) |
| Object storage | `TenantStorage` works on any Laravel disk (`TENANT_FILESYSTEM_DISK`). The S3 driver package (`league/flysystem-aws-s3-v3`) is not installed |
| API | No API routes. `laravel/sanctum` is verified compatible but not installed |
| Realtime | `laravel/reverb` is verified compatible but not installed; `BROADCAST_CONNECTION=log` |
| Search | `laravel/scout` installed with the `database` driver; no searchable model. Meilisearch is the intended later engine |
| Queue | `webhooks` exists as a queue name (Horizon `interactive` supervisor); nothing dispatches to it |
| `config/services.php` | Stock Laravel file, unused |

Patterns already in the code that integrations should reuse:

- Encrypted casts for stored secrets (as for `two_factor_secret`).
- `AuditLogger` redacts `api_key`, `access_token`, `refresh_token`, `client_secret`, `credentials`, `token` and
  `secret` at any depth.
- `RequiresTenantContext` for queued work that must run for one tenant.
- `app('cache.central')` for platform-level data that must not land in a tenant's cache namespace.

## Planned

Spec section 21 describes the Integration Centre; spec section 27 does not give it a phase of its own. Related
phases: API tokens with Sanctum (when first needed), email / SMS / WhatsApp providers
(Phase 8), social platforms (Phase 9), AI providers (Phase 11).

Scope from the spec: OAuth connections, encrypted credential storage, webhook registration and signature
verification, delivery logs, API access tokens with scoped abilities and expiry, provider configuration,
integration status, rate-limit monitoring, health reporting, reconnection workflows.

Rules the spec fixes:

- Every integration sits behind an interface and is replaceable.
- Adapters handle expired credentials, API failures, network errors, revoked permissions and duplicate webhooks
  (idempotency by provider event id).
- Incoming webhooks are signature-verified before anything else, then the tenant is resolved.
- If a service needs credentials or approval that do not exist, build the interface, document the missing
  configuration and show an honest "not configured" state. Unsupported API actions are never simulated.
- Fakes are allowed only in clearly separated test/dev code, bound in `testing` / `local`.
- Only official APIs and the permissions a platform has actually granted.
