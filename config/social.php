<?php

use App\Modules\Social\Platforms\Adapters\FacebookPagesPlatform;
use App\Modules\Social\Platforms\Adapters\LinkedInPlatform;
use App\Modules\Social\Platforms\Adapters\XPlatform;
use App\Modules\Social\Platforms\Facebook\FacebookPageInsights;

return [

    /*
    |--------------------------------------------------------------------------
    | Platform adapters
    |--------------------------------------------------------------------------
    |
    | Each adapter implements App\Modules\Social\Platforms\SocialPlatform against
    | the platform's official API. An adapter is usable only when the operator
    | has registered an app with that platform and set its credentials here
    | (never in the database). Without them it shows an honest "not configured"
    | state; nothing is simulated. Every platform also requires its own app
    | review / product approval before real accounts can be connected.
    |
    */

    'adapters' => [
        FacebookPagesPlatform::class,
        LinkedInPlatform::class,
        XPlatform::class,
    ],

    'platforms' => [
        'facebook' => [
            'client_id' => env('SOCIAL_FACEBOOK_CLIENT_ID'),
            'client_secret' => env('SOCIAL_FACEBOOK_CLIENT_SECRET'),
            'api_version' => env('SOCIAL_FACEBOOK_API_VERSION', 'v23.0'),
            // Video and Reels publishing asks for the `publish_video` permission, which Meta must approve
            // in app review. Set false until it is approved: the connect dialog then does not request it and
            // the composer offers neither Video nor Reel for Facebook.
            'video_publishing' => (bool) env('SOCIAL_FACEBOOK_VIDEO', true),
            // Limits are Meta's documented media requirements as of this build. Meta changes them: review on a
            // schedule (docs/SOCIAL.md). A file whose length or size could not be read is allowed with a warning.
            'limits' => [
                'photos_max' => 10,
                'video' => [
                    'max_bytes' => 10 * 1024 * 1024 * 1024,
                    'max_seconds' => 240 * 60,
                    'chunk_bytes' => 2 * 1024 * 1024,
                ],
                'reel' => [
                    'min_seconds' => 3,
                    'max_seconds' => 90,
                    'min_width' => 540,
                    'min_height' => 960,
                    // Vertical 9:16; a different shape is published but may be cropped, so it is a warning.
                    'aspect_width' => 9,
                    'aspect_height' => 16,
                    'aspect_tolerance' => 0.03,
                    'max_bytes' => 1024 * 1024 * 1024,
                    'chunk_bytes' => 2 * 1024 * 1024,
                ],
            ],
        ],
        'linkedin' => [
            'client_id' => env('SOCIAL_LINKEDIN_CLIENT_ID'),
            'client_secret' => env('SOCIAL_LINKEDIN_CLIENT_SECRET'),
            // LinkedIn-Version header, YYYYMM. Versions are retired after about a year.
            'api_version' => env('SOCIAL_LINKEDIN_API_VERSION', '202510'),
        ],
        'x' => [
            'client_id' => env('SOCIAL_X_CLIENT_ID'),
            'client_secret' => env('SOCIAL_X_CLIENT_SECRET'),
        ],
    ],

    /*
    | Central OAuth redirect broker. When enabled, every platform app registers ONE redirect URI,
    | https://APP_DOMAIN/social/oauth/callback/{platform}, which forwards the browser to the workspace
    | that started the flow. Disable to fall back to per-workspace URIs.
    */
    'redirect_broker' => (bool) env('SOCIAL_REDIRECT_BROKER', true),

    'media' => [
        'image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video_mimes' => ['video/mp4', 'video/quicktime'],
        'image_max_kb' => 8192,
        'video_max_kb' => 51200,
        'max_per_post' => 10,
    ],

    'publishing' => [
        // Targets processed by one run of the every-minute job.
        'batch' => 50,
        'max_attempts' => 4,
        // Seconds to wait after the 1st, 2nd, 3rd ... retryable failure.
        'backoff_seconds' => [60, 300, 1800],
        // A claimed target that never reported back is flagged for a manual check, never auto-retried.
        'stuck_after_minutes' => 15,
        // Publishes per workspace per minute (the per-account hourly cap is a workspace setting).
        'tenant_per_minute' => 30,
        'token_refresh_margin_minutes' => 10,
        // Stepwise publishing (video, Reels, multi-photo): one worker run must end inside the job timeout,
        // so no new request starts after this many seconds; the next run continues from the saved offset.
        'run_budget_seconds' => 35,
        // Wait between checks of a platform that is still processing a video.
        'processing_poll_seconds' => 60,
        // A video not ready this long after the upload began is flagged for a manual check, never retried.
        'processing_timeout_minutes' => 180,
        // Transient failures tolerated while uploading or polling before the target fails.
        'step_failures_max' => 6,
    ],

    'import' => [
        'max_rows' => 500,
        'max_bytes' => 1048576,
    ],

    'metrics' => [
        'refresh_hours' => 6,
        'window_days' => 30,
        'batch' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Page insights (Page-level figures, one row per account per day)
    |--------------------------------------------------------------------------
    |
    | Read only through documented Graph API Page Insights calls. Each figure lists the metric
    | names to try in order: Meta retires and renames metrics, so the first name the API accepts
    | and answers is used, and a figure no candidate answers is "not reported" (null, never zero).
    | Review the lists when SOCIAL_FACEBOOK_API_VERSION changes.
    |
    */
    'insights' => [
        // Accounts read per hourly run (the job has a short timeout) and the minimum hours between
        // two reads of one account.
        'accounts_per_run' => 3,
        'refresh_hours' => 6,
        // First read of an account reaches back this far (Meta allows at most 93 days per request);
        // later reads re-collect the last few days because Meta finalises figures late.
        'backfill_days' => 28,
        'recollect_days' => 3,
        // Stop reading an account's metrics once Meta's usage headers pass this percentage.
        'usage_stop_percent' => 80,
        // Meta days are Pacific Time days, whatever the workspace timezone.
        'day_timezone' => 'America/Los_Angeles',
        // The best-posting-times view needs at least this many posts with reported figures.
        'min_posts_for_best_time' => 10,
        // Re-check the Meta tasks and granted permissions of an account at most this often.
        'access_check_hours' => 24,

        'providers' => [
            'facebook' => FacebookPageInsights::class,
        ],

        'facebook' => [
            // figure => candidate metric names, tried in order (period=day).
            'metrics' => [
                'followers' => ['page_follows'],
                'new_followers' => ['page_daily_follows_unique', 'page_daily_follows'],
                'unfollows' => ['page_daily_unfollows_unique'],
                'impressions' => ['page_media_view', 'page_impressions'],
                'reach' => ['page_total_media_view_unique', 'page_impressions_unique'],
                'engaged_users' => ['page_engaged_users'],
                'post_engagements' => ['page_post_engagements'],
                'page_views' => ['page_views_total'],
                'reactions' => ['page_actions_post_reactions_total'],
                'video_views' => ['page_video_views'],
            ],
            // Page node fields read once per run: current follower and like counts.
            'page_fields' => ['followers' => 'followers_count', 'fans' => 'fan_count'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Facebook Lead Ads (docs/SOCIAL.md, "Lead ads")
    |--------------------------------------------------------------------------
    |
    | Off until the operator's Meta app has `leads_retrieval`, `pages_manage_ads` and `pages_manage_metadata`
    | approved and its webhook product subscribed: asking for an unapproved permission breaks the connect
    | dialog. The webhook callback is a workspace URL (`https://{workspace}.APP_DOMAIN/social/webhooks/facebook/leads`);
    | its signature is checked with the Meta app secret, the handshake with `webhook_verify_token`.
    |
    */
    'lead_ads' => [
        'enabled' => (bool) env('SOCIAL_FACEBOOK_LEAD_ADS', false),
        'webhook_verify_token' => env('SOCIAL_FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
        // Permissions the integration needs on a Page token.
        'required_scopes' => ['leads_retrieval', 'pages_manage_ads', 'pages_show_list', 'pages_manage_metadata'],
        'webhook_max_bytes' => 262144,
        // A delivery naming more leads than this is cut off (poison cap).
        'max_changes_per_delivery' => 100,
        // New deliveries are answered "try again" while a workspace holds this many unprocessed leads.
        'max_open_leads' => 2000,
        // Automatic retries of a fetch that failed for a transient reason, and how long a lead may keep retrying.
        'max_attempts' => 6,
        'backoff_seconds' => [60, 300, 1800, 7200, 21600],
        'max_age_days' => 3,
        // Recent-lead backfill for missed webhooks: default and largest window, leads fetched per run.
        'backfill_days' => 3,
        'backfill_max_days' => 14,
        'backfill_max_leads' => 100,
        // Forms are re-listed by hand or when older than this many hours.
        'forms_refresh_hours' => 12,
        // Fetched answers are kept only this long, and processing history this long.
        'payload_retention_days' => 14,
        'retention_days' => 180,
        // Leads handed to the CRM per sweep run.
        'sweep_batch' => 50,
    ],
];
