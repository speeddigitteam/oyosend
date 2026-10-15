# 0033 — Social publishing to Instagram, TikTok, YouTube and Pinterest

Status: accepted (groundwork and all four adapters built; follows ADR 0029). Date: 2026-10-14.

## Context

The owner wants one post written in Social → Posts to be published to Facebook, Instagram, TikTok, YouTube,
LinkedIn and Pinterest. Facebook, LinkedIn and X adapters exist; Instagram, TikTok, YouTube and Pinterest were listed
as "not built yet" placeholders. Unlike the existing three, these platforms do not publish text on its own (TikTok and
YouTube publish only video), Instagram's content-publishing API fetches the media from a public URL while workspace
media is private, and each needs operator credentials. Everything an operator configures must be set in the Super
Admin panel with the environment as the fallback (ADR 0028, 0029), and a platform without credentials must say
"not configured" honestly.

The four adapters are written separately, one file each. This ADR records the shared groundwork they rely on and the
platform decisions they follow.

## Decision

1. **Media requirements are capabilities.** `PlatformCapabilities` gains `requiresMedia`, `requiresVideo`
   (implies `requiresMedia`), `maxVideoBytes`, `maxVideoSeconds` and `minVideoSeconds`, defaulting to "nothing
   required", so Facebook, LinkedIn and X are unchanged. `PostContentRules::requirements()` turns them into
   translated per-account issues; a video whose length could not be read only warns (as for Facebook Reels).
   A new optional contract, `ValidatesMedia::mediaIssues(list<MediaInfo>, string $text)`, returns
   `{blocking, message}` items for rules the flags cannot express (aspect ratio, file type, a caption needed as a
   title). Both run in `PostContentRules::previews()`, which feeds the composer's live check and
   `assertPublishable()` in `PostWorkflow` submit, approve and schedule, so a target lacking what its platform needs
   cannot be scheduled. The flags are in `toArray()` (pages receive them); the composer shows each chosen account's
   requirements (`AccountRequirements.vue`) and the Accounts page lists them.

2. **A public, expiring URL for one media file, on request.** `MediaFile` gets an optional URL maker
   (`publicUrl()`, `hasPublicUrl()`), filled by `PostPublisher`. Nothing is exposed unless an adapter calls it, right
   before the platform call that needs it. The URL is a Laravel temporary signed route on the workspace host,
   `GET social/public-media/{media}` (`social.media.public`) in the stateless `tenant.callback` group with
   `entitled:social` (no session, no cookies, throttled 60/min per IP). The signature is relative (path and query, so
   it survives proxies and ports) and the query names the issuing workspace, which must equal the host's workspace;
   the file is found through the tenant-scoped model and streamed through `TenantStorage`. Lifetime
   `social.media.public_url_minutes`, default 120 minutes (long enough for Meta to fetch a large Reel after container
   creation), kept between 5 and 1440. Any failure — unsigned, tampered, expired, another workspace's host or id,
   missing file — is the same bare 404. **Exposure accepted:** anyone holding the URL can download that one file until
   it expires. The URL is never logged, stored or shown.

3. **Operator settings in the panel.** `IntegrationCredentials::SCHEMA` gains `social/tiktok` (client key, client
   secret), `social/youtube` (Google OAuth client id, secret) and `social/pinterest` (app/client id, secret), and a new
   kind of field, `flagFields`: non-secret switches stored as booleans in `settings`. `social/instagram` has only the
   switch `publishing` → `social.platforms.instagram.publishing` (default off, environment fallback
   `SOCIAL_INSTAGRAM_PUBLISHING`). A stored switch always overlays — "off" in the panel beats "on" in the environment —
   while an unmanaged or disabled row keeps the environment value. TikTok's client key is stored under the config key
   `client_id` so the shared adapter plumbing (`HttpPlatform::availability()`) reads it like every other platform. API
   bases, scopes and the documented media limits live in `config/social.php` as plain values, not new environment
   settings.

4. **Registration without collisions.** The four adapter class names
   (`App\Modules\Social\Platforms\Adapters\{Instagram,TikTok,YouTube,Pinterest}Platform`) are listed in
   `social.adapters` as strings; `SocialPlatforms` skips a listed class that does not exist, so each "not built yet"
   placeholder is replaced the moment its adapter ships and no shared file needs editing to register it.

5. **OAuth.** The central broker and the workspace callback have no platform allow-list; the four keys `instagram`,
   `tiktok`, `youtube` and `pinterest` work as soon as an available adapter answers to them (tested). The redirect URI
   for each developer console is `https://APP_DOMAIN/social/oauth/callback/{platform}` (docs/SOCIAL.md).

### Platform decisions for the adapters

- **Instagram** publishes to Instagram professional accounts linked to a Facebook Page, through the Instagram Graph
  API with Facebook Login, reusing the Facebook (Meta) app — no credential of its own. Scopes `instagram_basic`,
  `instagram_content_publish` (plus `pages_show_list`, `pages_read_engagement`, `business_management`), which need
  Meta app review; hence the panel switch, default off. Media goes by `image_url` / `video_url` from decision 2;
  container then `media_publish`, polling the container status. Feed images JPEG only, aspect 4:5 to 1.91:1;
  carousel up to 10; Reels 3 s to 15 min. `requiresMedia`. About 50 API-published posts per account per 24 h.
- **TikTok** uses the Content Posting API Direct Post with `FILE_UPLOAD` (chunked upload from storage, no public URL;
  `PULL_FROM_URL` would need a verified domain). `requiresVideo`. Until TikTok audits the app, posts are private
  (`SELF_ONLY`); the adapter must say so rather than imply public posting. The creator info query decides the
  allowed privacy levels and maximum duration.
- **YouTube** uploads through the YouTube Data API v3 resumable upload (`videos.insert`, `uploadType=resumable`),
  as a `StepwisePublisher`. `requiresVideo`. The post's internal title stays internal (the composer says it is never
  published); the video title is taken from the first line of the text (100 characters), and the adapter demands
  text through `ValidatesMedia`. Uploads from a project that has not passed Google's API audit are locked to private;
  each upload costs 1600 of the default 10,000 daily quota units.
- **Pinterest** creates Pins through API v5; a Pin needs media and a board. A connected account is one board
  (`completeAuthorization()` lists the member's boards as profiles), so no per-post board picker is needed. Images by
  `image_url` (decision 2) or base64; video through the media upload registration. `requiresMedia`. Needs Pinterest
  Standard access for `pins:write`.

## Consequences

- A post that targets a media-only or video-only platform without the media it needs is refused with a clear,
  translated, per-account reason in the composer and again on submit, approval and scheduling.
- One library file at a time becomes publicly fetchable for a bounded time, only while a platform that needs it is
  publishing it. SECURITY.md 5d.5 records the exposure.
- The operator configures every new platform in Super Admin → Integrations; a platform without credentials, or
  Instagram with the switch off, stays honestly unavailable.
- Social 1.6.0 records the new capability fields and the public media route in its catalogue version; Social 1.7.0 the
  creator info route (amendment below).
- None of the four platforms has been exercised against its live API in this repository; each needs its own app
  review or audit.

### Decisions taken while building the adapters (2026-10-14)

- **Pinterest** sends images as base64 (`image_base64`, `multiple_image_base64` for 2 to 5), so it never asks for a
  public URL; video pins use the media registration upload and take the post's photo as cover, else the frame at 1 s.
  All boards of one connection share one refresh token (unverified whether a refresh invalidates the others).
- **TikTok**'s `upload_url` carries an upload token and cannot be reissued, while a large video takes several worker
  runs. Only when an upload stops partway, the URL is kept in the workspace's tenant-prefixed cache, encrypted with
  `Crypt`, for 55 minutes (TikTok's link lives an hour), keyed by a hash of the `publish_id`; never in `remote_meta`, a
  log or a message. A missing entry fails the target with "nothing was posted" rather than starting a second upload.
  Privacy is `PUBLIC_TO_EVERYONE` when the creator info offers it, else `SELF_ONLY` (since 1.7.0 only for targets saved
  before the creator could choose; see the amendment below).
- **YouTube**'s resumable session URI is stored in `remote_meta` encrypted with the application key (checked to be
  https on the configured upload host before a token is sent to it). The first run only opens the session; a
  timeout or 5xx on the final chunk is an unknown outcome and never repeated.
- **Instagram** publishes every video as a Reel (the API has no plain feed video) and implements `PostTypeAware`
  (`post` one photo, `photos` a carousel of up to 10, `video`/`reel` one Reel). Only container ids and counters are
  stored; the signed media URL is created inside the request and never stored.

### Amendment 2026-10-14: TikTok settings chosen by the creator (Social 1.7.0)

TikTok's Content Posting guidelines and its audit require the creator, not the app, to choose each post's privacy
level, the comment / Duet / Stitch switches and the commercial content disclosure, from the creator info of that
account. The automatic choice above ("`PUBLIC_TO_EVERYONE` when offered, else `SELF_ONLY`") is replaced:

- A new optional adapter contract, `Platforms/ProvidesPostOptions` (`postOptions(AccountCredentials)`, a read-only
  API call; `normalizePostOptions()`; `postOptionIssues()`, pure), implemented by `TikTokPlatform` through
  `TikTok/TikTokPostOptions`.
- The choice is stored per target in a new nullable JSON column, `social_post_targets.options` (additive migration
  `2026_11_10_000200_add_options_to_social_post_targets`), sent through the existing store / update / preflight
  requests and validated there (known keys, TikTok's four privacy levels, booleans). `PublishPayload` gains an optional
  last parameter `targetOptions` (null = nothing chosen), filled by `PostPublisher`.
- The composer reads `GET social/posts/creator-info/{account}` (`social.posts.creator_info`) and shows a TikTok
  settings card: no privacy preselected, interaction switches off until turned on (disabled when the creator turned them
  off in TikTok), disclosure with "Your brand" / "Branded content", and a consent sentence referring to TikTok's Music
  Usage Confirmation (and Branded Content Policy). The endpoint never returns a token and replaces TikTok's error text
  with our own message.
- `PostContentRules` blocks a TikTok target without a privacy level, a disclosure without a brand choice and private
  branded content (composer check, submit, approve, schedule). Publishing re-reads the creator info: a privacy level
  no longer offered fails the target before `init` (`tiktok_privacy_not_offered`, not retried); a switch the creator
  disabled stays off.
- **Legacy fallback:** a target whose `options` is null (saved before this column existed) keeps the earlier automatic
  settings so already-scheduled posts publish as scheduled. Editing such a post asks for the settings like any other.

Also in Social 1.7.0: the composer's account chips and preview author rows show the account's stored profile picture
(http(s) only, `referrerpolicy="no-referrer"`, initials as fallback); the CSP `img-src` gains the platforms' picture
hosts (`social.avatar_hosts`, merged into `platform.security.img_hosts`). Not exercised against TikTok's live API.
