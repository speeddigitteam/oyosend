<?php

use App\Modules\Social\Platforms\Facebook\FacebookComments;

return [

    /*
    |--------------------------------------------------------------------------
    | Audience comments (inbox, ingestion and keyword rules)
    |--------------------------------------------------------------------------
    |
    | Only documented Graph API calls are used. `enabled` turns the whole feature off for the installation:
    | the Facebook adapter then reports `comments: false`, the connect dialog does not ask for the extra
    | permissions and nothing is ingested. Docs: docs/SOCIAL.md, ADR 0025.
    |
    */

    'enabled' => (bool) env('SOCIAL_COMMENTS', true),

    'providers' => [
        'facebook' => FacebookComments::class,
    ],

    'facebook' => [
        // The shared secret Meta echoes back in the one-time `hub.verify_token` handshake of the webhook.
        'verify_token' => env('SOCIAL_FACEBOOK_WEBHOOK_VERIFY_TOKEN'),
        // Private replies (a Messenger message in answer to a public comment) need `pages_messaging`, which
        // Meta reviews separately. Off until approved: the rule editor then does not offer the action.
        'private_replies' => (bool) env('SOCIAL_FACEBOOK_PRIVATE_REPLIES', false),
        // Pages of comments read per post per poll (25 comments each).
        'max_pages' => 2,
    ],

    'webhook' => [
        // A delivery bigger than this is refused before it is read.
        'max_bytes' => 262144,
        // After this many failures of one event it is given up on (logged without text), so Meta stops retrying.
        'max_failures' => 5,
        'max_changes' => 200,
    ],

    'poll' => [
        // Fallback for missed webhooks: bounded and rate-limit aware.
        'interval_minutes' => 10,
        'accounts_per_run' => 5,
        'posts_per_account' => 10,
        'lookback_days' => 7,
        'comments_per_post' => 50,
        'max_backoff_minutes' => 360,
        // A Page whose webhook is subscribed and has delivered recently is polled this many times less often.
        'subscribed_multiplier' => 3,
    ],

    'automation' => [
        // A comment older than this is never answered automatically.
        'max_comment_age_hours' => 48,
        // A comment held back (quiet hours, a cap) is looked at again this many times, this many minutes apart.
        'max_deferrals' => 6,
        'defer_minutes' => 10,
        'page_hourly_cap' => 60,
    ],

    'limits' => [
        'reply_max' => 2000,
        'template_max' => 500,
        'templates_max' => 5,
        'note_max' => 2000,
        'tag_max' => 40,
        'tags_max' => 10,
        'bulk_max' => 50,
        'rules_max' => 50,
        'preview_text_max' => 2000,
    ],
];
