# ADR 0031 - Messenger and Instagram direct messages: one webhook, Page-claim routing, the 24-hour window

- **Status:** Proposed, built (Social 1.5.0). Not reviewed by the owner. **Nothing here has run against Meta's live API**;
  every call is tested against recorded Graph answers (`Http::fake`) only.
- **Date:** 2026-10-14
- **Area:** Social Media (`app/Modules/Social/Messages`, `Platforms/Facebook/FacebookMessaging*`)
- **Builds on:** ADR 0025 (signed webhook, Page claim), ADR 0026 (permissions requested only when switched on),
  ADR 0030 (`InboxReplies`: the inbox reads and replies, the owning app sends).

## Context

The owner wants every chat channel answered from one place. People write to a business's Facebook Page in Messenger
and to its Instagram professional account in Instagram Direct. The workspace already has a unified inbox
(`communication/inbox`, ADR 0020 and 0030) and a Social app that holds the Page connections (encrypted Page tokens),
a claim registry that routes Page webhooks to one workspace (ADR 0025), and comment and lead-ads features that already
subscribe the operator's single Meta app to Page webhook fields. Every workspace shares that one Meta app, which has
**one callback URL per webhook object**, and a workspace without the Communication app must still be able to answer.

## Decision

1. **One webhook, no new callback.** Messaging events (`entry[].messaging[]` of `object=page` for Messenger and
   `object=instagram` for Instagram) arrive at the existing Facebook webhook (`/social/webhooks/facebook` on the central
   host and on the workspace host). `Comments\Ingest\WebhookReceiver` keeps its trust order unchanged: the app secret is
   configured, the size is capped, the `X-Hub-Signature-256` HMAC over the raw bytes is verified BEFORE parsing, and only
   then the body is parsed. It then hands the parsed payload to `Messages\Ingest\MessageWebhook`. The endpoint answers
   404 only when comments AND messaging are both off.

2. **Routing by the Page claim.** A Messenger event is applied only when `entry.id` is a Page claimed in
   `social_comment_page_claims` by exactly one account, the claim belongs to the asked workspace (workspace host) or
   names it (central host), the workspace is entitled to Social and the account's message capture is on. The claim is
   shared with audience comments: either feature claims it and it is released only when neither uses it. An Instagram
   event's `entry.id` is the Instagram professional account id: when capture is switched on, the account linked to the
   Page is read (`GET /{page-id}?fields=instagram_business_account`) and written to the claim's new `instagram_id`
   column (unique, so one Instagram account feeds one workspace); the event is routed through that claim. Unclaimed Pages,
   unknown Instagram ids and other workspaces' Pages are ignored without a hint. A failing event follows the comments'
   poison cap (503 until the cap, then logged with ids and the error class only).

3. **Idempotent storage, no contacts.** `social_dm_conversations` (one per Page account, platform and page-scoped person
   id) and `social_dm_messages` (unique `(tenant_id, provider_message_id)` on the platform's `mid`), both tenant-owned.
   A redelivery stores nothing. An `is_echo` message (sent as the Page from Meta Business Suite or another tool) is stored
   as outgoing so the thread is complete; the echo of a reply sent here completes that reply instead of adding a copy.
   Delivery and read receipts update the status of the Page's messages when they arrive. Attachments are stored as a
   type only, never fetched. The person's name is stored only when the platform gives it (it does not in the webhook);
   otherwise "Messenger user ••1234". **No CRM contact is created**: there is no phone or email and it would be a spam
   path; linking a conversation to a contact is a later option.

4. **Off by default, honest states.** `SOCIAL_FACEBOOK_MESSAGING` and `SOCIAL_INSTAGRAM_MESSAGING` (both false) gate
   everything: the connect dialog requests `pages_messaging` and `pages_manage_metadata` (Instagram adds
   `instagram_basic` and `instagram_manage_messages`) only while they are on, because an unapproved permission breaks the
   dialog. While off, the inbox sources list nothing, webhook events are not read and the Messages page says so. A
   per-Page switch (`social.accounts.manage`) checks the granted permissions (`debug_token`, the missing ones are named),
   claims the Page, subscribes the app and resolves Instagram. Switching off keeps every stored message.

5. **Webhook fields are merged.** `POST /{page-id}/subscribed_apps` with `subscribed_fields` sets the whole list.
   Messaging, comments and lead ads therefore all read the current list (`GET /{page-id}/subscribed_apps`, our app's
   entry), send the union, and remove only their own fields (`FacebookPageSubscriptions`). The app leaves the Page
   (`DELETE`) only when nothing remains, and never on a list it could not read.

6. **The 24-hour standard messaging window, text only.** A reply is sent as the Page with the Send API
   (`POST /{page-id}/messages`, Messenger with `messaging_type=RESPONSE` and the PSID, Instagram with the IGSID), the Page
   token in the `Authorization` header. It is allowed only within 24 hours of the person's last message; outside it the
   thread shows the reason and a reply is refused before any call. Message tags (for example `HUMAN_AGENT`, 7 days) need
   separate Meta approval and are **not built**. Outbound is text only. A rejected token moves the account to "needs
   reauthorization" (the existing `AccountManager` path); a rate limit, a closed window or an unreachable person is a
   refusal with a reason a person can act on; an unconfirmed send says it may have been delivered.

7. **One code path.** `Messages\Actions\DirectMessages::reply()` is used by the Social Messages page and by the inbox
   sources `social.messenger` / `social.instagram` (`InboxReplies::reply()`). Access is one class,
   `ConversationAccess`: the record scope of `social.messages.view|reply` (scopable; `own` / `department` / `branch`
   follow the assigned member), the account allow-list (a restricted Page is reached only by members allowed to publish
   for it, because a reply speaks as the Page), the operator switches and the Page's capture switch.

## Consequences

- Meta app review is required for `pages_messaging` (and `instagram_manage_messages` for Instagram); the Meta app's
  Webhooks product must subscribe the Page object to `messages` and `message_echoes` and, for Instagram, the Instagram
  object to `messages`, both at `https://APP_DOMAIN/social/webhooks/facebook`. Pages connected before must be connected
  again to grant the new permissions.
- A Page switched off keeps its conversations, but they are not listed until it is switched on again.
- Opening a conversation never marks it read; the page posts `social.messages.read` (as the unified inbox does).
- Realtime is not installed: the Messages page shows new messages on the next visit; the unified inbox polls (ADR 0030).
- Later options, not built: message tags after 24 hours, outbound media, quick replies, fetching the person's name
  through the User Profile API, linking a conversation to a CRM contact, an automation trigger for received messages.

## Alternatives considered

- **A separate messaging callback.** Rejected: a Meta app has one callback URL per object; a second endpoint would need
  its own relay and could not share the claim routing.
- **Creating a CRM contact per new conversation.** Rejected: no phone or email arrives, and an open inbox would become a
  way to fill the CRM with spam.
- **Message tags to answer after 24 hours.** Deferred: separate Meta approval and policy obligations.
- **Polling conversations (`GET /{page-id}/conversations`).** Not chosen: the webhook is the documented path and polling
  needs the same permissions at a higher rate-limit cost; it may be added as a fallback later.
