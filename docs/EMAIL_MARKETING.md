# Email Marketing

Module key `email_marketing`, category Email Marketing, one free offer (`free`), depends on `core` and
`communication`. Spec: `MASTER_PROMPT.md` section 11 and Phase 9. It lets a workspace build lists of shared
contacts, write emails with a structured block editor, send campaigns (now or scheduled, with an optional A/B
subject test), run welcome/drip sequences, import and export subscribers and read honest delivery reports.

It never talks to a mailer and never imports a Communication model. Everything it sends goes through the three core
contracts in `App\Core\Communication` (`docs/COMMUNICATION.md` section 14): `MarketingAudience`, `UnsubscribeLinks`
and `ChannelGateway`.

## 1. What it is, and what it is not

| Built | Not built (and why) |
|---|---|
| Lists, segments (declarative), CSV import/export | A public sign-up form or double opt-in page: needs a core contract that lets a marketing app record consent (section 13) |
| Block editor (heading, text, image, button, divider, spacer), preview, templates | Drag-and-drop layout and columns; custom HTML (by design: no free HTML is accepted) |
| Campaigns: draft, schedule, send now, pause, resume, cancel, duplicate, test send | Sending the HTML version: the platform mailer sends plain text only (section 5) |
| A/B subject test with a deterministic split and a measured winner | Open tracking: there is no pixel to place in plain text, so opens are not measured, and the app says so |
| Click measurement through a signed redirect; web-version page | Per-link click breakdown (only first click time and count per recipient are stored) |
| Welcome / drip sequences driven by the minute runner | Branching, goal exits and "send if" conditions |
| Reports page, two report providers, dashboard widget, automation trigger and action | Revenue attribution |
| Consent, suppression, bounce and unsubscribe mirrored from the platform | Writing consent into Communication |

## 2. Data model

All tables are tenant-owned (`tenant_id`, ULID ids, every unique index leads with `tenant_id`); see
`docs/DATABASE.md` section 3g3.

- **There is no subscriber identity.** A subscriber is a `Subscription`: membership of one list by one shared
  `Contact` (`contact_id`), with a status, consent evidence (method, free text, date), the source and a small
  `properties` map (keys `^[a-z][a-z0-9_]{0,31}$`, values up to 190 characters, at most 10).
- `MailingList` (active or archived), `Segment` (all/any of up to 10 rules), `Template`, `Campaign` (owner,
  department and branch for record scopes), `Delivery` (one row per planned email), `Sequence`, `SequenceStep`,
  `Enrollment`, `ImportRun` (counts and errors only), `Setting`, `AutomationActionReceipt`.
- `Delivery.send_key` is unique per workspace: `campaign:{campaignId}:{contactId}` or
  `sequence:{enrollmentId}:{stepId}`. It is also the idempotency key given to the gateway.
- `Delivery.message_id` is a plain column holding the communication message id; this module's tables never
  reference that module's tables.

Subscription status: `pending` (no evidence, never sent to), `subscribed` (evidence recorded), `unsubscribed`,
`bounced`, `complained`. Only `pending` and `subscribed` count toward the `email_marketing.subscribers` limit, and a
contact on several lists counts once.

## 3. Consent and suppression

Two separate things, kept separate on purpose:

1. **List state** (this app): the member's evidence of consent for *this list*. `subscribed` needs a method
   (`website_form`, `in_person`, `phone`, `email_reply`, `existing_customer`, `other`) and a description of 5 to 500
   characters. No evidence means `pending`. An import, an automation or a manual add without evidence is `pending`.
2. **Platform decision** (Communication): `MarketingAudience::decideMany()` (consent record, suppression list,
   address, channel availability). It is asked when an audience is prepared and the gateway asks again at send time,
   so a stale "yes" can never cause a send.

An email goes out only if the member is `subscribed` in the list **and** the platform says `ok`. A "no" is stored as a
`blocked` delivery with the platform's reason (`no_consent`, `opted_out`, `suppressed`, `unsubscribed`,
`no_address`, ...) and nothing is queued. The member pages show the platform's answer next to each address.

Mirroring (listeners, never polling):

| Event | Effect |
|---|---|
| `communication.marketing.message_status_changed` (core event, `module = email_marketing`) | updates the `Delivery` (`sent`, `delivered`, `failed`, `bounced`, `blocked`, `cancelled`); a state never moves backwards; `bounced` closes every open membership of the contact as `bounced` |
| `communication.contact.unsubscribed` (Communication's event, referenced by class name like Invoicing does for CRM; channel `email` only) | closes every open membership of the contact as `unsubscribed` (`complained` when the method is `complaint`) and stops running sequence enrolments |

Closed memberships are never reopened by an import or an automation. A manual add reopens only `unsubscribed`, and
only with fresh evidence; `bounced` and `complained` are handled in Communication. Closing a membership here does not
change the platform's consent or suppression records.

## 4. Sending pipeline

Nothing is sent in a web request. A member's "Send now" or "Schedule" only sets `status = scheduled` and
`scheduled_for`; a `RunEmailMarketing` job on queue `campaigns` does the work. It is also in the manifest as
scheduled job `email_marketing.run` (every minute, through the entitlement-gated `apps:run-scheduled` runner), so a
workspace that loses the app stops at once.

One pass (`CampaignRunner`, then `SequenceRunner`, under a cache lock per workspace):

1. Release deliveries a dead worker left in `sending` (older than 15 minutes) back to `pending`.
2. Start due campaigns (`scheduled` to `preparing`).
3. **Prepare** each preparing campaign in chunks of `email_marketing.audience_chunk` (500) members, keyed by member
   id: ask `decideMany`, create one `Delivery` per member (`pending`, `held` for the A/B remainder, or `blocked`).
   A short chunk is the last; the campaign becomes `sending`.
4. **Send** at most `send_per_minute` deliveries per pass across all campaigns (the workspace setting, capped by
   `email_marketing.send_per_minute_ceiling`), then sequences with what is left. Each delivery is claimed atomically
   (`pending` to `sending`) and handed to `ChannelGateway::send()` with the delivery's `send_key`.
5. Finish: no `pending`/`sending` deliveries left means `sent` (or `awaiting_winner` when an A/B remainder is held).
   The `email_marketing.campaign.sent` event is raised.

Guarantees:

- **Idempotent.** The claim is atomic, the delivery key is unique and the gateway replays a key (`replayed = true`),
  so a retried job, two workers, a re-run after a crash or a delivery reset to `pending` never produce a second email.
- **Bounded.** Chunk size, per-pass budget and the lock bound every pass; a large list takes several minutes by design.
- **Pauses, never fails silently.** A platform "plan message limit reached" (`failed`/`limit_reached`), or an
  unavailable channel or module, releases the delivery to `pending`, pauses the campaign with `paused_reason` and
  stops the pass. A person resumes it.
- **Unsubscribe link.** `UnsubscribeLinks::issue()` is called for each recipient and the link is written into the
  body; without one (no address) the delivery is `skipped` (`no_unsubscribe_link`). The platform also adds its own
  link and `List-Unsubscribe` headers.
- **Postal address.** Scheduling a campaign or activating a sequence is refused until the workspace's postal address
  is set in the settings; it is printed in every footer.
- **Metering.** `email_marketing.campaigns_per_month` (metered) is checked with `UsageLimits::ensure()` and recorded
  with `UsageMeter::record()` (key `campaign:{id}`) when a campaign is scheduled, once however often it is moved.
  The platform's `communication.messages` limit is metered by the outbox for every message.

## 5. Content

A message is a list of up to 50 typed blocks with fixed fields. There is **no HTML input anywhere**.

| Block | Fields |
|---|---|
| `heading` | `text` (160), `level` 1-3, `align` |
| `text` | `text` (5000), `align`; `**bold**`, `*italic*`, `[label](https://link)` are the only formatting |
| `image` | `url` (https only), `alt` (required, 200), `link_url?`, `width` 100-600 |
| `button` | `label` (60), `url` (http, https, `mailto:` or `{{unsubscribe_url}}`), `align`, `style` |
| `divider`, `spacer` | `height` 8-96 for a spacer |

Safety:

- `ContentBlocks::normalize()` validates on save with errors keyed to the exact block field; `EmailRenderer`
  escapes everything with `e()` and re-checks every link (`SafeUrl`: http/https with a host and no credentials,
  `mailto:` where allowed; no `javascript:`, `data:`, control characters or angle brackets). Because nothing raw is
  emitted, a `<script>` or an `onerror=` can only ever appear as inert text. Formatting is parsed before variables
  are substituted, so a contact's name cannot inject markup or links.
- Variables come from a fixed list: `first_name`, `last_name`, `full_name`, `email`, `company`, `workspace_name`,
  `current_year`, `unsubscribe_url` (not in a subject). Syntax `{{first_name}}` or `{{first_name|friend}}` (fallback
  up to 40 characters). An unknown or malformed variable is refused when saved. Nothing is evaluated.
- Subjects are one line (control characters are collapsed, so a subject cannot add headers).
- The preview (`POST content/preview`) renders made-up data and runs the same code as a send; the HTML is shown in a
  `sandbox=""` iframe.

**What the platform sends.** `OutboundMail` renders the message body as escaped plain text. This app therefore
sends the plain-text rendering today. The HTML rendering is produced from the same blocks and is available on the web
version page (`GET email-marketing/v/{token}`, linked from every email, locked down with a CSP). Sending HTML in the
email itself needs an HTML body on the `MarketingMessage` contract and in `OutboundMail`; that is an owner decision
(section 13).

## 6. A/B test

`ab_enabled` with `subject B`, a test share of 10-100 percent, a wait of 1-72 hours and a metric (`clicks`, which needs
click tracking on, or `views`, web-version views). The split is deterministic (`VariantSplitter`: hash of campaign
and contact), half A and half B inside the test share; the rest are `held`. After the test cohort is handed over the
campaign is `awaiting_winner` until `ab_decide_at`; then the better rate wins and the held deliveries are released
with the winner. Honest outcomes are stored in `ab_winner_reason`: `clicks`/`views`, `tie` (A kept),
`no_responses` (nobody clicked; A kept), `not_measured` (tracking off; A kept). At 100 percent there is no remainder.
Automated link scanners at a recipient's mail provider can inflate clicks; this is stated in the UI.

## 7. Sequences

An ordered list of up to 10 steps (`delay_minutes` 0 to 129600, subject, preheader, blocks). A sequence is attached
to a list; while `active`, a contact who **becomes `subscribed`** in that list is enrolled once (leaving and
re-joining does not restart it). Existing members are not back-filled. The runner sends the step that is due, then
schedules the next; a member who is no longer `subscribed` stops the enrolment. Steps can be edited only while the
sequence is draft or paused. Each step email follows the same consent, suppression, idempotency and throttling rules
as a campaign.

## 8. Segments

A segment is `{match: all|any, rules: [{field, operator, value}]}` from fixed lists
(`email_domain`, `city`, `country`, `job_title`, `has_company`, `source`, `subscribed_after/before`,
`property:<key>`). `SegmentRules` builds a bound-parameter query; there is no expression language and nothing from
the tenant is interpreted. Segment membership is evaluated when a campaign prepares its audience.

## 9. Import and export

CSV import (`email_marketing.subscribers.import`): at most `email_marketing.import.max_rows` rows (2000) and
`max_bytes` (1 MB), UTF-8 only, no NUL bytes, header row, columns limited to
`email,first_name,last_name,consent_method,consent_evidence,consented_at` and `prop_*`; the same file is not
imported twice into a list. An address that does not start with a letter, digit or underscore is refused (formula
characters). Each row is its own unit: bad rows are listed by line (first 100 kept), good rows are kept.

- A row is `subscribed` only with a method **and** evidence; otherwise `pending`. Importing never records consent in
  Communication and never reopens a closed membership.
- An unknown address becomes a new shared contact through `ContactDirectory` only if the importer holds
  `core.contacts.manage`; otherwise the row fails.

CSV export (`email_marketing.subscribers.export`, `email_marketing.reports.view` for a campaign's recipients) goes
through `CsvSafe`: a cell starting with `=`, `+`, `-`, `@`, tab or CR is prefixed with an apostrophe.

## 10. Tracking

Both public links are stateless callback routes (`loadTenantCallbackRoutes()`: tenant from the host, throttled, no
session or cookies, plus the module's entitlement). The token is `base64url(json).base64url(hmac)`; the HMAC key is
derived from the application key and covers the tenant, the delivery and (for a click) the destination, so the
redirect is not an open redirect and a token of another workspace is a bare 404. A click counts `click_count` and the
first `clicked_at`; a web-version view counts `view_count` and `viewed_at`. The unsubscribe link and `mailto:` are
never tracked.

## 11. Permissions, limits, automation, events

Permissions (`email_marketing.{resource}.{action}`; new permissions are granted to no role): `lists.view|manage`,
`subscribers.view`, `subscribers.manage` (sensitive), `subscribers.import` (sensitive), `subscribers.export`
(sensitive), `campaigns.view` (scopable), `campaigns.create`, `campaigns.edit` (scopable), `campaigns.delete`
(scopable), `campaigns.send` (sensitive), `templates.view|manage`, `sequences.view|manage`, `sequences.activate`
(sensitive), `reports.view`, `settings.manage` (sensitive). Campaigns use `HasRecordOwner`: list, show, edit, delete
and the send actions narrow by `RecordAccess` (a campaign outside the scope is a 404).

Subscriber pages show a contact's name and address under `email_marketing.subscribers.view`, like Communication's
audience page; they are not narrowed by the viewer's `core.contacts.view` scope.

Limits: `email_marketing.campaigns_per_month` (metered), `email_marketing.subscribers` (gauge).

Automation: triggers `email_marketing.campaign.sent`, `email_marketing.subscriber.subscribed`; action
`email_marketing.subscriber.add_to_list` (adds a contact to a list as `pending`; receipts in
`email_marketing_automation_action_receipts`; refusals are stored outputs). Domain events carry ids and counts only.

Report providers (tagged in the provider, see `docs/REPORTS.md`): `email_marketing.campaigns` (per finished campaign:
planned, sent, blocked, bounced, clicked, click rate) and `email_marketing.lists` (list health, a snapshot).
Dashboard widget `email_marketing.overview`. Contact merge: `EmailMarketingContactMergeReference` repoints
memberships, deliveries and enrolments and keeps the stricter status.

## 12. Routes, pages and props

Workspace routes sit under `/email-marketing`, carry `auth:web`, `auth.session`, `member`, `subscribed`,
`entitled:email_marketing` and the `can:` shown.

| Route name | Verb and URL | Permission |
|---|---|---|
| `email_marketing.campaigns.index` / `.create` / `.store` | GET `campaigns`, GET `campaigns/create`, POST `campaigns` | `campaigns.view` / `.create` / `.create` |
| `email_marketing.campaigns.show` / `.edit` / `.update` / `.destroy` | GET, GET `/edit`, PUT, DELETE `campaigns/{campaign}` | `.view` / `.edit` / `.edit` / `.delete` (record scope) |
| `email_marketing.campaigns.duplicate` | POST `campaigns/{campaign}/duplicate` | `.create` |
| `email_marketing.campaigns.schedule` / `.unschedule` / `.pause` / `.resume` / `.cancel` / `.test` | POST `campaigns/{campaign}/{action}` | `campaigns.send` |
| `email_marketing.campaigns.export` | GET `campaigns/{campaign}/deliveries/export` | `reports.view` |
| `email_marketing.content.preview` | POST `content/preview` (JSON) | `campaigns.view` |
| `email_marketing.templates.index` / `.create` / `.store` / `.edit` / `.update` / `.destroy` | `templates`, `templates/create`, `templates/{template}[/edit]` | `templates.view` / `.manage` |
| `email_marketing.lists.index` / `.show` | GET `lists`, `lists/{list}` | `lists.view` |
| `email_marketing.lists.store` / `.update` / `.destroy` | POST `lists`, PUT/DELETE `lists/{list}` | `lists.manage` |
| `email_marketing.segments.store` / `.update` / `.destroy` | POST `lists/{list}/segments`, PUT/DELETE `segments/{segment}` | `lists.manage` |
| `email_marketing.subscribers.index` | GET `subscribers` | `subscribers.view` |
| `email_marketing.subscribers.contacts` | GET `subscribers/contacts?q=` (JSON, at most 10) | `subscribers.manage` |
| `email_marketing.subscribers.store` | POST `lists/{list}/subscribers` | `subscribers.manage` |
| `email_marketing.subscriptions.update` / `.unsubscribe` / `.destroy` | PUT, POST `/unsubscribe`, DELETE `subscriptions/{subscription}` | `subscribers.manage` |
| `email_marketing.lists.export` | GET `lists/{list}/export` | `subscribers.export` |
| `email_marketing.imports.index` / `.template` / `.store` | GET `imports`, GET `imports/template`, POST `imports` | `subscribers.import` |
| `email_marketing.sequences.index` / `.show` | GET `sequences`, `sequences/{sequence}` | `sequences.view` |
| `email_marketing.sequences.create` / `.store` / `.edit` / `.update` / `.destroy` | | `sequences.manage` |
| `email_marketing.sequences.activate` / `.pause` | POST `sequences/{sequence}/{activate|pause}` | `sequences.activate` |
| `email_marketing.reports.index` | GET `reports` (`days` 7/30/90/365) | `reports.view` |
| `email_marketing.settings.index` / `.update` | GET, PUT `settings` | `settings.manage` |
| `email_marketing.track.click` | GET `email-marketing/t/c/{token}` (callback group) | signed token |
| `email_marketing.web.show` | GET `email-marketing/v/{token}` (callback group) | signed token |

Pages (`Modules/EmailMarketing/Pages/...`): `Campaigns/{Index, Form, Show}`, `Templates/{Index, Form}`,
`Lists/{Index, Show}`, `Subscribers/Index`, `Imports/Index`, `Sequences/{Index, Form, Show}`, `Reports/Index`,
`Settings/Index`. Props are exactly the shapes in `resources/js/Modules/EmailMarketing/Components/types.ts`
(`CampaignSummary`, `CampaignDetail`, `MemberRow`, `SegmentRow`, `SequenceRow`, `DeliveryRow`, `ImportRow`,
`OverviewReport`, `WidgetData`); the controllers build them in `EmailMarketingPresenter`.

## 13. Known limits and owner decisions

1. **HTML in the email.** The platform mailer sends plain text. Decide whether to add an optional `htmlBody` to
   `MarketingMessage`, `OutboundRequest` and `OutboundMail` (needs a column on `communication_messages`). Until then
   the HTML version is the web-version page, and opens cannot be measured.
2. **Recording consent from a marketing app.** There is no core contract for it, so a member moves from `pending`
   to sendable only after consent is also recorded in Communication (Audience page). Decide whether to add a
   `ConsentRecorder` contract so that list evidence can create the platform consent in one step, and a public
   sign-up form with double opt-in on top of it.
3. **Existing members and a new sequence.** Only people who become subscribed after activation are enrolled.
4. **Click counts** include automated scanners; per-link breakdown is not stored.
5. **Postal address** is required before sending (a compliance choice, easy to relax).
6. Bounce management beyond the platform's suppression list, and list hygiene tooling, are not built.

## 14. Tests

`tests/Feature/EmailMarketing`, `tests/Unit/EmailMarketing`, helper `tests/Support/InteractsWithEmailMarketing.php`
(builds on `InteractsWithCommunication`: `emTenant`, `emMember`, `emList`, `emSubscribe`, `emCampaign`, `emRun`);
frontend in `tests/js/emailMarketing.test.ts`. See `docs/TESTING.md`.
