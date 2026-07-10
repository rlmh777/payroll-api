<?php

return [
    'standard_daily_hours' => (float) env('ATTENDANCE_STANDARD_DAILY_HOURS', 8),
    'clock_round_off_minutes' => (int) env('ATTENDANCE_CLOCK_ROUND_OFF_MINUTES', 30),
    'schedule_comparison_source' => env('ATTENDANCE_SCHEDULE_COMPARISON_SOURCE', 'ROUNDED'),

    'timesheet_processing' => [
        // How many days before yesterday to reprocess on each daily run (catches late imports).
        'lookback_days' => (int) env('ATTENDANCE_TIMESHEET_LOOKBACK_DAYS', 7),
        // Daily schedule time in app timezone (requires `php artisan schedule:run` every minute).
        'schedule_time' => env('ATTENDANCE_TIMESHEET_SCHEDULE_TIME', '02:00'),
        // Queue processing after clocking log API import / ingest.
        'queue_after_ingest' => filter_var(env('ATTENDANCE_QUEUE_AFTER_INGEST', true), FILTER_VALIDATE_BOOL),
    ],
];

