<?php

/*
|--------------------------------------------------------------------------
| Named routes exposed to the browser
|--------------------------------------------------------------------------
|
| The root template calls @routes with the current area, so a workspace page
| never receives the Super Admin panel's route table and vice versa. Route
| names are not secrets, but there is no reason to publish another area's map.
|
| The tenant group is an exclusion list on purpose: every module's routes
| ({module}.*) are workspace routes without having to be listed here.
|
*/

return [
    'groups' => [
        'central' => ['central.*'],
        'platform' => ['platform.*'],
        'tenant' => ['!central.*', '!platform.*', '!horizon.*', '!storage.*', '!inertia.*', '!scout.*'],
    ],
];
