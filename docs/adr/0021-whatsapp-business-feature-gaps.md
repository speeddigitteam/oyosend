# ADR 0021 — WhatsApp Business 1.1 closes the gaps that fit the architecture; six larger gaps wait for owner decisions

- **Status:** Proposed. Part A is built (WhatsApp Business 1.1.0). Part B lists six owner decisions; nothing in
  Part B is built.
- **Date:** 2026-10-13
- **Phase:** not assigned. Builds on ADR 0013, 0014, 0017, 0018 and 0020.

## Context

The owner compared the WhatsApp Business app with a common feature list (multiple numbers, shared team inbox, AI
chatbot and auto reply, template management, opted-in bulk campaigns, segmentation, lead capture and CRM sync, order
and invoice notifications, abandoned carts, appointment and payment reminders, assignment and collaboration, tags,
notes and history, interactive buttons and lists, WhatsApp Flows, product catalogue and product messages, customer
feedback and surveys) and asked for everything that is needed.

Before 1.1 the app had the shared inbox, assignment, tags, history, approved-template mirrors, opted-in campaigns,
quick replies and keyword chatbot flows. Missing were internal notes, sending interactive messages, rule-based list
filling, AI help, lead capture, order triggers, and customer ratings. Some of the remaining items cannot be built
without changing a platform rule or adding a whole new app.

## Decision

### Part A: built in WhatsApp Business 1.1 (and small additions to CRM, AI, Automation and Communication)

1. **Interactive messages.** Reply buttons (up to 3) and single-section lists (up to 10) inside the open
   customer-service window, from agents and from chatbot menus. They pass through the existing provider-hint
   channel (`whatsapp_interactive`) of the `ChannelGateway`; the adapter builds Meta's `interactive` object. No
   contract changed.
2. **Internal notes** on conversations, team-only, audited without content.
3. **Customer ratings** after a person closes a conversation, opt-in per workspace, only inside the open window
   (a service message, never marketing), answered by the customer's next message, with a
   `whatsapp_marketing.feedback.received` trigger and analytics.
4. **Lists from contact rules**: a one-time fill from a closed rule vocabulary. Consent is never a rule; the platform
   keeps deciding it at snapshot and send time.
5. **AI reply drafts** through the existing AI drafting service and a read-only WhatsApp `AiTool` behind a new,
   opt-in data scope. The agent sends; the AI never does (ADR 0018 unchanged).
6. **Lead capture and order notifications as workflows**, not module coupling: CRM gains the `crm.lead.create`
   action and the `crm.quote.accepted` and `crm.sales_order.status_changed` triggers; WhatsApp's message trigger
   gains the `new_conversation` filter; the engine's trigger filter gains `maximum_x`. Invoice notifications and
   payment reminders already worked as workflows (`invoicing.invoice.issued` / `.overdue` with
   `whatsapp_marketing.template.send`).

### Part B: owner decisions (not built)

1. **Several WhatsApp numbers per workspace.** `communication_channel_connections` is `UNIQUE(tenant_id, channel)`,
   and conversations are `UNIQUE(tenant_id, phone)`. Several numbers means: a connection per number, the gateway
   choosing the sending number (per conversation, per campaign), conversations keyed by number and customer, the
   webhook routing already matching `phone_number_id`, and the plan limits deciding whether numbers are billed.
   It changes the communication platform for every channel. *Proposal:* allow several WhatsApp connections, keep
   one default, store the connection on the conversation and the campaign. Needs an explicit yes and a pricing rule.
2. **The AI answering customers on its own.** ADR 0018 says the AI never changes data or acts without a person.
   An auto-reply sends text to a real customer in the business's name. *Options:* (a) keep drafts only (today);
   (b) allow an AI reply step in chatbot flows, limited to a workspace-written knowledge text, labelled as automated,
   with handoff on low confidence, and an ADR 0018 amendment. Needs a decision and, for (b), the AI provider
   question of ADR 0018 settled first.
3. **Templates through Meta's Management API.** Creating, submitting and syncing templates needs the access token
   in the WhatsApp app or a new core contract (`TemplateCatalogue`) that the communication app implements with the
   credential it holds. *Proposal:* the contract, so no app ever reads a token. Needs a yes; it is then a normal
   build plus a live Meta test.
4. **Product catalogue, product messages and abandoned carts.** These need a product catalogue owned by a commerce
   app and a cart that can be abandoned. CRM has products, quotes and sales orders but no cart or storefront.
   *Options:* a new Commerce app, or an integration with an external shop (Shopify, WooCommerce) through
   webhooks. Either is a new app with its own ADR.
5. **Appointment reminders.** No app owns appointments or bookings. *Options:* a Booking app, or reminders from
   CRM activities with a due time (a `crm.activity.due` trigger) as a lighter first step. Needs a choice.
6. **Meta WhatsApp Flows** (multi-screen forms inside WhatsApp). They need Flow JSON authoring, publishing through
   the Management API (decision 3) and an endpoint that decrypts Flow data exchange. *Proposal:* only after
   decision 3, and only if a concrete use (sign-up, booking, survey) needs more than buttons and lists.

## Consequences

- Everything in Part A keeps the module boundaries: WhatsApp imports no CRM, AI or Invoicing class; cross-app
  behaviour is a workflow or a tagged contract. Nothing in Part A has run against live Meta or a live AI provider.
- The interactive limits are enforced three times (page, `Support\Interactive`, adapter); a change at Meta needs
  all three.
- Ratings reuse the conversation's own message flow, so a rating answer is stored like any message and the
  conversation stays closed. A customer who writes something else ends the question; nothing guesses later.
- Part B items stay listed as "not built" in `docs/WHATSAPP.md` section 14 until decided.
