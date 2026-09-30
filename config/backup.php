<?php

return [
    'database' => [
        'enabled' => env('BACKUP_DB_ENABLED', true),
        'connection' => env('BACKUP_DB_CONNECTION'),
        'binary' => env('BACKUP_DB_BINARY'),
        'disk' => env('BACKUP_DB_DISK', 'r2'),
        'path' => env('BACKUP_DB_PATH', 'backups/database'),
        'directory' => env('BACKUP_DB_DIRECTORY', 'app/backups/database'),
        'file_prefix' => env('BACKUP_DB_FILE_PREFIX', 'database_backup'),
        'compress' => env('BACKUP_DB_COMPRESS', true),
        'keep_days' => (int) env('BACKUP_DB_KEEP_DAYS', 7),
        'schedule_at' => env('BACKUP_DB_SCHEDULE_AT', '02:00'),
        'timeout' => (int) env('BACKUP_DB_TIMEOUT', 600),
        'alert_email' => env('BACKUP_DB_ALERT_EMAIL'),
        'alert_mailer' => env('BACKUP_DB_ALERT_MAILER'),
    ],
];
