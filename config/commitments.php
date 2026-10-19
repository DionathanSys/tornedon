<?php

return [
    'pdf' => [
        'binary' => env('COMMITMENTS_PDFTOTEXT_BINARY', 'pdftotext'),
        'max_size_bytes' => 20 * 1024 * 1024,
        'max_pages' => 100,
        'max_text_bytes' => 8 * 1024 * 1024,
        'timeout_seconds' => 30,
        'temporary_directory' => sys_get_temp_dir(),
    ],
];
