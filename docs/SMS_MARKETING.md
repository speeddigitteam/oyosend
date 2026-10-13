# SMS Marketing

The optional app `sms_marketing` (name "SMS Marketing", category SMS marketing, one free offer `free`, depends on `core`
and `communication`) lives in `app/Modules/SmsMarketing` and is registered by `SmsMarketingServiceProvider`. It sends
bulk and scheduled SMS campaigns to contact groups, keeps message templates, records sender ids, follows delivery
status and reports usage. Tables are prefixed `sms_marketing_`; routes sit under `/sms-marketing`; the frontend is
`resources/js/Modules/SmsMarketing`.

State of the build: **backend and Vue pages are built.** The Communication module ships two SMS adapters,
**Twilio** (`twilio`) and a **generic HTTP gateway** (`http_sms`), configured by the operator as provider accounts
in the Super Admin panel (ADR 0013, `docs/COMMUNICATION.md` section 3). **Neither has been run against a live
provider; they are covered by faked tests only**, and delivery receipts are not consumed (messages stay `sent`,
reports label them "Sent, no receipt"). Until the operator creates an account and the workspace selects it in
Communication > Settings > Channels, the SMS channel is `not_configured`: campaigns can be drafted, groups built,
templates written and cost estimated, but a campaign cannot be launched, and every screen says so. The app never
talks to a provider itself and holds no provider credential. Nothing is simulated outside `testing` / `local`,
where the Communication module's in-memory test provider is available and is labelled "Test provider" in the UI.

A workspace needs an effective plan or App Store entitlement for `sms_marketing` **and** `communication`; without
either every route answers 404 and the scheduled jobs do nothing for it. Uninstalling retains every record.

## 1. What goes through what

SMS Marketing imports no Communication model, enum or event. It uses the three core contracts only
(`App\Core\Communication`, docs/COMMUNICATION.md section 14):

| Need | Contract | Used for |
|---|---|---|
| May this contact be texted? | `MarketingAudience::canReceive` / `decideMany` | estimates and, at hand-over time, every recipient |
| Opt-out link | `UnsubscribeLinks::issue` | a fresh one-click link in every message body |
| Send, learn the outcome | `ChannelGateway::availability` / `send` / `statusForMany` | launch gate, one message per recipient, reconciliation |
| Delivery status | core event `MarketingMessageStatusChanged` (`communication.marketing.message_status_changed`) | recipient states |

Consent, suppression, quiet hours, the platform's own per-minute rate, provider retries and the plan limit
`communication.messages` are applied by the platform. The platform re-checks consent when the worker sends, so a
stale "yes" can never cause a send.

## 2. Concepts

- **Group**: a named list of references to shared contacts (`sms_marketing_group_members.contact_id`). Never a copy:
  a changed number or an opt-out is seen at send time. Deleting a group keeps the contacts.
  A viewer sees (and exports) a group's members only for contacts they may see under `core.contacts.view`; the
  rest are counted ("N members are contacts you may not see"), never listed. A campaign's recipient list and its
  CSV export follow the same rule (`hiddenRecipients`); campaign counters stay complete.
- **Template**: a message text with variables, purpose `marketing` or `transactional`.
- **Campaign**: a text (copied from a template or written) sent to the contacts of one or more groups. Status:
  `draft`, `scheduled`, `sending`, `completed`, `cancelled`. A running campaign may carry a `hold_reason`.
- **Recipient**: one contact in one campaign, unique per `(campaign, contact)`. Status: `pending`, `queued` (handed
  to the platform, no outcome yet), `sent` (the provider accepted it), `delivered` (a provider receipt said so),
  `failed`, `blocked` (no consent or suppressed; **never sent**), `skipped` (this app could not build a message:
  `invalid_number`, `too_long`, `campaign_cancelled`, `unknown_contact`).
- **Sender id**: a recorded text or number (see section 6).

## 3. Segment counting

`Support\SegmentCounter` (pure, table-tested in `tests/Unit/SmsMarketing/SegmentCounterTest.php`; a TypeScript twin in
`Components/smsFormat.ts` drives the live counter in the editor and is tested against the same table):

| Encoding | One segment | Several segments |
|---|---|---|
| GSM 03.38 (every character in the default or extension table) | 160 septets | 153 per segment |
| UCS-2 (any other character: Bengali, emoji, typographic quotes) | 70 UTF-16 code units | 67 per segment |

An extension character (`^ { } \ [ ~ ] | €` and form feed) costs two septets; a character outside the Basic
Multilingual Plane (emoji) costs two code units. Neither is split across segments: a character that does not fit
moves whole to the next segment. Anything not GSM makes the **whole** text UCS-2, and the UI names the characters
that caused it. The opt-out wording and link are part of the text and are counted (the estimate assumes a link of
real length: 40 random characters on the workspace host). Longer than `sms_marketing.max_segments` (10) is refused
when saving and skipped (`too_long`) at send time.

Cost: the workspace may record its **own** price per segment (`segment_cost_amount`, integer minor units next to a
`currency`). Estimates and the spend figure multiply segments by it; without a rate no cost figure is shown. A
provider's price is never read.

## 4. Phone numbers

`Support\PhoneNumber::normalize()` returns E.164 or null. Bengali digits are read as digits. Accepted: `+` or `00`
with a country code (8 to 15 digits); a Bangladesh mobile number as `01712345678`, `1712345678` or
`8801712345678` (operator digit 3 to 9). Refused (never guessed): a local number of another country without a
country code, a wrong operator digit, too short or too long, a landline written locally. The recipient's `phone` is
the E.164 form at send time. The platform sends to the address as it is stored on the contact (it normalizes only to
digits and an optional plus); an adapter must accept what the contacts hold.

## 5. Templates and personalization

Variables are `{{name}}` or `{{name|fallback}}` (fallback is plain text, at most 30 characters). The allow-list is
fixed: `first_name`, `last_name`, `full_name`, `company_name`. Transactional templates may also use the one-time
code placeholders `code` and `otp`; a marketing text containing them is refused, and a campaign refuses a
transactional template. Nothing is evaluated: a variable is a lookup in an array of plain strings (control
characters removed, value cut at 40 characters). Unknown variables, a stray `{{` or `}}` and expressions are
validation errors. Text up to 1000 characters.

A transactional template is a stored text and a purpose. **This app does not send transactional SMS**: the
`ChannelGateway` contract sends marketing only (docs/COMMUNICATION.md section 14), and a one-time code must not be
marketing. See "Owner decisions".

## 6. Sender ids

`Support\SenderIdRules`: a **masking** id is 3 to 11 letters and digits with at least one letter, no spaces; a
**non-masking** id is 3 to 15 digits with an optional leading `+`. Unique per workspace. The registration status
(`not_registered`, `pending`, `registered`, `rejected`), reference and note are what the workspace **recorded**: the
UI says it is not verified and no label or colour means "approved". The sender id is a record on the campaign and
the settings; the platform's message contract carries no per-message sender, so what recipients see is decided by
the connection in Communication settings (an owner decision below).

## 7. How a campaign runs

```
draft --launch(now)--> sending --all recipients settled--> completed
      \--launch(at)--> scheduled --due--> sending
sending / scheduled --cancel--> cancelled        completed --failures come back--> sending
```

`CampaignManager::launch()` (the only way to start): the campaign must be a draft; a schedule is between one minute
and 90 days ahead; the SMS channel must be available (`ChannelGateway::availability`, otherwise a validation error
that names the reason); groups must exist and reach at least one and at most `max_audience` (50000) contacts; and the
estimated segments must fit the plan allowance (`UsageLimits::ensure`). It does not send.

`CampaignRunner::run()` is the `sms_marketing.run_campaigns` scheduled job (every minute, queue `campaigns`, through
the entitlement-gated runner, plus a dispatch right after "launch now"). Per workspace, under a cache lock:

1. starts scheduled campaigns that are due;
2. builds the recipient rows of campaigns that have none (one per distinct contact, `insertOrIgnore` on the unique
   key, so a repeat adds nothing; an unusable number is recorded `skipped:invalid_number`);
3. hands up to `min(throttle per minute, daily cap left, 500)` pending recipients to the platform, at most 200
   per campaign per pass, oldest campaign first.

Per recipient: claim (compare-and-set `pending` to `queued`, attempts +1) -> contact still exists -> number valid ->
`MarketingAudience::decideMany` (no consent, opted out, suppressed, unsubscribed => `blocked`, nothing sent) -> opt-out
link -> compose text and count segments (`too_long` => `skipped`) -> plan allowance check including segments still
in flight -> `ChannelGateway::send` with the idempotency key `c:{campaign}:r:{recipient}:{attempt}`. The same
attempt can never queue two messages (the platform replays the original), and a crashed worker is repaired by the
hourly reconciliation (a claim with no message id for 10 minutes goes back to `pending` and gets its attempt back).

A campaign **holds** (stays `sending`, `hold_reason` shown, resumes by itself) when: the daily cap is reached
(`daily_cap`, the workspace's day in its timezone), the plan's segments are used up (`limit_reached`), the channel is
unavailable or switched off (`channel_unavailable`), or the platform's own message allowance is used up
(`platform_limit`). Quiet hours are the platform's: the message is accepted, waits (`due_at` is shown) and is sent
after the window.

**Throttling and caps** (workspace settings, bounds in `config/sms_marketing.php`): `throttle_per_minute` 1 to 600
(default 60), `daily_cap` 1 to 100000 (default 1000). The daily cap is hard: retries count, and blocked or skipped
recipients do not.

## 8. Delivery status and retries

`Listeners\ApplyMarketingStatus` listens to the core `MarketingMessageStatusChanged`, ignores other modules'
messages, checks tenant and entitlement, drops a late answer about an earlier attempt (message id mismatch) and
hands the state to `RecipientStatusApplier`, the only place a recipient changes after hand-over. Every change is a
compare-and-set; a delivered message never goes back to sent; usage is recorded once per attempt.

| State from the platform | Recipient |
|---|---|
| sent | `sent` (usage recorded: the segments, idempotency key `r:{recipient}:{attempt}`) |
| delivered | `delivered` (usage recorded if it was not yet) |
| failed (temporary) | `pending` again with a backoff (15, then 60 minutes), a **new** attempt and key |
| failed (permanent) / bounced | `failed` |
| blocked | `blocked` with the platform's reason |
| cancelled | `skipped:cancelled` |

Bounded retries: `max_attempts` = 3 attempts in all. Permanent codes (`invalid_recipient`, `provider_not_configured`,
`unknown_contact`, `no_address`) and bounces are never retried, automatically or by hand. "Retry failed" on a
campaign puts the retryable ones back (still within the bound). A failure that arrives after the campaign looked
finished re-opens it. `DeliveryReconciler` (hourly job `sms_marketing.reconcile`) asks `statusForMany` about messages
that never reported back and requeues orphaned claims.

## 9. Usage, limits and "not reported"

| Limit key | Kind | Meaning |
|---|---|---|
| `sms_marketing.segments_per_month` | metered | segments of messages the provider **accepted**, in the plan's billing period; recorded with `UsageMeter` and an idempotency key per attempt |
| `sms_marketing.contacts` | gauge | distinct contacts in at least one group; checked when adding to a group (all or nothing) |

The platform's own `communication.messages` limit still applies to every message. The **provider credit balance** is
shown only if the provider adapter reports it; the contract has no balance call today, so the UI always says "Not
reported" and never a number. The **delivery rate** is null ("Not reported") until a receipt exists: a provider that
sends none leaves messages at `sent`, which the reports label "Sent, no receipt".

## 10. CSV

Import (group page, `groups.manage`) adds **existing** contacts by phone number: column `phone`, UTF-8, up to
`import.max_rows` (5000) rows and `import.max_bytes` (512 KB), no NUL bytes. A file never creates a contact and
never records consent. Numbers are matched to contacts the importer may see (`core.contacts.view` scope); unmatched
or invalid rows are listed by line (first 100). Exports (group members, campaign recipients) are formula-safe:
cells starting with `=`, `+`, `-`, `@`, tab or CR get an apostrophe, and the importer reads that apostrophe back.

## 11. Permissions, navigation, schedules

| Permission | Scopable | Sensitive | Used by |
|---|---|---|---|
| `sms_marketing.campaigns.view` | yes | | list, detail, recipients, export |
| `sms_marketing.campaigns.create` | | | draft, estimate |
| `sms_marketing.campaigns.edit` / `.delete` | yes | | draft editing; delete a draft or cancelled campaign |
| `sms_marketing.campaigns.launch` | yes | yes | launch, schedule, cancel, retry failed |
| `sms_marketing.groups.view` / `.manage` | | | groups and members, export / create, change, members, CSV import |
| `sms_marketing.templates.view` / `.manage` | | | templates |
| `sms_marketing.senders.view` / `.manage` | | manage | sender ids |
| `sms_marketing.analytics.view` | | | reports page and the two report providers |
| `sms_marketing.settings.manage` | | yes | rate, cap, wording, price per segment |

New permissions are granted to no role. Campaigns carry owner/department/branch (`HasRecordOwner`); the controller
narrows with `RecordAccess` and `authorize()`. Navigation (group `apps`): Campaigns, Groups, Templates, Sender ids,
Reports; Settings in `manage`. Widget `sms_marketing.overview` (`SmsMarketingOverviewWidget`): sending, scheduled,
on hold, segments used of the allowance, channel state. Scheduled jobs: `sms_marketing.run_campaigns` (every minute)
and `sms_marketing.reconcile` (hourly).

## 12. Automation, events, reports

Triggers `sms_marketing.campaign.launched` and `sms_marketing.campaign.completed` (events `CampaignLaunched`,
`CampaignCompleted`, ids and counts only, after commit). Action `sms_marketing.group.add_contact`
(`sms_marketing.groups.manage`) adds an existing contact to a group; receipts in
`sms_marketing_automation_action_receipts`. No action sends a message. Report providers (tagged with
`ReportProvider::TAG`, docs/REPORTS.md): `sms_marketing.campaigns` and `sms_marketing.daily`, both behind
`sms_marketing.analytics.view`.

## 13. Routes, pages and props

All under `/sms-marketing`, middleware `auth:web`, `auth.session`, `member`, `subscribed`, `entitled:sms_marketing`
and the `can:` shown. Page names are paths under `resources/js`.

| Route name | Verb and URL | Permission | Fields |
|---|---|---|---|
| `sms_marketing.campaigns.index` | GET `campaigns` | `campaigns.view` | query `status`, `q` |
| `sms_marketing.campaigns.create` / `.store` | GET / POST `campaigns/create`, `campaigns` | `campaigns.create` | `name`, `template_id?`, `body?`, `sender_identity_id?`, `group_ids[]` |
| `sms_marketing.campaigns.estimate` | POST `campaigns/estimate` (throttle 60/min, JSON) | `campaigns.create` | `body`, `group_ids[]`; JSON `{sample, audience, suffix}` or 422 |
| `sms_marketing.campaigns.show` | GET `campaigns/{campaign}` | `campaigns.view` | query `status` |
| `sms_marketing.campaigns.edit` / `.update` | GET / PUT `campaigns/{campaign}` | `campaigns.edit` | as store |
| `sms_marketing.campaigns.destroy` | DELETE `campaigns/{campaign}` | `campaigns.delete` | none |
| `sms_marketing.campaigns.launch` | POST `campaigns/{campaign}/launch` (throttle 20/min) | `campaigns.launch` | `scheduled_for?` (`Y-m-d\TH:i`, workspace time) |
| `sms_marketing.campaigns.cancel` / `.retry` | POST `campaigns/{campaign}/{cancel\|retry}` | `campaigns.launch` | none |
| `sms_marketing.campaigns.export` | GET `campaigns/{campaign}/export` (throttle 10/min) | `campaigns.view` | none |
| `sms_marketing.groups.index` / `.show` | GET `groups`, `groups/{group}` | `groups.view` | show: query `contact_q` |
| `sms_marketing.groups.store` / `.update` / `.destroy` | POST `groups`, PUT / DELETE `groups/{group}` | `groups.manage` | `name`, `description?` |
| `sms_marketing.groups.members.store` / `.destroy` | POST `groups/{group}/members`, DELETE `groups/{group}/members/{member}` | `groups.manage` | `contact_ids[]` (up to 200) |
| `sms_marketing.groups.import` / `.template` | POST `groups/{group}/import` (throttle 10/min), GET `groups/template` | `groups.manage` | `file` (csv) |
| `sms_marketing.groups.export` | GET `groups/{group}/export` | `groups.view` | none |
| `sms_marketing.templates.index` | GET `templates` | `templates.view` | none |
| `sms_marketing.templates.preview` | POST `templates/preview` (JSON) | `templates.manage` | `body`, `purpose` |
| `sms_marketing.templates.store` / `.update` / `.destroy` | POST `templates`, PUT / DELETE `templates/{template}` | `templates.manage` | `name`, `purpose`, `body`, `is_active` |
| `sms_marketing.senders.index` | GET `sender-ids` | `senders.view` | none |
| `sms_marketing.senders.store` / `.update` / `.destroy` | POST `sender-ids`, PUT / DELETE `sender-ids/{sender}` | `senders.manage` | `label`, `value`, `type`, `registration_status`, `registration_reference?`, `registration_note?` |
| `sms_marketing.analytics.index` | GET `analytics` | `analytics.view` | query `from`, `to` (`Y-m-d`) |
| `sms_marketing.settings.index` / `.update` | GET / PUT `settings` | `settings.manage` | `daily_cap`, `throttle_per_minute`, `opt_out_wording?`, `segment_cost_amount?` (minor units), `default_sender_id?` |

Pages (`Modules/SmsMarketing/Pages/...`), props exactly as the controllers send them:

| Page | Props |
|---|---|
| `Campaigns/Index` | `campaigns` (paginator of CampaignRow), `filters {status, q}`, `statuses`, `standing`, `canCreate` |
| `Campaigns/Form` | `campaign: CampaignDetail or null`, `form {groups, templates, senders, defaultSenderId, optOutWording, timezone, limits, channel}`, `canEstimate` |
| `Campaigns/Show` | `campaign: CampaignDetail`, `recipients` (paginator of Recipient), `filters {status}`, `statuses`, `sample` (SegmentCount), `channel` |
| `Groups/Index` | `groups` (paginator of Group), `canManage`, `contacts {limit, used}` |
| `Groups/Show` | `group`, `members` (paginator), `candidates`, `filters {contact_q}`, `imports`, `importInfo {columns, maxRows, maxBytes}`, `contacts`, `canManage` |
| `Templates/Index` | `templates`, `variables {marketing, transactional}`, `purposes`, `maxBody`, `canManage` |
| `Senders/Index` | `senders`, `types`, `statuses`, `canManage` |
| `Analytics/Index` | `report {totals, deliveryRate, daily}`, `standing`, `campaigns`, `cost {segmentAmount, currency}`, `range` |
| `Settings/Index` | `settings`, `senders`, `channel`, `timezone` |

**CampaignRow**: `id, name, status, holdReason, scheduledFor, startedAt, completedAt, estimatedRecipients,
estimatedSegments, stats {recipients, pending, queued, sent, delivered, failed, blocked, skipped, accepted, segments},
createdAt, owner`. **CampaignDetail** adds `body, templateName, groups, groupIds, templateId, senderIdentityId,
sender, maxAttempts, retryable, abilities {edit, delete, launch, cancel, retry}`. **Standing**: `channel {state,
reason, testDouble}`, `plan {limit, used, remaining}`, `contacts {limit, used}`, `credit {reported, balance}`,
`dailyCap {cap, used}`. Widget `sms_marketing.overview`: `{sending, scheduled, onHold, segmentsUsed, segmentsLimit,
channelAvailable, channelTestDouble, nextScheduledFor}`.

## 14. Configuration

`config/sms_marketing.php` (no credentials): `max_segments`, `max_body_length`, `max_audience`, `batch_ceiling`,
`defaults`, `bounds`, `max_attempts`, `retry_backoff_minutes`, `reconcile_after_minutes`, `import`,
`permanent_failure_codes`. Provider credentials are the Communication module's: an operator provider account
(encrypted in the database, ADR 0013) or an entry of `config/communication.php` (`credentials`). They are resolved
by the platform, never by this app.

## 15. Tests

`tests/Unit/SmsMarketing` (segment table including Bengali, extension characters and surrogate pairs; phone numbers;
sender ids; template variables; formula-safe CSV), `tests/Feature/SmsMarketing` (tenant isolation of every model and
404 for every id route, entitlement and dependency 404, permissions and own-scope, sending, consent blocking,
idempotency, daily cap, throttle, schedule, cancel, quiet hours, channel loss, status webhooks, bounded retries,
reconciliation, plan limits, CSV, merge, automation, reports) and `tests/js/smsMarketing.test.ts`. Helper:
`Tests\Support\InteractsWithSmsMarketing` (`smsTenant`, `smsContact`, `smsGroup`, `smsCampaign`, `launchNow`,
`runCampaigns`; builds on `InteractsWithCommunication::connectFakeProvider`).

## 16. Not built, and owner decisions

- **SMS adapters are unverified against live providers.** `twilio` and `http_sms` exist (Communication module,
  configured as provider accounts), tested with fakes only. Which Bangladeshi or international gateway the owner
  contracts with, and that gateway's request, authentication and success format, still have to be confirmed by
  sending a real message; the generic adapter expresses one request per message with a configurable success text
  and cannot cover a gateway that needs signed bodies, OAuth, batches or XML. A gateway-specific adapter belongs in
  the Communication module's `ProviderRegistry`.
- **SMS delivery receipts are not consumed** (Twilio status callbacks, gateway DLR URLs). Delivery rate stays
  "Not reported" until an adapter parses them.
- **Transactional SMS (OTP and notices) is not sent by this app.** It needs a core contract for non-marketing
  sends (the Communication composer and its automation action can already send a transactional template to a
  contact).
- **Per-message sender id.** `MarketingMessage` has no sender field; sender ids are records until the contract
  carries one.
- **Inbound "STOP" replies** need inbound SMS (`ProviderEventType::Inbound`) handled by the platform and mapped to
  an opt-out; today opt-out is the one-click link in every message.
- **Provider credit balance** needs a balance call in the adapter contract.
- Regulator registration (BTRC and operator rules for masking ids, DND lists) is the workspace's own record; the
  rules differ by operator and are not encoded or checked.
- The opt-out line adds about 110 characters; a Unicode (Bengali) campaign pays for it at 67 per segment.
