<?php

/*
|--------------------------------------------------------------------------
| Communication platform (module `communication`)
|--------------------------------------------------------------------------
|
| Platform-level settings for notifications and outbound messaging. A
| workspace never stores a provider secret: it names a credential below with
| a `credential_ref`, and the secret itself stays in the environment.
|
| See docs/COMMUNICATION.md.
|
*/

return [

    // Notification categories offered in the preference matrix. Any other
    // category a module sends under is still delivered with the defaults below.
    'categories' => ['system', 'assignments', 'reminders', 'approvals', 'billing', 'mentions'],

    // What a member gets for a category they have not set a preference for.
    'category_defaults' => ['in_app' => true, 'email' => false],

    // Messages a workspace may send per minute, per channel. A workspace can
    // lower its own value on the channel connection, never raise it.
    'rate_limits' => ['email' => 60, 'sms' => 30, 'whatsapp' => 30],

    // Delivery attempts before a message is failed, and the wait before each retry (minutes).
    'max_attempts' => 5,
    'backoff_minutes' => [1, 5, 15, 60],

    // A message stuck in `sending` longer than this goes back to the queue.
    'sending_timeout_minutes' => 10,

    // Days a provider event id is remembered for duplicate detection, and a
    // delivered unsubscribe token stays valid.
    'webhook_retention_days' => 90,
    'unsubscribe_token_days' => 365,

    // Largest webhook body accepted, in bytes.
    'webhook_max_bytes' => 262144,

    // The WhatsApp Cloud API adapter (Graph API). The base URL is operator configuration, never tenant input.
    'whatsapp' => [
        'graph_base_url' => 'https://graph.facebook.com',
        'api_version' => 'v21.0',
    ],

    /*
    |----------------------------------------------------------------------
    | Credentials a workspace may reference
    |----------------------------------------------------------------------
    |
    | `reference name => ['secret' => env(...), 'tenants' => null|list<slug>]`.
    | `tenants` null means every workspace may use it (an operator-provided
    | account); a list restricts it to those workspace slugs. A reference that
    | is missing, empty or not allowed for the workspace reads as "not
    | configured". Workspaces see the reference names, never the values.
    |
    | Example:
    |   'sms_gateway_main' => ['secret' => env('COMMUNICATION_SMS_MAIN_SECRET'), 'tenants' => null],
    |
    | WhatsApp Cloud API: one entry for the system-user access token (`credential_ref`) and one for the Meta app
    | secret that signs webhooks, which also carries the subscription `verify_token` (`webhook_secret_ref`):
    |   'whatsapp_token_main' => ['secret' => env('COMMUNICATION_WHATSAPP_TOKEN'), 'tenants' => null],
    |   'whatsapp_app_main' => ['secret' => env('COMMUNICATION_WHATSAPP_APP_SECRET'), 'verify_token' => env('COMMUNICATION_WHATSAPP_VERIFY_TOKEN'), 'tenants' => null],
    |
    */
    'credentials' => [],

];
