# Social Media

The optional app `social` (name "Social Media", category Social Media, one free offer `free`) lives in
`app/Modules/Social` and is registered by `SocialServiceProvider`. It plans, reviews, schedules and publishes
posts to connected social accounts, keeps a shared media library, and reports only the engagement figures a
platform actually returned. Tables are prefixed `social_`.

State of the build: **backend and Vue pages are built** (`resources/js/Modules/Social`; the page names below are what
the controllers render). **No platform adapter has been exercised against a live platform in this repository**:
each needs the operator to register an app with the platform and pass that platform's own app review
(`config/social.php`, environment variables below). Until credentials exist the platform shows "not configured"
and cannot be connected. Nothing is simulated outside tests.

A workspace needs an effective plan or App Store entitlement for `social`; without it every route answers 404 and
the publish schedule does nothing for that workspace. Uninstalling retains every record; reinstalling restores them.

## Platform contract and honest availability

`App\Modules\Social\Platforms\SocialPlatform` is the one interface. An adapter reports:

| Member | Meaning |
|---|---|
| `capabilities()` | `publish`, `schedule` (our scheduling: follows `publish`), `analytics`, `comments`, `images`, `video`, `maxText`, `maxMedia`. A flag is true only for something implemented against a documented call |
| `availability()` | `available`, or an `unavailableCode` (`not_configured`, `not_implemented`) with a reason shown to people |
| `textLength()` | The length as the platform counts it (X counts every link as 23 characters) |
| `authorizationRequest()` / `completeAuthorization()` | OAuth 2.0 authorization code flow (PKCE where the platform requires it); returns the profiles/pages granted with their `TokenSet` |
| `refresh()` | New access token from a refresh token, or `ReauthorizationRequired` |
| `publish()` | Publish now. Throws `PublishFailed` with `retryable` (only when the platform provably did not process the request), `outcomeUnknown`, `reauthorize`, `retryAfterSeconds` |
| `metrics()` | The figures the platform returned for one item, or null |

`SocialPlatforms` (singleton) lists the adapters from `config('social.adapters')` and, below them,
`UnavailablePlatform` entries for platforms this build does not drive: **Instagram, YouTube, TikTok, Pinterest**.
Each carries its reason (app review/audit, public media URL, resumable upload...) and refuses every call.

| Adapter | Real API calls used | Capabilities | Needs |
|---|---|---|---|
| `FacebookPagesPlatform` (`facebook`) | Graph API: `dialog/oauth`, `oauth/access_token` (code + `fb_exchange_token`), `me/accounts`, `{page}/feed`, `{page}/photos` (multipart `source`; unpublished + `attached_media` for several photos), `{page}/videos` (resumable `upload_phase` start / transfer / finish), `{page}/video_reels` (start, upload to `rupload.facebook.com`, finish), `{video}?fields=status`, post `likes`/`comments`/`shares` fields | publish, schedule, analytics (likes, comments, shares), photos (up to 10), video, Reels (video and Reels only while `SOCIAL_FACEBOOK_VIDEO` is true) | Meta app with `pages_show_list`, `pages_manage_posts`, `pages_read_engagement` and, for video and Reels, `publish_video`, all approved by app review |
| `LinkedInPlatform` (`linkedin`) | OAuth 2.0 (`/oauth/v2/authorization`, `/oauth/v2/accessToken`), OpenID `userinfo`, `POST /rest/posts` with `LinkedIn-Version` and `X-Restli-Protocol-Version` | publish, schedule; **text only**; no analytics | LinkedIn app with "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect"; member profile only (no company pages) |
| `XPlatform` (`x`) | OAuth 2.0 + PKCE (`x.com/i/oauth2/authorize`, `api.x.com/2/oauth2/token`), `GET /2/users/me`, `POST /2/tweets`, `GET /2/tweets/:id?tweet.fields=public_metrics` | publish, schedule, analytics (impressions, likes, replies, reposts); **text only** | X developer project whose tier allows posting |

Two optional interfaces extend the contract; an adapter that implements neither publishes a plain post exactly as before.

| Interface | Meaning |
|---|---|
| `PostTypeAware` | `postTypes()` (the types it really publishes now), `checkPost(type, media)` (blocking problems and warnings for the chosen type), `mediaBadges(media)` (per-file facts shown on the media tile). Only Facebook implements it |
| `StepwisePublisher` | `usesSteps(payload)` and `advance(account, payload, session, deadline)`: a publish that cannot finish inside one worker run (chunked upload, several photo uploads, then platform-side processing). The publisher saves the session between runs and calls `advance` again. Nothing is public before the adapter's final call, so a failure before it is retryable; only the final call can be an unknown outcome |

### Facebook photos, video and Reels

Post types (`social_posts.post_type`, enum `PostType`): `post` (text, link, at most one photo; the old behaviour), `photos`
(1 to `photos_max` images), `video` (exactly one video), `reel` (exactly one video). Which types an account offers comes from
the adapter (`postTypes()`); the composer disables a type that any chosen account cannot publish and says which account.

| Flow | Calls | Notes |
|---|---|---|
| Several photos | one `POST {page}/photos` per image with `published=false` (and `alt_text_custom` when the file has a description), then one `POST {page}/feed` with `message` and `attached_media[n]={"media_fbid":...}` | uploads are invisible until the feed call, so an upload failure is retried; the feed call is the only unknown outcome. A single photo keeps the old one-call path |
| Video | `POST {page}/videos` with `upload_phase=start` (`file_size`), then `transfer` for each byte range Facebook returns (`start_offset`, `upload_session_id`, `video_file_chunk`), then `finish` (`description`) | the bytes are streamed from `TenantStorage` in the ranges Facebook names (never one string in memory, never a tenant URL) |
| Reel | `POST {page}/video_reels` `upload_phase=start`, then `POST rupload.facebook.com/video-upload/{version}/{video-id}` with headers `Authorization: OAuth`, `offset`, `file_size` and a binary body per chunk (`reel.chunk_bytes`), then `POST {page}/video_reels` `upload_phase=finish`, `video_state=PUBLISHED` | our own scheduler decides *when*; Facebook is always asked for `PUBLISHED`. Resuming sends the next `offset`; this resume behaviour is documented by Meta but has not been exercised live |
| Processing | `GET {video-id}?fields=status,permalink_url` | `status.video_status` `ready` (and, for Reels, a completed `publishing_phase`) means published; `error` / `expired` or an errored phase fails the target with Facebook's own reason; anything else is "still processing" |

**States.** A stepwise target is `pending` -> `publishing` (claimed) -> `processing` while it uploads and while Facebook processes
the media -> `published` or `failed`. The post stays `publishing` while any target is `processing`, and cannot be edited, unscheduled
or rescheduled meanwhile. The columns `remote_id`, `remote_state` (`uploading` / `processing`), `upload_offset`, `remote_meta` (adapter
private, no secrets) and `remote_started_at` on `social_post_targets` carry the session; they are cleared on success and on any
terminal failure. `next_attempt_at` is the next run that continues it.

**Never twice.** The platform's id is stored on the target (`external_id`) in the same save that records the accepted final call, and a
`processing` target only ever *polls* from then on; it never starts a new upload or repeats the final call. The claim back to
`publishing` is a compare-and-set like every other claim. A lost answer to the final call is `outcome_unknown` with `needs_manual_check`
and is never retried; a worker killed mid-step leaves the target `publishing` and is flagged the same way after 15 minutes. Media that
Facebook has not finished within `processing_timeout_minutes` (180) is flagged for a manual check (the video may still go live), not
retried. A failed video (`video_processing_failed`) clears the session, so the manual retry uploads a fresh one.

**Transient failures.** Before the final call, a timeout, 5xx or throttle keeps the saved session and tries again after the
backoff (`social.publishing.backoff_seconds`), up to `step_failures_max` (6) in a row, then fails the target. While polling, the same
applies. Each run stops starting requests after `run_budget_seconds` (35; the job timeout is 60) and continues from the saved offset
on the next minute, but always does at least one unit of work.

**Permissions and expired tokens.** Graph error 190, 102, 459, 464, 467 or HTTP 401 becomes `reauthorization_required`; code 3, 10 or
200-299 becomes `facebook_permission_missing`. Both put the account into `needs_reauthorization`, and the post page shows a calm
"this account needs to be connected again" panel with a link to the connected-accounts page. Facebook's own technical text is not shown
for these.

**Limits (config-driven, `social.platforms.facebook.limits`).** These are Meta's documented media requirements as of this build
and are not guessed; Meta changes them, so review them with the API version. A limit that is missing from the config is not enforced.

| Key | Default | Severity |
|---|---|---|
| `photos_max` | 10 | blocks |
| `video.max_seconds` / `video.max_bytes` | 240 minutes / 10 GiB | blocks |
| `reel.min_seconds` / `reel.max_seconds` | 3 / 90 | blocks |
| `reel.min_width` x `reel.min_height` | 540 x 960 | blocks |
| `reel.aspect_width:aspect_height` (`aspect_tolerance`) | 9:16 (3 %) | warns (may be cropped) |
| `reel.max_bytes` | 1 GiB | blocks |
| MOV for a Reel | MP4 recommended | warns |
| file whose length / size could not be read | unknown | warns, allowed |

The library's own upload cap (`social.media.video_max_kb`, 50 MB by default) is the effective ceiling for now.

Audience comments: `SOCIAL_COMMENTS` (default `true`; off removes the capability, the extra permissions and all ingestion), `SOCIAL_FACEBOOK_WEBHOOK_VERIFY_TOKEN` (the `hub.verify_token` of the webhook handshake) and `SOCIAL_FACEBOOK_PRIVATE_REPLIES` (default `false`; needs `pages_messaging`). The Meta app's Page webhook must point at `https://APP_DOMAIN/social/webhooks/facebook` with the `feed` field subscribed.

`SOCIAL_FACEBOOK_VIDEO` (default `true`) turns video and Reels off for the whole installation: the `publish_video` scope is then not
requested at connect time, the adapter's `video` capability is false, and the composer offers neither type for Facebook. Pages
connected before this build lack `publish_video` and are sent through the re-connect prompt on their first video.

Configuration (operator level, never in the database; `config/social.php`): `SOCIAL_FACEBOOK_CLIENT_ID`,
`SOCIAL_FACEBOOK_CLIENT_SECRET`, `SOCIAL_FACEBOOK_API_VERSION` (default `v23.0`), `SOCIAL_FACEBOOK_VIDEO` (default `true`), `SOCIAL_LINKEDIN_CLIENT_ID`,
`SOCIAL_LINKEDIN_CLIENT_SECRET`, `SOCIAL_LINKEDIN_API_VERSION` (`LinkedIn-Version` header, `YYYYMM`, default
`202510`), `SOCIAL_X_CLIENT_ID`, `SOCIAL_X_CLIENT_SECRET`, `SOCIAL_REDIRECT_BROKER` (default `true`). API versions are retired by
the platforms: review them on a schedule.

The app credentials for each platform (client id and client secret, and the Facebook lead-ads verify token) are
also settable in **Super Admin → Integrations**, stored encrypted and write-only (a secret is never shown again or
logged), with the environment as the fallback default — ADR 0029. The client ids are shown back so they can match the
registered OAuth redirect URIs; the secrets and the verify token are write-only. A panel value, when set and enabled,
overlays the matching `config('social.…')` key at boot, so every reader transparently sees it. API versions, the
media flags and `SOCIAL_REDIRECT_BROKER` stay environment settings.

**Redirect URIs and the central broker.** Most platforms require every redirect URI to be registered, which does not
scale to one URI per workspace. With `SOCIAL_REDIRECT_BROKER=true` the `redirect_uri` sent to the platform is one
central URI, `https://APP_DOMAIN/social/oauth/callback/{platform}` (route `central.social.oauth.callback`, group
`central.callback`: no session, no cookies, throttled; `Support\OAuthBroker`, `OAuthBrokerController`). The
platform-visible `state` is an envelope `{slug}.{expiry}.{nonce}.{mac}.{inner}`: `inner` is the session-bound random
state, and the MAC (HMAC-SHA-256 with a key derived from `APP_KEY`) covers slug, platform, expiry, nonce and inner, so
the envelope cannot be forged, re-aimed at another workspace or platform, or have its inner value swapped; it lives
10 minutes. The broker verifies it, requires that the workspace exists and is accessible, builds the host from
`APP_DOMAIN` and that slug (never from request input), and redirects the same `code` / `state` / `error*` query to
the workspace's own callback, which still does the real session-bound state check and the token exchange. Every
failure is a bare 404. With the broker off, each platform app must register the per-workspace URI
(`https://{workspace}.APP_DOMAIN/social/accounts/callback/{platform}`; the connected-accounts page lists it).

Adding a platform: implement `SocialPlatform`, list it in `config('social.adapters')`, add its credentials to
`config/social.php`, document the calls and approvals here, and test it with `Http::fake`. If the API cannot be
driven faithfully, add an `UnavailablePlatform` entry with the reason instead.

## Connected accounts

State machine (`SocialAccount.status`, `AccountStatus`):

```
(authorise) -> connected -> needs_reauthorization -> (authorise again) -> connected
                   \-> disconnected   (tokens discarded; reconnecting takes a seat again)
```

- **Connect**: `POST accounts/connect/{platform}` checks the platform is available and the plan has a seat
  (`social.connected_accounts`), puts `state` (random), the PKCE verifier, the member id and a 10-minute expiry in
  the session and redirects to the platform. `GET accounts/callback/{platform}` pulls the session value (one use),
  requires platform, member, expiry and `hash_equals(state)`, exchanges the code, and connects. Several granted
  pages are parked encrypted in the workspace cache for 10 minutes, bound to the member, and chosen on
  `accounts/select/{selection}`; the entry is deleted once used.
- **Re-authorise**: the same flow with `account_id`; only the same external profile is accepted.
- **Tokens**: `access_token` / `refresh_token` use the model's `encrypted` cast and are `$hidden`; they are not
  in `toArray()`, JSON, Inertia props, audit entries (`AuditLogger` redacts, and the model excludes them), events or
  logs. `TokenSet` and `AccountCredentials` redact themselves for `var_dump`, JSON and `serialize`. Error text from a
  platform has the live token stripped before it is stored.
- **Refresh**: before a publish `AccountManager::credentials()` refreshes a token that expires within 10
  minutes (row-locked, because refresh tokens can be single-use). The hourly job renews tokens expiring within a day.
  A refresh the platform rejects, or an expired token with no refresh token, moves the account to
  `needs_reauthorization`, drops its tokens, records `status_reason`, announces
  `social.account.needs_reauthorization` and fails (not retries) its due posts with `reauthorization_required`.
- **Disconnect** discards the tokens and fails the account's queued targets (`account_disconnected`).

## Content, calendar and publishing

- A **post** (`social_posts`, owned: `HasRecordOwner`, so `own` / `department` / `branch` / `all` scopes apply) has
  text, an optional link, a hashtag snapshot (copied from a hashtag set when chosen), media, a campaign and a
  category. Each chosen account is a **target** (`social_post_targets`) with an optional `body_override` (the
  per-platform variant). `PostContentRules::previews()` returns, per account, the exact text, its length against the
  platform's limit, media capability and a list of issues; `assertPublishable()` refuses submitting or scheduling
  while any issue is open.
- **Post type and the live check.** The composer chooses Post / Photos / Video / Reel. Before saving, it asks
  `POST posts/preflight` (route `social.posts.preflight`, permission `social.posts.view`, nothing stored) which runs the
  same `PostContentRules::previews()` as scheduling on the unsaved draft: per account, blocking `issues` and non-blocking
  `warnings`, plus the readiness checklist built from them. Ids from another workspace simply resolve to nothing.
  `assertPublishable()` refuses scheduling while an issue is open; warnings never block.
- **Calendar**: posts by `scheduled_for` (or `published_at`) in the workspace timezone. Instants are stored in UTC;
  forms send `Y-m-d\TH:i` in the workspace timezone. Drafts may carry a planned time that is not queued.
- **States** (`PostStatus`): `draft`, `pending_approval`, `approved`, `scheduled`, `publishing`, `published`,
  `partially_failed`, `failed`. Targets: `pending`, `publishing`, `published`, `failed`.
- **Approval** (setting `require_approval`): `submit` -> `pending_approval` -> `approve` -> `approved` ->
  `schedule`. The approver must be neither the person who submitted the post nor its author (four-eyes), and holds
  `social.posts.approve`. Approving a post whose planned time is still ahead queues it. Rejecting needs a reason
  and returns the post to draft. Changing the text, accounts, media or link of a post that is pending, approved or
  scheduled returns it to draft and clears the approval, so an approval always covers exactly what is published. When
  approval is not required a draft may be scheduled directly.
- **Scheduling** meters `social.scheduled_posts_per_month` once per post (`UsageMeter` idempotency key
  `post:{id}`; moving a post does not count twice) and queues every unpublished target with
  `next_attempt_at`.
- **Publishing** is the scheduled job `social.publish_due` (every minute, through the entitlement-gated runner
  that re-checks the workspace's entitlement at worker time). `PostPublisher::runDue()`:
  1. flags targets claimed more than 15 minutes ago as failed with `outcome_unknown` + `needs_manual_check`
     (never auto-retried);
  2. takes due `pending` targets of `scheduled`/`publishing` posts, oldest first, up to `social.publishing.batch`;
  3. rate limits: per account per hour (workspace setting `account_hourly_limit`, default 10) and per workspace
     per minute (`social.publishing.tenant_per_minute`); a limited target is deferred without spending an attempt;
  4. claims the target with an atomic compare-and-set (`pending` -> `publishing`, `attempts + 1`), so overlapping
     runs and workers publish nothing twice; a target that already has an `external_id` is never published again.
     The same UPDATE re-checks that the target is still due (`next_attempt_at` set and not in the future) and that
     its post is still `scheduled` / `publishing`: a post that was unscheduled, rejected, edited or rescheduled after
     the due ids were read is not claimed, so what is published is what was approved;
  5. obtains fresh credentials, loads media lazily from `TenantStorage`, calls `publish()`;
  6. success: stores `external_id` / `external_url`, logs the attempt, rolls the post up; the platform's id is the
     proof of publication.
- **Retry policy** (documented because most endpoints have no idempotency key): only a failure where the
  platform provably did not process the request is retried automatically (rate limit, a connection that never
  opened) with backoff `60s, 300s, 1800s` up to `max_attempts` (4) or the platform's `Retry-After`. Anything
  uncertain (5xx, a timeout after sending, a missing post id) fails the target with `needs_manual_check` and tells a
  person to look at the account before pressing retry. Rejections (duplicate content, invalid parameter) fail at
  once with the platform's message; a revoked token fails it with `reauthorization_required`.
- **Video, Reels and multi-photo posts** are published in steps and may leave the target `processing` for several minutes; see
  "Facebook photos, video and Reels" above. They obey the same rate limits, and an attempt is logged when the media is accepted
  (`deferred`, code `processing`) and when it is finally published or failed.
- **Failed posts**: the post becomes `failed` (no target published) or `partially_failed`; the detail page shows each
  account's `lastError`, `attempts`, `needsManualCheck` and the attempt log (`social_publish_attempts`).
  `POST posts/{post}/targets/{target}/retry` (permission `social.posts.schedule`) re-queues one failed target.
  Events: `social.post.published` (all published), `social.post.failed` (any target failed finally).

## Media library

`social_media` rows point at private files stored through `TenantStorage` under
`tenants/{tenant_id}/social/media/{generated name}`; the original name is display text only. Uploads are verified by
content (`finfo` MIME, `getimagesize` for images, max 20000 px), against an allow-list (JPEG, PNG, GIF, WebP, MP4,
QuickTime; **SVG is refused**) and size limits (`config('social.media')`). A video's length, width and height are read from the MP4 / QuickTime header boxes
(`Support\Mp4Probe`, no ffmpeg; `moov` is read by seeking past `mdat`, every size is bounded, a rotated picture is turned); a file that cannot be read
is kept with `probe_status = unknown` and shown with a warning, never with a guessed number. There are no thumbnails: a video shows a placeholder.
Every tile and the composer show per-platform compatibility badges decided by the adapters (`photo_ok`, `video_ok`, `reel_ready`,
`reel_too_long`, `reel_too_short`, `reel_low_resolution`, `reel_check`, `video_too_long`, `video_too_large`, `unsupported`). The same bytes are stored once (SHA-256
per workspace). A file used by a post cannot be deleted. The only way to the bytes is `GET media/{media}/file`,
authorised per request (`social.media.view`), tenant-scoped, with `X-Content-Type-Options: nosniff`, a sandboxing
CSP and `Cache-Control: private`. Props never contain the storage path.

## Campaigns, categories, hashtag sets

Workspace lists (`social_campaigns`, `social_categories`, `social_hashtag_sets`) managed with
`social.library.manage`. Hashtags are normalised (`HashtagNormalizer`: letters, marks, digits, underscore, at most
30). A post copies its hashtag set's tags when saved, so editing a set never changes a scheduled post.

## Bulk scheduling (CSV)

`GET imports/template` serves the header. Accepted columns: `body`, `accounts` (required), `scheduled_at`
(`YYYY-MM-DD HH:MM` in the workspace timezone), `title`, `link_url`, `campaign`, `category`, `hashtag_set`, `media`
(exact names; `;` separates several). Safe parsing in `CsvImporter`: at most 1 MB and 500 rows, valid UTF-8 without
NUL, fixed column list (unknown or duplicate columns refuse the file), no control characters, strict dates, links
must be http(s), every reference resolved by exact name inside the workspace (ambiguous names are errors), nothing
evaluated. The same file (SHA-256) is refused a second time. Each row is its own transaction; bad rows are reported by
line in `social_imports.errors`. Mode `draft` creates drafts (with the planned time); mode `schedule` needs
`social.posts.schedule` and goes through the normal workflow (submits for approval when required, otherwise
schedules, metering and limits included). Everything the module writes as CSV (`posts/export`, the template) passes
`CsvSafe`: cells starting `=`, `+`, `-`, `@`, tab or CR get a leading apostrophe.

## Analytics

`SocialAnalytics::overview()` has two parts. **Publishing** counts (published, failed, pending, by platform) come
from this application's own records. **Engagement** comes only from `social_post_metrics` rows written by
`MetricsCollector` from what a platform returned (hourly job, per target at most every 6 hours, for posts up to 30
days old, only for platforms whose `analytics` capability is true and accounts that are connected). A figure the
platform did not return is `null` ("not reported"), never zero; with no rows `reportedPosts` is 0 and totals are
`null`. `platformsWithAnalytics` lists who can report at all, so the page can say why a report is empty.

## AI assist and sentiment

Built on the core `TextAssistant` contract (ADR 0024, `docs/AI.md` section 10). Social imports **no** class of the
`ai` module. All code is in `app/Modules/Social/Ai` and three Vue components; the AI feature switch, plan allowance
(`ai.tokens`), per-workspace and per-member rate limits, redaction and metering are the contract's. Without the AI
app, a provider or the workspace switch the states are honest: `not_configured`, `disabled`, `limit_reached`,
`refused` (rate limited), `failed`. Nothing is simulated.

**Composer panel** (`AiAssistPanel.vue`, mounted in `Posts/Form.vue`). Permission `social.posts.ai_assist` (new)
**and** `social.posts.create` or `social.posts.edit`; the route is `POST social/posts/ai/{caption|hashtags|rewrite|shorten|translate}`
(`social.posts.ai`, throttled 30/min on top of the contract's per-member limit). It returns text and a state; it never saves,
applies, schedules or publishes.

| Operation | Contract kind | Notes |
|---|---|---|
| Generate caption | `caption` | input = notes, else the current text, else the title; title and link are quoted context (the link is never fetched); tone Friendly / Professional / Playful / Persuasive / Formal; language English / Bengali / same as input |
| Suggest hashtags | `hashtags` | 5, 8, 10 or 15; the person picks which to keep |
| Rewrite | `caption` | current text, chosen tone |
| Shorten | `summary` | at most 60% of the current length, inside the platform limit |
| Translate | `translate` | English or Bengali |

Target length is the **smallest `maxText`** of the chosen accounts (280 when none is chosen yet). Each "try again" sends a
new `attempt`, so the purpose key changes and the provider is asked again; a retry of the same attempt is metered once
and replayed (ADR 0024 section 6). The remaining allowance is **not shown**: neither the contract nor a public usage API
exposes it. Tokens used per day are likewise not shown on the settings page for the same reason; the settings page shows
Social's own count of comments analysed today.

Accepting is always a click: "Use as post text" (with Undo) or "Add N to post" (hashtags). Accepted hashtags travel as
`extra_hashtags` and are merged, de-duplicated and normalised (`HashtagNormalizer`, at most 30) into the post's hashtag
**snapshot** next to the chosen set's tags; "save as a hashtag set" links to the library. Accepting also sends
`ai_assisted = true`: `social_posts.ai_assisted` is a sticky, informational marker shown as an "AI-assisted" badge on the
post page, so approvers know to read the text carefully. It is honoured only for people holding
`social.posts.ai_assist`, is client-reported (it records that a person pressed accept, not what the text is) and an edit never clears it.
Preflight (`posts/preflight`) still sees only the chosen set's tags; the server-side publish checks use the full snapshot.

**Prompt safety.** The post text, title, notes and link are tenant text: they reach the model only as encoded
`<text_data>` / `<record_data>` blocks (ADR 0024 section 4), never in the task sentence. Output is plain text with tags
stripped, cut to the limit.

**Sentiment** (`App\Modules\Social\Ai\CommentSentiment`). The reusable service for audience comments; the comments
module does not need to import anything else:

```php
$sentiment = app(CommentSentiment::class);
$sentiment->queue($account->id, [$comment->external_id => $comment->message], [$comment->external_id => $comment->commented_at]); // jobs on queue `ai`
$sentiment->analyse($account->id, $comments);   // up to 50 now; returns a BatchOutcome (analysed, unchanged, skipped, pending, capReached, stopped)
$sentiment->summary($from, $to, $accountId?);   // page-level mix for a period
```

Results live in `social_comment_sentiments`, unique on `(tenant_id, account_id, external_comment_id)` (the platform's comment
id, the same value as `social_audience_comments.external_id`): label (`positive|neutral|negative|mixed|unknown`), signed basis-point
score, language, prompt version, the comment's own time and a SHA-256 of the text (a changed comment is analysed again;
the text itself is never stored here). The metering purpose is `social:sent:` + a hash of account, comment id and text hash, so a
retry is counted once. The first assistant state other than `ok` stops the batch (`stopped`; the rest stays `pending`) and
nothing is stored. `AnalyseCommentsJob` (queue `ai`, one chunk of 25 per account at a time through `WithoutOverlapping`,
rate-limited answers released with backoff) is the only queued class. The workspace **daily cap**
(`social_settings.ai_daily_cap`, default 200, 0 pauses; Settings page) counts stored results since 00:00 UTC.
The service never edits, hides, deletes or answers a comment. `GET social/analytics/sentiment?from&to`
(`social.analytics.sentiment`, `social.analytics.view`) feeds `SentimentSummary.vue` on the Analytics page: a mix bar with
counts and percentages as text, a per-page table, and the honest empty state "no comments analysed yet"; unknown results are
counted but never averaged. The comments module may call `queue()` after it stores a comment; Social does not hook into it.
## Lead ads (Facebook lead forms)

Page: **Lead forms** (`social.leadads.index`, permissions `social.leadads.view` / `social.leadads.manage`, ADR 0026).
A Facebook Page's lead-form answers reach the CRM **only through `App\Core\Crm\Contracts\LeadIntake`** (ADR 0023);
the code lives in `app/Modules/Social/LeadAds` and `Platforms/Facebook/LeadAdsGraph*` and imports no CRM class. **Not
exercised against Meta's live API in this repository.**

**What Meta needs (operator, once).** A Meta app with `leads_retrieval`, `pages_manage_ads`, `pages_show_list` and
`pages_manage_metadata` (the last to subscribe a Page to webhooks) approved by app review, the **Webhooks** product
with the Page object subscribed to the `leadgen` field, and the person who connects a Page needs leads access on it
(Business Settings, Leads Access). Environment: `SOCIAL_FACEBOOK_LEAD_ADS=true` (off by default: asking for an
unapproved permission breaks the connect dialog, and the connect dialog only requests the lead permissions when this is
on), `SOCIAL_FACEBOOK_WEBHOOK_VERIFY_TOKEN`; the app id and secret are the existing `SOCIAL_FACEBOOK_CLIENT_*`. The
page says honestly when any of this is missing (`not_enabled`, `not_configured`, `webhook_not_configured`).
**Meta allows one callback URL per app**, while this platform receives webhooks on the workspace host
(`https://{workspace}.APP_DOMAIN/social/webhooks/facebook/leads`, shown on the page). Until a central relay exists
(not built), a deployment can therefore point one Meta app at one workspace's URL (single-workspace installs, or an app
registered per customer); several workspaces behind one app need that relay.

**Per Page.** `Switch on lead capture` (`LeadAdsSetup::enable`): checks what the token holds (`debug_token`; the missing
permissions are named on the page), claims the Page, then calls `POST /{page-id}/subscribed_apps` with
`subscribed_fields=leadgen` and lists the forms (`GET /{page-id}/leadgen_forms`, cached in `social_lead_forms`;
refreshed by hand or when 12 hours old). A Page connected before the lead permissions were requested shows "connect it
again". Switching off calls `DELETE /{page-id}/subscribed_apps`, removes the claim and keeps every received lead.

**Claim registry.** `social_lead_ad_page_claims` has **no `tenant_id`** (documented exception, `docs/DATABASE.md`
section 2): `UNIQUE(page_id)`, owned through `lead_page_id` (a tenant-owned row). One Page feeds one workspace; a second
workspace gets "already connected to lead capture in another workspace". The receiver finds a Page's setup row through
the tenant scope, so a Page claimed elsewhere is dropped on this host.

**Receiver** (`LeadWebhookController`, group `tenant.callback`: no session, cookies or CSRF; `entitled:social`).
`GET` is Meta's handshake (answered only with the configured verify token, constant time, challenge restricted to
`[A-Za-z0-9_-]`). `POST` order: body capped (256 KB, 413) -> `X-Hub-Signature-256` HMAC over the **raw body** with the
Meta app secret (401) -> parse -> per `leadgen` change (at most 100 per delivery) the Page must be claimed and subscribed
by this workspace and its account not disconnected -> one row per `leadgen_id` (unique `tenant_id, leadgen_id`, a repeat is
a no-op) -> `ProcessFacebookLead` is queued. Only ids are read from the payload. While the workspace holds 2000 unprocessed
leads a delivery is answered 503 with `Retry-After` so Meta redelivers.

**Processing** (`LeadProcessor`, queue `webhooks`). The row is claimed atomically; the lead is fetched with
`GET /{leadgen-id}?fields=created_time,ad_id,adset_id,campaign_id,form_id,field_data,is_organic` using the Page token in
the `Authorization` header only; the form's mapping is applied (`FieldMapper`); `LeadIntake::capture` is called with
source `facebook_lead_ads`, idempotency key `fb-lead:{leadgen_id}`, campaign (`campaign_id`) and ad (`ad_id`) references,
the mapped answers (at most 29, plus the form name under `facebook_form`) and the lead's creation time. Outcomes:

| Status | Meaning |
|---|---|
| `received` | waiting to be fetched or retried (with `next_attempt_at`) |
| `captured` | the CRM has a new contact/lead (ids stored; automation trigger fired once) |
| `duplicate` | the CRM already had this `fb-lead:` key (`replayed`) |
| `failed` | with a reason code: `missing_contact`, `invalid_contact`, `permission_missing`, `lead_not_found`, `rejected`, `gave_up`, `expired`, `account_disconnected` |
| `waiting_for_crm` | `LeadIntake` answered `unavailable` (no CRM): answers kept, sent by the sweep once the CRM is entitled |
| `waiting_for_account` | token expired: the account is moved to `needs_reauthorization` (event `social.account.needs_reauthorization`), the lead resumes after reconnecting |
| `skipped` | the form is not switched on or not in the cached list; switch on and press Retry |

Facebook errors: a token error (190 and friends) never retries; a rate limit (429, codes 4/17/32/613/80xxx) reschedules
after `Retry-After` and does not spend an attempt; 5xx and network errors back off 1 min, 5 min, 30 min, 2 h, 6 h and
fail with `gave_up` after 6 attempts or 3 days; a lead Facebook no longer has fails at once. A person's **Retry**
restarts the budget. The fetched answers are stored encrypted (`payload`, hidden) only while still needed and are removed
when the CRM has them, after 14 days if they never could be handed over (`expired`), and processing history is deleted
after 180 days. The `social.lead_ads_sweep` job (every minute, entitlement-gated) re-queues due retries and lost jobs, sends
`waiting_for_crm` leads when the CRM appears, resumes `waiting_for_account` leads, and hourly refreshes stale form lists and
purges.

**Mapping.** Each form question goes to `name`, `email`, `phone`, `company`, `consent`, `answer` (kept under an answer
name) or `ignore`. Automatic defaults: `full_name`/`first_name`/`last_name` -> name (first and last are joined),
`email`/`work_email` -> email, `phone_number`/`work_phone_number` -> phone (the `p:` prefix of exports and every Bangladeshi
spelling are normalised to E.164 by `PhoneNumber`), `company_name` -> company, everything else -> answer. A lead needs a
usable email or phone: an unusable one is left out when the other works (logged as a note); with neither the lead fails
with `missing_contact`/`invalid_contact` and keeps its answers so fixing the mapping and pressing Retry needs no new
fetch. **Consent evidence is reported only when a question the person mapped as `consent` was answered affirmatively**
(yes, true, agree, accepted, and the Bengali yes); without such a question it is always false. The CRM never writes
platform consent from it. The mapping dialog previews the result with example values through the same code
(`POST forms/{form}/preview`, no data is saved).

**Fetch recent leads.** `POST accounts/{account}/backfill` (days, clamped to 1-14) queues `BackfillFacebookLeads`:
per switched-on form `GET /{form-id}/leads` with a `time_created` filter, at most 100 leads per run; leads already known are
skipped and the listed answers are used without a second fetch.

**Visibility.** The page shows per Page the connection state with the missing-permission explanation, received/in-CRM/
failed/waiting counts and a 14-day history (workspace timezone), the cached forms with a switch and the mapping dialog,
and a paginated lead list (filter by outcome and Page) whose detail dialog shows the processing log (received, fetched,
captured/duplicate/failed/waiting/retry/skipped, each with a translated reason) and the CRM contact and lead ids as text
(there is no CRM route contract, so no link). When the workspace has no CRM the page says so and leads wait. Automation
trigger `social.lead_ad.received` (ids only: `lead_ad_id`, `account_id`, `form_id`, `crm_contact_id`, `crm_lead_id`; fired
once, when a lead is first handed to the CRM). Report `social.lead_ads` (day, Page, form: received, handed to the CRM,
failed, waiting). The dashboard widget gains a "Lead ads, last 7 days" block for members with `social.leadads.view`.

Audit: `social.leadads.enabled|disabled|form_enabled|form_disabled|mapping_saved|backfill_requested|lead_retried`.
The per-account allow-list of Page access is not applied to lead ads (the permission is workspace-wide).

## Audience comments (Facebook Pages)

An inbox for what people write on a connected Page's posts, with moderation, a webhook plus polling ingestion and
declarative keyword rules. Code: `app/Modules/Social/Comments`, Graph calls in `Platforms/Facebook/FacebookComments.php`
and `FacebookCommentsWebhook.php`, configuration in `config/social_comments.php`, decisions in ADR 0025. The
`comments` capability of the Facebook adapter is true when `SOCIAL_COMMENTS` is on (the default) and false otherwise;
no other platform has a comments provider, so the others stay false. **Nothing here has run against Meta's live API**:
every call is tested against recorded Graph responses (`Http::fake`) only.

### What exists

| Capability | Facebook Pages |
|---|---|
| Read comments of a post (`GET /{post-id}/comments`, `filter=stream`) | yes (polling fallback) |
| Receive comments live (Page `feed` webhook, `item=comment`, verbs add / edited / remove / hide / unhide) | yes |
| Reply (`POST /{comment-id}/comments`) | yes |
| Hide / unhide (`POST /{comment-id}` with `is_hidden`) | yes |
| Delete (`DELETE /{comment-id}`) | yes |
| Like / unlike as the Page (`POST` / `DELETE /{comment-id}/likes`) | yes |
| Private reply (`POST /{comment-id}/private_replies`) | only when `SOCIAL_FACEBOOK_PRIVATE_REPLIES` is on **and** the account holds `pages_messaging`; otherwise the rule editor does not offer it |
| Sentiment | placeholder column, always empty |
| Other platforms | not available (`CommentProviders` has no provider) |

Meta permissions (all need app review): `pages_read_engagement` (read), `pages_manage_engagement` (reply, hide,
delete, like), `pages_manage_metadata` (subscribe the Page to `feed` events), and `pages_messaging` for private replies
only. The connect dialog asks for the first three (and the fourth when private replies are on) while `SOCIAL_COMMENTS`
is on; Pages connected earlier must be connected again to grant them. A call that Meta refuses for a missing
permission says so and leaves publishing untouched; a rejected token moves the account to "needs reauthorization" (the
same state publishing uses) through `AccountManager::markNeedsReauthorization()`.

### Ingestion

- **Turning a Page on** (`social.comments.manage_rules`, Comments > Rules and Pages) claims the Page platform-wide in
  `social_comment_page_claims` (unique `(platform, external_id)`, no tenant scope; DATABASE.md section 2): a Page feeds
  one workspace only. A claim whose account was disconnected can be taken over. It then calls
  `POST /{page-id}/subscribed_apps` (`subscribed_fields=feed`); if that fails the Page shows "Regular checks" and the
  polling fallback carries it.
- **Webhook**: `POST {workspace}/social/webhooks/facebook` (stateless `tenant.callback` group, entitled to Social) and
  `POST {central}/social/webhooks/facebook` (`central.callback`; a Meta app has one callback URL, so this one resolves the
  workspace from the Page's claim, never from anything the request says). `GET` answers the `hub.challenge` handshake
  against `SOCIAL_FACEBOOK_WEBHOOK_VERIFY_TOKEN` in constant time. Order: app secret configured (else 404), size cap
  (413), `X-Hub-Signature-256` HMAC over the raw bytes with `SOCIAL_FACEBOOK_CLIENT_SECRET` (else 401), parse, then the
  Page must be claimed, the claim must belong to the asked workspace, the account be connected and the source enabled.
  Comments are deduplicated by `(tenant, account, comment id)`, so a replay changes nothing. An event that keeps failing
  returns 503 (`Retry-After: 60`) until `social_comments.webhook.max_failures` (5) failures per workspace and event, then
  it is logged (ids and exception class, no text) and answered 200 so Meta stops retrying, like Communication's poison cap.
- **Polling** (`PollRecentComments`, scheduled `social.comments_poll`, every minute through the entitlement-gated
  runner): a few Pages per run (`accounts_per_run`), each Page's recent posts (`GET /{page-id}/posts` plus our own
  published targets) within `lookback_days`, `comments_per_post` comments each with `since`. A Page is read at most every
  `interval_minutes` (three times less often while its webhook is live), doubling after each failure up to
  `max_backoff_minutes`; a throttled or failing Page backs off, a rejected token ends in "needs reauthorization".
- Comment text and names are untrusted: control and bidirectional-override characters are stripped, length is capped
  (8000 characters), and the pages render them only as interpolated text (never `v-html`).

### Inbox

`social.comments.index` (`Comments/Index`): filters Open / Unread / Needs attention / Replied / Hidden / Mine /
Handled with counts, Page, post and text search, a conversation (thread) view with the Page's replies, and per comment:
reply, like, hide / show, delete (confirmed), mark handled / reopen, assign to a member, tags and internal notes (never sent
anywhere); bulk: mark read, handled, hide, show, assign, tag (at most 50 ids, each checked against the viewer's scope). A
person's own scope is "assigned to me" (`owner_id`); department and branch scopes follow the assignee. Everything that
changes something writes an audit entry (`social.comment.replied|private_replied|hidden|unhidden|removed|liked|unliked|
handled|reopened|assigned|note_added|tagged|untagged|flagged`, `social.comment_rule.*`, `social.comments.*`); automatic
ones carry `automatic`, `rule_id` and the rule's member.

### Keyword rules

`social.comments.rules.index` (`Comments/Rules`, `social.comments.manage_rules`). A rule is data: a name, keywords (up
to 50 of 60 characters; any / all; whole word or contains; case-insensitive; Bengali and every script), an optional
Page / post / campaign scope and a closed list of actions: reply with one of up to five templates (variables only
`{first_name}` and `{page_name}`, one literal substitution, the choice is deterministic per comment), hide, assign, tag,
send to review and private reply. **Keywords are never compiled into a pattern**, and nothing a member writes is
evaluated. Safety, all stored in the database: a global pause switch; a per-rule and a per-Page hourly cap; a cooldown per
commenter; quiet hours in the workspace timezone (held, not dropped); no comment older than 48 hours is answered; never
the Page's own comments, hidden or deleted ones; one run of each action of each rule per comment (a claim row written
before the platform call, so a crash can only skip a public reply, never double it); a temporary refusal is retried later,
a rejection is recorded as failed. Every action leaves a `social_comment_rule_runs` row and an audit entry. The rule runs
as the member who last saved or switched it on; before every action `RuleActorGuard` re-checks that the member is active,
the workspace entitled and the permission held for **all** records, and saving needs those permissions (no rule can be
more powerful than its author). A rule whose member lost a permission does nothing and the list says why. The dialog has a
test box (`POST social.comments.rules.preview`): it shows the matched keywords and the actions with the rendered reply and
sends or saves nothing.

### Automation, widget, reports

Trigger `social.comment.received` (ids only: comment, account, post, platform, is_reply). Actions
`social.comment.reply` (`social.comments.reply`) and `social.comment.hide` (`social.comments.moderate`), idempotent per
key through `social_automation_action_receipts`. Dashboard widget `social.comments` (waiting / unread / needing
attention, scope-aware). Report `social.comment_volume` (received, answered, average time to answer, waiting, hidden per
account; permission `social.comments.view`).

## Page insights and performance

Page-level (account-level) figures come from the documented Graph API Page Insights, are stored one row per account
per day, and feed the **Page performance** dashboard at the top of Social > Analytics. The `insights` capability
(`PlatformCapabilities::$insights`) is true only for `FacebookPagesPlatform`; LinkedIn and X do not claim it.

**Collection.** `PageInsightsCollector` runs inside the hourly `social.maintenance` job (after token renewal and post
metrics, failure-tolerant). Per run: at most `social.insights.accounts_per_run` (3) connected accounts whose last read is
older than `refresh_hours` (6), oldest first, within a 35-second budget. The first read of an account backfills
`backfill_days` (28, at most 90; Meta allows 93 per request); later reads re-collect the last `recollect_days` (3)
because Meta finalises late. A figure is written only when the platform returned it: a later read that lacks a figure
never blanks a stored one, and a missing figure is `null` ("not reported"), never zero. An empty breakdown object is
also "not reported". Days still in progress (`end_time` in the future) are skipped.

**Facebook calls** (all with the Page access token in `Authorization: Bearer`, never in a URL, a log line or an error
text; connection errors are never echoed because their message can contain the URL):

| Call | Used for |
|---|---|
| `GET /{page}?fields=followers_count,fan_count` | current followers and page likes, stored on the platform's current day |
| `GET /{page}/insights?metric=M&period=day&since=&until=` | one metric per request, so a retired metric cannot fail the others |
| `GET /{page}/roles?fields=id,name,tasks` | the Meta tasks of the person who authorised the page |
| `GET /debug_token?input_token=` (app token) | the permissions the token holds and the user it was issued for |

**Metrics** (`config/social.php` `insights.facebook.metrics`; the first candidate the API accepts and answers is used,
the one used is recorded per day in `sources`; review the lists when `SOCIAL_FACEBOOK_API_VERSION` changes, Meta retires
and renames metrics):

| Figure (column) | Candidates, in order |
|---|---|
| `followers` | `page_follows` (and the Page field `followers_count`) |
| `fans` (page likes) | Page field `fan_count` |
| `new_followers` | `page_daily_follows_unique`, `page_daily_follows` |
| `unfollows` | `page_daily_unfollows_unique` |
| `impressions` | `page_media_view`, `page_impressions` |
| `reach` | `page_total_media_view_unique`, `page_impressions_unique` |
| `engaged_users` | `page_engaged_users` |
| `post_engagements` | `page_post_engagements` |
| `page_views` | `page_views_total` |
| `reactions` | `page_actions_post_reactions_total` (sum of the per-type breakdown) |
| `video_views` | `page_video_views` |

Needs `read_insights`, `pages_read_engagement` and `pages_show_list` (Meta app review) and the **Analyze** task on the
page. NOT exercised against Meta's live API in this repository.

**Rate limits.** The first rate-limit answer (codes 4, 17, 32, 613, 80001-80014, HTTP 429) stops the account and the run
and is recorded as `rate_limited`; Meta's `x-app-usage` / `x-page-usage` headers at or above `usage_stop_percent` (80)
stop the read as `throttled`; two server errors stop it as `unavailable`; an invalid token as `token_invalid`; a
permission error as `permission_missing`. The status and error code (not the message) are kept per account and shown.

**Days and timezones.** Meta buckets Page insights in Pacific Time days: a daily value belongs to the Pacific day that
ended at its `end_time` (DST-safe: 08:00 UTC ends a PST day, 07:00 UTC a PDT day). The stored `metric_date` is that day
(`insights.day_timezone`). Periods on the dashboard are the last 7/28/90 workspace-timezone dates against the 7/28/90
before them; the page says so. Best posting times bucket **our own** posts' `published_at` in the workspace timezone
(DST-safe), never Meta's day.

**Tables** (additive migration `2026_10_27_100000_create_social_page_insights_tables`, guarded): `social_page_insights`
(unique `tenant_id, account_id, metric_date`), `social_account_diagnostics` (granted permission names, tasks, last read
state; no token) and `social_account_access` (the allow-list, below). All tenant-owned.

**Dashboard** (`PagePerformance`, props `insights` of `Analytics/Index`): period selector, page picker (up to 10 pages,
compared in a table and as one line each), KPI tiles with the change against the previous period, trend chart (accessible
SVG, keyboard readout, numbers table), best posting times (hour of day, day of week, strongest slots; marked as a first
impression below `min_posts_for_best_time` = 10 posts with reported figures), top and lowest posts, content types (text,
image, video, link), campaigns with a per-day timeline, and an honest coverage note (last read, days with data, Pacific
days, daily unique sums, partial coverage, per-account problems). A change is shown only when both periods report the same
number of days (or the figure is a level such as followers); otherwise the tile says why. States: `no_accounts`,
`restricted`, `not_configured`, `permission_missing`, `waiting`, `ready`, each with what to do next.
`GET analytics/export?days=&pages[]=&dataset=daily|posts|campaigns` downloads the same data as CSV through `CsvSafe`
(formula-safe).

**Reports** (`docs/REPORTS.md`): `social.page_insights` (per page, totals for flows, latest for followers) and
`social.campaign_performance` (per content campaign), both `social.analytics.view`.

**Page tasks and permissions.** On the Accounts page each Facebook account shows the Meta tasks of the person who
connected it (found by matching the `user_id` of `debug_token` in `/{page}/roles`), the permissions the app holds, and
which Oyosend feature needs which task: publishing `CREATE_CONTENT` (+ `pages_manage_posts`), insights `ANALYZE`
(+ `read_insights`, `pages_read_engagement`), comments `MODERATE`, advertising `ADVERTISE`. When the roles list cannot
be read the tasks are "Not reported", never an empty list. `me/accounts?fields=tasks` is not used: the stored credential
is a Page token, which cannot list the user's pages. Re-checked every 24 hours and on demand (`POST
accounts/{account}/access/check`, `social.accounts.manage`).

**Who may use a page (allow-list).** `AccountAllowList` + `social_account_access`: per account, entries for members
(`tenant_memberships.id`) or roles with `can_publish`, `can_approve`, `can_analytics`. No entries = open (previous
behaviour). Once an account has entries only the owner and matching entries may use it, **in addition to** the normal
permissions. Enforced server-side: creating or editing a post for the account, and every action on a post that targets it
(`SocialSupport::post`: edit, delete, submit, schedule, reschedule, unschedule, retry need `publish`; approve and reject
need `approve`), the post form's account list, the dashboard, the CSV export, the publishing/engagement reports and the
legacy engagement overview (`analytics`). A workflow-created draft (`social.post.create_draft`) is not restricted, but it
cannot be scheduled or approved without the allow-list. Editing the list (`PUT accounts/{account}/access`) needs
`social.accounts.manage` and is audited (`social.account.access_changed`).
## Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `social.posts.view` | yes | list, calendar, detail, export, comments |
| `social.posts.create` | | create drafts |
| `social.posts.edit` | yes | edit, delete needs `social.posts.delete` (yes) |
| `social.posts.schedule` | yes (sensitive) | submit, schedule, reschedule, unschedule, retry |
| `social.posts.approve` | sensitive | approve / reject; four-eyes enforced in the workflow |
| `social.posts.comment` | | add comments (delete own, or approvers) |
| `social.posts.ai_assist` | | AI suggestions in the composer (also needs create or edit); never applies anything |
| `social.media.view` / `.manage` | | library, file route / upload, rename, delete |
| `social.accounts.view` / `.manage` | manage sensitive | list / connect, re-authorise, disconnect |
| `social.leadads.view` / `.manage` | | lead forms, mapping, received leads and their log / switch Pages and forms on, change the mapping, fetch recent leads, retry (manage is sensitive: leads then enter the CRM) |
| `social.comments.view` | yes | audience comments inbox, thread, mark read |
| `social.comments.reply` | yes | reply, like, mark handled, internal notes |
| `social.comments.moderate` | yes (sensitive) | hide, delete, assign, tags |
| `social.comments.manage_rules` | sensitive | keyword rules, kill switch, turn Pages on or off |
| `social.library.view` / `.manage` | | campaigns, categories, hashtag sets |
| `social.imports.create` | | CSV bulk scheduling |
| `social.analytics.view` | | reports, Page performance, CSV export |
| `social.settings.manage` | sensitive | require approval, hourly account limit, daily AI analysis cap |

## Limits and events

- `social.scheduled_posts_per_month` (metered, `post:{id}` idempotency key) and `social.connected_accounts`
  (gauge: accounts that are not disconnected).
- Domain events (ids and scalars, never text or errors): `social.post.scheduled`, `social.post.approved`,
  `social.post.published`, `social.post.failed`, `social.account.needs_reauthorization`; each is also an
  automation trigger. The one automation action is `social.post.create_draft` (permission `social.posts.create`;
  durable receipts in `social_automation_action_receipts`): it only ever creates a draft, so a workflow cannot
  bypass approval or publish.
- Audit entries: `social.account.connected|disconnected|needs_reauthorization`, `social.post.created|updated|deleted|
  submitted|approved|rejected|scheduled|rescheduled|unscheduled|returned_to_draft|published|failed|target_retried`,
  `social.comment.added|deleted`, `social.import.completed`, campaigns, categories, hashtag sets, media, settings.

## Routes and pages

Routes are under `{workspace}.APP_DOMAIN/social`, all behind `auth:web`, `auth.session`, `member`, `subscribed`
and `entitled:social`. Pages are Inertia components `Modules/Social/Pages/...`:

| Route name | Page | Permission |
|---|---|---|
| `social.calendar.index` | `Calendar/Index` | `social.posts.view` |
| `social.posts.index` / `.create` / `.show` / `.edit` | `Posts/Index`, `Posts/Form`, `Posts/Show`, `Posts/Form` | view / create / view / edit |
| `social.media.index` | `Media/Index` | `social.media.view` |
| `social.accounts.index` / `.select` | `Accounts/Index`, `Accounts/Select` | `social.accounts.view` / `.manage` |
| `social.leadads.index` | `LeadAds/Index` | `social.leadads.view` |
| `social.comments.index` | `Comments/Index` | `social.comments.view` |
| `social.comments.rules.index` | `Comments/Rules` | `social.comments.manage_rules` |
| `social.library.index` | `Library/Index` | `social.library.view` |
| `social.imports.index` | `Imports/Index` | `social.imports.create` |
| `social.analytics.index` | `Analytics/Index` (Page performance + engagement) | `social.analytics.view` |
| `social.analytics.export` | CSV download | `social.analytics.view` |
| `social.accounts.access.update` / `.check` | allow-list, re-check page tasks | `social.accounts.manage` |
| `social.settings.index` | `Settings/Index` | `social.settings.manage` |

## Planned / not built

Facebook: Reels or videos scheduled by Facebook itself (`video_state=SCHEDULED` / `scheduled_publish_time`; we schedule), custom thumbnails or
covers, captions files, stories, link-preview control, and Instagram Reels. The Facebook photo, video and Reels flows are tested against
recorded Graph responses only; they have not run against Meta's live API and need `publish_video` from app review.

Instagram, YouTube, TikTok and Pinterest adapters; media and company-page publishing for LinkedIn; media for X;
audience comments for platforms other than Facebook (the `comments` capability is true only for Facebook Pages); sentiment of comments in the inbox; social listening. The
redirect broker above is built and unverified against a live platform. See the owner decisions in `PROGRESS.md`.
