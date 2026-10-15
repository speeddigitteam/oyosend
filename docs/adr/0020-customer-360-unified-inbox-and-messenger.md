# ADR 0020 — Customer 360 and the unified inbox are tagged read providers joined only by the shared contact; Messenger is a separate conversational app

- **Status:** Accepted by the owner on 2026-10-13, except the four open decisions at the end, which are still open.
  Messenger (decision 6) waits on open decisions 1 and 2. Rollout steps 1 to 3 are built (see "Rollout"); step 4
  (Messenger) is not.
- **Date:** 2026-10-13
- **Phase:** not assigned. Builds on ADR 0010, 0013, 0014, 0015 and 0016.

## Context

The owner wants a unified inbox, the WhatsApp inbox board and the CRM sales board to work together: one customer's
chat history, leads, deals, tasks and follow-ups visible in one place, while each module keeps its own responsibility.
Facebook Messenger is to be one of the inbox channels.

What exists today:

- One customer identity: core `contacts` / `companies`. `whatsapp_conversations.contact_id`, `support_tickets.contact_id`
  and CRM leads, deals and activities (`crm_activities` types include `task` and `follow_up`) already point at it.
- The shared interaction timeline (`InteractionRecorder`, `communication_interactions`). The platform writes one entry
  per inbound message when it can resolve the sender to a contact.
- Two boards: the WhatsApp inbox board (`InboxController::index`, `?view=kanban`: unassigned, active, closed) and the
  CRM deal board (`/crm/deals?view=board`).
- The CRM customer page (`crm.sales.customers.show`) shows only CRM's own timeline. CRM listens to no Communication,
  WhatsApp or Support event. There is no core contact detail page.

What is missing:

- No contract lets one module ask another "what do you hold about this contact". The only per-contact hook,
  `ContactMergeReference`, is write-only.
- No cross-channel inbox.
- Messenger is not a channel: `MarketingChannel` is `email`, `sms`, `whatsapp`, and the Social app publishes posts
  but reads no messages (`docs/SOCIAL.md`, "inbox and listening" not built).
- A gap: the first inbound WhatsApp message from an unknown number never reaches the timeline.
  `WebhookProcessor` writes the entry (`:258-262`) before it relays the event (`:65-66`), and WhatsApp creates the
  contact only during the relay (`ConversationManager.php:88`).

Constraints that hold: modules import no other module's models (ADR 0014). Record scope is each module's duty
(ADR 0015). A channel is honestly `available` or `not_configured`. Operator credentials stay in the environment.
Meta allows one webhook callback URL per app.

## Decision

1. **The shared contact is the only join.** There is no new customer table and no copy of another module's
   records. Each module keeps owning its rows, keyed by `contact_id` / `company_id`.

2. **Customer 360 is a set of tagged, read-only providers, on the pattern of ADR 0015.**
   - **Contract.** `App\Core\Contacts\Contracts\CustomerRecordProvider`, tag `contacts.customer_record_providers`
     (placed beside the contact directory it reads about, not in a new core folder), with two methods:
     - `definition()`: key `{module}.{section}`, module, label key, permission and order.
     - `forContact(CustomerQuery): CustomerSection`. The query carries the member, `contactId`, optional
       `companyId` and a small limit (default 5). The section returns summary items: id, title, status, UTC time,
       and the URL of the owning module's own page. It also returns a total and a "view all" URL.
   - **Registry.** A provider is offered only when the workspace is entitled to its module and the member holds
     its permission. Both are read from the database, never from the request.
   - **Provider duties.** A provider narrows its rows with `RecordAccess`, changes nothing, and returns `null` for
     "not reported".
   - **First providers:**
     - CRM: leads, open deals with their stage, open tasks and follow-ups.
     - WhatsApp: conversations with their last messages.
     - Support: tickets.
     - Communication: the interaction timeline.
   - **Where it shows.** A core `CustomerPanel` component renders the sections, on the CRM customer page and beside
     a conversation in the inbox. A core contact page is optional and later.

3. **Actions from a chat are links into the owning module, not cross-module writes.**
   - **Declared actions.** A provider may declare actions as a label and a URL, and only those the member may use.
     CRM's actions are "create lead", "create deal" and "add task / follow-up".
   - **The owning module does the work.** The URL opens CRM's own form, prefilled with the contact. CRM validates,
     authorises, audits and raises its events as it does today. CRM forms must accept that prefill.
   - **Automatic leads are a workflow.** "Create a lead when an unknown number writes" is not hard-coded. It is an
     Automation workflow: the existing WhatsApp `MessageReceived` trigger plus a new, receipt-backed, idempotent
     CRM action `crm.lead.create`. There is no such action today.
   - **No new write contract** in the first version.

4. **The unified inbox is a set of tagged inbox sources, merged by the Communication module.**
   - **Contract.** `App\Core\Communication\Contracts\InboxSource`, tag `communication.inbox_sources`, with
     `definition()` (key, module, channel, label, permission) and `page(InboxQuery): InboxPage`.
   - **What a source returns.** Normalised summaries, each with:
     - id and channel;
     - the contact, or an address label when the conversation is not linked to one;
     - a plain-text preview of at most 140 characters, and the last-activity time;
     - the owner and an unread count;
     - a state mapped by the source to `unassigned`, `active` or `closed`;
     - the URL of the owning app's conversation view.

     The cursor is `(lastActivityAt, id)`. Each source applies the channel, owner, state and search filters itself.
   - **The page.** Communication merges the sources by last activity and offers a list and a board with the same
     three columns as the WhatsApp board. Gating follows decision 2. Communication imports nothing from the source
     apps.
   - **Replies stay in the owning app.** Opening a conversation goes to the app that owns it, and that app replies
     through `ChannelGateway`. An embedded reply pane would need a reply contract and is out of scope.
     *Amended by ADR 0030: a source may implement `InboxReplies`; its conversations are then read and answered in the
     inbox, and the reply still goes through the owning app's own action.*
   - **Source order:** WhatsApp first, then Support (email tickets), then Messenger.

5. **The inbox board and the sales board stay separate.** One shows who is answering a conversation; the other shows
   where a sale stands. A conversation is not a deal, so their columns are not merged. They meet only in the Customer
   360 panel. Showing the last chat time on a deal card is not part of this decision: it would mean one lookup per
   card across modules.

6. **Messenger is a conversational channel, delivered as its own optional app.**
   - **Channel.** `MarketingChannel` gains a `messenger` case. Renaming the enum is not worth the churn.
     - Messenger accepts only `Transactional` replies inside Meta's 24-hour window; the outbox refuses `Marketing`
       for it.
     - Message tags outside the window, such as `HUMAN_AGENT`, are a later decision that needs Meta's approval.
     - `InteractionEntry::TYPES` gains `messenger`.
   - **Identity.**
     - The sender address is a page-scoped id (PSID), which is never matched to a contact automatically:
       `ChannelEvent::$contactId` stays null.
     - A person links or creates the contact, as with WhatsApp's `linkContact` / `createContact`.
     - The app then writes the conversation's timeline entries itself, as in decision 7.
     - The same person on WhatsApp and Messenger is joined by that manual link or by the existing contact merge.
   - **Connection.**
     - Per ADR 0014 section 2, the connection belongs to Communication: a workspace connects its Facebook Page by
       OAuth, with the same state and broker safeguards as Social (`docs/SECURITY.md` 5d.5).
     - The Page access token is stored encrypted, hidden and write-only on the workspace's connection.
     - The Meta app id and secret are environment variables (`COMMUNICATION_MESSENGER_*`).
     - The Page id is claimed in `communication_account_claims` (ADR 0016), so one Page belongs to one workspace.
   - **Webhook.** Meta sends every Page's events for one app to one callback URL, so the per-workspace URL used for
     WhatsApp cannot work. A central callback route (no session) does three things in order:
     1. Verifies `X-Hub-Signature-256` over the raw body with the app secret.
     2. Resolves `page_id` → claim → connection → workspace.
     3. Hands the event over inside `TenantContext::run()`.

     This is a cross-workspace lookup from a central route and needs owner approval (open decision 1).
   - **Not inside Social.** Social publishes posts; Messenger answers people. They have different permissions,
     limits and licensing, and Social's Facebook connection is not reused, since no module imports another's model.
     The cost is that a workspace connects its Page twice if it uses both apps.
   - **Honest until approved.** Until Meta approves `pages_messaging` and `pages_manage_metadata` for the operator's
     app, Messenger shows `not_configured`.

7. **The timeline gap is closed by the app, only for messages the platform skipped.**
   - The platform writes an inbound entry only when it resolves the sender to a contact
     (`ChannelEvent::$contactId`), keyed `inbound:{provider}:{eventId}` (`WebhookProcessor.php:273`).
   - When it could not, the app writes the entry through `InteractionRecorder` with its own per-message key
     (`whatsapp:inbound:{messageId}`). This happens as the message arrives, if the app found or created a contact,
     and again as a queued back-fill when a conversation without a contact gets one: linked or created by a person,
     or created automatically on a later message.
   - The two sets never overlap. A conversation without a contact has no platform entries, and the per-message key
     makes a repeated back-fill a no-op.
   - Built for WhatsApp in rollout step 1 (`ConversationTimeline`, `BackfillConversationTimeline`). Outbound replies
     to a conversation without a contact still get no entry (`Outbox` writes only for a message with a contact).

## Rollout

Each step is a separate change with its own tests, docs and review:

1. The timeline gap (decision 7).
2. `CustomerRecordProvider`, its registry and `CustomerPanel`; providers for CRM, WhatsApp, Support and
   Communication; CRM forms accept the prefill.
   - **Built:** `CustomerRecordRegistry` and the JSON endpoint `GET /contacts/{contact}/records`
     (`core.contacts.records`, `core.contacts.view` plus the contact's record scope, 60 per minute).
   - **Six sections:** `crm.leads`, `crm.deals`, `crm.activities`, `whatsapp_marketing.conversations`,
     `support.tickets`, `communication.timeline`.
   - **Panel.** `Core/Components/Contacts/CustomerPanel.vue` is shown on the CRM customer page (contact profiles
     only) and beside a WhatsApp conversation that has a contact.
   - **CRM prefill.** The lead, deal and activity lists accept `?create_for={contact}`. They open the create form
     with that contact, keep it in the options past the 500 cap, and drop the parameter from the address.
   - **Not built:** company subjects (a company profile shows no panel); a core contact page.
3. `InboxSource` and the unified inbox (list and board), with WhatsApp first and Support second.
   - **Built:** core `InboxSource` (`App\Core\Communication\Contracts`) with `InboxQuery`, `InboxItem`,
     `InboxCursor` and `InboxState`.
   - **Merging:** `UnifiedInbox` in the Communication app merges the sources, keeping one paging position per source.
   - **Page:** `/communication/inbox` has a list and a board.
   - **Permission:** the new `communication.inbox.view`, granted to no role by default (Communication 1.1.0).
   - **Sources:** `whatsapp_marketing.conversations` and `support.tickets`. See `docs/COMMUNICATION.md` 8a.
4. The Messenger app, only after the owner settles open decisions 1 and 2 and Meta approves the app.

## Consequences

- A module joins Customer 360 or the inbox by writing a provider and one `tag()` line. Neither core nor
  Communication changes per channel.
- **Scope rests on each provider.** As with reports, the registry cannot prove a provider narrowed its query. Every
  provider needs a scope test, and every new route that takes an id needs `assertNotFoundFromOtherTenant()`.
- **Cost.** A 360 view runs one bounded query set per provider. The inbox fetches `limit + 1` rows per source per
  page, which is fine for two to four sources and must be re-measured beyond that.
- **Unlinked Messenger chats.** Messenger conversations stay unlinked until a person links them, so a Messenger
  chat does not show on a customer's 360 view until then.
- **Previews.** Message previews appear only to members who may already open that conversation. They are never put
  in events or logs; WhatsApp events already carry no text.
- **No import enforcement.** No automated architecture test forbids cross-module imports. This is the same open item
  as in ADR 0014.
- **Unreviewed foundation.** The work builds on unreviewed Phase 9–12 modules (Communication, WhatsApp, Support).
  Defects found in them are fixed in those modules first.

## Open decisions for the owner

1. **The central Messenger webhook route** that resolves a Page id to a workspace (decision 6). The alternative is
   one Meta app per workspace with its own URL. That is impractical, because each app needs its own App Review for
   `pages_messaging`.
2. **Storing a workspace's Messenger Page token encrypted in Communication.** This follows the `social_accounts`
   pattern, but it is a workspace OAuth token outside ADR 0013's operator accounts. `CLAUDE.md` lists credential
   storage exceptions explicitly, so this needs a yes.
3. **Support tickets in the unified inbox.** They carry SLAs and statuses that a chat does not have.
   *Decided 2026-10-13: yes, as the second source.*
4. **Where the unified inbox lives.** The recommendation is the Communication app. The alternative is a separate
   optional app that depends on it. *Decided 2026-10-13: the Communication app.*

## Alternatives considered

- **A customer aggregate table that copies leads, deals and messages.** Rejected: a second source of truth that
  drifts, and a new leak point.
- **CRM reads WhatsApp and Support tables directly.** Rejected: model imports and duplicated scope logic.
- **One board for conversations and deals.** Rejected: they have different lifecycles and owners.
- **Messenger inside the Social app.** Rejected: it mixes publishing with customer conversations under one
  permission set and licence.
- **Matching Messenger senders to contacts by profile name.** Rejected: names are not unique, so it would merge the
  wrong people.
- **Inline "quick create" in the inbox that posts to CRM's routes.** Deferred: the inbox would have to know CRM's
  fields and validation. Revisit with a write contract and idempotency keys if links prove too slow for agents.
