<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY')),
    'issuer' => env('JWT_ISSUER', env('APP_NAME', 'SmartQdz')),
    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 120),
];
