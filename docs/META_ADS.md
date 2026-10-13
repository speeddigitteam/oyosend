# Meta Ads

Module key `meta_ads`, category Social Media, free offer, depends on `core` and `social`. Decision record: ADR 0027.
Code: `app/Modules/MetaAds`, pages `resources/js/Modules/MetaAds`, config `config/meta_ads.php`.

It shows what a workspace's Meta (Facebook / Instagram) ad accounts spend and deliver, and can pause, resume and edit
an existing budget under a ceiling. It talks only to the documented Marketing API. **It has never been run against
Meta's live API in this repository** (HTTP is faked in tests); treat first use with a real ad account as a
verification step.

## 1. What it does and does not do

Does: connect with Facebook Login; list the ad accounts the person can reach (`me/adaccounts`); sync campaigns, ad
sets and ads (name, status, objective, budgets, schedule) and daily insights (spend, impressions, reach, clicks,
actions including `lead`) hourly; a performance dashboard with KPIs and deltas, trend charts, top campaigns, a
campaign list, a formula-safe CSV export, two report providers, a dashboard widget; pause / resume / edit a budget.

Does **not** (and says so): create campaigns, ad sets, ads or creatives; archive or delete anything; boost posts;
create audiences; read ad-set or ad level insights (account and campaign level only); manage lead forms (Social
Media owns Lead Ads; here only the lead count Meta returns with the insights is shown); change an ad account's spend
cap or payment method.

## 2. Setup

Platform operator (once per installation):

1. Register a Meta app. Add the "Marketing API" product and "Facebook Login".
2. Register the **Valid OAuth Redirect URI** shown to workspace managers on the connection page:
   `https://APP_DOMAIN/meta-ads/oauth/callback` (the central broker; one URI for every workspace).
3. Set the Meta app id and secret. Preferred: **Super Admin → Integrations → Meta Ads**, where they are
   stored encrypted and write-only (the secret is never shown again or logged), with the environment as
   the fallback default — ADR 0029. The `app_id` is shown back so it can match the redirect URI; the
   `app_secret` is write-only. Alternatively set `META_ADS_APP_ID` / `META_ADS_APP_SECRET` in the
   environment. The panel value, when set and enabled, overlays the environment at boot. Optionally
   `META_ADS_API_VERSION` (default `v23.0`), `META_ADS_MANAGEMENT=false` to ask for `ads_read` only,
   `META_ADS_REDIRECT_BROKER=false` for per-workspace redirect URIs (these stay environment settings).
4. Get the permissions approved (section 8).

Workspace owner: Meta Ads → Connect Meta Ads. The checklist shows what is next: Meta app (operator) → authorise →
choose ad accounts → first sync; optional: a Facebook Page in Social Media; allow pausing and budget changes.
Everything is honest: without the Meta app the page says only the operator can fix it.

## 3. Connection and token handling

- `meta_ads_connections`: one row per Facebook person who authorised (`UNIQUE(tenant_id, external_user_id)`).
  `access_token` is `encrypted`, `$hidden`, never in a prop, array, JSON, audit entry, event, log or exception message
  (`ConnectionTest` and `SyncTest` assert this). Granted and declined scopes come from `me/permissions`; `ads_read` is
  required, `ads_management` enables changes.
- Flow: `POST /meta-ads/accounts/connect` stores a single-use random state in the member's session (10 minutes, bound
  to the member), wraps it in the signed central envelope and sends the browser to Facebook's dialog. Meta returns to
  the central broker, which verifies the envelope (HMAC, expiry, workspace exists and is accessible) and forwards the
  query to the workspace callback; any failure is a plain 404. The callback checks the state, exchanges the code, asks
  for a long-lived token (about 60 days; if that fails the short one is kept and its real expiry shown), reads
  `me`, `me/permissions` and `me/adaccounts`.
- Expiry: within ten days of expiry, once a day, a sync extends the token (`fb_exchange_token`). When Meta rejects a
  token (codes 102, 190, 459, 464, 467 or HTTP 401) the token is deleted, the connection becomes
  `needs_reauthorization`, one `meta_ads.connection.needs_reauthorization` event is raised (an automation trigger) and
  the page asks the person to authorise again (reconnect keeps the same connection and the same tracked accounts).
- Disconnect deletes the token and stops tracking; campaigns, insights and receipts are kept.
- Ad accounts: `act_<n>`, name, currency, timezone, Meta's `account_status` (shown, e.g. disabled / unsettled). A newly
  listed account is `available`; **Track** makes it `tracked` (counts against `meta_ads.ad_accounts`); stop tracking
  keeps history. A currency change at Meta clears that account's ceilings.

## 4. Sync (`SyncAdAccounts`, schedule key `meta_ads.sync`, hourly, queue `campaigns`)

Per tracked account, least recently synced first, at most `sync.max_accounts_per_run` per run and a 150 s time budget:

1. Renew an expiring token.
2. Campaigns, ad sets, ads: `{act}/campaigns|adsets|ads` with `fields=...`, cursor pagination (`paging.cursors.after`,
   never `paging.next`), at most `sync.max_pages_per_object` pages of `sync.page_size`. Upsert on
   `(tenant, account, level, external_id)`. Budgets are integer minor units; Meta's `"0"` means "not set here" and is
   stored as null. `DELETED` rows are skipped; what a **complete** listing no longer returns is marked `missing_at`
   (a listing cut short never marks anything).
3. Insights: `{act}/insights` with `level=account` and `level=campaign`, `time_increment=1`, `time_range` from the high-water
   mark minus `sync.restate_days` (Meta restates recent days) up to today in the account's timezone, bounded by the
   workspace's history setting (7 to 90 days, default 30). Upsert on `(tenant, account, level, object, day)`, so
   running twice changes nothing.
4. Outcomes on the account: `ok`, `partial` (budget cut it short), `throttled` (waiting), `error` with a code and a
   token-free message (`reauthorization_required`, `permission_missing`, `network_error`, `meta_server_error`, `meta_<code>`).

Quota: after every call the usage headers (`x-business-use-case-usage`, `x-ad-account-usage`, `x-app-usage`) are read.
At `rate_limit.pause_at_percent` (75) or on a throttling error (4, 17, 32, 613, 80000 to 80099, HTTP 429) the run
stops and `throttled_until` is set to Meta's reported regain time (default 15 min, at most 1 h); nothing else is sent
for that account until then. "Sync now" is limited to once per `manual_interval_minutes` per account.

## 5. Money and figures

- Spend `"1234.56"` becomes `123456` minor units by digit-string arithmetic in the account currency; extra digits
  beyond the minor unit round half up on the digits. Counts are non-negative integer strings. Anything else is `null`.
- Each stored row has the account's `currency`. A row Meta reports in a different currency is skipped and the
  account shows "reported in a different currency". Reports and the dashboard are **per currency**; the default is
  the workspace currency; amounts of different currencies are never added, compared or ordered.
- CTR (basis points), CPC, CPM and cost per lead are derived from summed totals with integer arithmetic (half away
  from zero); a missing or zero denominator gives null. Deltas compare with the equally long period before, in basis
  points. Reach is the sum of daily reach and is labelled as such (it is not de-duplicated across days).
- Insight days are each ad account's own calendar days.
- Assumption to verify: budgets are in the ISO 4217 minor unit of the account currency (`Money::decimals`). Meta
  documents a per-currency "offset"; if it differs from ISO for a currency in use, do not enable changes for it.

## 6. Making changes (`ChangeManager`)

Only `status` (ACTIVE and PAUSED only), `daily_budget` and `lifetime_budget` of an existing object, through
`POST /{object-id}`. Order of checks: workspace switch on (default off) and the platform asks for `ads_management` and
the grant includes it → account tracked, authorisation usable, not throttled → daily change cap → object not
archived or missing → live read from Meta equals what the dialog showed (otherwise a `stale` failure that also
refreshes the local copy) → for budgets: the object already has that kind of budget (ads and objects whose budget is
on the campaign are refused), a ceiling exists for the account, new value ≤ ceiling, an increase ≤
`changes.max_increase_percent` of the current value, value ≠ current. A receipt row (`meta_ads_changes`:
before, after, currency, actor, `request_id`, status, error code and message) is created before the call and finished
after; replay of the same `request_id` returns the stored result without calling Meta. Success re-reads the object and
audits `meta_ads.change.applied`; failure audits `meta_ads.change.failed`; a rejected token sends the connection to
re-authorisation. The interface shows before/after in a confirmation dialog; nothing is sent until confirmed.

## 7. Routes, pages, keys

Workspace routes use `['auth:web','auth.session','member','subscribed']`, `entitled:meta_ads` (from `loadTenantRoutes`)
and the permission below. Central: `GET /meta-ads/oauth/callback` (`central.meta_ads.oauth.callback`, group
`central.callback`, no session).

| Name | Verb + URL | Permission | Page |
|---|---|---|---|
| `meta_ads.performance.index` | GET `/meta-ads` | `meta_ads.insights.view` | `Modules/MetaAds/Pages/Performance/Index` |
| `meta_ads.performance.export` | GET `/meta-ads/performance/export` (throttle 10/min) | `meta_ads.insights.export` | CSV download |
| `meta_ads.campaigns.index` | GET `/meta-ads/campaigns` | `meta_ads.campaigns.view` | `Pages/Campaigns/Index` |
| `meta_ads.campaigns.status` | POST `/meta-ads/campaigns/{object}/status` (throttle 20/min) | `meta_ads.campaigns.manage` | redirect back |
| `meta_ads.campaigns.budget` | POST `/meta-ads/campaigns/{object}/budget` (throttle 20/min) | `meta_ads.campaigns.manage` | redirect back |
| `meta_ads.accounts.index` | GET `/meta-ads/accounts` | `meta_ads.accounts.view` | `Pages/Accounts/Index` (wizard) |
| `meta_ads.accounts.connect` | POST `/meta-ads/accounts/connect` (throttle 10/min) | `meta_ads.accounts.manage` | redirect to Facebook |
| `meta_ads.accounts.callback` | GET `/meta-ads/accounts/callback` (throttle 20/min) | `meta_ads.accounts.manage` | redirect |
| `meta_ads.accounts.track` / `untrack` | POST / DELETE `/meta-ads/accounts/{account}/track` | `meta_ads.accounts.manage` | redirect |
| `meta_ads.accounts.sync` | POST `/meta-ads/accounts/{account}/sync` (throttle 6/min) | `meta_ads.accounts.manage` | redirect |
| `meta_ads.accounts.destroy` | DELETE `/meta-ads/connections/{connection}` | `meta_ads.accounts.manage` | redirect |
| `meta_ads.settings.index` / `update` | GET / PUT `/meta-ads/settings` | `meta_ads.settings.manage` | `Pages/Settings/Index` |

Permissions (7, none scopable; sensitive: `campaigns.manage`, `accounts.manage`, `settings.manage`):
`meta_ads.insights.view`, `insights.export`, `campaigns.view`, `campaigns.manage`, `accounts.view`, `accounts.manage`,
`settings.manage`. Limit `meta_ads.ad_accounts` (gauge: tracked ad accounts). Navigation keys `metaAdsPerformance`,
`metaAdsCampaigns`, `metaAdsAccounts`, `metaAdsSettings` (section `apps`, orders 120 to 122 and 901). Widget
`meta_ads.overview` (`MetaAdsOverviewWidget`). Morph aliases: `meta_ads_connection`, `meta_ads_account`,
`meta_ads_object`, `meta_ads_insight`, `meta_ads_change`, `meta_ads_setting`. Trigger
`meta_ads.connection.needs_reauthorization`; no automation actions. Report providers `meta_ads.campaign_performance`
and `meta_ads.daily_spend` (permission `meta_ads.insights.view`, filters currency and ad account; per currency).
Audit events: `meta_ads.connection.connected|disconnected|needs_reauthorization`, `meta_ads.account.tracked|untracked|
ceilings_changed`, `meta_ads.change.applied|failed`, `meta_ads.performance.exported`, `meta_ads.settings.created|updated`.

Tables (all tenant-owned ULIDs, every unique index leads with `tenant_id`): `meta_ads_connections`, `meta_ads_accounts`,
`meta_ads_objects`, `meta_ads_insights`, `meta_ads_changes`, `meta_ads_settings`.

## 8. What Meta requires (the operator's work)

- `ads_read`: read ad accounts, campaigns and insights. `ads_management`: pause and edit budgets.
- In development mode the permissions work for people with a role on the Meta app and ad accounts those people own.
  For any other workspace owner the app needs **App Review** with a screencast per permission, a privacy policy URL, and
  **Advanced Access** to the Marketing API. For ad accounts owned by other businesses, **Business Verification** is
  required. Meta's rate-limit tier (development vs standard access) sets the quota the usage headers report.
- Page-level features (lead forms) stay in Social Media with their own permissions.
- Meta changes API versions about yearly: review `META_ADS_API_VERSION` on a schedule.

## 9. Owner decisions

1. Approve the per-workspace user token (encrypted, write-only) as the same pattern as Social's tokens (ADR 0027).
2. Choose the Meta app review path and whether `ads_management` is requested at all (`META_ADS_MANAGEMENT=false` keeps
   the app read-only).
3. Defaults: management off until a workspace turns it on; ceiling required; increase cap 100% per change;
   30 changes a day; history 30 days. Change in `config/meta_ads.php` or the settings page.
4. Verify Meta's currency offsets before enabling changes for non-ISO-aligned currencies (section 5).
5. Category: the app uses the existing `social_media` category; add an `advertising` category if you prefer.

## 10. Tests

`tests/Unit/MetaAds`, `tests/Feature/MetaAds` (`ConnectionTest`, `SyncTest`, `ChangeTest`, `PerformanceAndReportsTest`,
`AccessAndIsolationTest`, `ContractsAndBrokerTest`), `tests/js/metaAds.test.ts`; helpers in
`tests/Support/InteractsWithMetaAds.php` (`fakeMeta()` fakes the Graph API with first-match-wins stubs).
