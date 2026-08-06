# ZeroBoiler Events

[![Latest Version](https://img.shields.io/badge/version-1.3.0-blue)]()
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)]()
[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)]()
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-success)]()

Database-driven dynamic event manager for Laravel — register, manage, and fire event triggers via admin panel, API, or CLI without code changes.

## Quick Start

```bash
composer require zeroboiler/events
php artisan vendor:publish --tag=events-config
php artisan migrate
```

Register your first trigger:

```php
use ZeroBoiler\Events\Facades\EventManager;

EventManager::on('order.placed')
    ->name('Send Notification')
    ->action(SendOrderNotification::class)
    ->when(['amount' => ['>', 100]])
    ->async()
    ->priority(10)
    ->save();

EventManager::fire('order.placed', ['order_id' => 123, 'total' => 150]);
```

## Features

- **Dynamic Triggers** — Register event triggers in the database; no code deployment needed to add, modify, or remove them.
- **Wildcard Matching** — Support for `*` (single-segment), `**` (cross-segment), and catch-all patterns.
- **Condition Engine** — Rich condition operators: `>`, `<`, `>=`, `<=`, `=`, `!=`, `in`, `not_in`, `contains`, `between`, `null`, `not_null`, `empty`, `starts_with`, `ends_with`, `matches` (regex with ReDoS protection), and nested dot-notation fields.
- **Sync & Async Dispatch** — Execute triggers synchronously or queue them for async processing with configurable retry and backoff.
- **Webhook Subscriptions** — Subscribe external systems to events with HMAC-SHA256 payload signing, failure tracking, and auto-deactivation.
- **Domain Events** — First-class `DomainEvent` value object for event sourcing patterns with UUID, timestamp, serialization, and reconstruction.
- **Event History & Stats** — Query event logs, filter by event/status/trigger, and get aggregate statistics (success rates, avg duration, top events).
- **Log Retention** — Configurable automatic purge of old event logs.
- **CLI Commands** — Full set of Artisan commands for managing triggers, subscriptions, and event logs.

## Installation

```bash
composer require zeroboiler/events
```

### Publish Configuration & Migrations

```bash
php artisan vendor:publish --tag=events-config
php artisan migrate
```

## Configuration

```php
// config/events.php
return [
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

    'retention' => [
        'days' => env('EVENTS_LOG_RETENTION_DAYS', 30),
        'include_pending' => env('EVENTS_LOG_PURGE_PENDING', false),
    ],

    'subscriptions' => [
        'auto_generate_secret' => true,
        'max_failures' => env('EVENTS_SUB_MAX_FAILURES', 10),
        'timeout' => env('EVENTS_SUB_TIMEOUT', 30),
        'signature_algorithm' => 'sha256',
    ],

    'wildcard_cache_ttl' => env('EVENTS_WILDCARD_CACHE_TTL', 300),
];
```

## Usage

### Registering Triggers

```php
use ZeroBoiler\Events\Facades\EventManager;

// Fluent builder — single action
EventManager::on('order.placed')
    ->name('Send Order Notification')
    ->action(SendOrderNotification::class)
    ->when(['amount' => ['>', 100]])
    ->async()
    ->priority(10)
    ->save();

// Multiple actions
EventManager::on('order.placed')
    ->actions([LogOrderAction::class, SendSlackAlert::class])
    ->save();

// With action parameters (e.g., webhook URL)
EventManager::on('payment.received')
    ->action(WebhookAction::class)
    ->actionParams(['url' => 'https://partner.com/hooks/payment'])
    ->save();
```

### Firing Events

```php
// Fire with payload
EventManager::fire('order.placed', ['order_id' => 123, 'total' => 99.99]);

// Fire model events
EventManager::fireModel(Order::class, 'created', $order);
```

### Condition Engine

Conditions are stored as JSON and evaluated against the payload:

```php
// Simple equality
['status' => 'paid']

// Comparison operators
['amount' => ['>', 100]]
['amount' => ['between', [50, 200]]]

// Array/string operators
['tags' => ['contains', 'urgent']]
['status' => ['in', ['active', 'pending']]]

// Null checks
['deleted_at' => ['null']]

// String operators
['email' => ['starts_with', 'admin@']]
['code' => ['matches', '/^[A-Z]{3}-\d{4}$/']]

// Nested fields (dot notation)
['user.role' => 'admin']
['order.total' => ['>', 100]]
```

### Wildcard Patterns

```php
// Single-segment wildcard: matches order.placed, order.shipped
EventManager::on('order.*')->action(...)->save();

// Cross-segment wildcard: matches order.placed, order.placed.extra
EventManager::on('order.**')->action(...)->save();

// Multiple wildcards
EventManager::on('*.order.*')->action(...)->save();

// Catch-all
EventManager::on('*')->action(...)->save();
```

### Webhook Subscriptions

```php
// Subscribe an external system to an event
EventManager::subscribe('order.placed', 'https://partner.com/webhooks/order')
    ->withSecret('whsec_custom_secret')
    ->withFilter(['status' => 'paid'])
    ->priority(10)
    ->async()
    ->save();

// Quick subscribe (auto-generates HMAC secret)
$triggerId = EventManager::subscribeWebhook('order.placed', 'https://partner.com/hooks');

// Manage subscriptions
$subs = EventManager::listSubscriptions('order.*', activeOnly: true);
EventManager::unsubscribe($subscriptionId);
```

Webhook payloads are signed with HMAC-SHA256. The signature is sent in the `X-Webhook-Signature` header (`sha256=<hex>`). Subscriptions auto-deactivate after exceeding the configured failure threshold.

### Domain Events

```php
use ZeroBoiler\Events\Domain\DomainEvent;

// Create
$event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

// Access
$event->eventId;       // Ramsey\Uuid\UuidInterface
$event->eventType;    // string
$event->payload;      // array
$event->occurredAt;   // DateTimeImmutable

// Serialize / Deserialize
$data = $event->toArray();
$restored = DomainEvent::fromArray($data);  // Preserves eventId & occurredAt
```

### Event History & Statistics

```php
// Query history
$history = EventManager::getEventHistory(
    event: 'order.*',
    status: 'completed',
    triggerId: $triggerId,
    limit: 50,
);

// Aggregate statistics
$stats = EventManager::getStats(since: now()->subDays(7));
// Returns: total_logs, completed, failed, success_rate, avg_duration_ms,
//          top_events, top_failed_events, etc.

// Purge old logs
$deleted = EventManager::purgeLogs(
    before: now()->subDays(30),
    includePending: false,
);
```

### Enable / Disable Triggers

```php
EventManager::enable($triggerId);
EventManager::disable($triggerId);
EventManager::invalidateTriggerCache();
```

## CLI Commands

| Command | Description |
|---|---|
| `zeroboiler:events:list` | List triggers with optional filtering |
| `zeroboiler:events:fire {event}` | Manually fire an event |
| `zeroboiler:events:register` | Register a new trigger |
| `zeroboiler:events:enable {id}` | Enable a trigger |
| `zeroboiler:events:disable {id}` | Disable a trigger |
| `zeroboiler:events:retry {logId}` | Retry a failed event log |
| `zeroboiler:events:redeliver {logId}` | Redeliver an event log |
| `zeroboiler:events:log` | View event log history |
| `zeroboiler:events:subscribe` | Create a webhook subscription |
| `zeroboiler:events:unsubscribe {id}` | Remove a webhook subscription |
| `zeroboiler:events:subscriptions` | List webhook subscriptions |

## Architecture

### Core Classes

- **`EventManager`** — Central orchestrator. Fires events, matches triggers, dispatches actions.
- **`TriggerBuilder`** — Fluent builder for creating triggers.
- **`SubscriptionBuilder`** — Fluent builder for creating webhook subscriptions.
- **`ConditionEngine`** — Evaluates conditions against payloads with 15+ operators.
- **`WildcardMatcher`** — Pattern matching with `*`, `**`, and catch-all support.
- **`ActionResolver`** — Resolves action class names to `Triggerable` instances from the container.

### Traits

- **`ManagesHistory`** — Event history queries, statistics, and log purging.
- **`ManagesSubscriptions`** — Webhook subscription CRUD and management.
- **`EscapesWildcardLike`** — Converts event wildcards to safe SQL LIKE patterns.

### Models

- **`Trigger`** — Event trigger definition (event pattern, action handler, conditions, priority).
- **`EventLog`** — Execution log for each trigger dispatch (status, duration, error).
- **`Subscription`** — External webhook subscription with HMAC signing and delivery tracking.

### Events

- **`DomainEvent`** — Value object for event sourcing with UUID, timestamp, and serialization.

## Testing

```bash
composer test        # Run Pest test suite
composer analyse     # PHPStan level 9
composer lint        # Laravel Pint
composer ci          # All checks
```

## How It Works

```
┌─────────────┐    fire()     ┌──────────────────┐
│   Client     │────────────▶│   EventManager    │
│ (Facade/CLI) │              │   (Orchestrator)  │
└─────────────┘              └───────┬──────────┘
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                   ▼
           ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
           │   Exact DB    │  │  Wildcard     │  │  Wildcard     │
           │   Lookup     │  │  Cache (TTL) │  │  Matcher      │
           └──────┬───────┘  └──────┬───────┘  └──────┬───────┘
                  │                 │                   │
                  └────────┬────────┘                   │
                           ▼                            │
                  ┌──────────────┐                      │
                  │  Condition   │                      │
                  │  Engine      │                      │
                  └──────┬───────┘                      │
                         ▼                              │
                  ┌──────────────┐◀─────────────────────┘
                  │  Action      │
                  │  Resolver    │
                  └──────┬───────┘
                         │
                    ┌────┴────┐
                    ▼         ▼
              ┌──────────┐ ┌──────────────┐
              │   Sync   │ │   Async      │
              │ Execute  │ │ Queue::push  │
              └────┬─────┘ └──────┬───────┘
                   ▼              ▼
             ┌──────────┐  ┌──────────────┐
             │ EventLog │  │ DispatchJob  │
             │  (DB)    │  │ + EventLog   │
             └──────────┘  └──────────────┘
```

### Event Flow

1. **Client calls `EventManager::fire($event, $payload)`** — via Facade, direct injection, or CLI command.
2. **Trigger resolution** — Exact-match triggers are queried from the DB; wildcard triggers are loaded from a 5-minute cache.
3. **Condition evaluation** — Each matching trigger's conditions are evaluated against the payload using the `ConditionEngine` (15+ operators, dot-notation nesting, ReDoS protection).
4. **Dispatch** — Synchronous triggers execute immediately and log results; async triggers push a `DispatchTriggerJob` to the queue, creating the `EventLog` inside the job to prevent orphaned entries.
5. **Webhook delivery** — For subscription triggers, the `WebhookAction` sends an HMAC-SHA256-signed HTTP POST, tracking delivery count and failure count on the subscription model.

### Performance Optimizations

- **Wildcard cache** — Enabled wildcard triggers are cached for 5 minutes, avoiding a DB query on every `fire()` call.
- **Exact-match fast path** — Non-wildcard events skip the cache entirely and query directly (indexed, fast).
- **No orphaned logs** — Async jobs create their `EventLog` inside the job handler, so queue failures don't leave orphaned entries.
- **Cache invalidation** — The wildcard cache is automatically invalidated on trigger create, enable, and disable operations.

## Changelog

### v1.3.0

- **Fixed**: PHPStan 9 compliance — replaced `@var` PHPDoc workarounds with `assert()` for container resolution type safety in `EventManager::on()` and `ManagesSubscriptions::subscribe()`
- **Fixed**: Eliminated assignment-in-condition anti-patterns in `EventsLogCommand` for PHPStan strictness
- **Fixed**: Null-safe type checks in `EventsRegisterCommand` and `EventsSubscribeCommand` — replaced truthy checks with explicit `!== null && !== ''` guards
- **Fixed**: `DispatchTriggerJob::failed()` uses `instanceof` check instead of truthy for PHPStan null-safety
- **Fixed**: `WebhookAction::handle()` simplified nullable `$subscriptionId` pass-through to `recordSubscriptionFailure()`
- **Added**: `EventManagerParseActionsTest` — comprehensive test coverage for all 5 action string formats (simple, JSON array, JSON object, classes+params, array of objects)
- **Added**: `EdgeCasesPhase2Test` — nested value access edge cases, strict equality edge cases, WildcardMatcher::extractWildcards edge cases, EscapesWildcardLike percent/complex escape tests
- **Changed**: Version bumped to 1.3.0

### v1.2.0

- **Fixed**: PHPStan 9 type safety — resolved 20+ baseline errors with proper type guards across DomainEvent, ConditionEngine, WebhookAction, EventManager, console commands, and models
- **Fixed**: Model scope return types (Trigger, Subscription) now satisfy PHPStan's generic Builder expectations
- **Changed**: README enriched with Quick Start section and PHPStan Level 9 badge

### v1.1.1

- **Fixed**: `ActionResolver` now explicitly checks that resolved class implements `Triggerable` instead of relying on PHP type coercion
- **Fixed**: `ConditionEngine` handles empty array condition values gracefully (returns `false` instead of accessing undefined index)
- **Fixed**: `DispatchTriggerJob::failed()` uses `update()` instead of direct property assignment for consistent cast handling
- **Fixed**: SQL LIKE injection in `EventsListCommand` and `EventsSubscriptionsCommand` — event filter wildcards now properly escape `%`, `_`, and `\` characters
- **Added**: `EventManager::parseActions()` now handles `{"classes": [...], "params": {...}}` format for multi-action triggers with shared params
- **Added**: Test for `classes` key action format
- **Added**: Test for empty array condition edge case
- **Added**: Test for ActionResolver error message content

### v1.1.0

- Initial production-ready release
- Dynamic event triggers with wildcard matching
- Condition engine with 15+ operators and ReDoS protection
- Webhook subscriptions with HMAC-SHA256 signing
- Domain event value object for event sourcing
- Event history, statistics, and log retention
- Full CLI command set
- PHPStan level 9, Laravel Pint, Rector

## License

Proprietary. All rights reserved.
