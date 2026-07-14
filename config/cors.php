<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Ports wpi-hub middlewares/cors.js, which allowed the configured client
| origins (config/clients.json) with credentials. Origins are read from
| CORS_ALLOWED_ORIGINS (comma-separated) so they aren't hard-coded.
|
*/

$origins = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS',
    'https://membersbeta.windingpath.club,http://192.168.1.26:4201, https://https://membersdev.joeterranova.net'
))));

return [
    'paths' => ['v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
