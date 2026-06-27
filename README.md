# ZeroBoiler Events

DB-driven dynamic event manager for Laravel 13 / PHP 8.5. Manage triggers via admin panel, API, or CLI without code changes.

[![CI](https://github.com/zeroboiler/events/workflows/CI/badge.svg)](https://github.com/zeroboiler/events/actions)
[![License](https://img.shields.io/badge/license-proprietary-blue.svg)](LICENSE)

## Features

- 🎯 **DB-driven triggers** — No code changes needed to add/remove triggers
- 🔀 **Wildcard events** — Match `order.*` against `order.placed`, `order.shipped`, etc.
- ⚡ **Condition engine** — JSON-based conditions with rich operators (`>`, `<`, `between`, `contains`, etc.)
- 🚀 **Async dispatch** — Queue-based async execution with retry/backoff support
- 🔧 **CLI tools** — Full command-line interface for managing triggers
- 📊 **Event logging** — Track all dispatched events with status and duration
- 🧪 **Tested** — Comprehensive Pest test suite

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

The package creates two tables:

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
| `status` | enum | pending|dispatched|completed|failed |
| `error` | text | Error message (nullable) |
| `duration_ms` | integer | Execution duration in ms (nullable) |
| `created_at` | timestamp | Created at |
| `updated_at` | timestamp | Updated at |
| `deleted_at` | timestamp | Soft delete |

## Architecture

- **EventManager** — Main facade and service
- **TriggerBuilder** — Fluent API for creating triggers
- **ConditionEngine** — Evaluates JSON conditions
- **ActionResolver** — Resolves handler classes from container
- **WildcardMatcher** — Matches wildcard patterns
- **DispatchTriggerJob** — Queued job for async dispatch

## License

Proprietary. All rights reserved.

## Support

For issues and questions, please use the GitHub issue tracker.