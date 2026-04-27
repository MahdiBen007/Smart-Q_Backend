<?php

return [
    'secret' => ($secret = trim((string) env('JWT_SECRET', ''))) !== ''
        ? $secret
        : (string) env('APP_KEY'),
    'issuer' => ($issuer = trim((string) env('JWT_ISSUER', ''))) !== ''
        ? $issuer
        : (string) env('APP_NAME', 'SmartQdz'),
    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 120),
    'remember_ttl_minutes' => (int) env('JWT_REMEMBER_TTL_MINUTES', 43200),
    'mobile_refresh_ttl_minutes' => (int) env('JWT_MOBILE_REFRESH_TTL_MINUTES', 525600),
    'cookie_name' => env('JWT_COOKIE_NAME', 'smartqdz_dashboard_session'),
    'cookie_path' => env('JWT_COOKIE_PATH', '/'),
    'cookie_domain' => env('JWT_COOKIE_DOMAIN', env('SESSION_DOMAIN')),
    'cookie_secure' => env('JWT_COOKIE_SECURE', env('APP_ENV') === 'production'),
    'cookie_same_site' => env('JWT_COOKIE_SAME_SITE', 'lax'),
];
