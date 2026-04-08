<?php

return [
    'database' => [
        'enabled' => env('BACKUP_DB_ENABLED', true),
        'connection' => env('BACKUP_DB_CONNECTION'),
        'binary' => env('BACKUP_DB_BINARY'),
        'directory' => env('BACKUP_DB_DIRECTORY', 'app/backups/database'),
        'compress' => env('BACKUP_DB_COMPRESS', true),
        'keep_days' => (int) env('BACKUP_DB_KEEP_DAYS', 14),
        'schedule_at' => env('BACKUP_DB_SCHEDULE_AT', '02:00'),
        'timeout' => (int) env('BACKUP_DB_TIMEOUT', 600),
    ],
];
