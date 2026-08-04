# ZeroBoiler Events

DB-driven dynamic event manager for Laravel 13 / PHP 8.5. Manage triggers via admin panel, API, or CLI without code changes.

[![CI](https://github.com/zeroboiler/events/workflows/CI/badge.svg)](https://github.com/zeroboiler/events/actions)
[![License](https://img.shields.io/badge/license-proprietary-blue.svg)](LICENSE)

## Features

- 🎯 **DB-driven triggers** — No code changes needed to add/remove triggers
- 🔀 **Wildcard events** — Match `order.*` against `order.placed`, `order.shipped`, etc.
- ⚡ **Condition engine** — 18 operators including regex, between, contains, type-safe comparison
- 🚀 **Async dispatch** — Queue-based async execution with retry/backoff support
- 🔗 **Webhook subscriptions** — External HTTP POST notifications with HMAC-SHA256 signing, delivery tracking, and auto-deactivation
- 📊 **Event history & statistics** — Aggregate success/failure rates, avg duration, top-fired events, log purge
- 🛡️ **Safe by design** — Dispatch depth guard, ReDoS protection, atomic status transitions, LIKE injection prevention
- 🔧 **CLI tools** — 12 commands for triggers, logs, subscriptions, and cleanup
- 🧪 **Tested** — 201 tests, 452 assertions, PHPStan level 6 clean

## Installation

```bash
composer require zeroboiler/events
```

Publish the migrations:

```bash
php artisan vendor:publish --provider="ZeroBoiler\Events\EventsServiceProvider" --tag="events-config"
php artisan migrate
```

## Quick Start

### Create a Trigger via CLI

```bash
php artisan zeroboiler:events:register order.placed App\\Actions\\SendOrderNotification --async --priority=100
```

### Fire an Event

```php
use ZeroBoiler\Events\Facades\EventManager;

// Simple event
EventManager::fire('order.placed', ['order_id' => 123, 'amount' => 99.99]);

// Model event
EventManager::fireModel(Order::class, 'created', $order);
```

### Using the Builder

```php
use ZeroBoiler\Events\Facades\EventManager;

EventManager::on('order.placed')
    ->name('High Value Order Notification')
    ->action(App\Actions\SendOrderNotification::class)
    ->when(['amount' => ['>', 1000]]) // Condition: amount > 1000
    ->async(true)
    ->priority(100)
    ->save();
```

## Condition Engine

Supported operators:

- `>` — Greater than
- `>=` — Greater than or equal
- `<` — Less than
- `<=` — Less than or equal
- `=`, `===` — Equality (loose/strict)
- `!=`, `!==` — Inequality (loose/strict)
- `in` — Value in array
- `not_in` — Value not in array
- `contains` — String/array contains
- `not_contains` — String/array does not contain
- `between` — Value between [min, max]
- `null` — Value is null
- `not_null` — Value is not null
- `empty` — Value is empty
- `not_empty` — Value is not empty
- `starts_with` — String starts with
- `ends_with` — String ends with
- `matches` — Regex match

### Examples

```php
// Simple equality
['status' => 'paid']

// Comparison
['amount' => ['>', 1000]]

// Range
['amount' => ['between', [100, 500]]]

// Array contains
['tags' => ['contains', 'urgent']]

// Multiple conditions
['status' => 'paid', 'amount' => ['>', 1000]]

// Nested fields
['user.role' => 'admin', 'order.total' => ['>', 500]]
```

## Wildcard Events

Match multiple events with wildcards:

```php
// Create wildcard trigger
EventManager::on('order.*')
    ->action(App\Actions\LogOrderEvent::class)
    ->save();

// This will match:
EventManager::fire('order.placed');
EventManager::fire('order.shipped');
EventManager::fire('order.cancelled');

// Extract wildcard values
$wildcards = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
// Returns: ['profile']
```

## Creating Action Handlers

Action handlers must implement the `Triggerable` interface:

```php
<?php

namespace App\Actions;

use ZeroBoiler\Events\Contracts\Triggerable;

class SendOrderNotification implements Triggerable
{
    public function handle(array $payload): void
    {
        $orderId = $payload['order_id'];
        $amount = $payload['amount'];

        // Send notification
        Mail::to($payload['user_email'])->send(new OrderPlacedMail($orderId, $amount));
    }
}
```

## CLI Commands

### List all triggers

```bash
php artisan zeroboiler:events:list
```

### Register a new trigger

```bash
php artisan zeroboiler:events:register {event} {action} [--name=] [--async] [--priority=0]
```

### Manually fire an event

```bash
php artisan zeroboiler:events:fire {event} [--payload=*]
```

Example:

```bash
php artisan zeroboiler:events:fire order.placed --payload=order_id=123 --payload=amount=99.99
```

### View event logs

```bash
php artisan zeroboiler:events:log [--trigger=] [--status=] [--limit=50]
```

### Retry failed dispatches

```bash
php artisan zeroboiler:events:retry [--status=failed]
```

### Enable/disable a trigger

```bash
php artisan zeroboiler:events:enable {id}
php artisan zeroboiler:events:disable {id}
```

## Event Log Statuses

- `pending` — Created, waiting to be dispatched
- `dispatched` — Dispatched (for async triggers)
- `completed` — Successfully executed
- `failed` — Failed to execute (error stored in `error` field)

## Queue Configuration

Configure queue settings in `config/events.php`:

```php
return [
    'queue' => [
        'connection' => env('EVENTS_QUEUE_CONNECTION', config('queue.default')),
        'queue' => env('EVENTS_QUEUE', 'default'),
    ],

    'retry' => [
        'tries' => env('EVENTS_RETRY_TRIES', 3),
        'backoff' => env('EVENTS_RETRY_BACKOFF', '60,300,900'),
    ],
];
```

## Webhook Subscriptions

External systems can subscribe to events via HTTP POST webhooks with automatic HMAC-SHA256 payload signing.

### Create a Subscription

```php
use ZeroBoiler\Events\Facades\EventManager;

// Fluent builder
$subscription = EventManager::subscribe('order.placed', 'https://api.partner.com/webhooks/order')
    ->withSecret('whsec_abc123')       // HMAC signing secret (auto-generated if omitted)
    ->withFilter(['status' => 'paid'])  // Only fire when conditions match
    ->priority(100)
    ->async()                           // Queue-based delivery
    ->save();

// Quick one-liner (returns trigger ID)
$triggerId = EventManager::subscribeWebhook(
    'order.placed',
    'https://api.partner.com/webhooks/order',
    ['status' => 'paid'],
    priority: 100,
);
```

### Manage Subscriptions

```php
// List subscriptions (supports wildcard event filter)
$subs = EventManager::listSubscriptions('order.*', activeOnly: true);

// Get a single subscription
$sub = EventManager::getSubscription($subscriptionId);

// Remove a subscription
EventManager::unsubscribe($subscriptionId);
```

### Webhook Payload Verification

Each webhook delivery includes an `X-Webhook-Signature` header:

```
X-Webhook-Signature: sha256=<hex_hmac>
X-Webhook-Subscription-Id: <uuid>
```

Verify on the receiving end:

```php
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
$payload = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (! hash_equals($expected, $signature)) {
    abort(401, 'Invalid signature');
}
```

### CLI — Subscriptions

```bash
# Subscribe a webhook
php artisan zeroboiler:events:subscribe {event} {url} [--secret=] [--filter=*]

# Unsubscribe
php artisan zeroboiler:events:unsubscribe {id}

# List subscriptions
php artisan zeroboiler:events:subscriptions [--event=] [--active]

# Redeliver a failed webhook
php artisan zeroboiler:events:redeliver {log_id}
```

### Auto-Deactivation

Subscriptions are automatically deactivated after 10 consecutive failures (configurable via `EVENTS_SUB_MAX_FAILURES`). Use `events:enable` to reactivate.

## Event History & Statistics

### Query Event History

```php
use ZeroBoiler\Events\Facades\EventManager;

// Recent logs with filtering (supports wildcards)
$logs = EventManager::getEventHistory(
    event: 'order.*',        // wildcard filter
    status: 'failed',         // pending|dispatched|completed|failed
    triggerId: $triggerId,   // optional trigger filter
    limit: 50,
);
```

### Aggregate Statistics

```php
$stats = EventManager::getStats();

// Returns:
// [
//     'total_logs' => 1542,
//     'total_triggers' => 23,
//     'active_triggers' => 19,
//     'completed' => 1480,
//     'failed' => 42,
//     'pending' => 15,
//     'dispatched' => 5,
//     'success_rate' => 97.24,       // %
//     'failure_rate' => 2.76,        // %
//     'avg_duration_ms' => 12.5,
//     'top_events' => [...],          // top 10 by fire count
//     'top_failed_events' => [...],   // top 10 failed
// ]

// Time-bounded stats
$stats = EventManager::getStats(since: now()->subDays(7));
```

### Log Retention & Purge

```php
// Purge old completed/failed logs
$deleted = EventManager::purgeLogs(before: now()->subDays(30));

// Also purge stuck pending/dispatched logs
$deleted = EventManager::purgeLogs(before: now()->subDays(30), includePending: true);
```

### CLI — Cleanup

```bash
# Purge logs older than retention period
php artisan zeroboiler:events:cleanup [--days=30] [--pending]
```

Configure retention in `config/events.php`:

```php
'retention' => [
    'days' => env('EVENTS_LOG_RETENTION_DAYS', 30),
    'include_pending' => env('EVENTS_LOG_PURGE_PENDING', false),
],
```

## Managing Triggers Programmatically

### Enable/disable triggers

```php
use ZeroBoiler\Events\Facades\EventManager;

EventManager::enable($triggerId);
EventManager::disable($triggerId);
```

### Using Eloquent

```php
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\EventLog;

// Get enabled triggers
$triggers = Trigger::enabled()->get();

// Get event logs for a trigger
$logs = EventLog::where('trigger_id', $triggerId)->get();

// Get failed logs
$failed = EventLog::failed()->get();

// Get completed logs
$completed = EventLog::completed()->get();

// Get pending logs
$pending = EventLog::pending()->get();
```

## Domain Events

For event sourcing integration with `zeroboiler/domain`:

```php
use ZeroBoiler\Events\Domain\DomainEvent;

// Create a domain event
$event = DomainEvent::occur('order.placed', [
    'order_id' => 123,
    'amount' => 99.99,
]);

// Serialize for persistence
$data = $event->toArray();
// ['eventId' => '...', 'eventType' => 'order.placed', 'payload' => [...], 'occurredAt' => '...']

// Reconstruct from persisted data
$event = DomainEvent::fromArray($data);
```

## Testing

```bash
# Run all tests
composer test

# Run linting
composer lint

# Run static analysis
composer analyse

# Run Rector
composer rector

# Run full CI
composer ci
```

## Configuration

Publish and edit `config/events.php` for custom configuration:

```bash
php artisan vendor:publish --provider="ZeroBoiler\Events\EventsServiceProvider" --tag="events-config"
```

## Database

The package creates three tables:

### `triggers`

| Column | Type | Description |
|--------|------|-------------|
| `id` | uuid | Primary key |
| `name` | string | Trigger name |
| `event` | string | Event name (supports wildcards) |
| `action` | text | Handler class FQN (JSON for multiple) |
| `conditions` | json | JSON conditions |
| `async` | boolean | Dispatch asynchronously |
| `priority` | integer | Priority (higher first) |
| `enabled` | boolean | Trigger enabled |
| `created_at` | timestamp | Created at |
| `updated_at` | timestamp | Updated at |
| `deleted_at` | timestamp | Soft delete |

### `event_logs`

| Column | Type | Description |
|--------|------|-------------|
| `id` | uuid | Primary key |
| `trigger_id` | uuid | Foreign key to triggers |
| `event` | string | Event name |
| `payload` | json | Event payload |
| `status` | enum | pending\|dispatched\|completed\|failed |
| `error` | text | Error message (nullable) |
| `duration_ms` | integer | Execution duration in ms (nullable) |
| `created_at` | timestamp | Created at |
| `updated_at` | timestamp | Updated at |
| `deleted_at` | timestamp | Soft delete |

### `event_subscriptions`

| Column | Type | Description |
|--------|------|-------------|
| `id` | uuid | Primary key |
| `event` | string | Event name (supports wildcards) |
| `url` | string | Webhook endpoint URL |
| `conditions` | json | Optional filter conditions (nullable) |
| `priority` | integer | Priority (higher first) |
| `active` | boolean | Subscription active |
| `secret` | string | HMAC signing secret (nullable) |
| `last_fired_at` | timestamp | Last successful delivery (nullable) |
| `failure_count` | integer | Consecutive failure count |
| `delivery_count` | integer | Total successful deliveries |
| `created_at` | timestamp | Created at |
| `updated_at` | timestamp | Updated at |
| `deleted_at` | timestamp | Soft delete |

## Architecture

- **EventManager** — Main facade and service (uses `ManagesHistory` and `ManagesSubscriptions` traits)
- **TriggerBuilder** — Fluent API for creating triggers
- **SubscriptionBuilder** — Fluent API for webhook subscriptions
- **ConditionEngine** — Evaluates JSON conditions (18 operators, ReDoS-safe regex)
- **ActionResolver** — Resolves handler classes from container
- **WildcardMatcher** — Matches wildcard patterns (`*` single-segment, `**` cross-segment)
- **WebhookAction** — HTTP POST delivery with HMAC-SHA256 signing and delivery tracking
- **DispatchTriggerJob** — Queued job for async dispatch with retry/backoff
- **DomainEvent** — Event sourcing integration with `zeroboiler/domain`

## Safety Features

- **Dispatch depth guard** — Prevents infinite recursion when triggers fire other events
- **ReDoS protection** — Regex `matches` operator limits pattern length (500 chars), backtrack limit (1000), and rejects nested-quantifier patterns
- **Atomic status transitions** — `DispatchTriggerJob` uses atomic status update to prevent race conditions
- **LIKE injection prevention** — Wildcard `*` → `%` conversion escapes SQL LIKE special characters (`%`, `_`, `\`)
- **Orphan-free logging** — `EventLog` created inside the job, not before dispatch, so no orphaned rows if the queue is down
- **Deterministic ordering** — Triggers sorted by priority DESC → `created_at` ASC → `id` for fully deterministic execution order
- **Failure isolation** — `fire()` continues dispatching remaining triggers when one fails, re-throws after all attempted

## License

Proprietary. All rights reserved.

## Support

For issues and questions, please use the GitHub issue tracker.