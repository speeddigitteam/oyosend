<?php

/*
|--------------------------------------------------------------------------
| Telephony (module `telephony`)
|--------------------------------------------------------------------------
|
| Each workspace enters its own PBX in its Telephony settings (ADR 0036); nothing here names a PBX. These are
| the platform's guard rails around calling it. See docs/TELEPHONY.md.
|
*/

return [

    // A PBX API URL must resolve to public addresses only (server-side request forgery guard). Turn this on
    // only for a single-company install whose PBX sits on the same private network as the app.
    'allow_private_pbx' => (bool) env('TELEPHONY_ALLOW_PRIVATE_PBX', false),

    // Seconds to wait for the PBX to accept an origination.
    'pbx_timeout' => (int) env('TELEPHONY_PBX_TIMEOUT', 10),

    // A PBX event must carry a timestamp within this many seconds of now (replay window).
    'webhook_replay_seconds' => 300,

    // Largest PBX event body accepted, in bytes.
    'webhook_max_bytes' => 16384,

    // Bridge calls a member may start per minute.
    'bridge_per_minute' => 10,
];
