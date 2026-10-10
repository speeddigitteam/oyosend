<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Behind a load balancer the visitor's address, host and scheme arrive in
    | `X-Forwarded-*` headers. They are believed only from the proxies named
    | here: addresses or CIDR ranges, comma separated, or `*` to trust
    | whichever proxy connects (only when the application cannot be reached
    | except through it).
    |
    | With nothing set, forwarded headers are ignored - right for a server
    | reached directly, wrong behind a proxy: the audit trail and throttling
    | would see the proxy's address, and generated URLs the wrong scheme.
    |
    | Read by the framework's own Illuminate\Http\Middleware\TrustProxies.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
