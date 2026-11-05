<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Daftar origin frontend yang diizinkan, dipisah koma (diatur lewat .env)
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Biar frontend bisa baca nama file saat download PDF/Excel nanti
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    // Kita pakai Bearer token, bukan cookie
    'supports_credentials' => false,
];