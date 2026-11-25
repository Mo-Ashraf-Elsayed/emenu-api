<?php

$localOrigins = env('APP_ENV') === 'local' ? 'http://localhost:4200,http://127.0.0.1:4200' : '';

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE'],
    'allowed_origins' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('CORS_ALLOWED_ORIGINS', $localOrigins))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],
    'exposed_headers' => ['Retry-After', 'X-Request-ID'],
    'max_age' => 0,
    'supports_credentials' => false,
];
