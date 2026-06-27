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
    ],

    'queue' => [
        'connection' => env('EVENTS_QUEUE_CONNECTION', config('queue.default')),
        'queue' => env('EVENTS_QUEUE', 'default'),
    ],

    'retry' => [
        'tries' => env('EVENTS_RETRY_TRIES', 3),
        'backoff' => env('EVENTS_RETRY_BACKOFF', '60,300,900'),
    ],
];
