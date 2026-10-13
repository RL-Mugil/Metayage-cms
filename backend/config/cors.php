<?php

// Only the public enquiry endpoint is called cross-origin (from the marketing site).
// Everything else is same-origin (the SPA) or a native client that ignores CORS.
return [
    'paths' => ['api/public/*', 'api/cpi-admin/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [
        'https://myipstrategy.com', 'https://www.myipstrategy.com',
        'https://canpatentit.com', 'https://www.canpatentit.com',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
