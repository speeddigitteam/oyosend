<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Marketing
    |--------------------------------------------------------------------------
    |
    | Bounds of the SMS Marketing app. Nothing here is a provider credential:
    | the app never talks to an SMS provider. Messages go through the
    | communication platform's channel gateway, which owns providers and
    | credentials (config/communication.php).
    |
    */

    // Longest message the app accepts, in segments (GSM-7: 153 characters each once concatenated).
    'max_segments' => 10,

    // Longest template or campaign text, in characters, before the opt-out line is added.
    'max_body_length' => 1000,

    // Most contacts one campaign reaches (a bigger audience is refused at launch).
    'max_audience' => 50000,

    // Recipients handed to the channel gateway by one run of the every-minute job, at most.
    'batch_ceiling' => 500,

    // Workspace defaults; a workspace changes them in its SMS Marketing settings.
    'defaults' => [
        'daily_cap' => 1000,
        'throttle_per_minute' => 60,
    ],

    // Hard bounds for those settings.
    'bounds' => [
        'daily_cap' => [1, 100000],
        'throttle_per_minute' => [1, 600],
    ],

    // How often one recipient is attempted in total (the first send counts), and the wait before each retry.
    'max_attempts' => 3,
    'retry_backoff_minutes' => [15, 60],

    // A recipient stays "queued" at most this long before the hourly job asks the platform for its state.
    'reconcile_after_minutes' => 15,

    'import' => [
        'max_rows' => 5000,
        'max_bytes' => 524288,
    ],

    // Failure codes a person (or the retry rule) must not retry: the number or provider refuses the message.
    'permanent_failure_codes' => ['invalid_recipient', 'provider_not_configured', 'unknown_contact', 'no_address'],
];
