# WhatsApp Business

The optional app `whatsapp_marketing` (name "WhatsApp Business", category WhatsApp marketing, one free offer
`included`) lives in `app/Modules/WhatsappMarketing` and is registered by `WhatsappMarketingServiceProvider`. It
depends on `core` and `communication` and imports nothing else. It adds approved-template campaigns to contact
lists (filled by hand or from contact rules), a shared inbox that enforces the 24-hour customer-service window, agent
assignment, internal notes, reply buttons and list messages, quick replies, declarative chatbot flows, optional
customer ratings, delivery and read tracking, and reports. Version 1.2.0 (1.1 and 1.2 additions are marked below;
1.2 adds the CRM bridge in section 16: broadcast to a CRM segment, capture inbound as CRM leads, and transactional
reminder/notification packs, all through the core CRM and communication contracts; and the platform feature layer in
section 5d (ADR 0022): several business numbers with a sending-number picker, interactive replies (buttons, lists,
call-to-action links) and media messages inside the 24-hour window, secure serving of inbound media, and templates
with a media header and button values). Tables are prefixed `whatsapp_`; routes are
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
| Reply from the unified inbox | `ConversationInboxSource` (also an `InboxReplies` source, ADR 0030) narrows the thread with `ConversationAccess`, checks `conversations.reply` on the record and calls `Messenger::reply()`, so every rule in this table applies. Outside the window the unified inbox disables its composer and points to this inbox for templates. Reading the conversation there clears its unread count through `ConversationManager::markRead()`, as this inbox's `read` does (`MarksInboxRead`), on the unified inbox's explicit POST `communication.inbox.read`, never on loading the page |
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
- **Internal notes** (1.1, `whatsapp_conversation_notes`): plain text of up to 2000 characters for the team, shown
  under the thread. Never sent, never on the customer's timeline; the audit trail records `whatsapp_marketing.note.added`
  / `.removed` with the note id, not the text. Writing needs `conversations.reply` on the conversation; the author
  removes their own note, someone with `conversations.manage` on it removes any. At most 200 notes per conversation.
- **AI reply draft** (1.1): "Draft with AI" in the composer asks the AI app (`POST /ai/drafts`, kind `draft_reply`,
  record type `whatsapp_conversation`) for a reply. The AI app reads the conversation through this app's read-only
  `ConversationReadTool` (`whatsapp_conversation_get`: customer name, status, tags and the last 20 messages, no
  notes), as the member and within their conversation scope, and only when the workspace enabled the AI data scope
  `whatsapp_conversations` (off by default, ADR 0018). The draft lands in the text box, labelled as AI-generated;
  the agent edits and sends it like any reply. The AI never sends anything itself. It needs `ai.drafts.generate`.
- Timeline: the platform writes the interaction entry for an inbound message whose sender it can match to a contact,
  and for an outbound message that has a contact. When it could not match the sender, this app writes the entry
  itself (`ConversationTimeline`), as the message arrives if it found or created a contact, and later as a queued
  back-fill (`BackfillConversationTimeline`) when a conversation without a contact gets one. That happens when a
  person links or creates the contact, or when the contact is created automatically on a later message (ADR 0020,
  decision 7). Outbound replies sent while a conversation had no contact are not back-filled.

## 5a. Reply buttons and list messages (1.1)

Inside the open customer-service window a reply may offer **up to 3 reply buttons** (titles up to 20 characters) or a
**list of up to 10 options** (titles up to 24) behind a button label (up to 20); the body is then at most 1024
characters. Labels must be distinct. The limits are Meta's and are checked by the composer, by
`Support\Interactive` on the server and again by the adapter, which sends Meta's `interactive` object of type
`button` or `list` (one section) from the provider hint `whatsapp_interactive`. The message is stored with type
`interactive` and the offered labels in `whatsapp_messages.interactive`; the thread shows them under the text.

The customer's tap arrives as an inbound `button_reply` / `list_reply` whose title becomes the message text, so it
is matched exactly like a typed answer. Interactive messages are free-form messages: outside the window only a
template can be sent, as before.

**Chatbot menus** are sent the same way: up to three labels of at most 20 characters become reply buttons, up to ten
labels of at most 24 characters become a list (button label "Choose" in the workspace's language), anything longer
stays numbered text. The customer may still answer with the number or by typing the label.

## 5b. Customer ratings (1.1)

Off unless **Settings > Ask for a rating after closing** (`whatsapp_settings.feedback_enabled`) is on. When a
**person** closes a conversation (not automation), and the conversation has a contact, a person answered it
(`first_responded_at`), the customer's 24-hour window is still open and no rating was asked for this conversation in
the last 24 hours, the customer gets a list message ("How would you rate the help you received?", options
`5 - Excellent` ... `1 - Very poor`, in the workspace language). It is a service message inside the window, never
marketing. A send that is refused or fails leaves no request behind; closing never fails because of it.

`whatsapp_feedback` keeps the request (`pending`, `answered` or `expired`), the agent who owned the conversation, the
rating and when it was asked and answered. The customer's next message answers it when it is a rating: a list row,
a typed `1` to `5` (Bengali digits too) or text starting with one (`4 stars`). The rating is recorded in the same
transaction that stores the message, the conversation **stays closed** and is not counted as unread, the customer is
thanked once, and no flow runs. Any other message ends the question (`expired`) and reopens the conversation as
usual, so a later "3" in an ordinary thread is never read as a rating. A request also lapses with the window.

The rating raises `whatsapp_marketing.feedback.received` (ids and the rating), shown on the conversation's side
panel and in Analytics (asked, answered, average, count per rating).

## 5c. Platform foundation for numbers, rich messages, media and templates (ADR 0022)

The communication platform offers four contracts this app builds on (`docs/COMMUNICATION.md` section 14a).
**Section 5d describes how this app adopted them (1.2).** What exists, and what an app must keep to when it adopts it:

- **Several numbers** (`ChannelNumberDirectory`, `ChannelNumberRegistry`): a workspace can register more WhatsApp
  numbers on its one connection; every number is claimed platform-wide. Send from one with
  `MarketingMessage::$senderNumberId`; events say which number they arrived on (`ChannelEvent::$numberId`). This app
  still keys conversations by customer phone only (`UNIQUE(tenant_id, phone)`), so a customer who writes to two numbers
  lands in one conversation; keying conversations by number is part of adopting the contract.
- **Rich messages** (`MarketingMessage::$whatsapp`): template with header media and button parameters, reply buttons,
  list, call-to-action URL, media, product, product list and Flow messages. Everything but a template follows the
  24-hour rules of section 4: pass `whatsapp_window_until` from `WindowTracker` exactly as a reply does, use
  `MessagingPurpose::Transactional` only for a reply to a customer-initiated chat, and expect
  `outside_customer_service_window` otherwise.
- **Media** (`MediaGateway`): `store` a file, put its id in a `MediaReference`; download an inbound image/document/
  audio/video from `ChannelEvent::$content->media` with `download()`. Serve downloads only through an authorized
  controller with `MediaContents::headers()`.
- **Template catalogue** (`TemplateCatalogue`): list, submit and delete templates in Meta's Management API without this
  app ever seeing a token; needs the connection's `business_account_id`. This app's own `whatsapp_templates` table
  remains the mirror used for sending; syncing it from the catalogue is not built.
- **Inbound content** (`ChannelEvent::$content`): button and list replies carry the chosen id and title (the chatbot
  engine still matches on the title text as before), plus location, reaction, order, Flow response and media facts.
  This app stores the `text` it always did; from 1.2 it also keeps an inbound media message's provider media id (so a
  controller can download and serve it later, section 5d). It does not read location, reaction, order or Flow content.

## 5d. The feature layer: numbers, interactive, media and rich templates (1.2, ADR 0022)

This app now uses the foundation of section 5c. None of it changes the connection, a credential or a secret: those
stay in the communication app. Everything free-form still obeys the 24-hour window of section 4.

- **Several business numbers.** **Settings > WhatsApp numbers** (`whatsapp_marketing.numbers.*`, permission reused
  from `whatsapp_marketing.settings.manage`) lists, adds, labels, enables/disables and re-defaults the workspace's
  numbers through `ChannelNumberRegistry`/`ChannelNumberDirectory`. Each number's claim/availability is shown
  honestly (default, active/inactive); the honest refusals (`account_in_use`, `label_in_use`, `in_use`, `is_default`,
  `inactive`, `not_connected`, …) come straight from the registry. **Conversations stay one thread per customer phone
  (`UNIQUE(tenant_id, phone)`)**; a number only decides which line a message goes out from. The inbox composer and the
  campaign form carry a number picker (the default is pre-selected; it appears only when the workspace has more than
  one number). The chosen number is sent as `sender_number_id` and becomes `MarketingMessage::$senderNumberId`; the
  gateway validates it and answers `sender_number_unavailable` for an unknown, foreign, disabled or removed number,
  recording nothing. A campaign stores its sending number (`whatsapp_campaigns.sender_number_id`).
- **Interactive replies inside the window** (`RichComposer`, `whatsapp_marketing.inbox.{interactive,cta}`): up to three
  reply buttons, a list of up to ten rows behind a button label, and a call-to-action URL button, each built as a
  `Data\Whatsapp\WhatsappContent` and sent through the gateway with `MessagingPurpose::Transactional` and
  `whatsapp_window_until`. Button/list ids are generated (`btn-N`, `row-N`); the customer's tap comes back as the
  label's text (section 5a) and is matched as before. Reply buttons and lists are stored on the message's
  `interactive` column so the thread renders the offered choices; outside the window only a template is allowed.
- **Media** (`whatsapp_marketing.inbox.media`): send an image, document, video or audio with a caption, from an
  uploaded file (stored through `MediaGateway::store`, which sniffs and size-caps it) referenced by a `MediaReference`,
  inside the window. Inbound media is downloaded **on demand** and served by `whatsapp_marketing.messages.media`
  (`MediaController`): it is tenant-scoped (the message resolves within the active tenant), access-checked
  (`conversations.view` on the conversation) and answered with `MediaContents::headers()` (sniffed type, `nosniff`,
  forced download, sandboxing CSP). The thread shows media bubbles with a download link for an inbound file.
- **Templates with a media header and button variables** (`Messenger::sendTemplate`, campaigns and the single send
  `whatsapp_marketing.inbox.rich-template`): an approved template may be sent with a media header (an uploaded image,
  video or document) and dynamic button values (`url` suffix, `quick_reply` payload or `copy_code`), built as
  `WhatsappContent::template(...)`. A campaign carries one fixed header (`header_media_type`, `header_media_id`) and
  fixed button values (`button_params`) for every recipient. A plain template (no header media, no button values) still
  takes the older provider-hint path unchanged.

Still not adopted (section 14): product and Flow messages, keying conversations by number, number-level reporting or
a price for extra numbers, and syncing the template mirror from `TemplateCatalogue`.

## 6. Campaigns

A campaign is a template + a list + a mapping of each numbered variable to an allow-listed field or fixed text.

**Lists from rules (1.1).** Besides picking contacts and "add all contacts I can see", a list can be filled once
from declarative rules (`Support\ContactRules`, match every or any rule, at most 10): city, country or job title
(is, is not, contains, filled in, empty), has a company, contact added on or after / on or before a date, WhatsApp
conversation tag (is / is not), wrote to us within N days (1 to 365), has written to us at all. Only contacts the
member may see (`core.contacts.view` scope) with a phone number are added, up to the list cap; contacts that match
later are not added by themselves. Consent is deliberately not a rule: the platform decides it at snapshot and send
time. Route `POST lists/{list}/members/rules` (`lists.manage`, 10 per minute).

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
| `whatsapp_marketing.message.received` | trigger | `conversations.view` | ids only (no text): conversation, message, contact, owner, type, new conversation; filter `new_conversation: true` for a number's first message |
| `whatsapp_marketing.message.read` | trigger | `conversations.view` | the provider reported a read |
| `whatsapp_marketing.conversation.assigned` | trigger | `conversations.view` | conversation, contact, owner, previous owner, source |
| `whatsapp_marketing.campaign.completed` | trigger | `campaigns.view` | totals by outcome |
| `whatsapp_marketing.feedback.received` | trigger | `conversations.view` | 1.1: feedback, conversation, contact, owner, rating; filter `minimum_rating` / `maximum_rating` (`maximum_rating: 2` = poor ratings only) |
| `whatsapp_marketing.template.send` | action | `conversations.reply` | contact, template, string parameters; returns `state` (`queued`, `sent`, `blocked`, `refused` ...) and `reason`; a missing opt-in is a stored `blocked` output |
| `whatsapp_marketing.conversation.assign` | action | `conversations.assign` | named agent or the least loaded |
| `whatsapp_marketing.conversation.add_tag` | action | `conversations.manage` | |
| `whatsapp_marketing.send_order_confirmation` | action | `conversations.reply` | 1.2: order-confirmation pack. contact, template, string parameters; same output and consent rules as `template.send` |
| `whatsapp_marketing.send_invoice_notification` | action | `conversations.reply` | 1.2: invoice/payment-notification pack |
| `whatsapp_marketing.send_appointment_reminder` | action | `conversations.reply` | 1.2: appointment-reminder pack (values from the event; no booking data is invented) |
| `whatsapp_marketing.send_payment_reminder` | action | `conversations.reply` | 1.2: payment-reminder pack, also the generic scheduled reminder (see section 16) |

The four `send_*` packs (1.2) are template sends with their own idempotency namespace: each takes `contact_id`,
`template_id` and string `parameters` from the triggering event (or manual input), sends one approved template
through the gateway and records a receipt, so a retried step never sends twice. They never invent order, invoice or
cart data. Consent, suppression and the 24-hour window are the platform's, exactly as for `template.send`.

Actions write `whatsapp_automation_receipts` (`UNIQUE(tenant_id, action, idempotency_key)`) so a retried step
returns the first output. They never read the signed-in user.

**Recipes with other apps (workflows, nothing hard-wired):**

| Need | Trigger | Action |
|---|---|---|
| Lead capture | `whatsapp_marketing.message.received` with `new_conversation: true` | `crm.lead.create` (`contact_id` from the trigger; an open lead of the contact is reused). The conversation needs a contact: switch on "Add unknown numbers to contacts" |
| Invoice notification | `invoicing.invoice.issued` | `whatsapp_marketing.template.send` with an approved utility template |
| Payment reminder | `invoicing.invoice.overdue` | `whatsapp_marketing.template.send` |
| Order confirmation | `crm.quote.accepted` (sales order confirmed) | `whatsapp_marketing.template.send` |
| Order delivered / cancelled | `crm.sales_order.status_changed` with `status: fulfilled` or `canceled` | `whatsapp_marketing.template.send` |
| Follow up a poor rating | `whatsapp_marketing.feedback.received` with `maximum_rating: 2` | `crm.activity.create_follow_up` |

Outside the customer's own 24-hour window a template is marketing (section 4): the customer needs a recorded opt-in,
otherwise the step's output is `blocked`. The utility label does not exempt it.

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

New permissions are granted to no role. The 1.2 CRM bridge adds no permission: its page is gated by
`conversations.view`, lead capture by `settings.manage`, reminders by `conversations.reply` and the segment routes by
`campaigns.manage`. Navigation (section `apps`): inbox, campaigns, templates, lists, quick
replies, analytics, crm; (section `manage`): flows, settings. Widget `whatsapp_marketing.overview` (component
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
| `whatsapp_marketing.inbox.reply` | POST `inbox/{conversation}/messages` (60/min) | `conversations.reply` | `body` (max 4096, 1024 with choices), optional `interactive{kind: buttons\|list, button?, options[]}` |
| `whatsapp_marketing.inbox.template` | POST `inbox/{conversation}/templates` (30/min) | `conversations.reply` | `template_id`, `variables[]` |
| `whatsapp_marketing.inbox.assign` / `.claim` | POST `inbox/{conversation}/assign|claim` | `conversations.assign` | `assignee_id?` |
| `whatsapp_marketing.inbox.status` | POST `inbox/{conversation}/status` | `conversations.manage` | `status` open/closed |
| `whatsapp_marketing.inbox.tags` | POST `inbox/{conversation}/tags` | `conversations.manage` | `tags[]` |
| `whatsapp_marketing.inbox.contact` | POST `inbox/{conversation}/contact` | `conversations.manage` | `contact_id` or `create`+`name` (needs `core.contacts.manage`) |
| `whatsapp_marketing.inbox.notes.store` | POST `inbox/{conversation}/notes` (30/min) | `conversations.reply` | `body` (max 2000) |
| `whatsapp_marketing.inbox.notes.destroy` | DELETE `inbox/{conversation}/notes/{note}` | `conversations.view` + author, or `conversations.manage` on it | none |
| `whatsapp_marketing.inbox.interactive` | POST `inbox/{conversation}/interactive` (60/min) | `conversations.reply` | 1.2: `body`, `kind` buttons/list, `button?`, `options[]`, `sender_number_id?` |
| `whatsapp_marketing.inbox.cta` | POST `inbox/{conversation}/cta` (60/min) | `conversations.reply` | 1.2: `body`, `label`, `url`, `sender_number_id?` |
| `whatsapp_marketing.inbox.media` | POST `inbox/{conversation}/media` (60/min) | `conversations.reply` | 1.2: `type`, `file` or `media_id`, `caption?`, `sender_number_id?` |
| `whatsapp_marketing.inbox.rich-template` | POST `inbox/{conversation}/rich-template` (60/min) | `conversations.reply` | 1.2: `template_id`, `variables[]`, `header_type?`+`header_file?`/`header_media_id?`, `buttons[]?`, `sender_number_id?` |
| `whatsapp_marketing.messages.media` | GET `messages/{message}/media` | `conversations.view` | 1.2: serves an inbound media file (tenant-scoped, access-checked, safe headers) |
| `whatsapp_marketing.templates.index` | GET `templates` | `templates.view` | `Templates/Index`: `templates`, `categories`, `statuses`, `canManage` |
| `whatsapp_marketing.templates.store` / `.update` / `.status` / `.destroy` | POST `templates`, PUT `templates/{template}`, POST `templates/{template}/status`, DELETE | `templates.manage` | name, language, category, header, body, footer, buttons[], status, status_reason |
| `whatsapp_marketing.quick-replies.index` | GET `quick-replies` | `quick_replies.view` | `QuickReplies/Index` |
| `whatsapp_marketing.quick-replies.store` / `.update` / `.destroy` | POST, PUT/DELETE `quick-replies/{quickReply}` | `quick_replies.manage` | shortcut, title, body, is_active |
| `whatsapp_marketing.lists.index` / `.show` | GET `lists`, `lists/{list}` | `lists.view` | `Lists/Index`, `Lists/Show` (members with opt-in answer, candidates by `search`; members and `hiddenMembers` follow the viewer's `core.contacts.view` scope) |
| `whatsapp_marketing.lists.store` / `.update` / `.destroy` | POST, PUT/DELETE `lists/{list}` | `lists.manage` | name, description |
| `whatsapp_marketing.lists.members.store` / `.all` / `.destroy` | POST `lists/{list}/members`, POST `.../members/all`, DELETE `.../members/{member}` | `lists.manage` | `contact_ids[]` (max 500) |
| `whatsapp_marketing.lists.members.rules` | POST `lists/{list}/members/rules` (10/min) | `lists.manage` | `match` all/any, `rules[]{field, operator, value}` (max 10) |
| `whatsapp_marketing.campaigns.index` / `.show` | GET `campaigns`, `campaigns/{campaign}` | `campaigns.view` | `Campaigns/Index`, `Campaigns/Show` (recipient rows and `hiddenRecipients` follow the viewer's `core.contacts.view` scope; the state counts are aggregates) |
| `whatsapp_marketing.campaigns.create` / `.edit` | GET `campaigns/create`, `campaigns/{campaign}/edit` | `campaigns.manage` | `Campaigns/Form` |
| `whatsapp_marketing.campaigns.store` / `.update` / `.destroy` | POST, PUT/DELETE | `campaigns.manage` | name, template_id, list_id, variables |
| `whatsapp_marketing.campaigns.preview` | POST `campaigns/preview` | `campaigns.manage` | JSON `{body}` for sample values |
| `whatsapp_marketing.campaigns.send` / `.cancel` | POST `campaigns/{campaign}/send|cancel` (10/min) | `campaigns.send` | `scheduled_at?` |
| `whatsapp_marketing.flows.index` / `.store` / `.update` / `.destroy` | GET, POST, PUT/DELETE `flows` | `flows.manage` | `Flows/Index` |
| `whatsapp_marketing.campaigns.segments` | GET `campaigns/segments` | `campaigns.manage` | JSON: the CRM segments the member may broadcast to (1.2) |
| `whatsapp_marketing.campaigns.from_segment` | POST `campaigns/from-segment` | `campaigns.manage` | `name`, `template_id`, `segment_id`, `variables`; freezes the segment to a list and creates a draft (1.2) |
| `whatsapp_marketing.analytics.index` | GET `analytics` | `analytics.view` | `Analytics/Index`: `days`, `overview`, `daily` |
| `whatsapp_marketing.crm.index` | GET `crm` | `conversations.view` | `Crm/Index`: lead-capture gate, recent captures, the reminder sender (1.2) |
| `whatsapp_marketing.crm.contacts` | GET `crm/contacts` | `conversations.reply` | JSON contact search for the reminder composer (1.2) |
| `whatsapp_marketing.crm.reminders` | POST `crm/reminders` (30/min) | `conversations.reply` | `reminder_type`, `template_id`, `contact_id`, `variables` (1.2) |
| `whatsapp_marketing.crm.lead_capture` | PUT `crm/lead-capture` | `settings.manage` | `enabled`, `keywords[]` (1.2) |
| `whatsapp_marketing.settings.index` / `.update` | GET, PUT `settings` | `settings.manage` | `Settings/Index`: `settings`, `channel`, `agents`, `members`, `limits`, `guidance` |
| `whatsapp_marketing.agents.store` / `.update` | POST `settings/agents`, PUT `settings/agents/{agent}` | `settings.manage` | membership_id, is_available, max_open_conversations, is_active |
| `whatsapp_marketing.numbers.index` | GET `settings/numbers` | `settings.manage` | 1.2: `Settings/Numbers`: `numbers`, `channel`, `guidance` |
| `whatsapp_marketing.numbers.store` / `.update` / `.default` / `.destroy` | POST `settings/numbers`, PUT `settings/numbers/{number}`, POST `.../default`, DELETE | `settings.manage` | 1.2: account_id, label, display_number, make_default / is_active (via `ChannelNumberRegistry`) |

Provider events use the platform's routes `POST|GET /communication/webhooks/whatsapp_cloud` (signature and
handshake), see `docs/COMMUNICATION.md`.

## 13. Data

See `docs/DATABASE.md` section 3k2. Every table has `tenant_id` and tenant-first unique indexes. A reference to a
customer is a `contact_id` into the shared `contacts`. Money is not stored (no cost is computed).

1.2 (ADR 0022) adds nullable columns to `whatsapp_campaigns`: `sender_number_id` (the business number to send from,
a `ChannelNumber::$id`), `header_media_type` + `header_media_id` (a fixed media header, the id being a communication
media id — no path, no token) and `button_params` (fixed template button values). Inbound media messages keep the
provider media id and the receiving number on the message's `attachments` JSON (metadata only; the bytes are
downloaded on demand and never stored on the message). No new table is added; numbers and media live in the
communication app.

## 14. Not built and owner decisions

- **This app does not yet use the template catalogue.** The platform offers it since ADR 0022 (section 5c): list,
  submit and delete through Meta's Management API with no token in this app. Templates here are still entered by an
  administrator or updated by webhook events; syncing the mirror from the catalogue and a submit screen are not built.
- **Media, call-to-action and header/button templates are built in 1.2** (section 5d). Media, reply buttons, lists and
  call-to-action URLs are sent as `WhatsappContent`; inbound media is downloaded on demand and served securely; an
  approved template can carry a media header and dynamic button values, in single sends and campaigns. **Product and
  Flow messages are still not built** (no commerce or booking app drives them), and the template-catalogue sync
  (section 5c) is still not built: templates are entered by an administrator or updated by webhook events.
- **Owner decisions recorded in ADR 0021**, not built: the AI answering customers on its own, product catalogue
  and cart (no commerce app exists), appointment reminders (no booking app exists) and Flow authoring. Several business
  numbers with a sending-number picker now exist in this app (1.2, section 5d); still not adopted are keying
  conversations by number (conversations stay one per customer phone), number-level reporting, and any limit or price
  for extra numbers.
- No Business Management quality rating, messaging-limit tier or phone-number health. They are not reported, so
  they are not shown.
- No cost figures (the provider reports none in webhooks); only billable counts and pricing category.
- Broadcast to a segment of CRM data (leads, deals, tags, saved segments, customer lifecycle) is built in 1.2
  through the `CrmAudience` contract (section 16). It freezes the segment into a list once, scoped to the launcher;
  a campaign does not follow the segment afterwards, and a hand-built list still does not follow its rules.
- **Abandoned cart recovery is not built as a cart feature.** No commerce app exists, so the platform holds no
  cart, order or basket data. "Abandoned cart recovery" is therefore available only as a generic scheduled reminder
  (the `send_payment_reminder` pack and a workflow on a time or an external event); wiring it to real cart data is
  an owner decision that waits for a commerce app. Nothing here fakes a cart.
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
1.1: the adapter reads the hint `whatsapp_interactive` (JSON `{kind, button, options}`) and sends Meta's
`interactive` object of type `button` or `list`, refusing locally (`invalid_interactive`) anything outside the limits.
Later (ADR 0013): `CredentialResolver` also resolves operator provider accounts (`acct_*`), including the
`webhook_secret` and `verify_token` of a WhatsApp account; the adapter itself is unchanged.
ADR 0022 (additive; adopted by this app in 1.2, section 5d): `ChannelNumberDirectory`, `ChannelNumberRegistry`,
`MediaGateway`, `TemplateCatalogue`, `MarketingMessage::$senderNumberId|$whatsapp`, `SendReceipt::$senderNumberId`,
`ChannelEvent::$numberId|$content|$pricingCategory`, tables `communication_channel_numbers`, `communication_media`,
`communication_media_uploads`, `communication_messages.sender_number_id`, `communication_account_claims.number_id`
(`docs/COMMUNICATION.md` section 14a).

## 16. CRM bridge (1.2)

This app bridges to the CRM and to transactional messaging through the core contracts only (`App\Core\Crm` and
`App\Core\Communication`). It imports no CRM model and holds no CRM data; it references CRM leads and contacts by
id (ADR 0023). New code lives in `app/Modules/WhatsappMarketing/Crm` and `.../Reminders`; nothing in the inbox,
composer, campaigns or chatbot internals changed. Tables `whatsapp_lead_capture_settings` and
`whatsapp_captured_leads` (tenant-first, guarded migration) carry the gate and the capture record (ids only).

**Broadcast to a CRM segment.** The campaign form's audience step offers, besides a hand-built list, a CRM segment
(lead status, deal stage, tag, saved segment, customer lifecycle) read through `CrmAudience`. The form loads the
segments with their approximate (scope-narrowed) size from `GET /whatsapp/campaigns/segments` and shows a "scoped to
what you can see" note. `POST /whatsapp/campaigns/from-segment` (`campaigns.manage`) resolves the recipients with
`CrmAudience::contactIds(..., viewer: the launching member)` so only contacts in that member's CRM record scope are
reached, freezes them into a new contact list, and creates a normal draft campaign on it. The existing recipient
pipeline is unchanged, so consent and suppression are still decided by the platform at snapshot and send time. With
no CRM the segment list is empty and the form falls back to lists.

**Lead capture from WhatsApp.** A workspace setting, **CRM & reminders > Capture inbound as CRM leads** (off by
default, `whatsapp_lead_capture_settings`), turns an inbound conversation into a CRM lead. A listener on the existing
`MessageReceived` domain event (never the inbound handler or the chatbot) calls
`LeadIntake::capture(source 'whatsapp', idempotencyKey 'wa-lead:{message id}')` when the message starts a new
conversation (an unknown number) or contains a configured keyword. The contract dedupes the contact, so no contact
is ever duplicated; capture is idempotent per message. The conversation is tagged `crm-lead` and the capture is
recorded (conversation, message, contact id, lead id). With no CRM the contract answers `unavailable` and nothing
happens. The older automation recipe (`message.received` → `crm.lead.create`) still works for workflow-driven
capture; this setting is the zero-config path.

**Transactional reminder and notification packs.** Four automation actions — `send_order_confirmation`,
`send_invoice_notification`, `send_appointment_reminder`, `send_payment_reminder` (section 8) — send an approved
template to a contact with values mapped from the triggering event, idempotent per receipt. The same packs can be
sent by hand from **CRM & reminders** (`POST /whatsapp/crm/reminders`, `conversations.reply`): pick a type, a
template, a contact and values (allow-listed fields or fixed text, like a campaign). Purpose `transactional` is used
only for a template inside the customer's open 24-hour window; otherwise it is marketing and needs an opt-in, which
the gateway enforces. Abandoned cart recovery is not built as a cart feature (section 14): no commerce app holds cart
data, so it is available only as a generic scheduled reminder.
