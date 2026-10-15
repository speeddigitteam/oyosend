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
    | has registered an app with that platform and set its credentials in the
    | Super Admin panel (Integrations; stored encrypted and write-only, ADR 0029)
    | or, as the fallback, in the environment. Without them it shows an honest
    | "not configured" state; nothing is simulated. Every platform also requires
    | its own app review / product approval before real accounts can be connected.
    |
    | A listed class that does not exist in this build is skipped, and the
    | platform keeps its "not built yet" placeholder (SocialPlatforms::UNAVAILABLE).
    | The four adapters added in ADR 0033 are named as strings until they ship.
    |
    */

    'adapters' => [
        FacebookPagesPlatform::class,
        LinkedInPlatform::class,
        XPlatform::class,
        'App\Modules\Social\Platforms\Adapters\InstagramPlatform',
        'App\Modules\Social\Platforms\Adapters\TikTokPlatform',
        'App\Modules\Social\Platforms\Adapters\YouTubePlatform',
        'App\Modules\Social\Platforms\Adapters\PinterestPlatform',
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
        // Instagram professional accounts linked to a Facebook Page, through the Instagram Graph API with
        // Facebook Login. It REUSES the Meta app of `facebook` above (client id/secret and API version); it
        // has no credential of its own. Publishing asks for `instagram_basic` and `instagram_content_publish`,
        // which Meta must approve in app review, so it is off until the operator switches it on in the Super
        // Admin panel (Integrations > Instagram; the environment is the fallback). ADR 0033.
        'instagram' => [
            'publishing' => (bool) env('SOCIAL_INSTAGRAM_PUBLISHING', false),
            'api_base' => 'https://graph.facebook.com',
            'scopes' => ['instagram_basic', 'instagram_content_publish', 'pages_show_list', 'pages_read_engagement', 'business_management'],
            // Meta's documented content-publishing limits as of this build; review on a schedule (docs/SOCIAL.md).
            'limits' => [
                'caption_max' => 2200,
                'hashtags_max' => 30,
                'carousel_max' => 10,
                'image_mimes' => ['image/jpeg'],
                'image_max_bytes' => 8 * 1024 * 1024,
                // Width / height between 4:5 and 1.91:1 for feed images.
                'image_min_ratio' => 0.8,
                'image_max_ratio' => 1.91,
                'video_mimes' => ['video/mp4', 'video/quicktime'],
                'reel_max_bytes' => 300 * 1024 * 1024,
                'reel_min_seconds' => 3,
                'reel_max_seconds' => 900,
                // API-published posts per account per rolling 24 hours.
                'posts_per_day' => 50,
            ],
        ],
        // TikTok Content Posting API. TikTok calls the public identifier the "client key": it is stored under
        // `client_id` here so the shared adapter plumbing reads it like every other platform. Direct Post needs
        // the `video.publish` scope and an audited app; until the audit, posts are private (SELF_ONLY).
        'tiktok' => [
            'client_id' => env('SOCIAL_TIKTOK_CLIENT_KEY'),
            'client_secret' => env('SOCIAL_TIKTOK_CLIENT_SECRET'),
            'auth_base' => 'https://www.tiktok.com/v2/auth/authorize/',
            'api_base' => 'https://open.tiktokapis.com',
            'api_version' => 'v2',
            'scopes' => ['user.info.basic', 'video.publish'],
            'limits' => [
                'caption_max' => 2200,
                'video_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
                'video_max_bytes' => 4 * 1024 * 1024 * 1024,
                'video_min_seconds' => 3,
                'video_max_seconds' => 600,
                // Picture size TikTok accepts, in pixels per side.
                'video_min_side' => 360,
                'video_max_side' => 4096,
                // FILE_UPLOAD chunks: 5 MB to 64 MB, the last one up to 128 MB.
                'chunk_bytes' => 10 * 1024 * 1024,
            ],
        ],
        // YouTube Data API v3 (Google OAuth 2.0 web client). Videos uploaded by a project that has not passed
        // Google's API audit are locked to private. Each upload costs 1600 of the default 10,000 daily quota units.
        'youtube' => [
            'client_id' => env('SOCIAL_YOUTUBE_CLIENT_ID'),
            'client_secret' => env('SOCIAL_YOUTUBE_CLIENT_SECRET'),
            'auth_base' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'api_base' => 'https://www.googleapis.com/youtube/v3',
            'upload_base' => 'https://www.googleapis.com/upload/youtube/v3',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
            'limits' => [
                'title_max' => 100,
                'description_max' => 5000,
                'video_mimes' => ['video/mp4', 'video/quicktime', 'video/webm'],
                'video_max_bytes' => 256 * 1024 * 1024 * 1024,
                // Channels that are not verified may upload at most 15 minutes.
                'video_max_seconds' => 15 * 60,
                // Resumable upload chunks must be multiples of 256 KiB.
                'chunk_bytes' => 8 * 1024 * 1024,
                'privacy' => 'public',
            ],
        ],
        // Pinterest API v5. A Pin needs an image or a video and a board.
        'pinterest' => [
            'client_id' => env('SOCIAL_PINTEREST_CLIENT_ID'),
            'client_secret' => env('SOCIAL_PINTEREST_CLIENT_SECRET'),
            'auth_base' => 'https://www.pinterest.com/oauth/',
            'api_base' => 'https://api.pinterest.com',
            'api_version' => 'v5',
            'scopes' => ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read'],
            'limits' => [
                'title_max' => 100,
                'description_max' => 500,
                'image_mimes' => ['image/jpeg', 'image/png'],
                'image_max_bytes' => 20 * 1024 * 1024,
                'video_mimes' => ['video/mp4', 'video/quicktime'],
                'video_max_bytes' => 2 * 1024 * 1024 * 1024,
                'video_min_seconds' => 4,
                'video_max_seconds' => 15 * 60,
                'carousel_max' => 5,
            ],
        ],
    ],

    /*
    | Central OAuth redirect broker. When enabled, every platform app registers ONE redirect URI,
    | https://APP_DOMAIN/social/oauth/callback/{platform}, which forwards the browser to the workspace
    | that started the flow. Disable to fall back to per-workspace URIs.
    */
    'redirect_broker' => (bool) env('SOCIAL_REDIRECT_BROKER', true),

    /*
    | Hosts the connected accounts' profile pictures are served from (the URLs the platforms return at connect
    | time). The browser loads them directly, without a referrer, so they are added to the CSP `img-src`
    | (`platform.security.img_hosts`). Nothing is proxied or copied. A picture from another host shows initials.
    */
    'avatar_hosts' => [
        'https://*.fbcdn.net', 'https://*.fbsbx.com',                 // Facebook Pages
        'https://*.cdninstagram.com',                                   // Instagram
        'https://media.licdn.com',                                      // LinkedIn
        'https://pbs.twimg.com',                                        // X
        'https://*.tiktokcdn.com', 'https://*.tiktokcdn-us.com',        // TikTok
        'https://yt3.ggpht.com', 'https://yt3.googleusercontent.com',   // YouTube
        'https://i.pinimg.com',                                         // Pinterest
    ],

    'media' => [
        'image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video_mimes' => ['video/mp4', 'video/quicktime'],
        'image_max_kb' => 8192,
        'video_max_kb' => 51200,
        'max_per_post' => 10,
        // Lifetime of a public, expiring (signed) URL to one library file, made only for a platform that
        // fetches media itself (Instagram). Kept between 5 and 1440 minutes. ADR 0033.
        'public_url_minutes' => 120,
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

    /*
    |--------------------------------------------------------------------------
    | Facebook Messenger and Instagram direct messages (docs/SOCIAL.md, ADR 0031)
    |--------------------------------------------------------------------------
    |
    | Off until the operator's Meta app has `pages_messaging` (and, for Instagram, `instagram_manage_messages`)
    | approved in app review and its Webhooks product subscribes the Page object (and the Instagram object) to
    | `messages` / `message_echoes` at the one callback URL the audience comments use
    | (`https://APP_DOMAIN/social/webhooks/facebook`). Asking for an unapproved permission breaks the connect dialog,
    | so these scopes are requested only while the switch is on. `instagram` needs `enabled` as well.
    |
    */
    'messaging' => [
        'enabled' => (bool) env('SOCIAL_FACEBOOK_MESSAGING', false),
        'instagram' => (bool) env('SOCIAL_INSTAGRAM_MESSAGING', false),
        // Permissions a Page token needs for Messenger, and the extra ones for Instagram.
        'required_scopes' => ['pages_messaging', 'pages_manage_metadata'],
        'instagram_scopes' => ['instagram_basic', 'instagram_manage_messages'],
        // Page webhook fields message capture adds to (and removes from) the app's subscription.
        'webhook_fields' => ['messages', 'message_echoes'],
        // The standard messaging window: a Page may answer only this long after the person's last message.
        'reply_window_hours' => 24,
        // Longest reply sent; Messenger allows 2000 characters, Instagram 1000.
        'text_max' => ['messenger' => 2000, 'instagram' => 1000],
        // Messaging events read from one delivery (poison cap).
        'max_events' => 200,
    ],
];
