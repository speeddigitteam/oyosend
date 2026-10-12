# Communication platform

State: Phase 8 complete (backend and bilingual UI; no header notification bell yet). Module key `communication`, optional app, free offer ("Included"), depends on `core`.
Spec: `MASTER_PROMPT.md` sections 12 (provider and consent rules), 19 (notification and communication centre)
and 21 (integration centre, where relevant). Code: `app/Modules/Communication`, contracts in
`app/Core/Communication`, config in `config/communication.php`.

What it is: a per-member **notification center**, a **message outbox** with channel/provider adapters,
**templates**, **consent and suppression**, and a shared **interaction timeline** for contacts and companies.

What it is not (yet): there is **no SMS and no WhatsApp provider adapter**. Both channels ship with an honest
"no provider" adapter that reports `not_configured` and refuses every send. Email works through the platform's
Laravel mailer. Campaigns/bulk sending, inbound email parsing, social platforms (Phase 9) and the visual
automation builder (Phase 10) do not exist.

## 1. For other modules: two contracts, no model imports

Both live under `App\Core\Communication` and are always bound (`CoreServiceProvider` binds honest null objects;
this module rebinds them). They are no-ops that return `0` / `false` when the workspace is not entitled to
`communication`, so a caller must never depend on a record having been written. Call them inside the active
tenant context.

```php
use App\Core\Communication\Contracts\{Notifier, InteractionRecorder};
use App\Core\Communication\Data\{NotificationMessage, InteractionEntry};

app(Notifier::class)->notify(
    [$membership->id],                                   // tenant memberships, never User ids
    new NotificationMessage(
        category: 'approvals',                           // preference key
        titleKey: 'Leave request from :name',            // English translation key; Bengali in lang/bn.json
        params: ['name' => $employee->name],
        linkRoute: 'hrm.leave.requests.index',           // workspace route name, dropped if it does not exist
        dedupeKey: "leave:{$request->id}",               // same key + member = one notification
        sourceModule: 'hrm',
    ),
);

app(InteractionRecorder::class)->record(new InteractionEntry(
    type: 'email', summary: 'Invoice INV-0042 sent', contactId: $invoice->contact_id,
    direction: 'outbound', sourceModule: 'invoicing', dedupeKey: "invoice:{$invoice->id}:sent",
));
```

Rules: titles and bodies are translation **keys** plus scalar `params`, rendered at read time in the viewer's
locale; a `critical` severity cannot be muted by a member; email delivery of a notification goes through the
outbox (so it is logged, rate limited and retried). Existing modules have **not** been rewired to call these in
Phase 8; doing so is a follow-up per module.

## 2. Notifications

| Piece | Behaviour |
|---|---|
| Storage | `communication_notifications`, one row per member and notification, `read_at`, `archived_at` |
| Preferences | `communication_notification_preferences`: per member, channel (`in_app`, `email`) and category. No row = `config('communication.category_defaults')` (in-app on, email off) |
| Unread count | Dedicated JSON endpoint `GET /communication/notifications/summary` (count plus latest 8 unread). It is **not** a shared Inertia prop: that would add a query to every page of every workspace |
| Dashboard | Widget `communication.notifications` (component `NotificationsWidget`): `{unread, items[5]}` |
| Access | `communication.notifications.view`, not scopable. Every query is narrowed to the signed-in membership; another member's notification is a 404 |

## 3. Channels, providers and credentials

```
Outbox ──► SendMessage job ──► MessageDispatcher ──► ChannelProvider adapter (EmailChannel | SmsChannel | WhatsAppChannel)
```

- `ChannelProvider` (`Channels/Contracts`) is the adapter contract: `key()`, `channel()`, `fields()` (the
  non-secret settings form), `state()`, `send()`, `parseWebhook()`. `EmailChannel`, `SmsChannel` and
  `WhatsAppChannel` are the typed interfaces; `ProviderRegistry` refuses an adapter that does not implement the
  interface of its channel.
- Shipped adapters: `mail` (`LaravelMailProvider`, real), `none` for SMS and WhatsApp (`NullProvider`, always
  `not_configured`). `fake_sms` / `fake_whatsapp` (`Testing/InMemoryProvider`) are registered **only** when the
  environment is `testing` or `local`, and are flagged `isTestDouble` so no screen presents them as real.
- UI-facing state is only `configured` or `not_configured`. Email is `not_configured` in production when the
  mailer is `log` or `array`.
- **Credentials are never stored in the database.** `communication_channel_connections` holds the provider key,
  non-secret `settings` validated against the adapter's `fields()`, a `credential_ref` and a
  `webhook_secret_ref`. A reference names an entry of `config('communication.credentials')`:

  ```php
  'sms_gateway_main' => ['secret' => env('COMMUNICATION_SMS_MAIN_SECRET'), 'tenants' => null],     // every workspace
  'sms_acme'         => ['secret' => env('COMMUNICATION_SMS_ACME_SECRET'), 'tenants' => ['acme']], // one workspace
  ```

  A reference that is missing, empty or not allowed for the workspace reads as `not_configured`, and the
  settings screen only lists names the workspace may use. The secret exists in memory as `ChannelCredentials`,
  which cannot be serialized, dumped or cast to a string.
- Sender identity: a per-workspace **from name** and **reply-to**; the From *address* is the platform's
  (`MAIL_FROM_ADDRESS`). A custom sender domain needs SPF, DKIM and DMARC records for the domain and is set up
  by the operator; the settings screen shows guidance text and `customDomainVerified: false`. No DNS check is
  made, so nothing claims a domain is verified.

### Adding a real provider

1. Implement `SmsChannel` (or `WhatsAppChannel`/`EmailChannel`) in a first-party class. Use the secret only
   inside `send()`; never log it or put it in an exception message; answer with `SendResult::accepted()`,
   `::retry()` (temporary) or `::rejected()` (permanent), do not throw for provider errors.
2. If the provider calls back, implement `parseWebhook()`: verify the signature (`HmacSignature` for the common
   case) over the **raw body** before parsing, throw `InvalidWebhookSignature` otherwise, and return
   `ProviderEvent`s with the provider's own event id.
3. Register it in `CommunicationServiceProvider::register()` on the `ProviderRegistry` singleton.
4. Add the operator's credential entry to `config/communication.php` / `.env`. Document the provider's required
   approvals (sender id registration, WhatsApp Business template approval) in this file.
5. Test with `Testing/InMemoryProvider` as the model; never ship a simulated provider outside `testing`/`local`.

## 4. The outbox

`Outbox::queue(OutboundRequest)` is the only way in. In order:

1. **Idempotency**: `UNIQUE(tenant_id, idempotency_key)`. The same key returns the original row untouched.
2. Address normalization and validation per channel.
3. **Suppression** and **marketing consent** (section 5). A blocked message is still recorded, with status
   `suppressed` and `failure_code` `suppressed` or `no_consent`, so the log explains what did not happen.
4. **Plan limit** `communication.messages` (metered per billing period). A message over the limit is recorded as
   `failed` / `limit_reached`; interactive callers ask `assertWithinLimit()` first and show a form error.
5. Row `queued`, timeline entry for contact/company messages, `SendMessage` job dispatched after commit (queue
   `notifications`), delayed until `next_attempt_at`.

`MessageDispatcher` (inside the job) re-checks at worker time: still queued and due, workspace still entitled
(else `cancelled` / `module_not_entitled`), suppression and consent again, quiet hours, channel enabled and
configured, per-channel rate limit. Then it marks `sending`, calls the adapter and settles:

| Provider answer | Result |
|---|---|
| accepted | `sent`, `provider_message_id`, usage metered once with key `message:{id}`, `MessageSent` |
| retry | back to `queued` with backoff `config('communication.backoff_minutes')` (1, 5, 15, 60 min) up to `max_attempts` (5), then `failed` |
| rejected | `failed` with the provider's code; `invalid_recipient` also suppresses the address |

Statuses: `queued`, `sending`, `sent`, `delivered`, `failed`, `bounced`, `suppressed`, `cancelled`.
`delivered` and `bounced` only come from a provider webhook; the mail adapter has no receipts, so its messages
stay `sent`. A worker that dies mid-send leaves `sending`; `DispatchDueMessages` (every minute) returns messages
stuck longer than `sending_timeout_minutes` to the queue, so delivery is at-least-once across a crash and
exactly-once otherwise.

**Rate limit** per workspace and channel: `config('communication.rate_limits')` is the platform ceiling, a
workspace may lower it on its connection. A message over the rate is postponed, not failed.

**Quiet hours** (workspace timezone, may cross midnight) postpone `marketing` messages to the end of the window,
at queue time and again at worker time. Transactional messages ignore them.

## 5. Consent and suppression

- `communication_consents`: the current decision per contact, channel and purpose (`opted_in`/`opted_out`), with
  `method` and `evidence`; history is the audit trail. **No record means no consent.** An opt-in requires
  evidence text.
- `communication_suppressions`: unique `(tenant_id, channel, address, scope)`. Scope `all` (bounce, complaint,
  invalid, manual) blocks every message; scope `marketing` (unsubscribe) blocks promotional messages only.
  Complaints cannot be removed by hand; a new explicit opt-in lifts the contact's own unsubscribe.
- **Unsubscribe**: marketing messages carry a link and `List-Unsubscribe` / `List-Unsubscribe-Post` headers.
  The token is 40 random characters that exist only in the outgoing message; the table stores its SHA-256 and an
  expiry (365 days). `GET` shows a confirmation page and changes nothing (scanners cannot unsubscribe anyone),
  `POST` opts the contact out and suppresses the address. Every invalid, expired or foreign token answers the same.

## 6. Provider webhooks and the unsubscribe page

Stateless routes on the workspace host through `ModuleServiceProvider::loadTenantCallbackRoutes()`: group
`tenant.callback` (tenant from the hostname, throttle, no session, cookies, CSRF or Inertia) plus
`entitled:communication`.

`POST /communication/webhooks/{provider}`: the provider must be one the workspace connected and enabled; its
`webhook_secret_ref` must resolve for the workspace (otherwise 404, nothing disclosed); the body is capped at
`webhook_max_bytes` (413); the signature is verified over the raw body **before** parsing (401, nothing changed);
then each event is applied once per `(provider, event id)` (`communication_webhook_events`, no payload kept).
Events: `delivered`, `failed`, `bounced` (suppresses), `complained` (suppresses and opts out), `inbound` (a
timeline entry on the contact whose phone/email matches).

## 7. Templates

Per channel and locale (`en`, `bn`), `slug` + channel + locale unique per workspace, `purpose` transactional or
marketing. Locale fallback: requested, workspace locale, `en`. Placeholders are `{{ group.name }}` from a closed
allow-list (`TemplateVariables`: `contact.first_name|last_name|full_name|email|phone`, `company.name`,
`workspace.name`, `sender.name`); another module may add namespaced variables from its provider's `boot`.

Safety: this is **substitution, not a template engine**. One regex pass, no expressions, filters, includes,
Blade or PHP; unknown placeholders are refused when saving; a value that looks like a placeholder is not
expanded again; control characters are stripped and values capped at 500 characters. Email bodies render through
a fixed Blade view that escapes the text. A subject is collapsed to one line (header injection).

## 8. Interaction timeline

`communication_interactions`: type `note|call|meeting|email|sms|whatsapp|system`, direction, summary, body,
`occurred_at`, optional source (module/type/id) and `dedupe_key` (`UNIQUE(tenant_id, dedupe_key)`).

- Visible to whoever holds `communication.timeline.view` **and** may see the contact under their
  `core.contacts.view` scope (an out-of-scope contact is a 404). A company timeline shows the company's own
  entries and those of its contacts the viewer may see.
- `communication.timeline.log` (scopable) logs notes, calls, meetings and emails by hand and removes only
  hand-logged entries within its scope. Module and system entries are append-only.
- Messages sent through the outbox add their own entry (linked by `message_id`, status read live).
- Contact merges move timeline entries, messages and suppressions; consent keeps the stricter answer.

## 9. Permissions, navigation, limits, schedules

| Permission | Scopable | Sensitive | Used by |
|---|---|---|---|
| `communication.notifications.view` | | | own notifications and preferences |
| `communication.messages.view` | yes | | message log and detail |
| `communication.messages.send` | yes | | composer, retry, cancel |
| `communication.timeline.view` | | | contact/company timelines |
| `communication.timeline.log` | yes | | log or remove a manual entry |
| `communication.templates.view` / `.manage` | | | templates |
| `communication.audience.view` | | | consent and suppression lists |
| `communication.consent.manage` | | yes | record opt-in/out by hand |
| `communication.suppressions.manage` | | yes | add/remove suppressions |
| `communication.settings.manage` | | yes | sender identity, quiet hours, channel connections |

New permissions are granted to no role. Until an owner grants `communication.notifications.view` to a role,
only the owner sees a notification center.

Limit `communication.messages` (metered). Scheduled jobs (entitlement-gated runner): `communication.dispatch_due`
(every minute) and `communication.cleanup` (daily: expired tokens, old webhook event ids).

## 10. Automation

Triggers: `communication.message.delivered`, `.failed`, `.bounced`, `communication.contact.unsubscribed`.
Actions: `communication.message.send` (a **transactional** template to a contact; a marketing template is
refused as `template_not_transactional`; every refusal is a stored, replayable output, not an exception) and
`communication.interaction.log`. Both use `communication_automation_action_receipts`. Nothing executes workflows
until Phase 10.

## 11. Events

`communication.message.sent|delivered|failed|bounced`, `communication.contact.unsubscribed`,
`communication.interaction.logged`. Ids and scalars only, dispatched after commit.

## 12. Known limits and owner decisions

- No SMS/WhatsApp provider, no bulk campaigns, no inbound email, no per-member notification digest.
- Message bodies are stored for the log and the timeline; there is no retention policy yet.
- Provider webhook delivery receipts are only as good as the adapter; the mail adapter has none.
- Whether `communication.notifications.view` should join the default Employee/Manager role grants
  (`DefaultRoles`) is an owner decision; existing workspaces are not changed by a release either way.
