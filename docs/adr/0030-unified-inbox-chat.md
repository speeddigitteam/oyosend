# ADR 0030 — The unified inbox reads and replies through an optional `InboxReplies` contract; the owning app still sends

- **Status:** Proposed, built (Communication 1.2.0; the full-height chat screen and the list refresh in 1.3.0; the
  server-side Unread filter and "Suggest a reply" in 1.4.0). Not
  reviewed by the owner.
- **Date:** 2026-10-14
- **Amends:** ADR 0020 decision 4 ("Replies stay in the owning app ... An embedded reply pane would need a reply
  contract and is out of scope"). Everything else in ADR 0020 holds.

## Context

The owner wants every channel's messages in one place and to chat from that one place. ADR 0020 built the unified
inbox (`/communication/inbox`) as a read-only list: every item links to the owning app (the WhatsApp inbox, the
support ticket page), and a member must leave the inbox to answer.

Each owning app already has a reply action that enforces its channel's rules:

- WhatsApp: `Messenger::reply()` keeps the 24-hour customer-service window, needs a linked contact whose phone matches
  the conversation, and sends only through `ChannelGateway` (consent, suppression, quiet hours, provider).
- Facebook comments (Social, ADR 0025): `CommentActions::reply()` calls the platform through `CommentGateway`, stores
  the reply in the thread, clears the comment's attention flag and audits it.

Messenger, Instagram direct messages and a website chat are planned as further channels and must be able to join the
same screen without changes to Communication.

## Decision

1. **An optional second contract, `App\Core\Communication\Contracts\InboxReplies`.** A source class implements it
   beside `InboxSource`, under the same tag (`communication.inbox_sources`). The inbox finds it by the source's key.
   - `thread(string $id, User $viewer, int $limit = 50): ?InboxThread`: the conversation and its newest `$limit`
     messages, oldest first (at most `MAX_LIMIT` = 200), or null when it does not exist or the viewer may not see it.
     It changes nothing itself.
   - `reply(string $id, string $text, User $viewer): InboxMessage`: hands the text to the owning app's existing action
     as the viewer's membership. A refusal is a `ValidationException` on `text` with a reason a person can act on; a
     conversation the viewer may not see or answer is a `NotFoundHttpException`.
   - `replyPermission(): string`: the permission the owning app's own reply route requires.
   - Value objects: `InboxThread` (source key, id, title, channel, contact, owning app URL, `canReply`,
     `replyBlockedReason`, messages, `hasOlder`, optional subtitle) and `InboxMessage` (id, `in`/`out`, text, UTC time,
     author name, delivery status, attachment label, and an optional last `attachmentKind`: `image`, `video`, `audio`
     or `file` where the source stores the type, for the chip's icon). Message text is untrusted and is rendered as
     text; an attachment is described by a label only, its file is never fetched.
   - A source without the contract keeps opening its own app. Every source implements it today; Support tickets
     joined on 2026-10-14 (the requester's messages and public replies, never internal notes; a reply is a public
     reply through `TicketManager::addMessage()`).
   - **Read state, a third optional contract: `MarksInboxRead::markRead(string $id, User $viewer): void`.** A source
     whose conversations have an unread state implements it beside `InboxReplies`. **Mark-read is an explicit POST,
     never a side effect of a GET**: loading the page, opening a thread with `?open=` and every poll are read-only,
     so a crafted link cannot mark anything read.
     - `POST /communication/inbox/{source}/{id}/read` (`communication.inbox.read`, `can:communication.inbox.view`,
       120 per minute, the workspace `web` group with its session and CSRF check) calls `UnifiedInbox::markRead()`.
       That resolves the source with the same entitlement, view-permission and `InboxReplies` checks as a thread, uses
       the source's own `thread($id, $viewer, 1)` as the visibility guard (404 otherwise), and then calls `markRead()`
       when the source implements `MarksInboxRead`. For any other chat source it is a silent no-op. It answers 204 to
       the page's background call and redirects back for a plain form.
     - The page posts it while the member looks at the conversation: when a thread of a source with an unread state
       opens with an unread badge on its row (or its row is not in the loaded list), and when a poll brings new inbound
       messages. Never while the tab is hidden: the read waits for a visible look. The badge clears at once; the list
       is not reloaded. The thread carries `marksRead` for this.
     - The source clears the state exactly as its own conversation page does: WhatsApp calls
       `ConversationManager::markRead()` on the conversation in the viewer's view scope, like
       `whatsapp_marketing.inbox.read`. Facebook comments have no unread state ("awaiting an answer" is cleared only by
       answering or marking handled), so that source does not implement it.

2. **The inbox never sends.** `UnifiedInbox::reply()` checks only the gates it owns and hands over:
   - the source must be available to the member: workspace entitled to `definition()->module`, member holding
     `definition()->permission`, and the source implementing `InboxReplies`. Otherwise **404**;
   - the member must hold `replyPermission()`. Otherwise **403**;
   - the conversation must be one the member may open (`thread($id, $viewer, 1)` is not null). Otherwise **404**;
   - then `reply()` on the source, which calls the owning app's action. Channel rules are never duplicated or
     bypassed in Communication. A source may show the same rules in advance (`replyBlockedReason`) for display, but
     the owning action decides again on every reply.

3. **Record scope stays with the source.** A source narrows a thread exactly as its own conversation page does and
   checks the owning app's reply scope on the record that is answered. A conversation of another workspace, outside
   the member's scope, or missing gives the same answer: no thread on the page, a 404 on a reply.

4. **No new permissions.** Reading a thread needs `communication.inbox.view` (route) plus the source's view permission;
   replying additionally needs the owning app's reply permission: `whatsapp_marketing.conversations.reply` or
   `social.comments.reply`. The permission count is unchanged.

5. **One page, a partial reload, polling.**
   - `GET /communication/inbox?open={source}:{id}` opens the conversation beside the list. `thread` is a lazy prop, so
     a partial reload of `thread` computes only the thread. `messages` (1 to 200) loads older messages. When the
     conversation cannot be shown (missing, foreign, out of scope, a source without chat or not available to the
     member) `thread` is null, on the first open and on a poll alike, and the page says "This conversation is no longer
     available", stops polling and offers the way back to the list. Null is the same for every reason, so nothing is
     disclosed; the error page is not used, because a poll can meet a conversation the member has just lost.
   - `POST /communication/inbox/{source}/{id}/reply` (`communication.inbox.reply`, `can:communication.inbox.view`,
     30 per minute) validates `text` (required, at most 4096 characters) and redirects back.
   - While a thread is open the page reloads only `thread` every 15 seconds, skipped while the browser tab is hidden
     and never two at once. Realtime (Reverb) is not installed; polling is the honest minimum until it is. (Amended by ADR 0035: a content-free
     realtime signal makes the page ask at once while a Reverb server runs; polling stays as the fallback.)
   - **The list refreshes itself (1.3.0).** Every 20 seconds while the tab is visible, one request at a time, the page
     asks `GET /communication/inbox/poll` (`communication.inbox.poll`, `can:communication.inbox.view`, 60 per minute on
     its own throttle key) for the newest page of the same filters as JSON and merges it into the rows on screen:
     updated rows move up, new ones come first, the pages loaded with "Load more" and the paging position stay. It is
     read-only like every GET here. A JSON endpoint rather than a partial reload of `list`, because a reload of `list`
     would replace the loaded pages and Inertia's page URL still carries the last `after` position.
   - **The chat screen (1.3.0).** The list view fills the window below the shell and both panes scroll on their own;
     the conversation reads like a messenger (avatars, day separators in the workspace timezone, grouped bubbles,
     delivery ticks normalised from each source's statuses, older messages on scrolling up, a "sending" bubble until the
     server answers). When a reply cannot be written, the reason and an "Open in app" link replace the composer. Message
     text stays text; only `http`/`https` addresses become links, with `rel="noopener noreferrer nofollow"`.
     `docs/COMMUNICATION.md` section 8a has the details.
   - **Channels.** The page shows a generic icon (lucide, never a brand logo) and a translated name for the channels
     `whatsapp`, `facebook`, `messenger`, `instagram`, `web` and `support`
     (`communication.inbox.channels.*`). Any other channel a source names gets a generic icon and the source's own
     label, so a new source needs no change to the Communication pages.

6. **First two chat sources.**
   - `whatsapp_marketing.conversations`: thread narrowed by `ConversationAccess` like the WhatsApp inbox; reply through
     `Messenger::reply()`. Outside the 24-hour window the composer is disabled and says that an approved template must
     be sent from the WhatsApp inbox (templates stay there).
   - `social.comments` (new source, Facebook): one item is a top-level comment someone wrote on a connected Page's
     post, with its replies. Narrowed as on the comments page (`social.comments.view` scope, never the Page's own
     comments). A thread awaits an answer while one of its audience comments is neither handled nor deleted; the item
     is then `unassigned` or `active` by the top-level comment's assignee, otherwise `closed`, and its unread count is
     the number of unanswered comments. Ordering uses the newest comment of the thread. A reply from the inbox answers
     the newest unanswered audience comment (or, when none awaits, the newest one that was not deleted, else the
     top-level comment) through `CommentActions::reply()`. Thread reads go by `parent_id` on the index
     `social_audience_comments_thread (tenant_id, parent_id, commented_at)` (Social migration
     `2026_11_07_000100`, additive and guarded).

## Consequences

- A new channel (Messenger, Instagram direct messages, website chat) joins the chat screen by implementing
  `InboxReplies` (and `MarksInboxRead` when it has an unread state) on its source. Communication does not change.
- Each chat source needs its own scope test, a cross-workspace test that the thread is null and cross-workspace 404
  tests on the reply and the read.
- Polling costs one thread query set per open thread every 15 seconds per visible tab, plus one small POST when
  something new was read.
- **No read on a GET.** Following a link with `?open=` shows the conversation and changes nothing; only the page's own
  POST, which needs the session and the CSRF token, marks it read. The cost is a second request when a conversation
  is read.
- **Facebook list cost.** Ordering threads by their newest comment groups all of a workspace's comments on every list
  and count query (about 0.2 to 0.3 s for 15,000 comments in 6,000 threads on a development machine). The index makes
  the per-thread reads fast but not that grouping; a stored thread id and last-activity time on the comment, written by
  the Social app, would. Re-measure before a workspace reaches that size.
- **Facebook threads.** One inbox reply answers one comment; an older unanswered comment in the same thread keeps the
  thread open until it is answered or marked handled in the Social app. Thread activity and the preview follow
  comments up to two levels below the top-level comment. Members whose scope reaches only a reply, not its top-level
  comment, see that reply on the Social comments page but not in the inbox (narrower, never wider).
- **Lost access while open.** If a member loses access to an open conversation, the next poll returns no thread and
  the pane says it is no longer available; nothing else on the page changes.
- WhatsApp templates, interactive messages, media, notes, assignment and closing stay in the WhatsApp app; comment
  moderation (hide, delete, like, assign, tag, notes) stays in the Social app. The thread's "Open in app" link goes
  there.

## Alternatives considered

- **Inbox sends through `ChannelGateway` itself.** Rejected: it would duplicate the window, contact, consent and
  comment rules each app already enforces, and Communication would learn every channel's rules.
- **A required reply method on `InboxSource`.** Rejected: Support tickets (and any read-only source) would need a stub,
  and a missing implementation should mean "opens its own app", not an error.
- **A JSON endpoint for the thread.** Not chosen: an Inertia partial reload keeps one page, deep links work, and
  errors use the application's error page. (The list refresh does use a small read-only JSON endpoint; see decision 5.)
- **A server-side "unread only" filter for the Unread chip.** First not built (the chip narrowed the rows already
  loaded); built in 1.4.0, see the note of 2026-10-14 below.
- **One inbox item per Facebook comment, as on the Social comments page.** Not chosen: a chat screen lists
  conversations, and a customer's follow-up under their own comment belongs to the same conversation.

## Note, 2026-10-14: server-side Unread filter and "Suggest a reply" (Communication 1.4.0)

- **Unread is a server filter.** `InboxQuery` gained `unread` (default false, so existing callers are unchanged) and
  the page, the board and `communication.inbox.poll` take `unread=1`, validated like the other filters and kept in
  `filters`. Every source narrows by the same notion that makes its items' `unread` greater than zero (WhatsApp,
  Messenger/Instagram and website chat `unread_count > 0`; tickets awaiting a reply; Facebook threads with a comment
  awaiting an answer) in `items()` and `counts()`; a source without one returns nothing. The chip now visits the page
  like the others, so paging and the 20-second refresh follow it. No index was added: each filter is one more
  condition on the tenant-leading index the source already walks (`docs/COMMUNICATION.md` section 8a).
- **Suggest a reply.** `POST /communication/inbox/{source}/{id}/suggest` (`communication.inbox.suggest`,
  `can:communication.inbox.view`, `throttle:20,1,inbox-suggest`) returns an AI draft for the composer through the core
  `TextAssistant` contract (ADR 0024, `TextKind::ReplySuggestion`). The conversation is resolved with the same gates
  as a reply (`UnifiedInbox::answerable()`: 404 for a conversation the member may not open or answer, 403 without the
  source's reply permission, 422 when the channel refuses a reply now). It sends, saves and marks nothing; the member
  edits and sends the text through the normal reply, which applies every channel rule again. The button is offered
  only when the thread can be answered and the workspace has the AI app (`aiDrafting`); whether AI is configured,
  switched on and within its allowance is answered by the contract on each request and shown as its safe message.
  Communication imports no class of the AI app.
