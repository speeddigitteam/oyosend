# ADR 0025 - Social audience comments: signed webhook plus polling, keyword rules as data

- **Status:** Accepted (unreviewed; no live Meta call has been made)
- **Date:** 2026-11-03
- **Area:** Social Media (`app/Modules/Social/Comments`)

## Context

Teams want one inbox for what people write on their Facebook Pages, with answers, moderation and a little automation
("someone asks the price, reply with the price list"). The risks are the ones the rest of the platform already
handles: a webhook is an unauthenticated request that names a workspace; an automatic public reply is hard to take back
and can embarrass a customer if it fires twice or at the wrong person; "automation" must never become code execution; and
a tenant must never read or act on another tenant's comments.

## Decision

1. **Comments are a new model.** `AudienceComment` is separate from the internal team comments on a draft
   (`social_comments`). It is tenant-owned, uses `HasRecordOwner` (`owner_id` = the member it is assigned to, so the scope
   of `social.comments.view|reply|moderate` means "assigned to me / my department / my branch / all") and is deduplicated
   by `(tenant, account, platform comment id)`.
2. **Provider behind an interface, honest capability.** `CommentProvider` is implemented only by
   `Platforms/Facebook/FacebookComments` using documented Graph calls. The Facebook adapter's `comments` capability is
   true only while `SOCIAL_COMMENTS` is on; other platforms stay false. Private replies are offered only when configured
   and the account holds `pages_messaging`. Tokens travel in the `Authorization` header and never in a message, log line
   or audit entry; a rejected token moves the account to "needs reauthorization" through the existing
   `AccountManager::markNeedsReauthorization()`.
3. **Webhook trust order.** The workspace-host route and the central route (a Meta app has one callback URL) share
   `WebhookReceiver`: the app secret must be configured, the size is capped, the HMAC-SHA256 `X-Hub-Signature-256` over the
   raw bytes is verified first and only then is the body parsed. An event is applied only if the Page is **claimed** by
   exactly one account (`social_comment_page_claims`, platform-wide unique) and the claim belongs to the workspace that was
   asked (workspace host) or names it (central host). Unclaimed Pages and other workspaces' Pages are ignored without a
   hint. One Page therefore cannot feed two workspaces. The claim table is a documented platform-wide exception
   (`docs/DATABASE.md` section 2): its unique index is the point, it is owned through a tenant-owned account row and deleted
   with it; a claim whose account was disconnected may be taken over.
4. **Replay and poison.** Ingestion is idempotent by comment id. A failing event returns 503 until a per-workspace,
   per-event failure cap, then is logged (ids and exception class only) and acknowledged, like Communication's poison cap.
5. **Polling fallback.** A scheduled job reads a few Pages per run, a bounded number of recent posts and comments, spaced
   by an interval that doubles after failures and stops on rate limiting or a rejected token. It writes through the same
   ingestor, so overlapping with the webhook is harmless.
6. **Rules are data, never code.** A rule holds keywords, a match mode and type, an optional Page / post / campaign
   scope, a closed list of actions and reply templates with an allow-list of two variables. Keywords are compared as
   normalised tokens or substrings; **nothing a member types becomes a regular expression** (no ReDoS surface) or is
   evaluated. Template values are substituted in one literal pass.
7. **Run as the last editor, re-checked.** A rule acts as the member who last saved it or switched it on (ADR 0017's
   model, implemented locally in `RuleActorGuard` because optional apps do not import each other). Saving requires that
   member to hold each action's permission for all records; before every action the member must still be active, the
   workspace entitled and the permission held. Otherwise the action is skipped with a recorded reason and the rule list
   says why. There is no system identity.
8. **Layered safety, stored in the database.** Global pause switch; per-rule and per-Page hourly caps; cooldown per
   commenter; quiet hours (held and retried, not dropped, within a bounded number of deferrals); comments older than 48
   hours, the Page's own comments, hidden and deleted comments are never answered; each action of each rule runs at most
   once per comment through a `claimed` row written **before** the platform call. A crash mid-call can therefore skip a
   public reply but never double it. Every automatic action leaves a run row and an audit entry carrying the rule and the
   member.
9. **Automation surface.** Trigger `social.comment.received` (ids only) and actions `social.comment.reply` and
   `social.comment.hide` with receipts. No action approves money, resolves, hires or opts anyone in.

## Consequences

- Meta app review is required for `pages_read_engagement`, `pages_manage_engagement`, `pages_manage_metadata` and, for
  private replies, `pages_messaging`. Pages connected before this feature must be connected again to grant them.
  Until then reading may work while writing answers with a permission message; publishing is unaffected.
- A rule whose author is removed or narrowed stops silently apart from the warning in the rules list (same as workflows).
- Reply claims favour at-most-once over at-least-once: after a crash a person may have to answer one comment by hand.
- Sentiment is a placeholder column; it is not filled by this feature.
- Nothing here has run against Meta's live API; behaviour is verified against recorded Graph responses only.

## Alternatives considered

- **Regular expressions or an expression language for matching.** Rejected: ReDoS and tenant-supplied evaluation.
- **A service identity for automatic replies.** Rejected: bypasses permissions and scope (ADR 0017).
- **Reusing the internal `social_comments` table.** Rejected: different trust level, different lifecycle, different scope.
- **Webhook only, or polling only.** Webhooks can be missed and need app configuration; polling alone is slow and
  rate-limited. Both, through one idempotent ingestor.
