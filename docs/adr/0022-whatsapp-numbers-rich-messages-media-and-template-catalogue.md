# ADR 0022 — Several WhatsApp numbers per workspace, rich WhatsApp payloads, media and the provider template catalogue

- **Status:** Accepted for the foundation (contracts, platform implementation, honest `not_configured`). Not run against
  live Meta. The WhatsApp Business app does not use it yet (see "Not built").
- **Date:** 2026-10-13
- **Phase:** not assigned. Builds on ADR 0013, 0014, 0016 and 0021 (Part B items 1, 3, 4 and 6 become possible).

## Context

ADR 0021 left four WhatsApp gaps that needed a change of the communication platform, not of the WhatsApp app:
one number per workspace (`UNIQUE(tenant_id, channel)` on connections), no media in or out, no template management
through Meta's Business Management API, and only plain text, reply buttons and one list on the outbound side and
text-only parsing on the inbound side. The architecture rules fixed the shape of the answer: apps send only through
`App\Core\Communication` contracts, hold no provider credential and import no Communication model; one WhatsApp
phone number can belong to one workspace only (ADR 0016); nothing is faked.

## Decision

### 1. Numbers belong to the connection, not the other way round

`communication_channel_connections` stays `UNIQUE(tenant_id, channel)`: one provider and one credential reference per
channel. A new tenant-owned table `communication_channel_numbers` hangs several **numbers** off the WhatsApp
connection (`phone_number_id`, display number, label, active, default). Why not "one connection per number": the
credential reference, webhook secret, rate-limit setting and provider are the same for every number of one WhatsApp
Business Account, so duplicating them per number would multiply places a secret reference can go stale, and every
existing consumer of "the connection" would have had to learn which one.

- Exactly one default number per connection, enforced by the database (a nullable `default_key` under a unique
  index with the connection id).
- The legacy setting `settings.phone_number_id` is kept and always equals the **default number's** account id. All
  code and tests written before numbers existed keep working: the connection form, `state()`, the adapter. A change
  to that setting (form or direct write) is reconciled by `ChannelNumbers::ensure()`: replace the only number in
  place, or add/promote one in a multi-number workspace. A backfill migration creates the default number of every
  existing WhatsApp connection and links its claim.
- Every number is claimed in the platform-wide registry (`communication_account_claims`, ADR 0016); the claim row
  now has a nullable `number_id`. A number another workspace holds is refused with `account_in_use`.
- Webhook account guard: an event is accepted when its `phone_number_id` is **any** number the workspace registered
  (disabled ones too: receipts of earlier sends still arrive), and the `ChannelEvent` is tagged with `numberId`.
  Non-account keys (`business_account_id`) are compared with the connection's settings as before.
- Outbound: `MarketingMessage::$senderNumberId` (null = default). The gateway resolves it at queue time (unknown,
  foreign, disabled → `sender_number_unavailable`, nothing recorded), the outbox stores `sender_number_id`
  (receipt carries it), the worker re-checks it, and the adapter uses the number's account id as the Graph
  `phone_number_id`. Providers rate-limit per number, so the worker's per-minute budget is per number.
- A number cannot be deleted once any message references it (disable it instead); the default cannot be disabled or
  deleted while others exist.
- Read contract `ChannelNumberDirectory`; write contract `ChannelNumberRegistry` (authorization is the caller's job).
  Whether extra numbers are billed or limited is **not decided** here: no `LimitDefinition` was added (owner
  decision, as in ADR 0021).

### 2. Rich content is a validated value object, carried as JSON in the message meta

`MarketingMessage` gains `$whatsapp: ?WhatsappContent` (and `$senderNumberId`). `WhatsappContent` has named
constructors for template (header text/media, body parameters, button parameters), reply buttons (3), list (10 rows,
10 sections), call-to-action URL, media (image, document, audio, video), product, product list and Flow, each
validated against Meta's limits and capped at 16 000 bytes of JSON. Media is a `MediaReference` (a stored media id
**or** a provider media id), never a path or a URL. The gateway writes the JSON to the message meta under
`whatsapp_content` (a provider hint with that key is stripped), the adapter re-validates it when it reads it back and
maps it to the Graph message object (`docs/COMMUNICATION.md` section 14a). The existing hint-based template and
interactive paths keep working.

Window rule: every kind except a template needs the customer's open 24-hour window. The gateway enforces it
**exactly like a transactional reply**: the caller must name the window with the hint `whatsapp_window_until`; a
missing, past or unparsable value answers `unavailable / outside_customer_service_window` and records nothing. The
adapter refuses locally again. Whether it is marketing or a service message stays the caller's declared `purpose`
(a reply is `Transactional`, still gated by the same hint; anything else needs consent).

### 3. Media through a core contract, with the platform holding bytes and credentials

`MediaGateway` (core): `store`/`storeFromTenantFile` put a file into the workspace's `TenantStorage` and answer a
media id; `upload` sends it to the provider for a number and caches the provider id per (media, number) for
`upload_reuse_days` (the dispatcher uploads automatically before a send that references a stored media); `download`
fetches an inbound customer media; `read` returns bytes plus the headers an app must serve them with. Safety:
per-type allow-list and size cap (`config('communication.whatsapp.media')`), libmagic sniffing that must agree with the
declared type, generated storage names (extension from the sniffed type), SHA-256 checked when the provider gives
one, provider download URLs followed only over https on a named host under `media_hosts` with no redirects, and
nothing fetched from a URL an app or customer supplied. The metadata-only inbound event is unchanged: the bytes are an
explicit later `download()`.

### 4. Template catalogue as a core contract

`TemplateCatalogue` (core) lists, creates (submits for approval) and deletes templates in Meta's
`/{waba_id}/message_templates`, implemented in Communication with the credential it already holds (no app reads a
token). Drafts are validated like Meta validates them (name, category, numbered variables with examples, button
limits). States are honest: `module_unavailable`, `not_configured`, `missing_business_account` (the phone number id is
not enough), plus the provider's own refusal text. Nothing is stored; approval changes still arrive as
`template_status` events.

### 5. Inbound parsing

`ChannelEvent` gains `numberId`, `content` (`InboundContent`: button/list reply with id and title, template
quick-reply, media facts, location, reaction, order with decimal-string prices, Flow response as capped untrusted JSON,
contacts, unsupported) and `pricingCategory`; the text of a plain message is unchanged and `Inbound` stays the only new
inbound type, so no exhaustive `match` in an app breaks.

## Consequences

- Single-number workspaces, the connection form and every test written before this ADR behave as before.
- `communication_account_claims` stays the one platform-wide exception to tenant-first unique indexes; the claim of a
  number is owned through the number row (`number_id`, cascade).
- Several adapters' worth of Graph shapes are now in one place (`Channels/Providers/Cloud/*`), all verified only
  against HTTP fakes. Using any of it for real needs the operator's Meta app, a verified business, registered numbers,
  approved templates and app review.
- ADR 0021 Part B items 1 (numbers), 3 (template catalogue), 4 (the message formats, not the commerce app) and 6 (Flow
  messages and responses, not Flow authoring) now have a platform to stand on; the WhatsApp Business app still has to
  adopt them.

## Not built

The UI and routes to manage numbers; conversations keyed by number (the WhatsApp app's `UNIQUE(tenant_id, phone)`);
choosing the sending number per conversation or campaign in the WhatsApp app; plan limits or billing for extra numbers;
the commerce app (catalogue, cart); Flow JSON authoring/publishing and the Flow data-exchange endpoint (encrypted);
authentication templates (OTP buttons); the resumable-upload API that produces a template's media-header example
handle; number registration/verification with Meta; automatic download of inbound media; a media-serving route and
retention policy for the media store; live verification.
