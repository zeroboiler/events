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
        'connection' => env('EVENTS_QUEUE_CONNECTION', config('queue.default')),
        'queue' => env('EVENTS_QUEUE', 'default'),
    ],

    'retry' => [
        'tries' => env('EVENTS_RETRY_TRIES', 3),
        'backoff' => env('EVENTS_RETRY_BACKOFF', '60,300,900'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Subscriptions
    |--------------------------------------------------------------------------
    |
    | Settings for external webhook subscriptions.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | Number of days to retain event logs before they are soft-deleted
    | by the events:cleanup command.
    |
    */

    'retention_days' => env('EVENTS_RETENTION_DAYS', 30),

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
        'signature_algorithm' => 'sha256',
    ],
];
