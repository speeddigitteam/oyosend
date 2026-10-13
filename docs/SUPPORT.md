# Customer Support

`app/Modules/Support` (key `support`, category Customer Support, free offer `included`, depends only on `core`).
Spec: `MASTER_PROMPT.md` section 20. Tickets, queues and assignment, a conversation thread with internal notes,
canned responses, SLA policies on business-hours calendars, satisfaction surveys with single-use links, an
internal knowledge base, merge and split, and reports.

Customers are the workspace's shared contacts and companies (`contact_id` / `company_id`): the app has no customer
table. `requester_name` / `requester_email` only describe someone who is not in the directory yet.

> State: backend and Vue pages (`resources/js/Modules/Support`) are built. There is no mail ingestion: `Contracts\InboundTicketReceiver` is the documented entry
> point a channel integration calls (section 11). The knowledge base is internal (agents); there is no public help
> centre and no customer portal.

## 1. Tickets

| Concept | Rule |
|---|---|
| Number | `{prefix}{zero-padded counter}` (default `TKT-00001`), per workspace, gap-free: the counter row is locked and incremented inside the creating transaction, so a rollback gives the number back. Merge and split never reuse or remove a number. `UNIQUE(tenant_id, number)` |
| Status | `new`, `open`, `waiting_customer`, `on_hold`, `resolved`, `closed`, `merged`. Active = the first four. Resolved can go to open (reopen) or closed; closed to open; `merged` is terminal and read-only |
| Priority | `low`, `normal`, `high`, `urgent` |
| Channel | `web`, `email`, `phone`, `chat`, `api`, `internal` (plus `api` for automation) |
| Assignee | `owner_id` (a tenant membership). It is **not** the creator: a ticket filed by an agent stays unassigned until routing or a person assigns it. Department/branch are copied from the assignee, or from the queue's department while unassigned |
| Tags | Free text up to 60 characters, at most 10 per ticket, case-insensitively unique per workspace |
| History | `support_ticket_events` is append-only (the model refuses updates): created, updated, assigned, status/priority/queue changes, replies, notes, merge/split, SLA pause/resume/breach, survey events |
| Idempotency | `source_key` (`UNIQUE(tenant_id, source_key)`) makes inbound delivery and automation creation repeat-safe: a repeat returns the first ticket and allocates nothing |

Every change goes through `Actions\TicketManager` (one transaction, the ticket row locked, history entry, audit
entry, domain event). `TicketMerger` does merge and split, `SurveyManager` the satisfaction surveys.

### Messages and attachments

Three kinds: `public_reply` (agent to customer), `customer_reply` (the requester; also what an agent logs on the
customer's behalf, e.g. a phone call) and `internal_note` (never leaves the workspace). Messages are plain text and
immutable; they only move between tickets through merge and split.

- A public reply is the **first response** (`first_responded_at`). An internal note is not.
- A customer reply on a waiting, resolved or closed ticket re-opens it and resumes the SLA clocks.
- An agent can log a customer reply (`kind=customer_reply`, for a phone call or a forwarded email). On a resolved or
  closed ticket that reopens it, so it needs `support.tickets.resolve` like any reopen. The message is shown as the
  customer's; the history entry carries the logging agent as `actor_id` and `logged_by`.
- A public reply on a closed ticket is refused ("reopen first"); an internal note is allowed.
- A reply can carry a status in the same step (`status` on the message request): reply and resolve atomically.

Attachments are private files in `TenantStorage` (`support/attachments`): at most 5 per message, 10 MB each, a
fixed extension allow-list (`AttachmentStore::EXTENSIONS`). The stored path is hidden from serialization and never
sent to a page. A download goes through `support.tickets.attachments.download`: ticket-scoped authorization,
`Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Content-Type: application/octet-stream`,
`Cache-Control: private, no-store`. There is no public URL.

## 2. Access

| Rule | Detail |
|---|---|
| Scopes | `support.tickets.{view,reply,edit,assign,resolve,merge}` are scopable. `own` = assigned to me, `department`, `branch`, `all` (`RecordAccess`, columns `owner_id` / `department_id` / `branch_id`) |
| Team rule | `Support\TicketAccess` adds one rule: an agent enrolled in a queue also reaches the **unassigned** tickets of that queue with any scope they hold, so a pool ticket can be picked up. Nothing else is widened |
| Assigning to others | Taking a ticket yourself (`claim`) needs `support.tickets.assign` in scope. Handing it to anybody else, unassigning others' work or round-robin auto-assignment needs the permission for `all` records |
| Assignees | Must be an **enrolled, active agent** (`support_agents`) with an active membership |
| Model trait | The ticket model uses `Models\Concerns\HasAssignee` (the `HasRecordOwner` column contract without its "creator becomes owner" hook), so `RecordAccess` works unchanged |
| Entitlement | Every route carries `entitled:support`; a workspace without the app gets 404 everywhere, including the survey link |

## 3. Queues, agents and assignment

- **Agents** (`support_agents`): a member enrolled to work tickets, with `is_active`, `is_available` and an optional
  `max_open_tickets`. Active agents count against the plan's `support.agents` limit (gauge).
  Enrolling or changing agents (`POST agents`, `PUT agents/{agent}`) also needs `support.tickets.assign` at scope
  `all`: an enrolled agent reaches the unassigned tickets of their queues with any scope, so the right is not
  something a narrower holder may hand out (to others or to themselves).
- **Queues** (`support_queues` + `support_queue_agents`): a team inbox with `assignment_mode` `manual` or
  `round_robin`, an optional department, an optional SLA policy. The queue form's `agent_ids` enrols or removes
  queue agents, which widens (or narrows) who reaches the queue's unassigned tickets: when the submitted set differs
  from the stored one (`POST queues`, `PUT queues/{queue}`) it needs `support.tickets.assign` at scope `all`, like
  the agent endpoints. An unchanged or omitted set passes, so a narrower manager can still edit the other fields.
- **Round robin**: the queue row is locked, eligible agents (active, available, active membership, under their
  capacity) are taken in id order after the last agent chosen (`last_assigned_agent_id`), then wrapping. If nobody
  is eligible the ticket stays unassigned in its queue.
- **Rule table** (`support_assignment_rules`, no code, no expressions): per rule optional equality columns
  `match_channel`, `match_priority`, `match_category_id`, `match_company_id` and one `match_keyword` (substring of
  the subject, case-insensitive); outcome `queue_id`, optional `assign_agent_id` (an agent of that queue) and
  optional `set_priority`. The first active matching rule by `position` wins. The company picker lists up to 500
  companies the manager may see, plus any company a rule already names; a company id that is not in this workspace is
  refused when the rule is saved.
- **Order of decisions**: an explicit queue/assignee/priority chosen by the person always wins; then the matching
  rule; then the category's default queue; then the workspace default queue; then the queue's assignment mode.

Ticket history entries for assignment and queue changes carry the ids (`from`, `to`); the ticket page adds
`fromName` / `toName` server-side (two tenant-scoped queries for the whole history, no per-row lookups), and a
deleted member or queue simply has no name. The edit dialog can change the contact and company from the same bounded
directory lists (500 each, only sent to someone who may edit); there is no directory search endpoint yet, so a
directory beyond 500 records cannot be fully offered there (open point for the owner).

## 4. SLA

Policies (`support_sla_policies`) hold first-response and resolution targets per priority in **integer minutes**
(`targets` JSON, validated to whole minutes between 0 and 525 600; resolution never shorter than first response)
and `pause_on_waiting`. A policy points at a business-hours calendar (`support_calendars`) or, without one, counts
around the clock. A queue's policy wins over the workspace default (`support_settings.default_sla_policy_id`).

`Support\BusinessCalendar` is the pure arithmetic: weekly windows per ISO weekday (several per day, `24:00`
allowed), holidays as local dates, a timezone per calendar (default: the workspace timezone). Instants are real
UTC timestamps; daylight-saving days are honoured because each local day's windows are converted separately.
`addMinutes()` walks forward through working time; `minutesBetween()` counts working minutes (floored).

| Event | Effect |
|---|---|
| Create | Due instants computed from the creation time |
| Waiting on customer | Only once the ticket has had its first response (`first_responded_at`): remaining working minutes stored, `sla_paused_at` set; breach detection skips the ticket. On a ticket nobody has answered yet nothing pauses, so the first-response clock keeps running; the first reply pauses it if the ticket is already waiting |
| Back to open / customer reply | Due instants recomputed from "now" with the stored remaining minutes |
| Resolved or closed while paused | Resumed first, so the ticket is judged on the time it had left |
| Priority change | Unmet clocks are re-targeted; time already used stays used |
| Queue change to another policy | Unmet clocks restart under the new policy from the ticket's creation |
| Reopen | A fresh resolution target counted from the reopen time; `resolution_breached_at` (the current cycle) is cleared, but a miss of the cycle that ended is kept in `resolution_first_breached_at` (stamped by the monitor, or derived from a resolution after its due instant) and reports judge resolution attainment on that first cycle |

**Breach detection**: scheduled job `support.sla` (every minute, entitled workspaces only, queue `automation`) runs
`SlaMonitor`. Each target of a ticket is stamped (`first_response_breached_at`, `resolution_breached_at`) and
announced (`support.ticket.sla_breached`, with `target` and `due_at`) exactly once under the ticket's row lock, so
running it twice or concurrently is harmless.

Changing a policy or calendar applies to clocks that start or restart afterwards; running clocks keep their
computed due instants.

## 5. Canned responses

Shared, workspace-wide text (`support.canned.manage` writes, `support.canned.view` reads and uses). Only the
placeholders of `PlaceholderRenderer::ALLOWED` are accepted: `requester.name`, `requester.email`, `ticket.number`,
`ticket.subject`, `agent.name`, `workspace.name`. Saving refuses unknown or malformed placeholders. Rendering is a
single-pass string substitution on the server (`support.tickets.canned.preview` returns the filled text as JSON);
there is no expression language and no recursion, so a value containing `{{ ... }}` is inserted as text.

## 6. Satisfaction surveys (CSAT)

- Requested when a ticket is resolved (or closed straight from an active status) if `csat_enabled`, or by an
  agent (`support.tickets.survey`). A new request supersedes an older pending one.
- An agent's request is limited to one new survey per ticket per 10 minutes (the survey created by resolving counts)
  and is audited as `support.csat.forced`.
- The token is 256 random bits, emailed to the requester when `csat_send_email` is on and an address is known. Only
  when no email carries it (`csat_send_email` off, or no address) is it shown once to the requesting agent (the
  `surveyInvitation` flash: `ticketId`, `url`); an emailed link is never flashed, so an agent cannot answer for the customer. The queued mailable is
  encrypted (`ShouldBeEncrypted`) and queued after commit. **Only the SHA-256 is stored**
  (`support_csat_surveys.token_hash`), never logged, audited or put in an event.
- Public, stateless routes: `GET /support-survey/{token}` (`support.csat.show`) and `POST /support-survey/{token}`
  (`support.csat.store`, 10/min). They are loaded with `loadTenantCallbackRoutes()` (`app/Modules/Support/callbacks.php`),
  i.e. the `tenant.callback` group like Communication's unsubscribe page: tenant from the host, `entitled:support`
  and `throttle:60,1`, **no session, no cookies, no CSRF token, no Inertia**. The page is the server-rendered Blade
  view `resources/views/support/survey.blade.php` in the workspace language; a failed validation re-renders it
  with status 422. Every response (GET, POST, 404) sends `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`
  and `Cache-Control: no-store, private`. The token must be 64 lowercase hex characters; malformed, unknown, answered, superseded and
  expired are the same 404 page. Because the group has no `subscribed` paywall, a link keeps working for a
  workspace whose subscription lapsed.
- **Single use**: answering locks the row, then a conditional `UPDATE ... WHERE status = 'pending'` flips it to
  `answered` and nulls the hash, so two simultaneous submissions count once. Pending surveys expire after
  `csat_expiry_days` (the daily maintenance job also clears their hashes).
- The page shows only the workspace name, ticket number and subject. Rating 1 to 5, comment up to 2 000 characters.
- Result: ticket history entry, audit entry `support.csat.answered` and the `support.csat.submitted` event
  (rating, ticket ids; no comment text).

## 7. Knowledge base

Categories (`support_kb_categories`) and articles (`support_kb_articles`): status `draft`, `published`,
`archived`. Every save of changed text adds an immutable row in `support_kb_article_versions`; an earlier version
can be restored (it becomes a new version). Editing a published article changes the live text at once. Everyone
with `support.kb.view` reads and searches **published** articles; drafts, archived articles and the version list are
for `support.kb.manage`; publishing, unpublishing and archiving need `support.kb.publish`. Search uses
`App\Core\Support\Like` over title, summary and body.

## 8. Merge and split

- **Merge** (`support.tickets.merge`): moves every message and attachment and the tags of the source onto the
  target, adopts the source's requester fields where the target has none and its first response only when the requesters match (shared contact, company or email) and it is the earlier one, expires the source's
  pending surveys, repoints earlier merges (no chains) and marks the source `merged` with `merged_into_id`. Refused:
  merging a ticket into itself, either ticket already merged, a target that is resolved or closed (reopen it first),
  and two tickets with no common contact, company or requester email unless `confirm` is sent. Both tickets get a
  history entry; the whole operation is one transaction with both rows locked in id order.
- **Split**: moves chosen messages (and their attachments) into a new ticket of the same requester, category,
  queue, priority and tags, linked by `split_from_id`. At least one message must stay behind. The new ticket takes
  the next number and counts as a ticket against the plan limit.

## 9. Reports

`Actions\SupportReports::overview(from, to, queue?)` (`support.reports.view`, workspace-wide): volume (created
and resolved per local day), backlog (by status, priority, queue; unassigned; aging buckets), SLA attainment
(met / missed / pending, rate in basis points), first-response and resolution times (average and median, whole
minutes of wall-clock time) and CSAT (requested, responses, response rate, average in hundredths, satisfied share,
distribution). Resolution attainment is judged on the first resolution cycle (a reopened ticket that missed its
target stays missed). Callers that need one figure pass `sections` (`volume`, `backlog`, `sla`, `times`, `csat`); the
report providers do, so each scans only what it shows. Ranges are local dates in the workspace timezone, at most 366 days. Merged tickets are excluded.

Every figure is computed in SQL on the tenant-scoped ticket query, and no ticket is hydrated: GROUP BY for volume (15-minute UTC
buckets mapped to local days in PHP, so daylight saving and half-hour zones stay exact), backlog (status, priority, queue,
owner IS NULL, aging band) and CSAT ratings; conditional SUM/COUNT for SLA outcomes, counts and averages; medians read
the one or two middle rows of an ordered, bounded (LIMIT/OFFSET) query. `tests/Feature/SupportDesk/SupportReportsAggregateTest.php`
compares it with a per-ticket reference over a few hundred generated tickets in three timezones. Measured with
`php artisan ops:benchmark --tickets=6000`: about 4.9 s before, about 0.26 s after, identical output.

## 10. Plan limits, events, automation

- Limits: `support.tickets_per_month` (metered; recorded with idempotency key `ticket:{id}` in the creating
  transaction; replies, merges and status changes do not count, a split does) and `support.agents` (gauge).
- Events (after commit, ids and scalars, never message text, names or emails): `support.ticket.created`,
  `.assigned`, `.replied`, `.customer_replied`, `.resolved`, `.closed`, `.reopened`, `.merged`, `.split`,
  `.sla_breached`, and `support.csat.submitted`.
- Triggers: `support.ticket.created`, `.assigned`, `.customer_replied`, `.resolved`, `.sla_breached` and
  `support.csat.submitted`.
- Actions (durable receipts in `support_automation_action_receipts`, `UNIQUE(tenant_id, action,
  idempotency_key)`): `support.ticket.create` (the key becomes the ticket's `source_key`), `support.ticket.assign`
  (a named agent, or the queue's next round-robin agent) and `support.ticket.add_note` (internal only). There is
  deliberately **no action that replies to a customer**, resolves, merges or closes.
- Scheduled jobs: `support.sla` (every minute) and `support.maintenance` (daily: closes tickets resolved more than
  `auto_close_days` ago when configured, expires unanswered surveys).
- Contact merge: `SupportContactMergeReference` repoints `support_tickets.contact_id` during a core contact merge.

## 11. Inbound contract

`App\Modules\Support\Contracts\InboundTicketReceiver::receive(InboundTicketMessage): InboundTicketResult`,
implemented by `Actions\InboundTicketHandler` and bound in the container. Documented in the class comments of
`InboundTicketMessage`: `channel`, `externalId` (the idempotency key), `fromEmail`, `fromName`, `subject`, `body`,
`inReplyToTicketNumber`, `receivedAt`. The handler matches the sender to a shared contact by email, threads a
message into an existing ticket only when the sender is that ticket's requester (a guessable ticket number alone
never grants write access; merges are followed), and otherwise opens a ticket through the same path as the UI
(numbering, limits, routing, SLA). A repeat of the same `channel + externalId` returns `duplicate: true` and
changes nothing. **Nothing calls it yet and no mail ingestion exists or is simulated.** A future channel app calls
it inside `TenantContext::run()`.

## 12. Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `support.tickets.view` | yes | list and read tickets |
| `support.tickets.create` | no | open a ticket |
| `support.tickets.reply` | yes | public replies, internal notes, status changes that do not finish a ticket |
| `support.tickets.edit` | yes | subject, priority, category, queue, requester, tags |
| `support.tickets.assign` | yes | claim; handing to others needs `all` |
| `support.tickets.resolve` | yes | resolve, close, reopen, request a survey |
| `support.tickets.merge` | yes, sensitive | merge and split |
| `support.canned.view` / `.manage` | no | |
| `support.kb.view` / `.manage` / `.publish` | no | |
| `support.queues.manage` | no, sensitive | agents, queues, categories, assignment rules |
| `support.sla.manage` | no, sensitive | policies and calendars |
| `support.settings.manage` | no, sensitive | prefix, defaults, CSAT, auto-close |
| `support.reports.view` | no, sensitive | workspace-wide |

## 13. Routes

All under `/support` on the workspace host with `['auth:web', 'auth.session', 'member', 'subscribed']` and `can:`;
the two survey routes are the only public ones (stateless, see section 6).

| Name | Verb and path | Permission |
|---|---|---|
| `support.tickets.index` / `.create` / `.store` / `.show` / `.update` | GET `tickets`, GET `tickets/create`, POST `tickets`, GET/PUT `tickets/{ticket}` | view / create / create / view / edit |
| `support.tickets.messages.store` | POST `tickets/{ticket}/messages` | reply (+ resolve to finish or reopen) |
| `support.tickets.status` | POST `tickets/{ticket}/status` | reply (+ resolve to finish or reopen) |
| `support.tickets.assign` / `.claim` | POST `tickets/{ticket}/assign`, `.../claim` | assign |
| `support.tickets.merge` / `.split` | POST `tickets/{ticket}/merge`, `.../split` | merge |
| `support.tickets.survey` | POST `tickets/{ticket}/survey` | resolve |
| `support.tickets.attachments.download` | GET `tickets/{ticket}/attachments/{attachment}` | view |
| `support.tickets.canned.preview` | GET `tickets/{ticket}/canned/{canned}` (JSON) | reply |
| `support.canned.*` | `canned` index / store / update / destroy | view / manage |
| `support.kb.*` | `kb`, `kb/articles/...`, `kb/categories/...` | view / manage / publish |
| `support.reports.index` | GET `reports` | reports.view |
| `support.queues.index`, `support.queues.store/update`, `support.agents.store/update`, `support.categories.store/update`, `support.rules.store/update/destroy` | `queues`, `agents`, `categories`, `rules` | queues.manage |
| `support.sla.index`, `support.sla.policies.store/update`, `support.sla.calendars.store/update` | `sla/...` | sla.manage |
| `support.settings.index/update` | GET/PUT `settings` | settings.manage |
| `support.csat.show` / `.store` | GET/POST `/support-survey/{token}` (stateless callback group, Blade page) | none (token) |

## 14. Not built

Email/chat ingestion (contract only), a customer portal, a public help centre, outbound replies by email (an
agent's public reply is stored and shown; delivery to the customer needs the Communication app), business-hours
queues per agent, custom ticket fields, ticket-to-task links with Projects, and AI suggestions.
