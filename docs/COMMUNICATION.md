# Communication platform

State: Phase 8 complete (backend and bilingual UI; a notification bell in the workspace top bar). Module key `communication`, optional app, free offer ("Included"), depends on `core`.
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
| Top bar | `NotificationBell` (in `TenantLayout`, for members with `communication.notifications.view`, never in a support session): the unread count ("9+" above nine) and the latest unread from the summary endpoint, asked on load, on every page visit, when opened and every 60 seconds while the tab is visible; choosing one marks it read and follows its link; "Mark all as read" and "See all notifications" |
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
  `whatsapp_template`, `whatsapp_language`, `whatsapp_params` (JSON list), `whatsapp_window_until` and
  `whatsapp_interactive` (JSON `{kind: buttons|list, button, options}`: reply buttons or a one-section list, refused
  locally as `invalid_interactive` outside Meta's limits) from the message meta. Operator credentials: `config/communication.php` entries as documented in `docs/WHATSAPP.md`.
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
route already drops events whose account is not one of the connection's own (since ADR 0022 a workspace can register several numbers; each is claimed the same way, see section 14a). Operators should still scope tokens with
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
whose account is not one the workspace holds (any registered WhatsApp number; `business_account_id` is compared with the setting). `ProviderEventType` also has `read` and `template_status`; they
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

## 8a. Unified inbox (ADR 0020, ADR 0030)

`GET /communication/inbox` (`communication.inbox.index`, `can:communication.inbox.view`) shows the conversations of
every inbox source the member may use, newest first.

**Sources.** A source implements the core contract `App\Core\Communication\Contracts\InboxSource` and is tagged
`InboxSource::TAG` in its own app. Six exist today:

- `whatsapp_marketing.conversations` (WhatsApp, narrowed by `ConversationAccess`; chat);
- `social.comments` (Facebook comment threads of the Social app, narrowed by the `social.comments.view` scope; chat);
- `social.messenger` and `social.instagram` (Facebook Page and Instagram direct messages of the Social app, narrowed by the
  `social.messages.view` scope and the Page allow-list; chat, marks read; replies only inside the 24-hour window, ADR 0031);
- `support.live_chat` (Support website chat, narrowed by `ChatAccess`; chat, marks read, ADR 0032);
- `support.tickets` (Support, narrowed by `TicketAccess`; resolved, closed and merged count as closed; chat: the
  requester's messages and the public replies, never internal notes; a reply is a public reply, refused on a closed or
  merged ticket).

A source is listed only when the workspace is entitled to its module and the member holds its permission. Holding
`communication.inbox.view` alone shows nothing but a "no inbox available" message.

**States.** Each source maps its statuses onto `unassigned` (open, no owner), `active` (open, with an owner) and
`closed`.

**Filters.** The list defaults to open conversations (`unassigned` and `active`) and can filter by:

- state: `open`, `unassigned`, `active`, `closed` or `all`;
- source;
- owner: `me` or `unassigned`;
- search, which each source applies to its own fields;
- `unread=1` (`InboxQuery::$unread`, Communication 1.4.0): only conversations whose item would show an unread count,
  by each source's own notion: WhatsApp, Messenger/Instagram and website chat `unread_count > 0`; support tickets
  awaiting a reply (`last_customer_message_at` set and later than `last_agent_message_at`, or no agent message);
  Facebook comment threads with a comment still awaiting an answer (`awaiting > 0`, so never a `closed` thread). A
  source without an unread notion returns nothing. It narrows the board's columns and totals, paging and the list's
  refresh as well. Each filter is one more condition on the source's existing tenant-leading index walk (no new index:
  unread rows are a minority read in the same order, and the ticket rule compares two columns of the row).

**Board.** `?view=board` shows three columns, each with up to 25 items and the column's total.

**Paging.** Paging keeps one position per source in `after`, an opaque value that only the inbox issues. A position
that does not decode, or names a source the member does not have, is a validation error. The page drops `after`
from the address after loading more, so a reload starts at the newest.

**Chat (ADR 0030).** A source that also implements the optional core contract
`App\Core\Communication\Contracts\InboxReplies` can be read and answered from the inbox. Every list item carries
`chat` (true for such a source); the others still link to their own app.

- **Opening.** `?open={source}:{id}` opens the conversation beside the list (the lazy `thread` prop: title, channel,
  the owning app's URL, the newest 50 messages oldest first, `hasOlder`). `messages` (up to 200) loads older ones;
  older still stay in the owning app. It is resolved only in the list view. Loading the page, opening and polling are
  read-only.
- **Not available.** For a conversation the member may not open (missing, another workspace's, out of scope), a source
  without chat or one the workspace is not entitled to, `thread` is null, on the first open and on a poll alike. The
  pane then says "This conversation is no longer available", polling stops and a button returns to the list. No error
  page, and the same answer for every reason.
- **Read state: an explicit POST, never on a GET.** A source with an unread state also implements the optional core
  contract `App\Core\Communication\Contracts\MarksInboxRead` (the thread then carries `marksRead`).
  `POST /communication/inbox/{source}/{id}/read` (`communication.inbox.read`, `can:communication.inbox.view`, 120 per
  minute on its own counter, session and CSRF of the workspace `web` group) calls `UnifiedInbox::markRead()`: the same entitlement,
  view-permission and chat checks as a thread, the source's own thread as the visibility guard (404 otherwise), then
  the source clears the state exactly as its own page does (WhatsApp: `ConversationManager::markRead()`). A chat source
  without an unread state (Facebook comments) is a silent no-op. It answers 204 to the page's background call and
  redirects back for a plain form. The page posts it when a conversation opens with an unread badge (or outside the
  loaded list) and when a poll brings new inbound messages, never while the tab is hidden, and clears the badge at
  once without reloading the list. A link with `?open=` therefore marks nothing read.
- **Replying.** `POST /communication/inbox/{source}/{id}/reply` (`communication.inbox.reply`,
  `can:communication.inbox.view`, 30 per minute on its own counter, `text` required, at most 4096 characters). `UnifiedInbox` checks the
  source's view permission and entitlement (404), the source's reply permission (403:
  `whatsapp_marketing.conversations.reply`, `social.comments.reply`, `social.messages.reply`, `support.tickets.reply` for
  tickets and website chat) and that the member may open the conversation (404), then hands the text to the source. The source calls
  the owning app's own action (`Messenger::reply()`, `CommentActions::reply()`, `DirectMessages`, `LiveChatManager`,
  `TicketManager::addMessage()` as a public reply),
  which applies the channel's rules. A refusal (the WhatsApp 24-hour window closed, a
  deleted comment, a channel that is not connected) comes back as a validation error on `text`. The inbox never sends
  anything itself.
- **The composer.** It is hidden from a member without the source's reply permission. When a reply cannot be written
  now, the source's reason (`replyBlockedReason`) and an "Open in app" link replace it; outside the WhatsApp window that
  means sending an approved template from the WhatsApp inbox. On a desktop (a fine pointer, 768 px and wider) Enter
  sends and Shift+Enter starts a new line; on touch and narrow screens Enter is a new line. Ctrl+Enter or Cmd+Enter
  always sends. It grows to six lines, and shows how many of the 4096 characters are left only in the last 200. A reply
  shows at once as "sending" (a clock) and gives way to the stored message when the server answers; a refusal shows
  its reason under the composer and puts the text back.
- **Freshness.** While a conversation is open the page reloads only `thread` every 15 seconds, not while the tab is
  hidden and never two requests at once. The list refreshes itself every 20 seconds the same way, through
  `GET /communication/inbox/poll` (`communication.inbox.poll`, `can:communication.inbox.view`, 60 per minute on a
  throttle key of its own, so it never uses up the reply or read budget). It takes the list filters (`source`, `owner`,
  `state`, `search`, `unread`, validated as on the page), answers the newest page as JSON (`{items, next}`, the same rows as the
  page's `list.items`) and is read-only. The page merges it into the rows on screen: a row already shown is updated and
  moves up, a new one comes first, the pages loaded with "Load more" stay and "Load more" continues where it was. An
  answer for filters that are no longer on screen is dropped.
- **Realtime (Communication 1.5.0, ADR 0035).** When the platform runs a Reverb server (`BROADCAST_CONNECTION=reverb`
  and the `REVERB_*` values; `docs/DEPLOYMENT.md` section 4a), the page also listens on the workspace's private channel
  `private-tenant.{tenantId}.inbox` for `inbox.changed` signals. A signal is `{source, id}` only, never content; the
  page answers it by asking the endpoints above again (the list's `communication.inbox.poll`, and the `thread` reload
  when the open conversation is the one that changed), gathering signals for 400 ms and asking once. While subscribed
  both pollers slow down to once a minute as a safety net; when the connection drops they return to 15/20 seconds and
  the page asks again once it is back. The page gets the connection details as its `realtime` prop (`{key, host, port,
  scheme, channel, authEndpoint}`, never the secret; null while realtime is off, and then nothing loads). The
  subscription is signed by the core route `POST /broadcasting/auth` (`core.realtime.auth`) on the workspace host only:
  session, membership, paywall, `throttle:120,1,realtime-auth`, the channel's tenant must be the hostname's and the
  member must hold `communication.inbox.view` (`AccessChecker`: entitlement, active membership, grant); 403 otherwise,
  404 while realtime is off. The source apps send the signal through the core contract
  `App\Core\Communication\Contracts\InboxSignals` after their write commits (WhatsApp: inbound, outbound, statuses of
  conversation messages but not of campaign messages, assignment, close, read; Messenger/Instagram: messages, receipts,
  replies, read, close, assignment; Facebook comments: new, removed, hidden, replied, handled, assigned, by thread;
  website chat: every message, close, read; tickets: created, edited, public and customer replies but never internal
  notes, status, assignment). The signal is queued on `notifications`; a failure is logged by class and never reaches
  the write. If the server cannot be reached the page warns once in the console and keeps polling.
- **The chat screen.** The list view fills the window below the shell's top bar (the page asks for it with
  `useFullHeightPage`); the list and the conversation scroll on their own. From the `md` breakpoint the Communication
  sections leave the tab row and become an icon rail on the card's left edge, like a desktop messenger
  (`CommunicationNav variant="rail"`: Inbox, Notifications, Messages, Templates, Consent and suppression, with Settings
  pinned to the bottom; each shown only with its permission, the current one marked `aria-current="page"`); below `md`
  the tab row stays. The board view and the other Communication pages keep the tab row. Rows show an avatar (initials from the title
  on a tint picked from the conversation's key), the title, the time (today the time, then "Yesterday", the weekday
  within a week, otherwise the date, in the workspace timezone), the preview, the channel icon, an unread count, the
  status and the owner. Quick filters above the list: All, Unread, Mine, Unassigned, the status and the channel, each a
  server filter above ("All" clears Unread and the owner), so paging and the refresh follow it. With Unread on, a
  conversation read on screen keeps its row (without the badge) until the list is asked for again. The board has an
  "Unread only" checkbox for the same filter. The conversation shows day separators, groups messages from one side and author within five minutes, names
  the author only when a side has more than one in view, and marks outgoing messages with a tick: a clock while queued
  or sending, one tick when sent, two when delivered, two in the accent colour when read, an alert when failed or
  blocked. Message text is rendered as text; only `http` and `https` addresses become links (`target="_blank"`,
  `rel="noopener noreferrer nofollow"`). An attachment is a chip with an icon of its kind when the source stores one
  (`attachmentKind`: `image`, `video`, `audio` or `file`; WhatsApp and Messenger/Instagram do). The log opens at the
  newest message and stays there while the reader is there; otherwise a "jump to latest" button counts what arrived.
  Scrolling to the top loads older messages and keeps the place; a "Load older messages" button stays for the keyboard.
- **Suggest a reply (Communication 1.4.0).** When the member can answer the open conversation now and the workspace has
  the AI assistant (the thread's `aiDrafting`: the AI app is installed and the workspace is entitled to `ai`), the
  composer shows a "Suggest a reply" button (a sparkles icon). `POST /communication/inbox/{source}/{id}/suggest`
  (`communication.inbox.suggest`, `can:communication.inbox.view`, 20 per minute on its own throttle key `inbox-suggest`,
  session and CSRF of the workspace `web` group) resolves the conversation through `UnifiedInbox::answerable()`: the
  same entitlement, view and chat checks as a thread (404), the source's reply permission (403), a conversation the
  member may not open or answer (404), and a channel that refuses a reply now (422 on `text` with the source's
  reason). `InboxReplySuggestions` then reads the newest 10 messages, takes the newest customer message with text as
  the input (422 when there is none) and the others as two quoted context blocks (`earlier_messages`,
  `replies_since`; "Customer:"/"Business:" lines, no names, each message cut to 500 characters, newest kept within
  2000), and calls the core `TextAssistant` (ADR 0024) with `TextKind::ReplySuggestion`, purpose
  `inbox:reply:{source}:{messageId}` (the same message is metered once and replayed for an hour), the customer's own
  language (`locale` null), a friendly tone, at most 600 characters, the channel as `platform` and the member's
  membership for the rate limit. It answers `{state, text, message, retryable}`; `text` only when `state` is `ok`,
  otherwise `message` is the assistant's safe wording (`not_configured`, `disabled`, `limit_reached`, `refused`,
  `failed`). It sends, saves and marks nothing. The page puts the text into the composer (keeping what the member had
  typed to restore), labels it "AI-generated. Check it before sending." until it is sent or cleared, and shows the
  message under the composer otherwise. The member sends it through the normal reply. Communication imports nothing
  of the AI app, only the core contract.
- **Narrow screens.** Below 768 px the list and the open conversation take turns; a back button or Escape returns to
  the list. From the board, a chat item opens in the list view.
- **Channels.** Each row and the open conversation show the channel as a generic icon with its name:
  `whatsapp`, `facebook`, `messenger`, `instagram`, `web` (website chat) and `support`
  (`communication.inbox.channels.*`). Any other channel gets a generic icon named by the source's label, so a new
  source needs no change to these pages.

The inbox imports no WhatsApp, Social or Support class.

**Not built.**

- The Reverb server package is not installed (ADR 0035: its release conflicts with Guzzle 8); until it runs, the inbox
  polls only.
- No templates, media, interactive messages, notes, assignment or closing from the inbox; those stay in the owning app.
- Sorting runs in each source's own query: `last_message_at`, the newest comment of a Facebook thread and
  `last_activity_at`, then the id.

## 9. Permissions, navigation, limits, schedules

| Permission | Scopable | Sensitive | Used by |
|---|---|---|---|
| `communication.inbox.view` | | | the unified inbox page (each source still needs its own permission) |
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

## 14a. WhatsApp numbers, rich messages, media and the template catalogue (ADR 0022)

Four additions for the WhatsApp Business app and any later app. All are core contracts, bound to honest null objects
when the communication app is absent or the workspace is not entitled (`module_unavailable`), and none is proven
against live Meta (HTTP-faked tests only).

| Contract (`App\Core\Communication\Contracts`) | Methods |
|---|---|
| `ChannelNumberDirectory` | `numbers(MarketingChannel): list<ChannelNumber>` (default first), `default(MarketingChannel): ?ChannelNumber`, `find(string $numberId): ?ChannelNumber` |
| `ChannelNumberRegistry` | `register(channel, accountId, label, ?displayNumber, makeDefault): ChannelNumberOutcome`, `update(numberId, ?label, ?displayNumber, ?active)`, `makeDefault(numberId)`, `remove(numberId)`; reasons in `ChannelNumberOutcome` |
| `MediaGateway` | `availability()`, `store(WhatsappMediaType, bytes, ?filename, ?declaredMime)`, `storeFromTenantFile(type, tenantPath, ?filename)`, `upload(mediaId, ?senderNumberId)`, `download(InboundMedia, ?numberId)`, `read(mediaId): ?MediaContents`; all but `read` answer a `MediaOutcome` |
| `TemplateCatalogue` | `list(?ProviderTemplateStatus, ?name, limit, ?after)`, `create(TemplateDraft)`, `delete(name, ?providerTemplateId)`; all answer a `TemplateCatalogueResult` |

### Several numbers per workspace

The connection (provider, credential reference, webhook secret reference) stays one per channel. A WhatsApp
connection owns numbers in `communication_channel_numbers` (`phone_number_id`, `display_number`, unique `label`,
`is_active`, `is_default`). `ChannelNumber::$id` is the platform's id for a number; apps use it, not the Graph id.

- The connection's legacy `settings.phone_number_id` always equals the default number's account id, so single-number
  workspaces, the connection form and `state()` are unchanged. The first read of a connection written before numbers
  existed (or written directly) creates its default number "Main number" and claims it (`ChannelNumbers::ensure()`);
  a migration backfills existing connections.
- Each number is claimed in `communication_account_claims` (`number_id` points at it). Registering a number another
  workspace holds, or one already in this workspace, answers `account_in_use`.
- **Sending**: `MarketingMessage::$senderNumberId` (null = the default number). The gateway answers `unavailable` /
  `sender_number_unavailable` for an unknown, other-workspace or disabled number and records nothing. The chosen id is
  stored on the message (`communication_messages.sender_number_id`), returned in `SendReceipt::$senderNumberId`, and
  re-checked by the worker (a number disabled meanwhile fails the message with `sender_number_unavailable`). The adapter
  posts to `/{version}/{that number's phone_number_id}/messages`. The worker's per-minute budget is per number. A
  channel without per-number accounts (the test double, other providers) ignores numbers: no id is stored, and a named
  number is refused.
- **Receiving**: the webhook guard accepts an event whose `phone_number_id` is any number the workspace registered
  (inactive ones too); `ChannelEvent::$numberId` names it and `details['phone_number_id']` repeats the Graph id.
  A status event is attributed to the number the message was sent from.
- Removal is refused (`in_use`) once messages reference the number: disable it. The default cannot be disabled or
  removed while others exist (`is_default`).

### Rich outbound content

`MarketingMessage::$whatsapp` takes a `Data\Whatsapp\WhatsappContent`; `$body` stays the message text (the
interactive/product/flow body, up to 1024 characters; for media the log text; for a template the rendered text).
Constructors and limits (all enforced on construction and again when the adapter reads the stored JSON):

| Constructor | Limits |
|---|---|
| `template(name, language, bodyParameters, headerText, headerMedia, buttons)` | 20 parameters of up to 1024 characters (empty becomes `-`, whitespace collapsed); header text or image/document/video, not both; button parameters `url` (2000), `quick_reply` (256), `copy_code` (15) at index 0-9; allowed at any time |
| `buttons(buttons, headerText, headerMedia, footer)` | 1-3 buttons, id up to 256, title up to 20, ids and titles unique |
| `list(buttonLabel, sections, headerText, footer)` | button label 20; up to 10 sections and 10 rows in total; row id 200, title 24, description 72; section title 24 (required when several sections) |
| `ctaUrl(label, url, headerText, headerMedia, footer)` | label 20; absolute http(s) URL up to 2000 |
| `media(MediaReference, caption)` | image, document (file name 240), audio (no caption), video; caption 1024 |
| `product(catalogId, retailerId, footer)`, `productList(catalogId, header, sections, footer)` | header up to 60 and required for a list; up to 10 sections, 30 products, section title 24 |
| `flow(flowId, ctaLabel, flowToken, screen, data, published, headerText, footer)` | CTA 20; token 100; data a flat scalar map of up to 4000 bytes and only with a screen |

Header text and footer: 60 characters. The whole content serializes to at most 16 000 bytes. `MediaReference` is exactly
one of a stored `mediaId` or a `providerMediaId`; there is no path or URL form.

**Window rule.** Everything but `template` needs the customer's open 24-hour window, enforced like a transactional
reply: the caller passes the provider hint `whatsapp_window_until` (ISO-8601); missing, past or unparsable gives
`unavailable` / `outside_customer_service_window` and nothing is recorded; the adapter checks again at send time.
Consent follows `purpose` as always: `Transactional` (a reply; honoured only with the same hint) skips marketing
consent, `Marketing` needs an opt-in. A template campaign is always `Marketing`.

**Media at send time.** A `mediaId` is uploaded for the sending number right before the send (reused per (media,
number) for `communication.whatsapp.media.upload_reuse_days`); a missing file fails the message with `media_not_found`,
a refused upload with `media_upload_failed`, and a temporary provider failure retries the message. The outbox keeps
the stored reference, never the provider id.

**Graph API mapping** (`POST /{version}/{phone_number_id}/messages`, version `communication.whatsapp.api_version`,
overridable per connection by `graph_api_version`; envelope `messaging_product: whatsapp`, `recipient_type:
individual`, `to`):

| Kind | `type` and object |
|---|---|
| template | `template`: `name`, `language.code`, `components[]`: `header` with one parameter `{type: text, text}` or `{type: image/document/video, <type>: {id, filename?}}`; `body` with `parameters[] {type: text, text}`; `button` with `sub_type` (`url`, `quick_reply` or `copy_code`), `index` (string), one parameter `{type: text, text}`, `{type: payload, payload}` or `{type: coupon_code, coupon_code}` |
| buttons | `interactive`: `type: button`, optional `header` (`{type: text, text}` or media `{type, <type>: {id}}`), `body.text`, optional `footer.text`, `action.buttons[] {type: reply, reply: {id, title}}` |
| list | `interactive`: `type: list`, optional `header` (text), `body.text`, `footer.text`, `action {button, sections[] {title?, rows[] {id, title, description?}}}` |
| ctaUrl | `interactive`: `type: cta_url`, header/body/footer, `action {name: cta_url, parameters {display_text, url}}` |
| media | `type: image`, `document`, `audio` or `video`, with `<type>: {id, caption?, filename? (document)}` |
| product | `interactive`: `type: product`, `body.text`, `footer.text?`, `action {catalog_id, product_retailer_id}` |
| productList | `interactive`: `type: product_list`, `header {type: text, text}`, `body.text`, `footer.text?`, `action {catalog_id, sections[] {title, product_items[] {product_retailer_id}}}` |
| flow | `interactive`: `type: flow`, header/body/footer, `action {name: flow, parameters {flow_message_version: "3", flow_token, flow_id, flow_cta, mode: published or draft, flow_action: navigate, flow_action_payload {screen, data?}}}` (the two `flow_action*` fields only when a screen is given) |

Adapter rejections (never retried): `invalid_content`, `outside_customer_service_window`, `media_not_uploaded`,
`sender_number_unavailable`, plus Meta's own mapped codes.

### Media (`MediaGateway`)

Files live in `TenantStorage` under `communication/media/{generated id}.{extension from the sniffed type}`; the table
`communication_media` holds metadata and a tenant-relative path that is never returned. `communication_media_uploads`
holds the provider media id per (media, number) with an expiry.

- Allow-list (sniffed): image `jpeg`, `png`; sticker `webp`; audio `aac`, `mp4`/`m4a`, `mpeg`, `amr`, `ogg`; video
  `mp4`, `3gpp`; document `pdf`, `txt`, `doc`, `xls`, `ppt`, `docx`, `xlsx`, `pptx` (an Office container is accepted
  only when the claimed family agrees: ZIP for OOXML, OLE for legacy). Caps per type in
  `communication.whatsapp.media.max_bytes` (image 5 MB, sticker 512 KB, audio and video 16 MB, document 20 MB).
- Refusals: `unsupported_media_type`, `content_mismatch` (bytes are not what the type or declared MIME says),
  `too_large`, `empty_file`, `not_found`, `number_unavailable`, `provider_not_configured`, `provider_rejected`,
  `provider_unreachable`, `provider_unavailable`, `provider_authentication_failed`.
- Download: `GET /{version}/{media_id}` then the returned URL with the same token, only over https on a host under
  `communication.whatsapp.media_hosts`, no port, no IP literal, no redirects; idempotent per provider media id. The
  inbound event still carries metadata only; the app calls `download()` (soon: provider ids are short-lived).
- Serving: `MediaContents::headers()` gives the sniffed `Content-Type`, `X-Content-Type-Options: nosniff`,
  `Content-Disposition: attachment`, `Cache-Control: private, no-store` and a sandboxing CSP. The app serves the file
  only through its own authorized controller.

### Template catalogue (`TemplateCatalogue`)

Implemented by `ProviderTemplateCatalogue` with the connection's credential; needs the `business_account_id` setting.
`list` calls `GET /{waba_id}/message_templates?fields=id,name,language,status,category,components,rejected_reason,quality_score`
with `limit` (1-100), `status`, `name` and the `after` cursor; `create` posts `{name, language, category, components[],
allow_category_change?}`; `delete` calls `DELETE /{waba_id}/message_templates?name=..[&hsm_id=..]`. `TemplateDraft`
validates what Meta validates: name `[a-z0-9_]`, language `en`/`en_US`, categories marketing and utility
(authentication is refused), one body of up to 1024 characters whose variables are `{{1}}`... without gaps, not first
or last, each with a non-empty example; header text up to 60 with at most one variable, or an image/video/document
header with the upload handle of an example file; footer up to 60 without variables; up to 10 buttons (quick reply,
URL with an example when it ends in `{{1}}`, one call button, one copy-code button, at most 2 URL buttons).
Results are `ProviderTemplate` (id, name, language, category, normalized `ProviderTemplateStatus`, components,
rejected reason, quality score). States: `ok`, `module_unavailable`, `not_configured`, `missing_business_account`,
`rejected` (Meta's `error_user_msg`, clipped), `failed` (`provider_unreachable`, `provider_authentication_failed`,
`provider_rate_limited`, `provider_unavailable`), `not_found`. A submission and a deletion are audited (name,
language, category; never the token). Nothing is stored here: the provider is the source of truth.

### Inbound events

`ChannelEvent` additively carries `numberId`, `pricingCategory` and `content` (`Data\InboundContent`, kind
`InboundContentKind`): `button_reply` / `list_reply` (id, title, description), `template_button` (payload, text),
`media` (`InboundMedia`: type, provider media id, MIME as declared, sha256, caption, file name, voice), `location`
(coordinates in range, name, address), `reaction` (emoji, empty on removal, reacted message id), `order` (catalogue
id, note, up to 60 items with retailer id, quantity, price as the provider's decimal string and currency),
`flow_response` (name, token, the `response_json` text capped at 8000 characters; `flowResponse()` decodes it only
when complete and an object), `contacts`, `unsupported`. Every kind carries `contextMessageId` (the message replied
to) when given. Plain text has no `content`. `text` is unchanged (the reply title, a media caption); types without
text keep `text = null`. Everything is customer input: clipped, capped, never evaluated. Delivery and status events
carry `pricing_category`, `billable`, `pricing_model`, `pricing_type`, `conversation_id`, `conversation_origin` in
`details` (only what Meta reports).
