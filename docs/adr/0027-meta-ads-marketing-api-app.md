# ADR 0027 — Meta Ads: a separate optional app on the Marketing API, its own token, a read-only Social contract

- **Status:** Accepted (implemented, not independently reviewed; never run against Meta's live API)
- **Date:** 2026-10-13
- **Builds on:** ADR 0013 (what may be a database credential), ADR 0014 (core contracts), ADR 0015 (reports)

## Context

Workspaces already connect Facebook Pages in Social Media. Advertising is a different Meta product: it needs the
Marketing API, different permissions (`ads_read`, `ads_management`), a different holder of the access (a person with
a role on an ad account, not a Page admin) and different safety rules (spend). Putting it inside Social would give
Social's publishing tokens reach into ad accounts and the other way round.

## Decision

1. **A new optional app `meta_ads`** (category `social_media`, free offer, depends on `core` and `social`). It reads
   ad accounts, campaigns, ad sets, ads and daily insights, shows a performance dashboard and reports, and makes
   exactly three kinds of change to Meta: pause, resume, and edit an existing daily or lifetime budget. It never
   creates, archives or deletes anything and has no boost-post.
2. **Its own credential, stated explicitly.** The app does **not** use a Social token. Meta Ads has one
   installation-wide Meta app, configured in the environment only (`META_ADS_APP_ID`, `META_ADS_APP_SECRET`;
   `config/meta_ads.php`); nothing about it is stored in the database, which keeps ADR 0013's boundary: that ADR
   approved database credentials for operator messaging provider accounts and for nothing new.
   Each workspace owner then authorises that app with Facebook Login. The resulting **per-workspace user access
   token** is stored encrypted in `meta_ads_connections.access_token` with Laravel's `encrypted` cast, `$hidden`,
   excluded from every page, array, JSON, audit entry, event, log line and exception message: **the same pattern as
   `social_accounts.access_token`**, which is also a per-workspace token obtained by OAuth and also not covered by
   ADR 0013's exception. It is a delegated user grant, not a platform credential. Requests carry it only in the
   `Authorization` header (with an `appsecret_proof`); Meta's `paging.next` URL, which embeds a token, is never
   followed, only `paging.cursors.after` is used. A long-lived token (about 60 days) is requested, extended once a
   day inside its last ten days, and dropped when Meta rejects it (status `needs_reauthorization`, one domain event).
3. **A documented, read-only contract with Social.** `App\Core\Social\Contracts\ConnectedPages` lists the
   workspace's Facebook Page accounts (`ConnectedPage`: opaque id, name, Facebook page id, `connected` or
   `needs_reauthorization`) and says whether Social is available. Core binds `NullConnectedPages` (unavailable,
   empty); `SocialServiceProvider` rebinds `SocialConnectedPages`. Meta Ads imports no Social class and reads no
   Social table or token (a test scans the source). The Pages feed the setup checklist and the connection page only.
4. **The OAuth redirect broker is re-implemented inside the module**, same signed-envelope pattern as Social's
   (`{slug}.{expiry}.{nonce}.{mac}.{inner}`, HMAC with a key derived from `APP_KEY` and a different context string),
   with its own central route `GET /meta-ads/oauth/callback` in the stateless `central.callback` group. The workspace
   callback still checks and consumes a session-bound single-use state within ten minutes and binds it to the member.
   Facebook's code flow offers no PKCE for confidential web apps, so the single-use session state stands in for it.
5. **Money.** Spend is a decimal string in the account currency; it is parsed on the digit string into integer
   minor units (no float). Budgets are integer strings already in minor units. Every row carries the account
   `currency`; an insight row Meta reports in another currency is skipped and flagged, never stored or added.
   The dashboard, export and reports are per currency; the default is the workspace currency, never "the biggest
   amount". Absent figures are `null` ("not reported"), never zero. Rates (CTR, CPC, CPM, cost per lead) are
   derived in integer arithmetic from summed totals.
6. **Safe changes.** A change needs `meta_ads.campaigns.manage` (sensitive) and the workspace switch (off by
   default) and `ads_management` in the grant; the live object is read first and must equal what the confirmation
   dialog showed; a budget can only be edited where the object already has that kind of budget, only to at most the
   per-ad-account **ceiling** the workspace set (no ceiling, no budget edits), and one increase is bounded by
   `meta_ads.changes.max_increase_percent`; at most `max_changes_per_day` per workspace. Each confirmation carries a
   `request_id`; a receipt row (`meta_ads_changes`) is written before Meta is called, finished after, and audited;
   replaying the id never calls Meta twice.
7. **Quota.** Usage is read from `x-business-use-case-usage`, `x-ad-account-usage` and `x-app-usage`; at
   `rate_limit.pause_at_percent` or on a throttling error work stops and the account waits (`throttled_until`) for
   Meta's reported time. Per workspace and run: at most `max_accounts_per_run` accounts, `max_pages_per_object` pages
   per listing, `max_insight_pages_per_run` insight pages and a time budget. Listings cut short never mark anything
   missing.

## What this does not decide

- Whether Meta approves the app for `ads_read` / `ads_management` (app review; for other businesses' ad accounts also
  Business Verification and Advanced Access). That is the platform operator's, see `docs/META_ADS.md`.
- Meta's currency "offset" table: budgets are assumed to be in the ISO 4217 minor unit of the account currency
  (`Money::decimals`). Where Meta's offset differs for a currency, that currency's budgets would be wrong by a
  factor; verify the table before enabling management for such a currency.
- Ad-set and ad level insights are not synced (account and campaign level only).

## Consequences

- One more token class to protect; it follows Social's rules and tests (`ConnectionTest`).
- Social and Meta Ads can be installed independently, but Meta Ads requires Social in the entitlement graph; removing
  Social removes Meta Ads for that workspace.
- Owner decisions are listed in `docs/META_ADS.md` section 9.
