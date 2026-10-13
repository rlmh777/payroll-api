<?php

return [
    'heartbeat_timeout_seconds' => (int) env('BIOMETRIC_HEARTBEAT_TIMEOUT', 90),
    'max_future_skew_seconds' => (int) env('BIOMETRIC_MAX_FUTURE_SKEW', 900),
    'max_event_age_days' => (int) env('BIOMETRIC_MAX_EVENT_AGE_DAYS', 30),
    'default_model' => 'DS-K1T342',
    'default_timezone' => env('BIOMETRIC_DEFAULT_TIMEZONE', 'America/Belize'),
];
