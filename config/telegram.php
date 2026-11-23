<?php

return [
    'contents_encryption_enabled' => (bool) env('TELEGRAM_CONTENTS_ENCRYPTION_ENABLED', false),

    /*
    |------------------------------------------------------------------
    | Telegram API credentials (my.telegram.org)
    |------------------------------------------------------------------
    | Used by MadelineProto (MTProto). Env-only: never stored in the
    | database, never returned in responses, never logged.
    */
    'app_id' => env('TELEGRAM_APP_ID'),
    'api_hash' => env('TELEGRAM_API_HASH'),

    /*
    |------------------------------------------------------------------
    | Roxi system account
    |------------------------------------------------------------------
    | The central Telegram user account whose persistent MTProto session
    | stores files on behalf of all users.
    */
    'roxi' => [
        'username' => env('TELEGRAM_ROXI_USERNAME'),
        'device_model' => env('TELEGRAM_DEVICE_MODEL', 'Roxivia Web'),
    ],

    /*
    |------------------------------------------------------------------
    | Long-running Telegram worker
    |------------------------------------------------------------------
    */
    'worker' => [
        'queue' => env('TELEGRAM_QUEUE', 'telegram'),
        'pop_interval_ms' => (int) env('TELEGRAM_POP_INTERVAL_MS', 500),
        'heartbeat_interval_seconds' => (int) env('TELEGRAM_HEARTBEAT_INTERVAL_SECONDS', 15),
        'lock' => env('TELEGRAM_WORKER_LOCK', 'roxi-runtime'),
        'lock_ttl_seconds' => (int) env('TELEGRAM_WORKER_LOCK_TTL', 90),
        'max_attempts' => (int) env('TELEGRAM_MAX_ATTEMPTS', 5),
        'operation_lease_seconds' => (int) env('TELEGRAM_OPERATION_LEASE_SECONDS', 120),
        'drain_seconds' => (int) env('TELEGRAM_DRAIN_SECONDS', 60),
        'max_concurrent_operations' => (int) env('TELEGRAM_MAX_CONCURRENT_OPERATIONS', 4),
        'php_binary' => env('TELEGRAM_WORKER_PHP_BINARY', 'php'),
        'upload_bridge_address' => env('TELEGRAM_UPLOAD_BRIDGE_ADDRESS', '127.0.0.1:18751'),
        'upload_bridge_url' => env('TELEGRAM_UPLOAD_BRIDGE_URL', 'http://127.0.0.1:18751'),
        'upload_bridge_timeout_seconds' => (int) env('TELEGRAM_UPLOAD_BRIDGE_TIMEOUT_SECONDS', 3600),
    ],

    /*
    |------------------------------------------------------------------
    | Phase 1 temporary file storage (private, never web-accessible)
    |------------------------------------------------------------------
    */
    'storage' => [
        'max_upload_kib' => (int) env('TELEGRAM_MAX_UPLOAD_KIB', 204800),
        'max_user_bytes' => (int) env('TELEGRAM_MAX_USER_BYTES', 10 * 1024 ** 3),
        'max_temp_user_bytes' => (int) env('TELEGRAM_MAX_TEMP_USER_BYTES', 2 * 1024 ** 3),
        'max_active_operations' => (int) env('TELEGRAM_MAX_ACTIVE_OPERATIONS', 10),
        'upload_dir' => 'telegram-upload',
        'download_dir' => 'telegram-download',
        'provision_code_ttl_minutes' => (int) env('TELEGRAM_PROVISION_CODE_TTL_MINUTES', 15),
        'confirmation_code_ttl_minutes' => (int) env('TELEGRAM_CONFIRMATION_CODE_TTL_MINUTES', 5),
        'invite_code_ttl_minutes' => (int) env('TELEGRAM_INVITE_CODE_TTL_MINUTES', 5),
        'temp_max_age_hours' => (int) env('TELEGRAM_TEMP_MAX_AGE_HOURS', 24),
    ],

    /*
    |------------------------------------------------------------------
    | MadelineProto state store (dedicated PostgreSQL database)
    |------------------------------------------------------------------
    | MadelineProto owns its tables there; the application never queries
    | them. Separated from the application database on purpose.
    | MadelineProto and AsyncOrm serialize personal session state without
    | encryption. Set the confirmation only when the PostgreSQL storage,
    | snapshots, and backups are encrypted at rest. Personal sign-in fails
    | closed until this deployment requirement is confirmed.
    */
    'database' => [
        'host' => env('TELEGRAM_DB_HOST', '127.0.0.1'),
        'port' => env('TELEGRAM_DB_PORT', '5432'),
        'database' => env('TELEGRAM_DB_DATABASE', 'roxifile_madeline'),
        'username' => env('TELEGRAM_DB_USERNAME'),
        'password' => env('TELEGRAM_DB_PASSWORD'),
        'encryption_at_rest_confirmed' => (bool) env('TELEGRAM_DB_ENCRYPTION_AT_REST_CONFIRMED', false),
    ],

];
