# Communication platform

State: Phase 8 complete (backend and bilingual UI; no header notification bell yet). Module key `communication`, optional app, free offer ("Included"), depends on `core`.
Spec: `MASTER_PROMPT.md` sections 12 (provider and consent rules), 19 (notification and communication centre)
and 21 (integration centre, where relevant). Code: `app/Modules/Communication`, contracts in
`app/Core/Communication`, config in `config/communication.php`.

What it is: a per-member **notification center**, a **message outbox** with channel/provider adapters,
**templates**, **consent and suppression**, and a shared **interaction timeline** for contacts and companies.

What it is not (yet): inbound email parsing and SMS delivery receipts do not exist. Email works through the
platform's Laravel mailer or an operator's SMTP account; SMS through an operator's Twilio or generic HTTP gateway
account (ADR 0013, section 3). Until an operator creates a provider account (or an environment credential entry)
and a workspace selects it, SMS and WhatsApp report `not_configured` and refuse every send. **Twilio, the generic
HTTP SMS gateway and SMTP have only been exercised by faked tests, never against a live provider.**

WhatsApp has one real adapter, **`whatsapp_cloud`** (the official WhatsApp Business Platform, Cloud API), used by
the WhatsApp Business app (`docs/WHATSAPP.md`). It reports `not_configured` until an operator provides credentials
and a workspace saves the connection, and it has **not been exercised against live Meta** (HTTP-faked tests only).

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
- Shipped adapters: `mail` (`LaravelMailProvider`, the platform mailer), `smtp` (`SmtpProvider`, an operator's
  SMTP server, email), `twilio` (`TwilioSmsProvider`, SMS), `http_sms` (`HttpSmsProvider`, SMS: a generic gateway
  that takes one HTTP request per message), `whatsapp_cloud`, and `none` for SMS and WhatsApp (`NullProvider`,
  always `not_configured`). `smtp`, `twilio`, `http_sms` and `whatsapp_cloud` take a platform provider account
  (`PlatformAccountProvider`: `accountFields()`, `supportsTestSend()`). `fake_sms` / `fake_whatsapp`
  (`Testing/InMemoryProvider`) are registered **only** when the environment is `testing` or `local`, and are
  flagged `isTestDouble` so no screen presents them as real.
- `smtp`: host, port, encryption (`starttls`/`ssl`), username, password (secret), from address and name; sends
  through a mailer built for that account, plain text as the platform mailer does. `twilio`: Account SID,
  auth token (secret), a sender number or Messaging Service SID; sends through the REST Messages API.
  `http_sms`: endpoint URL (https only unless `communication.sms.allow_insecure_http`; no IP literal, local or
  private host, no embedded credentials), method, how parameters and the key are sent, the number / message /
  sender parameter names and an optional `success_contains` text the response must contain. None of the three
  consumes delivery receipts: their messages stay `sent`.
- `whatsapp_cloud` (`WhatsAppCloudProvider`, WhatsApp channel): sends template and free-form text messages
  through the Graph API with a bearer token from `credential_ref`; non-secret settings `phone_number_id`
  (required), `business_account_id`, `graph_api_version`; webhook secret from `webhook_secret_ref` (the Meta app
  secret, which may also carry a `verify_token` for the subscription handshake). It reads the hints
  `whatsapp_template`, `whatsapp_language`, `whatsapp_params` (JSON list) and `whatsapp_window_until` from the
  message meta. Operator credentials: `config/communication.php` entries as documented in `docs/WHATSAPP.md`.
- UI-facing state is only `configured` or `not_configured`. Email is `not_configured` in production when the
  mailer is `log` or `array`.
- **A workspace connection never holds a secret.** `communication_channel_connections` holds the provider key,
  non-secret `settings` validated against the adapter's `fields()`, a `credential_ref` and a
  `webhook_secret_ref`. A reference names either a **platform provider account** (`acct_{id}`, see below) or an
  entry of `config('communication.credentials')`:

  ```php
  'sms_gateway_main' => ['secret' => env('COMMUNICATION_SMS_MAIN_SECRET'), 'tenants' => null],     // every workspace
  'sms_acme'         => ['secret' => env('COMMUNICATION_SMS_ACME_SECRET'), 'tenants' => ['acme']], // one workspace
  ```

  A reference that is missing, empty or not allowed for the workspace reads as `not_configured`, and the
  settings screen only lists names the workspace may use. The secret exists in memory as `ChannelCredentials`,
  which cannot be serialized, dumped or cast to a string.
- **Platform provider accounts** (ADR 0013). The operator adds an account in the Super Admin panel
  (**Messaging providers**, `platform.settings.manage`): channel, provider, a name, the adapter's non-secret
  settings, its secrets, an enabled flag and which workspaces may use it (`tenant_ids`; empty = all). Secrets are
  stored **encrypted** in `communication_provider_accounts` (central, no `tenant_id`), are write-only (never
  returned to a page; an empty value on edit keeps the stored one) and never reach logs, audit entries or
  exception messages. `CredentialResolver` resolves `acct_*` references next to the environment entries; an
  account answers only to the provider key it was created for, and only for a workspace in `tenant_ids`. Secret
  keys: `secret` (API credential / password), `webhook_secret`, `verify_token`. Email and SMS accounts have a
  test send to one address. Environment references keep working unchanged. Payment gateways stay
  environment-only. A workspace picks the account by name in the credential dropdown of **Settings > Channels**;
  it cannot create, read or see the value of one.
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
4. If the adapter takes an operator account, implement `PlatformAccountProvider` (`accountFields()` marks the
   secret fields with type `secret`); otherwise add the operator's credential entry to `config/communication.php`
   / `.env`. Document the provider's required approvals (sender id registration, WhatsApp Business template approval) in this file.
5. Test with `Testing/InMemoryProvider` as the model; never ship a simulated provider outside `testing`/`local`.

**One provider account, one workspace** (ADR 0016). A provider field marked `account` (the WhatsApp `phone_number_id`) can be
connected by one workspace only. A shared credential reference (`tenants: null`) is usable by every workspace its
operator allows, so without this a workspace could name another workspace's number and send from it.
`ChannelConnections::save()` claims the value in `communication_account_claims` (platform-wide, no `tenant_id`,
unique `(provider, account_key, account_value)`, owned by the connection and deleted with it); a second workspace
gets the form error "This account is already connected to another workspace" and nothing is saved. The webhook
route already drops events whose account is not the connection's own. Operators should still scope tokens with
`tenants` allow-lists where they can: that is the other half of the control.

## 4. The outbox

`Outbox::queue(OutboundRequest)` is the only way in. In order:

1. **Idempotency**: `UNIQUE(tenant_id, idempotency_key)`. The same key returns the original row untouched.
2. Address normalization and validation per channel.
3. **Suppression** and **marketing consent** (section 5). A blocked message is still recorded, with status
   `suppressed` and `failure_code` `suppressed` or `no_consent`, so the log explains what did not happen.
4. **Plan limit** `communication.messages` (metered per billing period, spent when the provider accepts a
   message). Queued and sending messages are counted as pending use, so a campaign cannot queue thousands more
   than the plan has left. A message over the limit is recorded as `failed` / `limit_reached`; interactive
   callers ask `assertWithinLimit()` first and show a form error.
5. Row `queued`, timeline entry for contact/company messages, `SendMessage` job dispatched after commit (queue
   `notifications`), delayed until `next_attempt_at`.

`MessageDispatcher` (inside the job) re-checks at worker time: still queued and due, workspace still entitled
(else `cancelled` / `module_not_entitled`), suppression and consent again, quiet hours, the plan limit again
(`failed` / `limit_reached`, counting messages currently being sent), channel enabled and configured,
per-channel rate limit. Then it marks `sending`, calls the adapter and settles:

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
- **Phone numbers have one canonical form**, `Core\Communication\Support\PhoneNumber::normalize()` (used by
  `Channel::normalize()` for SMS and WhatsApp, the gateway, suppression, consent, conversation matching and the
  inbound contact lookup): E.164 with a plus. A Bangladesh mobile number in any usual spelling (`01712345678`,
  `1712345678`, `8801712345678`, `+8801712345678`, `008801712345678`, with spaces or dashes, Bengali digits) is
  `+8801712345678`; a number of another country is accepted only when it already carries its country code (`+` or
  `00`); anything else is refused, never guessed. Contacts saved in a national spelling are matched through
  `PhoneNumber::digitVariants()`. Nothing is deployed with the older keys, so no data migration exists; a
  workspace that already holds suppression rows in another spelling would need them re-keyed.
- `communication_suppressions`: unique `(tenant_id, channel, address, scope)`. Scope `all` (bounce, complaint,
  invalid, manual) blocks every message; scope `marketing` (unsubscribe) blocks promotional messages only.
  Complaints cannot be removed by hand; a new explicit opt-in lifts the contact's own unsubscribe.
- **Unsubscribe**: marketing messages carry a link and `List-Unsubscribe` / `List-Unsubscribe-Post` headers.
  The token is 40 random characters that exist only in the outgoing message; the table stores its SHA-256 and an
  expiry (365 days). `GET` shows a confirmation page and changes nothing (scanners cannot unsubscribe anyone),
  `POST` opts the contact out and suppresses the address. Every invalid, expired or foreign token answers the same.
- **Opt-out from a reply**: `Core\Communication\Contracts\ConsentRecorder::recordOptOut(contactId, channel,
  method, evidenceNote)` (method `keyword` or `button`) records the withdrawal and the marketing suppression of
  the address in one transaction, at once, idempotently. WhatsApp uses it for STOP (see `docs/WHATSAPP.md`).
  Without the app or entitlement it returns `false`.
- **Opt-in from a confirmed form**: `ConsentRecorder::recordOptIn(contactId, channel, method, evidence)` (method
  `form` or `double_opt_in`, stored as `form`; evidence 1 to 500 characters, no IP). One transaction, audited,
  entitlement-checked. It returns `false` and changes nothing when the contact's address has any marketing or
  all-scope suppression (an unsubscribe, bounce, complaint or manual block stays until the platform lifts it) or
  the contact's opt-out was a complaint; a contact's own plain `opted_out` record with no suppression is replaced;
  an already opted-in contact is left as is (`true`). Email Marketing's confirmed sign-up uses it.

## 6. Provider webhooks and the unsubscribe page

Stateless routes on the workspace host through `ModuleServiceProvider::loadTenantCallbackRoutes()`: group
`tenant.callback` (tenant from the hostname, throttle, no session, cookies, CSRF or Inertia) plus
`entitled:communication`.

`POST /communication/webhooks/{provider}`: the provider must be one the workspace connected and enabled; its
`webhook_secret_ref` must resolve for the workspace (otherwise 404, nothing disclosed); the body is capped at
`webhook_max_bytes` (413); the signature is verified over the raw body **before** parsing (401, nothing changed);
then each event is applied once per `(provider, event id)` (`communication_webhook_events`, no payload kept).
The dedupe row, the applied change and the hand-over to apps commit in ONE transaction per event: if applying it
or an app's inbound-message listener fails, the row rolls back, the route answers `503` with `Retry-After`, and
the provider's redelivery is applied afresh (a bounce or an inbound message is never "seen" and lost). A failing
listener for receipts or template status is logged by class and contained: a retry loop on those would only
block delivery tracking.
Events: `delivered`, `failed`, `bounced` (suppresses), `complained` (suppresses and opts out), `inbound` (a
timeline entry on the contact whose phone/email matches).

`GET /communication/webhooks/{provider}` (`communication.webhooks.verify`) is the subscription handshake for a
provider that confirms its callback URL (adapters implementing `VerifiesWebhookSubscription`; the WhatsApp Cloud API
`hub.challenge`). It answers only for a connected, enabled connection whose `webhook_secret_ref` entry has a
`verify_token`. An event may carry `accounts` (`phone_number_id`, `business_account_id`): the route drops an event
whose account is not the connection's own setting. `ProviderEventType` also has `read` and `template_status`; they
change no outbox message, they are forwarded to channel-event listeners (section 14).

The **unsubscribe routes are not entitlement-gated** (`callbacks-unsubscribe.php`: `tenant.callback` only). A
person who received a message can always opt out, also after the workspace's Communication app lapsed; the page
still resolves the workspace from the hostname, answers identically for every unknown, used or expired token and
shows nothing but the workspace name. The webhook routes keep `entitled:communication`. The email tracking
routes of the Email Marketing app are gated by that app and answer 404 after it lapses: opens and clicks stop
being recorded and links in already-sent mail stop redirecting; whether to keep click redirects alive for a
lapsed workspace is an owner decision.

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
| `communication.messages.send_transactional` | | yes | send hand-written text as transactional (skips consent, marketing suppression, quiet hours, footer) |
| `communication.timeline.view` | | | contact/company timelines |
| `communication.timeline.log` | yes | | log or remove a manual entry |
| `communication.templates.view` / `.manage` | | | templates |
| `communication.audience.view` | | | consent and suppression lists |
| `communication.consent.manage` | | yes | record opt-in/out by hand |
| `communication.suppressions.manage` | | yes | add/remove suppressions |
| `communication.settings.manage` | | yes | sender identity, quiet hours, channel connections |

The composer derives the purpose on the server: a template carries its own (templates are curated by
`templates.manage`), and free text is `marketing` unless the sender holds `communication.messages.send_transactional`
and asked for `transactional`; asking without it is refused. The composer's idempotency key is
`composer:{membership}:{request_id}:{hash of contact, channel, purpose, template, subject, body}`, so a reused
request id with other content is a different message and never answers with the first.

New permissions are granted to no role. Until an owner grants `communication.notifications.view` to a role,
only the owner sees a notification center.

Limit `communication.messages` (metered). Scheduled jobs (entitlement-gated runner): `communication.dispatch_due`
(every minute) and `communication.cleanup` (daily: expired tokens, old webhook event ids).

## 10. Automation

Triggers: `communication.message.delivered`, `.failed`, `.bounced`, `communication.contact.unsubscribed`.
Actions: `communication.message.send` (a **transactional** template to a contact; a marketing template is
refused as `template_not_transactional`; every refusal is a stored, replayable output, not an exception) and
`communication.interaction.log`. Both use `communication_automation_action_receipts`. The Workflow Automation app
(`docs/WORKFLOWS.md`) executes them like any registered action: as the member who published the workflow, after
re-checking `communication.messages.send` for all records and the entitlement.

## 11. Events

`communication.message.sent|delivered|failed|bounced`, `communication.contact.unsubscribed`,
`communication.interaction.logged`. Ids and scalars only, dispatched after commit.

## 12. Known limits and owner decisions

- Provider accounts are platform-owned: a workspace cannot bring its own SMS/SMTP account, only choose one the
  operator made available to it. Twilio, `http_sms` and SMTP are unverified against live providers. No inbound
  email, no per-member notification digest.
- Message bodies are stored for the log and the timeline; there is no retention policy yet.
- Provider webhook delivery receipts are only as good as the adapter; the mail, SMTP, Twilio and HTTP SMS
  adapters have none (SMS delivery receipts are not consumed), so their messages stay `sent`.
- Whether `communication.notifications.view` should join the default Employee/Manager role grants
  (`DefaultRoles`) is an owner decision; existing workspaces are not changed by a release either way.

## 13. Routes, pages and props

All workspace routes sit under `/communication`, carry `auth:web`, `auth.session`, `member`, `subscribed`,
`entitled:communication` and the `can:` shown. Page names are paths under `resources/js`. Money is not used.
Validation failures come back as Inertia form errors; feedback is a `Toast`.

| Route name | Verb and URL | Permission | Fields |
|---|---|---|---|
| `communication.notifications.index` | GET `notifications` | `notifications.view` | query `filter` (all/unread/archived), `category` |
| `communication.notifications.summary` | GET `notifications/summary` | `notifications.view` | JSON `{unread, items[<=8]}` |
| `communication.notifications.read-all` | POST `notifications/read-all` | `notifications.view` | none |
| `communication.notifications.read` / `.unread` / `.archive` | POST `notifications/{notification}/{read\|unread\|archive}` | `notifications.view` | none (own notification only, else 404) |
| `communication.preferences.index` / `.update` | GET / PUT `preferences` | `notifications.view` | `preferences[]`: `category`, `in_app`, `email` (bool) |
| `communication.messages.index` | GET `messages` | `messages.view` | query `search`, `status`, `channel`, `source`, `contact` |
| `communication.messages.create` | GET `messages/compose` | `messages.send` | query `contact` (ULID, preselect) |
| `communication.messages.store` | POST `messages` (throttle 30/min) | `messages.send` | `contact_id`, `channel`, `purpose?` (advisory; `transactional` only with `send_transactional`), `template_id?`, `subject?` (required for email without template), `body?` (required without template, max 5000), `request_id?` (idempotency) |
| `communication.messages.show` | GET `messages/{message}` | `messages.view` | none |
| `communication.messages.retry` / `.cancel` | POST `messages/{message}/{retry\|cancel}` | `messages.send` | none |
| `communication.timeline.contact.show` / `.company.show` | GET `contacts/{contact}/timeline`, `companies/{company}/timeline` | `timeline.view` | query `type` |
| `communication.timeline.contact.store` / `.company.store` | POST same URLs | `timeline.log` | `type` (note/call/meeting/email), `direction?`, `summary`, `body?`, `occurred_at?` |
| `communication.timeline.destroy` | DELETE `timeline/{interaction}` | `timeline.log` | none |
| `communication.templates.index` | GET `templates` | `templates.view` | none |
| `communication.templates.preview` | POST `templates/preview` | `templates.manage` | `subject?`, `body`; JSON `{subject, body, unknown[]}` |
| `communication.templates.store` / `.update` / `.destroy` | POST `templates`, PUT/DELETE `templates/{template}` | `templates.manage` | `slug`, `name`, `channel`, `locale`, `purpose`, `subject?`, `body`, `is_active` |
| `communication.audience.index` | GET `audience` | `audience.view` | query `search`, `status`, `channel`, `consents_page`, `suppressions_page` |
| `communication.consent.store` | POST `contacts/{contact}/consent` | `consent.manage` | `channel`, `status` (opted_in/opted_out), `method`, `evidence` (required when opted_in) |
| `communication.suppressions.store` / `.destroy` | POST `suppressions`, DELETE `suppressions/{suppression}` | `suppressions.manage` | `channel`, `address`, `scope` (all/marketing), `note?` |
| `communication.settings.index` / `.update` | GET / PUT `settings` | `settings.manage` | `from_name?`, `reply_to_email?`, `quiet_hours_enabled`, `quiet_hours_start`, `quiet_hours_end` (HH:MM), `footer_text?` |
| `communication.settings.channels.update` | PUT `settings/channels/{channel}` (`email`/`sms`/`whatsapp`) | `settings.manage` | `provider`, `is_enabled`, `settings{}`, `credential_ref?`, `webhook_secret_ref?`, `rate_limit_per_minute?` |
| `communication.webhooks.receive` | POST `communication/webhooks/{provider}` (callback group) | signature | raw body, provider headers |
| `communication.unsubscribe.show` / `.store` | GET / POST `communication/unsubscribe/{token}` (`tenant.callback`, not entitlement-gated, Blade view `communication.unsubscribe`, not Inertia) | token | none |

Pages (`Modules/Communication/Pages/...`), props exactly as the controllers send them:

| Page | Props |
|---|---|
| `Notifications/Index` | `notifications` (paginator of Notification), `filters {filter, category}`, `unreadCount`, `categories: string[]` |
| `Notifications/Preferences` | `preferences: {category, in_app, email}[]`, `emailAvailable: boolean` |
| `Messages/Index` | `messages` (paginator of Message), `filters {search, status, channel, source, contact}`, `statuses: string[]`, `channels: {channel, state}[]`, `canSend` |
| `Messages/Show` | `message`: Message plus `body`, `locale`, `provider`, `failureMessage`, `templateId`, `owner {id, name}|null` |
| `Messages/Compose` | `contacts: {id, name, email, phone}[]`, `channels: {channel, state}[]`, `purposes: string[]` (what free text may be: `['marketing']`, plus `transactional` with the sensitive permission), `canChoosePurpose: boolean`, `templates: {id, name, channel, locale, purpose, subject}[]`, `contactId: string|null` |
| `Timeline/Show` | `subject {type: contact|company, id, name, email, phone, ...}`, `entries` (paginator of TimelineEntry), `filters {type}`, `types: string[]` (manual types), `canLog`, `canCompose` |
| `Templates/Index` | `templates: Template[]`, `variables: {key, label}[]`, `channels`, `locales`, `purposes`, `canManage` |
| `Audience/Index` | `consents` (paginator, page name `consents_page`), `suppressions` (paginator, `suppressions_page`), `filters {search, status, channel}`, `contacts: {id,name,email,phone}[]` (empty without consent permission), `methods`, `channels`, `scopes`, `canManageConsent`, `canManageSuppressions` |
| `Settings/Index` | `settings {fromName, replyToEmail, quietHoursEnabled, quietHoursStart, quietHoursEnd, footerText}`, `channels: Channel[]`, `credentialRefs: string[]` (names only), `senderGuidance {platformSender, customDomainVerified: false}` |

Shapes. **Notification**: `id, category, severity, title, body|null, titleKey, bodyKey|null, params, url|null, read, archived, createdAt`.
**Message**: `id, channel, purpose, status, recipient, subject, source, contact {id,name}|null, company {id,name}|null, attempts, failureCode, queuedAt, nextAttemptAt, sentAt, deliveredAt, failedAt, createdAt, retryable, cancellable`.
**TimelineEntry**: `id, type, direction, summary, body, occurredAt, sourceModule, sourceType, sourceId, contactId, messageId, messageStatus, owner {id,name}|null, deletable`.
**Template**: `id, slug, name, channel, locale, purpose, subject, body, isActive`.
**Consent**: `id, contact {id,name}|null, channel, purpose, status, method, evidence, recordedAt`.
**Suppression**: `id, channel, address, scope, reason, note, contactId, removable, createdAt`.
**Channel**: `channel, provider, providerLabel, isTestDouble, enabled, saved, state (configured|not_configured), settings, credentialRef, webhookSecretRef, supportsWebhooks, rateLimitPerMinute, rateLimitCeiling, providers: {key,label,isTestDouble,supportsWebhooks,fields}[]`.
Widget `communication.notifications` (component `NotificationsWidget`): `{unread, items: Notification[<=5]}`.

## 14. Contracts for marketing apps

Decision record: ADR 0014. Email marketing, SMS and WhatsApp apps must not import this module's models, enums or events. Everything they
need is under `App\Core\Communication` (always bound; honest null objects answer when the app is not installed,
and the module answers the same way when the workspace is not entitled). Call inside the active tenant context.

| Contract | Answers | Null / not entitled |
|---|---|---|
| `Contracts\MarketingAudience` | `canReceive(contactId, MarketingChannel, MessagingPurpose = Marketing): AudienceDecision`; `decideMany(list<id> up to 1000, ...): array<id, AudienceDecision>` (a fixed ~6 queries) | `allowed=false`, `channel_unavailable` |
| `Contracts\UnsubscribeLinks` | `issue(contactId, MarketingChannel, source): ?string` absolute workspace URL | `null` |
| `Contracts\ChannelGateway` | `availability(channel)`, `send(MarketingMessage): SendReceipt`, `statusFor(id)`, `statusForMany(ids)` | `not_configured` / `unavailable`, nothing recorded |
| `Contracts\ConsentRecorder` | `recordOptOut(contactId, MarketingChannel, method, evidenceNote?, address?): bool` (method `keyword`\|`button`; consent withdrawn and the address suppressed for marketing, at once); `recordOptIn(contactId, MarketingChannel, method, evidence): bool` (method `form`\|`double_opt_in`; refused with `false` while a suppression holds the address) | `false`, nothing recorded |

**Decision** (`AudienceReason`): checked in order channel available (`channel_unavailable`), contact exists
(`unknown_contact`), valid address (`no_address`), suppression (`unsubscribed` when only unsubscribe suppressions
match, else `suppressed`), consent for marketing (`opted_out`, `no_consent`; no record is no), else `ok`. These are
the rules `Outbox::blockedReason()` applies at queue and worker time; the decision is advice, the outbox
re-checks. Transactional purpose ignores consent and marketing-scope suppression.

**Sending**: `send()` puts one message in the outbox through `Outbox::queue()` (so suppression, consent, plan
limit `communication.messages`, quiet hours, rate limit, provider registry, retries and metering all apply) and
never delivers inline. The address is taken from the contact. The message is `marketing` (a WhatsApp reply may be `transactional`, see the end of this section); its `source` is
the module key, and the campaign and recipient ids are stored in `meta` (`marketing_module`,
`marketing_campaign_id`, `marketing_recipient_id`). The idempotency key is namespaced `{module}:{key}` (key up to
119 characters), so a retried campaign step returns the original receipt (`replayed = true`) and queues nothing.

`SendReceipt.state` (`DeliveryState`): `queued` (recorded; `dueAt` set when postponed), `blocked` (recorded as
suppressed, `reason` explains), `failed` (`limit_reached`), `unavailable` (not recorded: app, channel or
provider unavailable, `unknown_contact`, `no_address`; `messageId` is null). Replays return the message's
current state.

**Status**: listen to the core event `App\Core\Communication\Events\MarketingMessageStatusChanged`
(`communication.marketing.message_status_changed`), raised after commit for messages sent through the gateway
only, when they become `sent`, `delivered`, `failed`, `bounced`, `blocked` (suppressed or consent lost after
queueing) or `cancelled`. Payload: `message_id`, `module`, `campaign_id`, `recipient_id`, `contact_id`,
`channel`, `state`, `failure_code`. Ignore events whose `module` is not yours. The module's own events
(`communication.message.*`) are unchanged. Use `statusForMany()` to reconcile after downtime. Email through the
platform mailer has no delivery receipts (stays `sent`); SMS and WhatsApp states depend on the provider adapter.

**Unsubscribe**: the platform already adds the link and `List-Unsubscribe` headers to marketing messages. `issue()`
is for bodies that must embed it; each call mints a new 365-day token (`communication_unsubscribe_tokens`, hash
only, no message id). `source` is validated but not stored.

Binding in a marketing module needs nothing: type-hint the contract in a controller, action or job. In tests,
use `commTenant`, `optIn`, `connectFakeProvider` (SMS/WhatsApp, testing and local only; `availability()` reports
`testDouble`) and `Mail::fake()` for email; the testing queue is synchronous.


### Service replies, provider hints and the channel event feed

- `MarketingMessage::$purpose` defaults to `Marketing`. `Transactional` is accepted for **WhatsApp only** (a reply
  inside the customer-service window of a conversation the customer started): no marketing consent, no quiet hours,
  suppression scope `all` still blocks. Any other channel throws. The gateway also requires the provider hint
  `whatsapp_window_until` naming a moment still in the future; without it the message is queued as marketing.
  A campaign must always send `Marketing`, whatever its template's category.
- `MarketingMessage::$providerHints` carries provider instructions (at most 10, keys prefixed with the channel such as
  `whatsapp_template`, scalar values up to 2000 characters). The gateway stores them in the message meta, before the
  `marketing_*` references so they can never replace them; the prefix keeps a hint from overriding connection
  settings such as `phone_number_id`.
- `Contracts\ChannelEventListener` (tag `ChannelEventListener::TAG`) receives, in-process after the platform applied a
  verified, first-seen provider event, a `Data\ChannelEvent`: type (`delivered`, `read`, `failed`, `bounced`,
  `complained`, `inbound`, `template_status`), provider and event id, our message id when known, the normalised
  address, the matching shared contact id, the marketing module/campaign/recipient references, the text of an
  inbound message and provider `details` (message type, profile name, media MIME type, pricing facts, template
  name/language/status). A listener checks its own entitlement, is idempotent, treats text and details as untrusted
  and should not throw: exceptions are logged by class only and never stop another listener. For an `inbound`
  event a listener's failure makes the webhook answer `503` and keeps nothing of the event, so the provider
  redelivers it (the message exists nowhere else); this is why listeners must be idempotent.
