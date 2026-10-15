<?php

/*
|--------------------------------------------------------------------------
| Broadcasting (realtime, ADR 0035)
|--------------------------------------------------------------------------
|
| Realtime is infrastructure and lives in the environment, like the queue and the database connection. It is on only
| with BROADCAST_CONNECTION=reverb and every REVERB_* value below set (App\Core\Realtime\Realtime). Anything else is
| "off": nothing is broadcast, /broadcasting/auth answers 404 and the pages keep polling. Tests use `null`.
|
| The application publishes to Reverb over HTTP (`options`: often an internal address such as 127.0.0.1:8080), while
| browsers connect to the websocket address in `client` (often the public TLS address of a proxy in front of Reverb).
| Only the key and the `client` address reach the browser; the secret never does.
|
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // Where this application sends events (the Reverb HTTP API).
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            // Where browsers open the websocket. Defaults to the address above.
            'client' => [
                'host' => env('REVERB_CLIENT_HOST', env('REVERB_HOST')),
                'port' => env('REVERB_CLIENT_PORT', env('REVERB_PORT', 443)),
                'scheme' => env('REVERB_CLIENT_SCHEME', env('REVERB_SCHEME', 'https')),
            ],
            // A slow or stopped Reverb must not hold a queue worker for long (Guzzle request options).
            'client_options' => [
                'connect_timeout' => 2,
                'timeout' => 5,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
