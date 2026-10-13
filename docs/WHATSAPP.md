# WhatsApp Business

The optional app `whatsapp_marketing` (name "WhatsApp Business", category WhatsApp marketing, one free offer
`included`) lives in `app/Modules/WhatsappMarketing` and is registered by `WhatsappMarketingServiceProvider`. It
depends on `core` and `communication` and imports nothing else. It adds approved-template campaigns to contact
lists, a shared inbox that enforces the 24-hour customer-service window, agent assignment, quick replies,
declarative chatbot flows, delivery and read tracking, and reports. Tables are prefixed `whatsapp_`; routes are
`/whatsapp/...` with names `whatsapp_marketing.*`. Spec: `MASTER_PROMPT.md` section 12 and Phase 9.

State of the build: backend, Vue pages (`resources/js/Modules/WhatsappMarketing`), tests and docs are built.
**The WhatsApp Cloud API adapter has not been exercised against live Meta.** It is covered by HTTP-faked tests
only. Using it for real needs the operator's Meta app, a verified business, a registered phone number, approved
templates and (for production volume) Meta's app review. Until the operator provides credentials and a workspace
connects the channel, the app shows **not configured** and sends nothing. Nothing is simulated outside tests.

A workspace needs an effective plan or App Store entitlement for `whatsapp_marketing` (which itself needs
`communication`); without it every route answers 404, the inbound listener ignores events and the scheduled jobs do
nothing for that workspace. Uninstalling retains every record; reinstalling restores them.

## 1. How the pieces fit

```
Meta  --webhook-->  communication: /communication/webhooks/whatsapp_cloud   (signature, event id dedupe, status)
                         |  ChannelEventListener (core contract, tagged)
                         v
                    whatsapp_marketing: ChannelEventHandler  -> conversations, messages, read receipts, template status
whatsapp_marketing --ChannelGateway::send()--> communication outbox --> WhatsAppCloudProvider --> Graph API
```

- **The connection is the communication app's.** A WhatsApp channel connection (`communication_channel_connections`,
  provider `whatsapp_cloud`) holds the non-secret `phone_number_id` (and optional `business_account_id`,
  `graph_api_version`) plus two credential **references**. Secrets live in the environment behind
  `config('communication.credentials')` or in an operator-created provider account (`acct_{id}`, encrypted,
  write-only, ADR 0013). This app has no connection, token or secret of its own and its pages never show one.
- **Everything outbound goes through `App\Core\Communication\Contracts\ChannelGateway`.** Consent, suppression,
  quiet hours, per-channel rate limit, retries, plan limit `communication.messages` and the provider are the
  platform's. This app adds the WhatsApp rules (window, approved templates) before it asks.
- **Opt-in is the platform's consent record.** `MarketingAudience::decideMany()` answers per contact (a fixed
  number of queries per page of 1000); the outbox re-checks at send time. No record means no marketing message.
- **Events come in through `ChannelEventListener`** (`App\Core\Communication\Contracts`), tagged in the provider.
  The platform's webhook route is the only inbound door: it already resolves the tenant from the host, verifies the
  signature over the raw body before parsing and de-duplicates by provider event id. This app has no webhook route
  of its own (Meta allows one callback URL per app, so a second route could not receive anything).

## 2. Operator setup (what a real deployment needs)

1. A Meta developer app with the WhatsApp product, a verified Meta business, a WhatsApp Business Account and a
   registered phone number (Cloud API). A permanent system-user access token with `whatsapp_business_messaging` and
   `whatsapp_business_management`. For production volume Meta's app review applies.
2. Environment variables and `config/communication.php` entries (names only; never commit values):

   ```php
   'credentials' => [
       'whatsapp_token_main' => ['secret' => env('COMMUNICATION_WHATSAPP_TOKEN'), 'tenants' => null],
       'whatsapp_app_main'   => ['secret' => env('COMMUNICATION_WHATSAPP_APP_SECRET'),
                                 'verify_token' => env('COMMUNICATION_WHATSAPP_VERIFY_TOKEN'), 'tenants' => null],
   ],
   ```

   `tenants` null makes an entry available to every workspace; a list of slugs restricts it to those workspaces
   (use it when each workspace brings its own Meta app and number).
3. In the workspace: **Communication > Settings > WhatsApp**: provider "WhatsApp Business Platform (Cloud API)",
   `phone_number_id`, optional `business_account_id`, credential reference (token), webhook secret reference (app
   secret + verify token), enabled. The reference is either an environment entry from step 2 or, alternatively,
   a **provider account** the operator created in the Super Admin panel (**Messaging providers**, provider
   "WhatsApp Business Platform"): the token, app secret and verify token are then typed there once, stored
   encrypted and never shown again (ADR 0013). Both routes are equivalent to the app; the account route is
   as unverified against live Meta as the rest.
4. In the Meta app dashboard: callback URL `https://{workspace}.{APP_DOMAIN}/communication/webhooks/whatsapp_cloud`
   and the verify token from step 2. Meta's GET handshake (`hub.mode=subscribe`, `hub.verify_token`,
   `hub.challenge`) is answered only for a connected, enabled connection whose reference carries a `verify_token`,
   compared in constant time, and only echoes an alphanumeric challenge. Subscribe the `messages` and
   `message_template_status_update` fields.
5. One Meta app serving several workspaces: each event names its `phone_number_id` (and, for template status, the
   business account id). The platform's webhook route drops any event whose account does not equal the
   connection's own setting, so a shared app can never feed one workspace another workspace's messages. Template
   status events are also dropped unless the workspace entered its `business_account_id`.

Not configured is reported (and every send refused) when: the app is not entitled (`module_unavailable`), the
channel is disabled (`channel_disabled`), no `phone_number_id`/credential reference is saved or the reference does
not resolve for the workspace (`provider_not_configured`).

## 3. Templates

Templates are created and approved in Meta's WhatsApp Manager. `whatsapp_templates` mirrors them: name, language,
category (marketing/utility/authentication), header text, body, footer, up to three buttons, and the approval
**status** with who recorded it (`status_source`: `provider` for a verified webhook event, `admin` for a person) and
the reason Meta gave. Only `approved` templates are ever sent.

- Body variables must be `{{1}}`, `{{2}}`... without gaps (at most 20). Header variables, media headers and URL
  variables are not supported and are refused when saving.
- A provider status event updates an existing template by name + language (`APPROVED`/`REINSTATED` approved,
  `REJECTED`, `PENDING`/`IN_APPEAL`, `PAUSED`/`LIMIT_EXCEEDED`, `DISABLED`/`PENDING_DELETION`). An event for a
  template this workspace never entered is ignored: the event carries no body to create one from.
- The app does not call Meta's template API, so it cannot create or fetch templates. That is deliberate and
  listed in section 14.
- A template used by a draft, scheduled or sending campaign cannot be deleted.

## 4. The 24-hour rules (server side)

| Situation | Rule |
|---|---|
| Free-form reply | Only while `last_inbound_at + 24h` is in the future. Enforced in `Messenger::reply()`, again by the adapter at send time (`whatsapp_window_until`; a postponed message cannot go out late) and finally by Meta (error 131047) |
| After the window | Only an approved template. The inbox page disables free text and offers templates |
| Marketing template | Needs a recorded opt-in, always; quiet hours apply |
| Utility/authentication template (one agent to one customer) | A service message ONLY while the customer's own 24-hour service window is open (`whatsapp_window_until` is sent with it); after the window, or for someone who never wrote, it is marketing and needs an opt-in. Having written once is not consent |
| Any template in a campaign | ALWAYS marketing (consent, suppression, quiet hours), whatever category the workspace gave the template. A "utility" label never exempts a bulk send |
| Reply to a customer-initiated chat | `MessagingPurpose::Transactional` through the gateway: no marketing consent needed, quiet hours ignored, suppression scope `all` (bounced/complained) still blocks |
| Not approved / wrong variable count | Validation error, nothing queued |
| Channel not connected | Validation error ("WhatsApp is not connected for this workspace yet"), nothing recorded |

A conversation window (`whatsapp_windows`) opens when a customer writes, or when a business-initiated template is
accepted by the provider, and no window is running. It lasts 24 hours and is metered once under the key
`wa-window:{window id}` (limit `whatsapp_marketing.conversations_per_month`). A template inside a running window
costs nothing extra. Starting a business window needs room in the plan limit, otherwise the send is refused. A
window is counted when the provider accepts the template, so templates that are queued but not yet accepted
reserve their window: a campaign cannot queue more new conversations than the plan has left.
The platform's own message limit (`communication.messages`) is checked again at worker time.

## 5. Inbox, assignment and record scope

- Inbound messages are untrusted: text is cleaned (valid UTF-8, no control characters) and capped at 4096
  characters, rendered as text only; attachments are **metadata only** (kind, MIME type, has caption). Media are
  never downloaded, so there is no storage, type or size handling to get wrong.
- A conversation is one thread per phone number (`UNIQUE(tenant_id, phone)`), linked to the shared contact whose
  number matches. A number that is not a contact keeps the number and WhatsApp profile name only; an agent with
  `core.contacts.manage` can create the contact from the conversation, or the workspace can switch on
  `auto_create_contacts`. A reply needs a contact whose phone is the conversation's number: the platform reads the
  address from the contact, never from the caller.
- Phone numbers are E.164 in the platform's one canonical form (`Core\Communication\Support\PhoneNumber`).
  A Bangladesh number in any usual spelling (`01711000000`, `8801711000000`, `+8801711000000`) is read as
  `+8801711000000`; a number of another country is used only when it already carries its country code; anything
  else (a landline, another country's national number) is never guessed and shows as "number needs a country code".
- The record owner of a conversation is its assignee (`owner_id`, with department and branch copied). Scopes work
  like Support tickets: `own` = assigned to me, `department`, `branch`, `all`. An **enrolled, active agent** also
  sees unassigned conversations with any scope they hold so they can pick them up; nobody else does.
- Assigning to someone else needs `conversations.assign` for all records; taking one for yourself needs the
  agent enrolment. Assignment only goes to enrolled, active agents. Replying to an unassigned conversation assigns
  it to the person who replied. A person's reply or assignment pauses the bot for that conversation.
- Optional auto-assignment (workspace setting) picks the available agent with the fewest open conversations,
  within each agent's own cap.
- The platform writes the interaction timeline entry for every outbound and inbound message (unchanged).

## 6. Campaigns

A campaign is a template + a list + a mapping of each numbered variable to an allow-listed field or fixed text.

- **Fields**: `contact.first_name`, `contact.last_name`, `contact.full_name`, `company.name`, `workspace.name`,
  `agent.name`. Nothing else, no expressions or filters.
- **Lifecycle**: draft > scheduled (or sending now) > sending > completed | cancelled | failed. Only a draft can be
  changed or deleted. Starting needs the channel available, an approved template and 1 to
  `max_recipients` (10,000) list members.
- **Snapshot**: the first chunk job freezes the list in pages of 500 with one `decideMany()` per page. A contact
  without a readable number is `skipped (invalid_phone)`, one the platform denies is `blocked` with its reason
  (`no_consent`, `opted_out`, `unsubscribed`, `suppressed`), the rest `pending`.
- **Chunks**: `SendCampaignChunk` (queue `campaigns`) hands `chunk_size` (50) recipients to the gateway and
  dispatches itself until none are pending, under a per-campaign lock. A cancelled campaign stops between
  recipients. The scheduled job `whatsapp_marketing.campaigns` (every minute) starts due campaigns and nudges a
  campaign that made no progress for five minutes.
- **Idempotency**: key `campaign:{campaign}:recipient:{recipient}` per recipient. A repeated chunk or job returns
  the original message and sends nothing.
- **Progress** comes back through the core `MarketingMessageStatusChanged` event (sent, delivered, failed,
  blocked, cancelled) and the channel-event feed (read). Ranked states only move forward; a failure is accepted
  only before delivery.
- **Stop reasons**: `template_unavailable` (the template stopped being approved), `channel_unavailable` (the
  connection went away: the rest stays pending, the campaign is `failed`), `cancelled`.
- A recipient who withdraws consent between the snapshot and the send is blocked by the platform at send time.

## 7. Chatbot flows

A flow is data: trigger `keyword` (exact or contains, up to 10 keywords of 40 characters) or `welcome` (the first
message of a conversation window), then a closed list of steps: `reply{text}`, `tag{tag}`,
`assign{assignee_id|null}`, `handoff`, and one optional final `menu{prompt, options[{label, actions}]}` (at most
10 steps, 8 options, 5 steps per option, no nested menus). Text may only use the allow-listed placeholders.
**Nothing a tenant writes is ever executed as code or as an expression**; unknown keys are dropped when saving.

- A menu waits for the customer's next message for 30 minutes (number or label); anything else clears it.
- Guards: no bot reply in a conversation a person owns or after a handoff; at most 5 bot messages per
  conversation per 10 minutes; the bot starts again when a customer writes after the window closed.
- Built-in STOP: a message that is exactly `stop`, `stop all`, `unsubscribe`, `opt out`, `optout`, `বন্ধ` or
  `আনসাবস্ক্রাইব` (compared case-insensitively after trimming and collapsing spaces) is handled even with no flow
  and with bots off. The opt-out is recorded AT ONCE through the core `ConsentRecorder` contract (method
  `keyword`): marketing consent withdrawn and the number suppressed for marketing, so a running campaign blocks
  the contact on the very next message. The customer is told once ("You have been unsubscribed ..."); repeating
  STOP records nothing new and sends no second confirmation, and the bot's own message budget (5 per 10 minutes)
  applies to the confirmation. Where it cannot be recorded (no contact behind the number, the communication app
  not entitled) the older fallback is used: an unsubscribe link to confirm on, or a plain "we have noted your
  request", and the request waits in the inbox for a person.
- A bot reply is a service message (Transactional) and counts as a normal outbound message.

## 8. Automation

| Key | Kind | Permission | Notes |
|---|---|---|---|
| `whatsapp_marketing.message.received` | trigger | `conversations.view` | ids only (no text): conversation, message, contact, owner, type, new conversation |
| `whatsapp_marketing.message.read` | trigger | `conversations.view` | the provider reported a read |
| `whatsapp_marketing.conversation.assigned` | trigger | `conversations.view` | conversation, contact, owner, previous owner, source |
| `whatsapp_marketing.campaign.completed` | trigger | `campaigns.view` | totals by outcome |
| `whatsapp_marketing.template.send` | action | `conversations.reply` | contact, template, string parameters; returns `state` (`queued`, `sent`, `blocked`, `refused` ...) and `reason`; a missing opt-in is a stored `blocked` output |
| `whatsapp_marketing.conversation.assign` | action | `conversations.assign` | named agent or the least loaded |
| `whatsapp_marketing.conversation.add_tag` | action | `conversations.manage` | |

Actions write `whatsapp_automation_receipts` (`UNIQUE(tenant_id, action, idempotency_key)`) so a retried step
returns the first output. They never read the signed-in user.

## 9. Delivery, read and cost

- Delivery/failed state comes from the platform's status handling; read receipts and the provider's pricing
  facts (`billable`, `pricing_category`) come from the channel-event feed and are stored on the message.
- **Cost is never computed.** Meta's webhooks carry no amount, so analytics shows cost as "not reported" next to
  the counts the provider did report (billable / not billable / not reported, by pricing category).
- Plan limits: `whatsapp_marketing.conversations_per_month` (metered, one record per conversation window,
  idempotent) and `whatsapp_marketing.agents` (gauge of active agents). The platform's own
  `communication.messages` limit also applies to every send.

## 10. Reports

`ReportProvider`s tagged in the provider (permission `whatsapp_marketing.analytics.view`, workspace-wide):
`whatsapp_marketing.campaign_delivery` (per campaign started in the period: recipients, sent, delivered, read,
failed, blocked, skipped; delivered and read rates in basis points, `null` when there is nothing to divide) and
`whatsapp_marketing.conversation_activity` (per local day: new conversations, customer, agent/bot and campaign
messages, windows opened by customers and by the business). Days are bucketed with the UTC offset of the period
start (a zone with daylight saving can be off by an hour at a boundary).

## 11. Permissions, navigation, schedules

| Permission | Scopable | Sensitive | Used by |
|---|---|---|---|
| `whatsapp_marketing.conversations.view` | yes | | inbox list and thread |
| `whatsapp_marketing.conversations.reply` | yes | | replies, templates, starting a conversation |
| `whatsapp_marketing.conversations.manage` | yes | | close/reopen, tags, link or create a contact |
| `whatsapp_marketing.conversations.assign` | yes | | claim, assign (to others needs the `all` scope) |
| `whatsapp_marketing.templates.view` / `.manage` | | | templates |
| `whatsapp_marketing.campaigns.view` / `.manage` | yes | | campaigns and drafts |
| `whatsapp_marketing.campaigns.send` | | yes | start, schedule, cancel |
| `whatsapp_marketing.lists.view` / `.manage` | | | lists |
| `whatsapp_marketing.quick_replies.view` / `.manage` | | | quick replies |
| `whatsapp_marketing.flows.manage` | | yes | chatbot flows |
| `whatsapp_marketing.analytics.view` | | | analytics and the two reports |
| `whatsapp_marketing.settings.manage` | | yes | options, agents, connection status |

New permissions are granted to no role. Navigation (section `apps`): inbox, campaigns, templates, lists, quick
replies, analytics; (section `manage`): flows, settings. Widget `whatsapp_marketing.overview` (component
`WhatsappOverviewWidget`): open, unassigned, mine, unread, active campaigns, connected. Scheduled jobs through the
entitlement-gated runner: `whatsapp_marketing.campaigns` (every minute), `whatsapp_marketing.maintenance`
(daily: forgets unanswered menu states and windows older than 400 days). Queue: `campaigns`.

## 12. Routes and pages

All workspace routes sit under `/whatsapp`, carry `auth:web`, `auth.session`, `member`, `subscribed`,
`entitled:whatsapp_marketing` and the `can:` shown. Pages are `Modules/WhatsappMarketing/Pages/...`.

| Route name | Verb and URL | Permission | Page / fields |
|---|---|---|---|
| `whatsapp_marketing.inbox.index` | GET `inbox` | `conversations.view` | `Inbox/Index`: `conversations` (paginator), `filters`, `counts`, `agents`, `channel`, `canStart`, `contactMatches`, `templates` |
| `whatsapp_marketing.inbox.store` | POST `inbox` | `conversations.reply` | `contact_id`, `template_id`, `variables[]` |
| `whatsapp_marketing.inbox.show` | GET `inbox/{conversation}` | `conversations.view` | `Inbox/Show`: `conversation`, `messages`, `templates`, `quickReplies`, `agents`, `phoneContacts`, `channel`, `can` |
| `whatsapp_marketing.inbox.read` | POST `inbox/{conversation}/read` | `conversations.view` | none |
| `whatsapp_marketing.inbox.reply` | POST `inbox/{conversation}/messages` (60/min) | `conversations.reply` | `body` (max 4096) |
| `whatsapp_marketing.inbox.template` | POST `inbox/{conversation}/templates` (30/min) | `conversations.reply` | `template_id`, `variables[]` |
| `whatsapp_marketing.inbox.assign` / `.claim` | POST `inbox/{conversation}/assign|claim` | `conversations.assign` | `assignee_id?` |
| `whatsapp_marketing.inbox.status` | POST `inbox/{conversation}/status` | `conversations.manage` | `status` open/closed |
| `whatsapp_marketing.inbox.tags` | POST `inbox/{conversation}/tags` | `conversations.manage` | `tags[]` |
| `whatsapp_marketing.inbox.contact` | POST `inbox/{conversation}/contact` | `conversations.manage` | `contact_id` or `create`+`name` (needs `core.contacts.manage`) |
| `whatsapp_marketing.templates.index` | GET `templates` | `templates.view` | `Templates/Index`: `templates`, `categories`, `statuses`, `canManage` |
| `whatsapp_marketing.templates.store` / `.update` / `.status` / `.destroy` | POST `templates`, PUT `templates/{template}`, POST `templates/{template}/status`, DELETE | `templates.manage` | name, language, category, header, body, footer, buttons[], status, status_reason |
| `whatsapp_marketing.quick-replies.index` | GET `quick-replies` | `quick_replies.view` | `QuickReplies/Index` |
| `whatsapp_marketing.quick-replies.store` / `.update` / `.destroy` | POST, PUT/DELETE `quick-replies/{quickReply}` | `quick_replies.manage` | shortcut, title, body, is_active |
| `whatsapp_marketing.lists.index` / `.show` | GET `lists`, `lists/{list}` | `lists.view` | `Lists/Index`, `Lists/Show` (members with opt-in answer, candidates by `search`; members and `hiddenMembers` follow the viewer's `core.contacts.view` scope) |
| `whatsapp_marketing.lists.store` / `.update` / `.destroy` | POST, PUT/DELETE `lists/{list}` | `lists.manage` | name, description |
| `whatsapp_marketing.lists.members.store` / `.all` / `.destroy` | POST `lists/{list}/members`, POST `.../members/all`, DELETE `.../members/{member}` | `lists.manage` | `contact_ids[]` (max 500) |
| `whatsapp_marketing.campaigns.index` / `.show` | GET `campaigns`, `campaigns/{campaign}` | `campaigns.view` | `Campaigns/Index`, `Campaigns/Show` (recipient rows and `hiddenRecipients` follow the viewer's `core.contacts.view` scope; the state counts are aggregates) |
| `whatsapp_marketing.campaigns.create` / `.edit` | GET `campaigns/create`, `campaigns/{campaign}/edit` | `campaigns.manage` | `Campaigns/Form` |
| `whatsapp_marketing.campaigns.store` / `.update` / `.destroy` | POST, PUT/DELETE | `campaigns.manage` | name, template_id, list_id, variables |
| `whatsapp_marketing.campaigns.preview` | POST `campaigns/preview` | `campaigns.manage` | JSON `{body}` for sample values |
| `whatsapp_marketing.campaigns.send` / `.cancel` | POST `campaigns/{campaign}/send|cancel` (10/min) | `campaigns.send` | `scheduled_at?` |
| `whatsapp_marketing.flows.index` / `.store` / `.update` / `.destroy` | GET, POST, PUT/DELETE `flows` | `flows.manage` | `Flows/Index` |
| `whatsapp_marketing.analytics.index` | GET `analytics` | `analytics.view` | `Analytics/Index`: `days`, `overview`, `daily` |
| `whatsapp_marketing.settings.index` / `.update` | GET, PUT `settings` | `settings.manage` | `Settings/Index`: `settings`, `channel`, `agents`, `members`, `limits`, `guidance` |
| `whatsapp_marketing.agents.store` / `.update` | POST `settings/agents`, PUT `settings/agents/{agent}` | `settings.manage` | membership_id, is_available, max_open_conversations, is_active |

Provider events use the platform's routes `POST|GET /communication/webhooks/whatsapp_cloud` (signature and
handshake), see `docs/COMMUNICATION.md`.

## 13. Data

See `docs/DATABASE.md` section 3k2. Every table has `tenant_id` and tenant-first unique indexes. A reference to a
customer is a `contact_id` into the shared `contacts`. Money is not stored (no cost is computed).

## 14. Not built and owner decisions

- **No template creation or fetch through Meta's Management API.** Templates are entered by an administrator or
  updated by webhook events. Pulling the list would need the app to read the access token; the credential belongs
  to the communication app. Owner decision if wanted: add a `TemplateCatalogue` method to the core contracts.
- No media sending or downloading, no media/header templates, no interactive-message sending (buttons are shown
  from the template text; customer button/list replies arrive as text).
- No Business Management quality rating, messaging-limit tier or phone-number health. They are not reported, so
  they are not shown.
- No cost figures (the provider reports none in webhooks); only billable counts and pricing category.
- Broadcast to a segment of CRM data (leads, deals) is not built: lists hold contacts you add.
- The STOP keyword list is fixed in code (English, and the two Bengali words above); a tenant cannot edit it.
  `বন্ধ` also means "close/off" in everyday Bengali, so a customer writing only that word is unsubscribed: whether
  to keep it is an owner decision.
- Whether replying to an unknown number should auto-create a contact by default is an owner decision
  (the option exists, off by default).
- BSP (authorised solution provider) adapters: only the Meta Cloud API adapter exists.

## 15. Changes to the communication app made for this app (additive)

`WhatsAppCloudProvider` adapter (registered in `CommunicationServiceProvider::register()`), the GET handshake
route and `VerifiesWebhookSubscription`, `ProviderEvent::$accounts|$meta`, `ProviderEventType::Read|TemplateStatus`,
`ChannelEventRelay` and the core `ChannelEventListener`/`ChannelEvent`, `CredentialResolver::attribute()`,
`MarketingMessage::$purpose|$providerHints`, `MarketingGateway` pass-through, `config('communication.whatsapp')`.
Later (ADR 0013): `CredentialResolver` also resolves operator provider accounts (`acct_*`), including the
`webhook_secret` and `verify_token` of a WhatsApp account; the adapter itself is unchanged.
