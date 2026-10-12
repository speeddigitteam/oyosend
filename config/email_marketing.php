<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Campaigns are worked in bounded chunks on the `campaigns` queue by the
    | every-minute runner. A workspace chooses its own rate in settings, never
    | above `send_per_minute_ceiling`. The platform outbox applies its own
    | per-channel rate limit and the plan's message limit on top of this.
    |
    */

    'send_per_minute_default' => 60,
    'send_per_minute_ceiling' => 600,

    // Subscriptions looked at (and checked for consent) per runner pass of one campaign.
    'audience_chunk' => 500,

    // Sequence steps handed over per runner pass.
    'sequence_chunk' => 200,

    // A runner pass that is still going after this many seconds is not started a second time.
    'run_lock_seconds' => 300,

    // A delivery claimed by a worker that never reported back is released after this many minutes.
    'stuck_after_minutes' => 15,

    // Most members a test send may address, and how often.
    'test_send_max_recipients' => 5,

    'import' => [
        'max_rows' => 2000,
        'max_bytes' => 1048576,
        'max_properties' => 10,
    ],

    'export' => [
        'max_rows' => 50000,
    ],

    'segments_per_list' => 25,

];
