# ADR 0035 — Realtime inbox updates: a content-free signal over Reverb, polling as the fallback

- **Status:** Accepted by the owner on 2026-10-14 (server install deferred), built (Communication 1.5.0). Not run against a live Reverb server
  (see "Not verified").
- **Date:** 2026-10-14
- **Amends:** ADR 0030 decision 5 ("Realtime (Reverb) is not installed; polling is the honest minimum until it is").
  Polling stays, as the fallback.

## Context

The unified inbox (ADR 0020, ADR 0030) refreshes an open conversation every 15 seconds and the list every 20 seconds.
The owner asked for new messages to appear at once. CLAUDE.md listed Reverb as "not installed until needed"; it is
needed now.

Constraints: the tenant comes from the hostname only; sessions are host-only; every authorization goes through
`AccessChecker`; source apps must not import Communication; an unconfigured install, the test suite and a stopped
websocket server must keep working exactly as before.

## Decision

1. **Broadcast a signal, never content.** The event `inbox.changed` carries `{source, id}`: the inbox source key
   (`whatsapp_marketing.conversations`, `social.messenger`, `social.instagram`, `social.comments`, `support.live_chat`,
   `support.tickets`) and the conversation id the inbox lists. No text, name, number, status or count. The page reacts
   by doing what it already does (the list's `communication.inbox.poll` and the partial reload of `thread`), so every
   authorization decision stays in those endpoints. A member who holds `communication.inbox.view` but not a source's own
   permission learns at most that some conversation id of that source changed.

2. **One private channel per workspace:** `private-tenant.{tenantId}.inbox`. Signing a subscription is
   `POST /broadcasting/auth` (`core.realtime.auth`), a **core** route in `routes/tenant.php`: workspace host only (not
   central, not admin: 404), the `web` + `tenant` groups, `auth:web`, `auth.session`, `member`, `subscribed` and
   `throttle:120,1,realtime-auth`. The controller:
   - answers 404 while realtime is off (so an unconfigured install has no such endpoint in effect);
   - validates `socket_id` (`digits.digits`), refuses a `callback` (no JSONP) and accepts only names of the form
     `private-tenant.{id}.{name}` (no presence, no encrypted, nothing outside a workspace): 403 otherwise;
   - registers the channels modules tagged `RealtimeChannel::TAG` at request time (building the broadcaster needs
     the realtime settings, and only this route needs channels), on the `web` guard;
   - signs through the framework's Pusher-protocol broadcaster with the app secret. A refusal is a 403, the same for
     every reason.
   The inbox channel (`App\Modules\Communication\Realtime\InboxChannel`) says yes only when the channel's tenant id
   equals the tenant of this hostname (`hash_equals`; the name alone is never trusted) and `AccessChecker` allows
   `communication.inbox.view` for the user: an active member of an active workspace entitled to Communication.
   The route is core and not in the Communication manifest, because a later app's channel uses the same route.

3. **A core contract, so apps never import Communication:** `App\Core\Communication\Contracts\InboxSignals::changed(
   string $source, string $id)`. Core binds `NullInboxSignals`; Communication binds `BroadcastInboxSignals`, which:
   - does nothing while realtime is off or without an active workspace (no queued job at all);
   - defers to `TenantContext::afterCommit()`: nothing leaves before the outermost transaction commits, nothing at all
     when it rolls back, and the job is built inside its workspace;
   - dispatches `InboxChanged` (`ShouldBroadcast`, `RequiresTenantContext`, queue `notifications`, 1 try, 15 s
     timeout): the worker sends it, so a slow or stopped Reverb never holds a request. The broadcaster's HTTP client
     has a 2 s connect and 5 s total timeout;
   - catches any failure, logs it by exception class only (a message could carry the server address or a signed
     query) and never lets it reach the write.
   Signals are not merged on the server (that could drop the later of two changes); the page merges them.

4. **Where the apps signal** (after commit, through the contract; no extra query except where noted):
   - WhatsApp: inbound message (`ConversationManager::receive`), a reply or any outbound message to a conversation
     (`Messenger`/`RichComposer` touch), assignment, close/reopen, mark-read, and a delivery status change of a
     conversation message (`StatusProgress::message`). **Campaign message statuses are not signalled**: a campaign
     would flood the channel; the ticks of campaign messages follow on the next poll.
   - Messenger/Instagram: a stored message or echo, read and delivery receipts that changed a row, an unsent message
     (one lookup of its conversation), a reply, mark-read, close/reopen, assignment.
   - Facebook comments: a new comment (by its top-level comment, as the inbox lists threads; no query for a new
     comment), a comment removed or hidden by the platform (one lookup), and a reply, hide, delete, handled or
     assignment from the app (one lookup of the parent when the comment is a reply).
   - Website chat: every message (`LiveChatManager::append`), close, mark-read.
   - Tickets: created, edited, every public reply and customer reply (never an internal note), status change,
     assignment.

5. **Frontend: optional, lazy, with polling kept.** The inbox page gets a `realtime` prop computed on the server:
   `{key, host, port, scheme, channel, authEndpoint}` while realtime is on, otherwise null. No `VITE_REVERB_*` value is
   read, so the browser needs no rebuild when the server address changes, and the secret never leaves the server.
   `useInboxRealtime` (`resources/js/Modules/Communication/Components/useInboxRealtime.ts`):
   - with no config does nothing; otherwise imports `laravel-echo` and `pusher-js` on demand (a separate chunk; pages
     without realtime never load it) and subscribes with `broadcaster: 'reverb'`, ws/wss only, no stats;
   - signs the subscription with a same-origin `fetch` carrying the session cookie and the XSRF header (the app has no
     CSRF meta tag), through pusher-js's `channelAuthorization.customHandler`;
   - accepts only `{source, id}` that match the server's own patterns, gathers signals for 400 ms and reports each
     conversation once; the page then refreshes the list and, when the open conversation is among them, the thread;
   - **fallback:** if the server cannot be reached before it ever connected, or the subscription is refused, it warns
     once in the console, closes (no endless retries) and the page polls at the normal pace. While subscribed the two
     pollers slow down to every 60 seconds as a safety net; when a working connection drops they return to 15/20
     seconds, and once it is back the page refreshes everything once (signals may have been missed);
   - single-flight and visibility are the pollers' own: a signal during a running request asks once more when it
     ends, nothing is asked while the tab is hidden, and coming back to the tab asks anyway;
   - closes on unmount.

6. **Configuration is infrastructure, in the environment.** `BROADCAST_CONNECTION=reverb` plus `REVERB_APP_ID`,
   `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` (where the application publishes)
   and optionally `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME` (where browsers connect, e.g. the
   public `wss://…:443` of a proxy in front of a Reverb that listens on 127.0.0.1:8080). `App\Core\Realtime\Realtime`
   is "on" only when the default connection is `reverb` and all of these are present and well formed (host name
   pattern, port 1–65535, scheme http/https, key and id letters and digits); everything else is off. The owner's
   "every integration in the Super Admin panel" decision (ADR 0028) covers integration credentials of outside
   providers; the realtime server is part of the platform's own bootstrap floor, like the queue, cache and database
   connections, and stays environment-only.

7. **CSP.** `SecurityHeaders` adds the websocket origin (`ws://host:port` or `wss://host:port`, from the validated
   client settings) to `connect-src` while realtime is on; nothing otherwise.

## Dependencies and licences

Checked from the package metadata (composer/npm registries and the downloaded tarballs) before installing:

| Package | Version | Licence | Why |
|---|---|---|---|
| `pusher/pusher-php-server` (composer) | 7.3.0, pinned | MIT | The framework's `reverb` broadcast driver is its Pusher driver: publishing events and signing private-channel subscriptions. Supports Guzzle 7 and 8 and PSR-7 2 and 3; adds no other package. |
| `laravel-echo` (npm, dev) | 2.5.0, pinned | MIT | Channel subscription on the page. Imports nothing itself. |
| `pusher-js` (npm, dev) | 8.6.0, pinned | MIT | The websocket client Echo uses for Reverb. |
| `tweetnacl` (npm, transitive of pusher-js) | 1.0.3 | **Unlicense** (public-domain dedication) | Installed in `node_modules` but **not bundled**: pusher-js's default web build only accepts an injected `nacl` for encrypted channels, which are not used (and refused by the auth route). Unlicense is not in the project's MIT/BSD/Apache/ISC list; recorded here for the owner, like the CC0/0BSD/MPL build tools already in the tree. |

**The Reverb server package (`laravel/reverb`) is not installed.** Its latest release, 1.12.0 (MIT, 2026-09-22),
requires `guzzlehttp/psr7 ^2.6`, while this application runs Guzzle 8.2 with PSR-7 3.1. Installing it would
downgrade Guzzle to 7.15.5, PSR-7 to 2.13.1 and Guzzle Promises to 2.5.3 for the whole application (`composer require
laravel/reverb:1.12.0 -W --dry-run`). Reverb's main branch already allows PSR-7 3, so the next release is expected to
install cleanly. That choice is the owner's (see "Open decision"). The application side is complete without it: it
speaks the Pusher protocol to any Reverb server.

## Not verified

- No live websocket session: no Reverb server has been run against this application and no browser has subscribed.
  The signing is tested against the documented HMAC (`key:hmac_sha256(secret, socket_id:channel)`), the broadcast
  against a recording broadcaster, the page logic against a fake connection.
- The CSP `connect-src` entry has not been watched in a browser (CSP is report-only by default anyway).
- Reverb behind a TLS proxy (`wss://` on 443) is documented, not tried.

## Decision on the server (owner, 2026-10-14)

Option (b): wait for the `laravel/reverb` release that allows PSR-7 3, then `composer require laravel/reverb`. The
`tweetnacl` licence (Unlicense, transitive through `pusher-js`, not bundled) is accepted. The options considered:


How to run the Reverb server: (a) install `laravel/reverb` with the Guzzle 7 downgrade now; (b) wait for the Reverb
release that allows PSR-7 3 and then `composer require laravel/reverb`; (c) run Reverb from a separate, tiny Laravel
install on the server (it only needs the same app id, key and secret). Until then realtime stays off and the inbox
polls exactly as before.

## Consequences

- With realtime on, a member sees a new message as soon as the queue worker sends the signal (typically well under a
  second) and the page has asked again. With it off, nothing changes: the same 15/20-second polling.
- Each signal is one queued job on `notifications` and, per open inbox tab, one list request (plus a thread reload
  when it is the open conversation). Polling drops to once a minute per tab while subscribed.
- A new chat source signals by calling `InboxSignals::changed()` at its write points; a new realtime channel
  implements `RealtimeChannel` and is tagged; neither needs a change to core or to Communication.
- Operations: a Reverb process (supervised like the queue workers), the env values above, a proxy for `wss://` in
  production, and the websocket origin in `connect-src` follows automatically.

## Alternatives considered

- **Broadcast the message itself.** Rejected: the payload would bypass every per-source permission and record scope,
  and put customer text into another system.
- **A channel per member or per source.** Not chosen for now: one workspace channel is simpler, and what it discloses
  to a member without a source's permission is only that a conversation id of that source changed. Per-source channels
  (each joined with the source's own view permission) can be added later through `RealtimeChannel` without changing
  the apps.
- **Server-Sent Events or long polling from PHP.** Rejected: holds a PHP worker per open tab.
- **`VITE_REVERB_*` build-time settings.** Not chosen: a rebuild for every address change, and the page could not
  tell whether the server has realtime on. The server prop answers both.
- **Broadcast synchronously in the request.** Rejected: a slow or stopped Reverb would slow or break writes.
