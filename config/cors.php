<?php

$normalizeOrigin = static function (?string $origin): ?string {
    if ($origin === null) {
        return null;
    }

    $trimmed = trim($origin);

    if ($trimmed === '') {
        return null;
    }

    return rtrim($trimmed, '/');
};

$configuredOrigins = array_filter(array_map(
    static fn (string $origin) => $normalizeOrigin($origin),
    explode(',', (string) env('FRONTEND_URLS', ''))
));

$defaultOrigins = array_filter([
    $normalizeOrigin(env('FRONTEND_URL')),
    $normalizeOrigin(env('FRONTEND_URL_ALT')),
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
