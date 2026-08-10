<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Events Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the ZeroBoiler Events package.
    |
    */

    'table_names' => [
        'triggers' => 'triggers',
        'event_logs' => 'event_logs',
        'subscriptions' => 'event_subscriptions',
    ],

    'queue' => [
        'connection' => env('EVENTS_QUEUE_CONNECTION') ?: config('queue.default', 'default'),
        'queue' => env('EVENTS_QUEUE', 'default'),
    ],

    'retry' => [
        'tries' => env('EVENTS_RETRY_TRIES', 3),
        'backoff' => env('EVENTS_RETRY_BACKOFF', '60,300,900'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | Event logs older than this many days are eligible for purge.
    | Set to null to disable automatic retention.
    |
    */

    'retention' => [
        'days' => env('EVENTS_LOG_RETENTION_DAYS', 30),
        // When purging, also delete pending/dispatched logs that are stuck
        'include_pending' => env('EVENTS_LOG_PURGE_PENDING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Subscriptions
    |--------------------------------------------------------------------------
    |
    | Settings for external webhook subscriptions.
    |
    */

    'subscriptions' => [
        // Auto-generate HMAC signing secrets when none is provided
        'auto_generate_secret' => true,

        // Auto-deactivate subscriptions after this many consecutive failures
        'max_failures' => env('EVENTS_SUB_MAX_FAILURES', 10),

        // HTTP timeout for webhook delivery (seconds)
        'timeout' => env('EVENTS_SUB_TIMEOUT', 30),

        // Signature algorithm for HMAC payload signing
        'signature_algorithm' => env('EVENTS_SUB_SIGNATURE_ALGORITHM', 'sha256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wildcard Cache
    |--------------------------------------------------------------------------
    |
    | Enabled wildcard triggers are cached to avoid a DB query on every fire()
    | call. The cache is automatically invalidated on trigger create, enable,
    | and disable operations. Set to null to disable caching entirely.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Global Disable
    |--------------------------------------------------------------------------
    |
    | Set to true to completely disable the event system. When disabled,
    | all fire() calls will silently return without dispatching any triggers.
    | Useful for maintenance windows or testing environments.
    |
    */

    'disabled' => env('EVENTS_DISABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Wildcard Cache
    |--------------------------------------------------------------------------
    |
    | Enabled wildcard triggers are cached to avoid a DB query on every fire()
    | call. The cache is automatically invalidated on trigger create, enable,
    | and disable operations. Set to null to disable caching entirely.
    |
    */

    'wildcard_cache_ttl' => env('EVENTS_WILDCARD_CACHE_TTL', 300),
];
