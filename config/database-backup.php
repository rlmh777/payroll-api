<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database backups
    |--------------------------------------------------------------------------
    |
    | Backups are stored on the private local disk and pruned by retention
    | window and max count so scheduled copies cycle over ~one month.
    |
    */
    'enabled' => (bool) env('DB_BACKUP_ENABLED', true),

    'disk' => env('DB_BACKUP_DISK', 'local'),

    'directory' => env('DB_BACKUP_DIRECTORY', 'database-backups'),

    /** Keep backups for this many days (older files are deleted). */
    'retention_days' => (int) env('DB_BACKUP_RETENTION_DAYS', 30),

    /**
     * Hard cap after retention pruning. Default: 4 backups/day × 30 days.
     */
    'max_backups' => (int) env('DB_BACKUP_MAX', 120),

    /**
     * Scheduled backup times (HH:MM, app timezone). Four slots ≈ every 6 hours.
     *
     * @var list<string>
     */
    'schedule_times' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DB_BACKUP_SCHEDULE_TIMES', '00:00,06:00,12:00,18:00')),
    ))),

    'pg_dump_path' => env('DB_BACKUP_PG_DUMP', 'pg_dump'),

    'pg_restore_path' => env('DB_BACKUP_PG_RESTORE', 'pg_restore'),
];
