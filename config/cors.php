 <?php

$configuredOrigins = array_filter(array_map(
    static fn (string $origin) => trim($origin),
    explode(',', (string) env('FRONTEND_URLS', ''))
));

$defaultOrigins = array_filter([
    env('FRONTEND_URL'),
    env('FRONTEND_URL_ALT'),
]);

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_unique([
        ...$defaultOrigins,
        ...$configuredOrigins,
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
