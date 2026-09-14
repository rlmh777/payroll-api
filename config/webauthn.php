<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Relying Party (WebAuthn / Passkeys)
    |--------------------------------------------------------------------------
    */
    'rp_id' => env('WEBAUTHN_RP_ID', parse_url((string) env('FRONTEND_URL', 'http://localhost:9000'), PHP_URL_HOST) ?: 'localhost'),
    'rp_name' => env('WEBAUTHN_RP_NAME', env('APP_NAME', 'Payroll')),
    'origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'WEBAUTHN_ORIGINS',
            rtrim((string) env('FRONTEND_URL', 'http://localhost:9000'), '/')
        ))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Challenge lifetime (seconds)
    |--------------------------------------------------------------------------
    */
    'challenge_ttl' => (int) env('WEBAUTHN_CHALLENGE_TTL', 300),
];
