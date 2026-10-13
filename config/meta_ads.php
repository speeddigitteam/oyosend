<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Ads (Marketing API)
    |--------------------------------------------------------------------------
    |
    | The platform app (one Meta app for the whole installation) is configured here, in
    | the environment, never in the database. Each workspace owner authorises it with
    | Facebook Login; the resulting per-workspace user access token is stored encrypted
    | (ADR 0027), exactly like a connected social account's token.
    |
    | Reading ad accounts, campaigns and insights needs the `ads_read` permission.
    | Pausing and resuming campaigns and editing budgets needs `ads_management`. Meta
    | grants either to people who are roles on the app without review ("standard access"
    | in development mode), and to everybody else only after app review (and, for
    | other businesses' ad accounts, Business Verification). Nothing here is simulated.
    |
    */

    'app_id' => env('META_ADS_APP_ID'),
    'app_secret' => env('META_ADS_APP_SECRET'),
    'api_version' => env('META_ADS_API_VERSION', 'v23.0'),

    // Request `ads_management` in the connect dialog. Set false to run the app read-only: the
    // dialog then asks only for `ads_read`, and pause / resume / budget changes are not offered.
    'request_management_scope' => (bool) env('META_ADS_MANAGEMENT', true),

    // One redirect URI on the central host (https://APP_DOMAIN/meta-ads/oauth/callback) for every
    // workspace, like the Social app's broker. Disable to register each workspace's own URI.
    'redirect_broker' => (bool) env('META_ADS_REDIRECT_BROKER', true),

    'sync' => [
        // Bounds per ad account and run, so one large account cannot starve the queue or Meta's quota.
        'page_size' => 100,
        'max_pages_per_object' => 10,
        'max_insight_pages_per_run' => 50,
        // Days of insight history fetched; the workspace setting is clamped to these bounds.
        'min_lookback_days' => 7,
        'max_lookback_days' => 90,
        'default_lookback_days' => 30,
        // Recent days are re-read on every sync because Meta restates them (attribution window).
        'restate_days' => 3,
        // At most this many ad accounts are synced per workspace per run.
        'max_accounts_per_run' => 5,
        // A manual "sync now" is allowed this often per ad account (minutes).
        'manual_interval_minutes' => 10,
    ],

    'rate_limit' => [
        // Stop work for an account when Meta's usage headers report this much of its quota.
        'pause_at_percent' => 75,
        // Fallbacks (seconds) when Meta gives no `estimated_time_to_regain_access`.
        'default_backoff_seconds' => 900,
        'max_backoff_seconds' => 3600,
    ],

    'changes' => [
        // A workspace must switch management on and set a ceiling per ad account first.
        // A single increase can be at most this much above the current budget (percent).
        'max_increase_percent' => 100,
        // Changes accepted per workspace per rolling day, across all ad accounts.
        'max_changes_per_day' => 30,
    ],

    // Marketing API objects are never created or deleted by this app.
];
