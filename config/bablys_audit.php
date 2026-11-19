<?php

declare(strict_types=1);

return [
    // This is a POLICY target, not an automatic deletion schedule.
    'retention_days' => (int) env('BABLYS_AUDIT_RETENTION_DAYS', 730),

    // The archive CLI only processes complete UTC dates older than this age.
    'archive_after_days' => (int) env('BABLYS_AUDIT_ARCHIVE_AFTER_DAYS', 90),

    // Dedicated key; retain old keys for future verification after rotation.
    // Generate with: php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
    'archive_key' => env('BABLYS_AUDIT_ARCHIVE_KEY'),

    // Do not point to public/, a web-accessible drive, or a shared upload folder.
    'archive_directory' => env('BABLYS_AUDIT_ARCHIVE_DIRECTORY', storage_path('app/private/bablys-audit')),
];
