<?php

return [
    'storage' => [
        'full_snapshot_actions' => ['created', 'deleted'],
        'full_snapshot_events' => [],
    ],
    'archive' => [
        'enabled' => true,
        'retention_months' => 3,
        'disk' => env('AUDIT_ARCHIVE_DISK', 'local'),
        'path' => env('AUDIT_ARCHIVE_PATH', 'audit-archives'),
        'schedule_at' => env('AUDIT_ARCHIVE_SCHEDULE_AT', '03:20'),
        'chunk_size' => 1000,
    ],
];
