<?php

use App\Modules\Social\Platforms\Adapters\FacebookPagesPlatform;
use App\Modules\Social\Platforms\Adapters\LinkedInPlatform;
use App\Modules\Social\Platforms\Adapters\XPlatform;

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
];
