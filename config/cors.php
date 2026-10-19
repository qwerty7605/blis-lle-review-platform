<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Comma-separated in CORS_ALLOWED_ORIGINS; the site's own address
    // (FRONTEND_URL) when unset. The SPA calls the API from its own origin in
    // production and through Vite's proxy in development, so this matters only
    // to a front end served from somewhere else.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
