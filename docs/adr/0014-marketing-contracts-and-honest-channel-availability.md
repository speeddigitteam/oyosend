# ADR 0014 — Marketing apps send only through core communication contracts; channels are honestly available or not

- **Status:** Accepted
- **Date:** 2026-10-13
- **Phase:** 8 (Communication platform) and 9 (Marketing apps)

## Context

Three optional apps send bulk or conversational messages to customers: Email Marketing, SMS Marketing and WhatsApp
Business. Each needs the same hard parts: consent, suppression, unsubscribe, quiet hours, per-channel rate limits,
retries, plan limits, delivery receipts and provider credentials. If each app owned them, a consent rule or a
provider fix would have to be made three times, and a missing check in one app would be a compliance failure for a
customer. If the apps imported the Communication module's models instead, none of them could be installed or removed
independently (CLAUDE.md: modules talk through events and documented contracts, never another module's models).

No SMS or WhatsApp provider adapter has ever been run against a live service, and the platform mailer sends plain
text only. A marketing screen that implied otherwise (a "Send" that silently does nothing, a delivery rate of 0%, a
simulated provider) would mislead the people who rely on it. CLAUDE.md forbids presenting a placeholder or simulated
integration as working.

## Decision

1. **Contracts in core, implementation in Communication.** `App\Core\Communication` publishes the only surface a
   marketing app may use. `CoreServiceProvider` binds an honest null object for each; the Communication module
   rebinds the real one:

   | Contract | Purpose |
   |---|---|
   | `MarketingAudience` | `canReceive` / `decideMany` (up to 1000 contacts): channel available, contact exists, valid address, suppression, consent. Advice; the outbox re-checks |
   | `ChannelGateway` | `availability`, `send(MarketingMessage): SendReceipt`, `statusFor`, `statusForMany`. Puts one message in the outbox through `Outbox::queue()`; never delivers inline |
   | `UnsubscribeLinks` | `issue()` a hashed, expiring one-click token URL for bodies that embed the link |
   | `ConsentRecorder` | `recordOptOut()`; `recordOptIn()` for a confirmed double opt-in form only, refused while any suppression holds the address (added after this decision, see `docs/COMMUNICATION.md` section 5) |
   | `ChannelEventListener` (tag) | Receives verified provider events (delivered, read, failed, bounced, complained, inbound, template status) after the platform applied them |
   | `Notifier`, `InteractionRecorder` | Not marketing-specific; member notifications and the contact timeline |

   The status outcome is the core event `MarketingMessageStatusChanged`
   (`communication.marketing.message_status_changed`), raised after commit for messages sent through the gateway.
   A marketing app ignores events whose `module` is not its own.

2. **No credential, connection or webhook route in a marketing app.** The connection, the credential reference
   and the webhook belong to the Communication module (`communication_channel_connections`, ADR 0013 for operator
   accounts). A marketing app never reads a secret.

3. **The platform decides consent at send time, twice.** `Outbox::queue()` (queue time) and `MessageDispatcher`
   (worker time) both apply suppression, marketing consent, quiet hours and the plan limit
   `communication.messages`; the worker also applies the per-channel rate limit (a message over the rate is
   postponed, not failed). No record means no consent. A "no" is stored as a `suppressed` message with failure code
   `no_consent` or `suppressed`, so the log explains what did not happen. A campaign must send `Marketing` purpose
   whatever its template's category; `Transactional` is accepted for WhatsApp only, as a reply inside a
   customer-service window the customer opened.

4. **Honest availability.** `ChannelAvailability` is `available` or `not_configured` with a reason
   (`module_unavailable`, `channel_disabled`, `provider_not_configured`) and a `testDouble` flag. A send that cannot
   be recorded returns `SendReceipt::unavailable()` and records nothing. Without a provider the apps show
   `not_configured`, allow drafting and estimates, and refuse to launch. In-memory providers are registered only in
   `testing` and `local` and are labelled "Test provider". A figure a provider did not return is `null` ("not
   reported"), never zero; the SMS delivery rate stays "Not reported" while no receipt exists, and messages from an
   adapter without receipts stay `sent`, labelled "Sent, no receipt".

5. **Idempotent by key.** Each send carries an app-namespaced key (`{module}:{key}`, at most 119 characters). A retry
   returns the original receipt (`replayed = true`) and queues nothing. Campaigns run in bounded, per-recipient
   idempotent chunks from a minute runner on the `campaigns` queue.

6. **Dependency declaration.** Each marketing app declares `core` and `communication` in `dependencies`; the
   entitlement checker removes it when `communication` is missing. The unsubscribe page is the one route deliberately
   not entitlement-gated, so a person can always opt out even after Communication lapsed.

## Consequences

- One implementation of consent, suppression, quiet hours and rate limits serves all three apps; a fix lands once.
- A marketing app is testable with the in-memory provider and the null contracts, and can be uninstalled without
  touching Communication data.
- A marketing app cannot do what the contracts do not offer. Known gaps that follow from that: no per-message sender
  id (`MarketingMessage` has no sender field), no transactional SMS or email from a marketing app, no provider
  balance, no opt-in recording, and an HTML email body is not carried (the platform mailer sends plain text). Each
  needs a contract change and is recorded as an owner decision in the app's doc.
- The boundary "no import of a Communication class" is held by code review and by the apps' current code (none of
  `EmailMarketing`, `SmsMarketing`, `WhatsappMarketing` has such an import); **no automated architecture test
  enforces it**. Adding one is open.
- The platform decides, but a marketing app mirrors the answers (`blocked`, `failed`, `bounced`) onto its own rows.
  Mirrors can lag a provider; each app has a reconciliation path (`statusForMany`) and says when a state is unknown.
- Provider adapters remain unverified against live services (Twilio, generic HTTP SMS, SMTP, WhatsApp Cloud API).

## Alternatives considered

- **Each app talks to providers itself.** Rejected: triples consent and credential handling and makes the compliance
  surface three times larger.
- **Marketing apps import Communication's models and actions.** Rejected: breaks independent install/uninstall and the
  module boundary rule; a model change would ripple into three apps.
- **A single "Marketing" module.** Rejected: the product sells and entitles email, SMS, WhatsApp and social as separate
  apps; one module would force all-or-nothing licensing and one plan limit.
- **Simulated provider in production so screens always "work".** Rejected: forbidden by CLAUDE.md and misleading.
