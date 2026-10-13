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
| `FacebookPagesPlatform` (`facebook`) | Graph API: `dialog/oauth`, `oauth/access_token` (code + `fb_exchange_token`), `me/accounts`, `{page}/feed`, `{page}/photos` (multipart `source`), post `likes`/`comments`/`shares` fields | publish, schedule, analytics (likes, comments, shares), one image; no video | Meta app with `pages_show_list`, `pages_manage_posts`, `pages_read_engagement` approved by app review |
| `LinkedInPlatform` (`linkedin`) | OAuth 2.0 (`/oauth/v2/authorization`, `/oauth/v2/accessToken`), OpenID `userinfo`, `POST /rest/posts` with `LinkedIn-Version` and `X-Restli-Protocol-Version` | publish, schedule; **text only**; no analytics | LinkedIn app with "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect"; member profile only (no company pages) |
| `XPlatform` (`x`) | OAuth 2.0 + PKCE (`x.com/i/oauth2/authorize`, `api.x.com/2/oauth2/token`), `GET /2/users/me`, `POST /2/tweets`, `GET /2/tweets/:id?tweet.fields=public_metrics` | publish, schedule, analytics (impressions, likes, replies, reposts); **text only** | X developer project whose tier allows posting |

Configuration (operator level, never in the database; `config/social.php`): `SOCIAL_FACEBOOK_CLIENT_ID`,
`SOCIAL_FACEBOOK_CLIENT_SECRET`, `SOCIAL_FACEBOOK_API_VERSION` (default `v23.0`), `SOCIAL_LINKEDIN_CLIENT_ID`,
`SOCIAL_LINKEDIN_CLIENT_SECRET`, `SOCIAL_LINKEDIN_API_VERSION` (`LinkedIn-Version` header, `YYYYMM`, default
`202510`), `SOCIAL_X_CLIENT_ID`, `SOCIAL_X_CLIENT_SECRET`, `SOCIAL_REDIRECT_BROKER` (default `true`). API versions are retired by
the platforms: review them on a schedule.

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
- **Failed posts**: the post becomes `failed` (no target published) or `partially_failed`; the detail page shows each
  account's `lastError`, `attempts`, `needsManualCheck` and the attempt log (`social_publish_attempts`).
  `POST posts/{post}/targets/{target}/retry` (permission `social.posts.schedule`) re-queues one failed target.
  Events: `social.post.published` (all published), `social.post.failed` (any target failed finally).

## Media library

`social_media` rows point at private files stored through `TenantStorage` under
`tenants/{tenant_id}/social/media/{generated name}`; the original name is display text only. Uploads are verified by
content (`finfo` MIME, `getimagesize` for images, max 20000 px), against an allow-list (JPEG, PNG, GIF, WebP, MP4,
QuickTime; **SVG is refused**) and size limits (`config('social.media')`). The same bytes are stored once (SHA-256
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

## Permissions

| Permission | Scopable | Notes |
|---|---|---|
| `social.posts.view` | yes | list, calendar, detail, export, comments |
| `social.posts.create` | | create drafts |
| `social.posts.edit` | yes | edit, delete needs `social.posts.delete` (yes) |
| `social.posts.schedule` | yes (sensitive) | submit, schedule, reschedule, unschedule, retry |
| `social.posts.approve` | sensitive | approve / reject; four-eyes enforced in the workflow |
| `social.posts.comment` | | add comments (delete own, or approvers) |
| `social.media.view` / `.manage` | | library, file route / upload, rename, delete |
| `social.accounts.view` / `.manage` | manage sensitive | list / connect, re-authorise, disconnect |
| `social.library.view` / `.manage` | | campaigns, categories, hashtag sets |
| `social.imports.create` | | CSV bulk scheduling |
| `social.analytics.view` | | reports |
| `social.settings.manage` | sensitive | require approval, hourly account limit |

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
| `social.library.index` | `Library/Index` | `social.library.view` |
| `social.imports.index` | `Imports/Index` | `social.imports.create` |
| `social.analytics.index` | `Analytics/Index` | `social.analytics.view` |
| `social.settings.index` | `Settings/Index` | `social.settings.manage` |

## Planned / not built

Instagram, YouTube, TikTok and Pinterest adapters; media and company-page publishing for LinkedIn; media for X;
reading and answering audience comments (`comments` capability is false everywhere); inbox and listening. The
redirect broker above is built and unverified against a live platform. See the owner decisions in `PROGRESS.md`.
