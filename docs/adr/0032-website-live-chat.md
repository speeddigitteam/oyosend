# ADR 0032 — Website live chat: a stateless widget on the workspace host, answered from Support and the unified inbox

- **Status:** Proposed, built (Support 1.1.0; amended in 1.2.0, see the amendment below). Not reviewed by the owner. Not run in a real browser on a second origin.
- **Date:** 2026-10-14
- **Builds on:** ADR 0020 (unified inbox) and ADR 0030 (`InboxReplies`, `MarksInboxRead`). Changes no locked decision.

## Context

The owner wants every chat channel answered from one place. WhatsApp conversations, Facebook comments and (ADR 0031)
Messenger and Instagram messages already reach the unified inbox. A workspace's own website had no channel: a visitor
could not write to the business without leaving the site.

A website chat differs from the other channels in three ways. The visitor is anonymous (no provider identifies them),
the page that hosts the chat belongs to the customer, on any domain, and there is no provider in between: Oyosend itself
serves the widget and receives the messages. That makes it public, unauthenticated, cross-site input into a tenant.

## Decision

1. **Inside the Support app, no new module.** Conversations are a Support channel, like tickets. Three tenant-owned
   tables (`support_chat_settings`, `support_chat_conversations`, `support_chat_messages`), one action class
   (`LiveChatManager`) that the Support "Live chat" page and the inbox source both call, and one inbox source
   (`support.live_chat`, channel `web`, label "Website chat") implementing `InboxSource`, `InboxReplies` and
   `MarksInboxRead`. Off by default.

2. **Stateless public endpoints on the workspace host.** `widget.js`, the chat `frame` and a small JSON API are loaded
   through `loadTenantCallbackRoutes()` (group `tenant.callback` plus `entitled:support`): the workspace comes from the
   hostname only; there is no session, no cookie, no CSRF token and no Inertia. Everything answers 404 while the chat is
   off, the workspace is suspended or locked, or not entitled to Support.

3. **An iframe, framed only by the origins the workspace lists.** The customer's page loads `widget.js` (a few dozen
   lines of vanilla JavaScript: a launcher button and, once opened, an iframe). The chat itself runs in the frame, on the
   workspace origin, so the customer's page can neither read nor alter it. The frame sends
   `Content-Security-Policy: frame-ancestors <allowed origins>` (`'none'` and `X-Frame-Options: DENY` when the list is
   empty) with `default-src 'self'`, `script-src` and `style-src` limited to a per-response nonce, `connect-src 'self'`,
   `form-action 'none'`, `base-uri 'none'`, `object-src 'none'`. Allowed origins are canonical `https://host[:port]`
   (at most 10, no path, wildcard, credentials or IP address); plain `http` only for `localhost` / `*.localhost` while the
   application runs locally. The page and the loader talk through `postMessage`: the loader accepts only messages whose
   `event.origin` is the workspace origin and whose source is its own iframe; the frame accepts only messages from its
   parent on an allowed origin, and the loader posts only to the workspace origin.

4. **A hashed visitor token.** Starting a chat returns a 256-bit random token (base64url, 43 characters) once; only its
   SHA-256 is stored (`UNIQUE(tenant_id, visitor_token_hash)`). The frame keeps it in its own `localStorage` (inside
   `try/catch`; without storage the chat lasts for the page) and sends it in the `X-Chat-Visitor` header. A token reaches
   exactly one conversation of exactly one workspace; a missing, wrong or foreign token is a 404. One conversation per
   token: a token holder cannot start a second one (409).

5. **Same-origin API.** The API is called only by the frame, from the workspace origin. A request is refused (403) unless
   its `Origin` header equals the workspace origin, or, with no `Origin` (a same-origin GET), it carries
   `Sec-Fetch-Site: same-origin`. No CORS headers are sent. This stops other websites' pages; a script outside a
   browser can send any header, and the token and the limits bound what it can do.

6. **Polling, no Reverb.** The frame polls `GET api/messages?after={id}` every 5 seconds while the chat is open and the
   page is visible, backs off up to 2 minutes while it is hidden or after an error, and stops while the chat is closed.
   "After" is the message's `seq`, a per-conversation counter assigned under the conversation's row lock, so a poll never
   skips a message written in the same millisecond by another process. The Support page reloads its list and thread
   every 10 seconds while visible; the unified inbox polls as ADR 0030 describes. Realtime needs Reverb, which is not
   installed.

7. **Abuse limits** (`Support\LiveChatLimits`), on top of the group throttle (60 requests a minute per address):
   starting is limited to 10 attempts a minute and 5 successful starts an hour per address, and 300 starts an hour per
   workspace; sending to 20 messages a minute per token and 40 per address; polling to 30 a minute per token. Messages are
   1 to 2000 characters of valid UTF-8, control and bidirectional-override characters removed, stored and rendered as
   plain text (`textContent` in the frame, text interpolation in Vue, never `innerHTML` or `v-html`); a name is one line
   of at most 100 characters, an email at most 190 and validated; a conversation holds at most 1000 messages. A honeypot
   field turns away simple scripts. Rate-limit keys hold an HMAC of the address and the SHA-256 of the token.

8. **Privacy: no raw address, no automatic contact.** No table stores the visitor's IP address and visitor actions write
   nothing to the audit trail (which records an address). The page address is kept without its query and fragment, which
   may hold secrets. What the visitor types (name, email) stays on the conversation; no shared contact is created from
   anonymous input (spam would pollute the directory). Members are not named to the visitor: replies show the workspace
   name. The frame tells the visitor that the workspace keeps what they write.

9. **Permissions are reused.** Reading chats needs `support.tickets.view`, answering `support.tickets.reply`, closing
   `support.tickets.resolve`, the widget settings `support.settings.manage`. A chat has one assignee
   (`assignee_membership_id`): `all` scope reaches every chat; a narrower scope reaches the member's own chats plus the
   unassigned ones when the member is an active enrolled agent (`ChatAccess`, the pool rule of `TicketAccess`). The first
   reply to an unassigned chat assigns it to the replier. No permission was added.

10. **Inbox mapping.** Open and unassigned is `unassigned`, open and assigned `active`, closed `closed`. Unread is the
    number of visitor messages since a member last read (`markRead`) or answered. A member cannot reply to a closed chat:
    the thread says "Closed — the visitor can reopen it by writing again", and a visitor message reopens it (the assignee
    is kept). The item's URL is the Support Live chat page, so workspaces without the Communication app can answer too.

## Consequences

- One more public, unauthenticated input into a workspace. Its surface is three JSON endpoints behind a hashed token,
  an origin check and the limits above; the frame runs on the workspace origin under a nonce CSP.
- The group throttle is per address: several visitors behind one address (an office) share 60 requests a minute, about
  five open chats polling at once. The frame backs off on 429.
- A chat that is switched off or a workspace that loses Support makes the frame forget its token on the next poll (404);
  the conversation stays in the workspace.
- `support.live_chat.started` is dispatched (conversation id only) but is not an automation trigger yet; adding one
  needs catalogue labels.
- Not built: realtime (Reverb), file upload, typing and read indicators, agent presence or business hours (the
  "offline note" is static text), handing a chat to another member, and per-domain widget styling. Saving the visitor
  as a contact and converting a chat into a ticket were added by the amendment below.

## Alternatives considered

- **Script on the customer's page talking to the API with CORS.** Rejected: the token would live in the customer's
  page and storage, readable by any script there, and every API route would need CORS with a per-workspace origin list.
- **Session cookies for the visitor.** Rejected: third-party cookies are blocked or partitioned in iframes, and a session
  on the workspace host is the members' session; the callback group deliberately has none.
- **Reverb or server-sent events.** Not chosen now: Reverb is not installed and SSE holds a PHP worker per open chat.
- **A contact for every chat.** Rejected: anonymous input would flood the shared directory; "save as contact" is a later,
  deliberate member action.

## Amendment 2026-10-14: save as contact and convert to ticket (Support 1.2.0)

The owner decided that a member may save a chat's visitor as a shared contact and turn a chat into a support ticket,
both as explicit actions; nothing happens automatically. Decision 8 ("no automatic contact") stands.

1. **Save as contact** (`core.contacts.manage` plus the chat's view reach). An existing contact with the visitor's email
   address (workspace-wide, case-insensitive, oldest first) is linked rather than duplicated; otherwise a contact is
   created through `ContactDirectory`, owned by the member. The match is workspace-wide on purpose (no duplicates); a
   member whose contact scope does not reach the matched contact sees only that a contact is linked, not its name. The
   link is `support_chat_conversations.contact_id`; contact merges re-point it.
2. **Convert to ticket** (`support.tickets.create` plus the chat's view reach; closing in the same step also needs the
   resolve reach). `TicketManager::create()` with the existing `chat` channel, requester name and email from the visitor,
   the linked contact, and `source_key = live_chat:{conversation id}`; the conversation row is locked, so a chat becomes
   at most one ticket (`ticket_id` on the conversation).
3. **The transcript is an internal note, not a customer message.** A public reply or a customer message on the ticket
   would fire reply events (automation, and any later outbound channel) and would show members' names, which the widget
   never shows the visitor. Internal notes never leave the workspace. The ticket is therefore created without a seed
   message and the transcript is written as internal notes of at most 18 000 characters each.
4. Both actions are audited with ids only (`support.live_chat.contact_linked`, `support.live_chat.converted_to_ticket`).
   One additive migration (`2026_11_10_000100`) adds the two nullable foreign keys with indexes leading with `tenant_id`.