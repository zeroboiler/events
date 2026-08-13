# ZeroBoiler Events

| ![Latest Version](https://img.shields.io/badge/version-4.56.0-blue)]()
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)]()
[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)]()
|[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-success)]()
[![CI](https://github.com/zeroboiler/events/actions/workflows/ci.yml/badge.svg)](https://github.com/zeroboiler/events/actions/workflows/ci.yml)

Database-driven dynamic event manager for Laravel — register, manage, and fire event triggers via admin panel, API, or CLI without code changes.

## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [CLI Commands](#cli-commands)
- [Architecture](#architecture)
- [Advanced Topics](#advanced-topics)
  - [Event Sourcing with DomainEvent](#event-sourcing-with-domainevent)
  - [Custom Triggerable Actions](#custom-triggerable-actions)
  - [Testing Strategies](#testing-strategies)
  - [Performance Considerations](#performance-considerations)
- [Security Considerations](#security-considerations)
- [Troubleshooting](#troubleshooting)
- [Production Deployment Checklist](#production-deployment-checklist)
- [API Reference](#api-reference)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

## Requirements

| Requirement | Version |
|---|---|
| PHP | >= 8.5 |
| Laravel | >= 13.0 |
| ext-pdo | Required (for database) |
| ext-json | Required (for condition/action parsing) |
| ext-ctype | Required (for Laravel framework) |

Optional peer packages for enhanced functionality:
- **zeroboiler/security** — Rate limiting for webhook endpoints
- **zeroboiler/observability** — Structured logging and metrics integration

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
- **Sync & Async Dispatch** — Execute triggers synchronously or queue them for async processing with configurable retry and backoff. Force async mode with `fire($event, $payload, async: true)` or `--async` CLI flag.
- **Webhook Subscriptions** — Subscribe external systems to events with HMAC-SHA256 payload signing, failure tracking, and auto-deactivation.
- **Domain Events** — First-class `DomainEvent` value object for event sourcing patterns with UUID, timestamp, serialization, and reconstruction.
- **Event History & Stats** — Query event logs, filter by event/status/trigger, and get aggregate statistics (success rates, avg duration, top events).
- **Log Retention** — Configurable automatic purge of old event logs.
- **CLI Commands** — Full set of Artisan commands for managing triggers, subscriptions, event logs, and a health check diagnostic for ops monitoring.

## Installation

```bash
composer require zeroboiler/events
```

### Publish Configuration & Migrations

```bash
php artisan vendor:publish --tag=events-config     # Publish config only
php artisan vendor:publish --tag=events-migrations # Publish migrations only
php artisan vendor:publish --tag=events-config --tag=events-migrations # Both
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
        'connection' => env('EVENTS_QUEUE_CONNECTION', config('queue.default', 'default')),
        'queue' => env('EVENTS_QUEUE', 'default'),
    ],

    'retry' => [
        'tries' => env('EVENTS_RETRY_TRIES', 3),
        'backoff' => env('EVENTS_RETRY_BACKOFF', '60,300,900'),
    ],

    'retention' => [
        'days' => env('EVENTS_LOG_RETENTION_DAYS', 30),
        'include_pending' => env('EVENTS_LOG_PURGE_PENDING', false),
        'schedule_cron' => env('EVENTS_RETENTION_CRON', '0 2 * * *'),
    ],

    'subscriptions' => [
        'auto_generate_secret' => true,
        'max_failures' => env('EVENTS_SUB_MAX_FAILURES', 10),
        'timeout' => env('EVENTS_SUB_TIMEOUT', 30),
        'signature_algorithm' => 'sha256',
        'cleanup_cron' => env('EVENTS_SUB_CLEANUP_CRON', '0 3 * * *'),
    ],

    'disabled' => env('EVENTS_DISABLED', false),

    'wildcard_cache_ttl' => env('EVENTS_WILDCARD_CACHE_TTL', 300),
];
```

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `EVENTS_QUEUE_CONNECTION` | `queue.default` config | Queue connection for async trigger dispatch |
| `EVENTS_QUEUE` | `default` | Queue name for async trigger dispatch |
| `EVENTS_RETRY_TRIES` | `3` | Number of retry attempts for failed jobs |
| `EVENTS_RETRY_BACKOFF` | `60,300,900` | Comma-separated backoff intervals (seconds), or a JSON array (`[60,300,900]`) |
| `EVENTS_LOG_RETENTION_DAYS` | `30` | Days before event logs become eligible for purge |
| `EVENTS_LOG_PURGE_PENDING` | `false` | Include pending/dispatched logs in purge |
| `EVENTS_RETENTION_CRON` | `0 2 * * *` | Cron expression for automatic log purge schedule |
| `EVENTS_SUB_MAX_FAILURES` | `10` | Auto-deactivate subscription after this many consecutive failures |
| `EVENTS_SUB_TIMEOUT` | `30` | HTTP timeout for webhook delivery (seconds) |
| `EVENTS_SUB_SIGNATURE_ALGORITHM` | `sha256` | HMAC algorithm for webhook payload signing |
| `EVENTS_SUB_CLEANUP_CRON` | `0 3 * * *` | Cron expression for automatic subscription cleanup schedule |
| `EVENTS_DISABLED` | `false` | Set `true` to globally disable the entire event system |
| `EVENTS_WILDCARD_CACHE_TTL` | `300` | Cache TTL (seconds) for enabled wildcard triggers; set to `0` to disable |

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

// Force async dispatch (overrides individual trigger settings)
EventManager::fire('order.placed', ['order_id' => 123], async: true);

// Fire model events
EventManager::fireModel(Order::class, 'created', $order);
```

```bash
# Fire from CLI
php artisan zeroboiler:events:fire order.placed --payload=order_id=123

# Fire from CLI with async dispatch
php artisan zeroboiler:events:fire order.placed --async

# Fire with JSON payload
php artisan zeroboiler:events:fire order.placed --json='{"order_id": 123, "total": 99.99}'
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
// Returns: total_logs, total_triggers, active_triggers,
//          completed, failed, pending, dispatched,
//          success_rate (%), failure_rate (%),
//          avg_duration_ms, top_events, top_failed_events

// Purge old logs
$deleted = EventManager::purgeLogs(
    before: now()->subDays(30),
    includePending: false,
);

// Get stuck pending logs
$stale = EventManager::getStalePendingLogs(now()->subHours(1));

// Auto-deactivate failed subscriptions
$count = EventManager::deactivateExceededSubscriptions();
```

### Enable / Disable Triggers

```php
EventManager::enable($triggerId);
EventManager::disable($triggerId);
EventManager::invalidateTriggerCache();
```

### Global Disable

The event system can be globally disabled — useful for maintenance windows or testing:

```php
// Check if disabled
if (EventManager::isDisabled()) {
    // ...
}

// Disable at runtime (in-memory only)
EventManager::setEnabled(false);

// Re-enable
EventManager::setEnabled(true);
```

Or via environment variable (persistent across requests):

```env
EVENTS_DISABLED=true
```

When disabled, all `fire()` calls silently return without dispatching any triggers.

## CLI Commands

| Command | Description |
|---|---|
| `zeroboiler:events:list` | List triggers with optional filtering (supports `--event`, `--enabled`, `--disabled`, `--per-page`, `--page`) |
| `zeroboiler:events:fire {event}` | Manually fire an event (supports `--async`, `--json`, `--payload=*`) |
| `zeroboiler:events:register` | Register a new trigger (supports `--name`, `--async`, `--priority`) |
| `zeroboiler:events:enable {id}` | Enable a trigger |
| `zeroboiler:events:disable {id}` | Disable a trigger |
| `zeroboiler:events:retry` | Retry failed or pending event dispatches (supports `--status=failed|pending`) |
| `zeroboiler:events:redeliver {logId}` | Redeliver a failed/completed event log to its webhook endpoint (supports `--force`) |
| `zeroboiler:events:log` | View event logs (supports `--event`, `--status`, `--trigger`, `--limit`) |
| `zeroboiler:events:subscribe` | Create a webhook subscription (supports `--secret`, `--filter`, `--priority`, `--async`) |
| `zeroboiler:events:unsubscribe {id}` | Remove a webhook subscription |
| `zeroboiler:events:subscriptions` | List webhook subscriptions (supports `--event`, `--active`, `--inactive`, `--per-page`, `--page`) |
| `zeroboiler:events:health` | Diagnostic health check (supports `--json`, `--check-cache`) |

### Scheduled Tasks

The package provides an `EventScheduler` that registers automated maintenance tasks. To enable scheduled tasks, register the scheduler in your application's `Kernel::schedule()` method:

```php
// app/Console/Kernel.php
use ZeroBoiler\Events\Facades\EventManager;

protected function schedule(Schedule $schedule): void
{
    EventManager::registerScheduler($schedule);
}
```

| Task | Default Schedule | Config Key |
|---|---|---|
| Log retention purge | Daily at 02:00 UTC | `events.retention.schedule_cron` |
| Subscription cleanup | Daily at 03:00 UTC | `events.subscriptions.cleanup_cron` |

Both tasks use `withoutOverlapping()` and `onOneServer()` to prevent duplicate execution in multi-worker deployments.

## Architecture

### Package Structure

```
events/
├── config/
│   └── events.php              # Package configuration
├── database/
│   ├── factories/
│   │   ├── EventLogFactory.php
│   │   ├── SubscriptionFactory.php
│   │   └── TriggerFactory.php
├── migrations/
│       ├── 2024_01_01_000001_create_triggers_table.php
│       ├── 2024_01_01_000002_create_event_logs_table.php
│       └── 2025_06_28_000001_create_event_subscriptions_table.php
├── rector.php                    # Rector code upgrade configuration (Laravel 13)
├── phpstan.neon.dist            # PHPStan level 8 configuration
├── src/
│   ├── Actions/
│   │   └── WebhookAction.php   # Triggerable: HTTP POST webhook dispatch
│   ├── Console/                 # 12 Artisan commands
│   ├── Contracts/
│   │   ├── ConditionEngineContract.php
│   │   └── Triggerable.php
│   ├── Concerns/
│   │   ├── EscapesWildcardLike.php
│   │   ├── GetsWebhookTimeout.php
│   │   ├── ManagesHistory.php
│   │   └── ManagesSubscriptions.php
│   ├── Domain/
│   │   └── DomainEvent.php      # Event sourcing value object
│   ├── Facades/
│   │   └── EventManager.php    # Laravel facade
│   ├── Jobs/
│   │   └── DispatchTriggerJob.php
│   ├── Models/
│   │   ├── EventLog.php
│   │   ├── Subscription.php
│   │   └── Trigger.php
│   ├── ActionResolver.php
│   ├── ConditionEngine.php
│   ├── EventManager.php        # Central orchestrator
│   ├── EventScheduler.php      # Automated log purge & subscription cleanup
│   ├── EventsServiceProvider.php
│   ├── SubscriptionBuilder.php
│   ├── TriggerBuilder.php
│   └── WildcardMatcher.php
└── tests/                      # 211 test files (Pest + support)
```

### How It Works

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

### Service Container Bindings

| Service | Binding | Lifetime |
|---|---|---|
| `EventManager` | Singleton | Shared across app |
| `ConditionEngine` | Singleton | Shared across app |
| `ConditionEngineContract` | Singleton → `ConditionEngine` | Interface binding |
| `ActionResolver` | Singleton | Shared across app |
| `TriggerBuilder` | Transient | Fresh instance per resolution |
| `SubscriptionBuilder` | Transient | Fresh instance per resolution |
| `EventScheduler` | Singleton | Registers scheduled tasks for log purge & subscription cleanup |
| `EventManager` (Facade) | `getFacadeAccessor()` → `EventManager::class` | Resolved from container |

### Performance Optimizations

- **Wildcard cache** — Enabled wildcard triggers are cached for 5 minutes, avoiding a DB query on every `fire()` call.
- **Exact-match fast path** — Non-wildcard events skip the cache entirely and query directly (indexed, fast).
- **No orphaned logs** — Async jobs create their `EventLog` inside the job handler, so queue failures don't leave orphaned entries.
- **O(1) dedup** — Trigger deduplication uses a hash set instead of O(n) linear scans.
- **Cache invalidation** — The wildcard cache is automatically invalidated on trigger create, enable, and disable operations.

### PHP 8.5 Compatibility

This package targets PHP 8.5+ and leverages modern PHP features:

- **`#[\Override]` attribute** — Applied to all method overrides (`register()`, `boot()`, `provides()`, `handle()`, `getTable()`, `casts()`, `newFactory()`, `boot()`, `getFacadeAccessor()`, `scopeEnabled()`, etc.) for compile-time override verification.
- **`#[\Pure]` attribute** — Applied to side-effect-free methods in `ConditionEngine` (`strictEquals()`, `getNestedValue()`, `contains()`, `between()`) and `WildcardMatcher` (`matches()`, `findMatchingPatterns()`, `extractWildcards()`) for improved static analysis and memoization safety. Note: `evaluateCondition()` is intentionally not `#[\Pure]` because it may call `safeRegexMatch()` which temporarily modifies `pcre.backtrack_limit`.
- **`readonly` classes** — `WildcardMatcher` is declared as a `readonly final class` with only static methods.
- **`readonly` promoted properties** — `EventManager`, `ActionResolver`, `EventScheduler`, `TriggerBuilder`, `SubscriptionBuilder`, `DomainEvent`, and `DispatchTriggerJob` use constructor-promoted `readonly` properties for immutability.
- **`final` classes** — All service classes, commands, and models are declared `final` to prevent unsafe inheritance.
- **Typed properties** — Every class property has an explicit type declaration.
- **Return type declarations** — Every method has an explicit return type (`void`, `bool`, `int`, `string`, `array`, `Collection`, etc.).
- **Strict types** — All source files use `declare(strict_types=1)`.
- **No `setAccessible()` calls** — Removed all deprecated `ReflectionMethod::setAccessible(true)` / `ReflectionProperty::setAccessible(true)` calls (deprecated in PHP 8.1, removed in PHP 8.5). All reflection-based tests use native visibility.

### Database Schema

#### `triggers` Table

| Column | Type | Description |
|---|---|---|
| `id` | UUID (primary) | Unique trigger identifier |
| `name` | string | Human-readable trigger name |
| `event` | string | Event pattern (supports `*` and `**` wildcards) |
| `action` | text | JSON-encoded action class(es) with optional params |
| `conditions` | JSON (nullable) | Condition rules evaluated against the payload |
| `async` | boolean | Whether to dispatch via queue |
| `priority` | unsigned int | Execution priority (higher = first) |
| `enabled` | boolean | Whether the trigger is active |
| `created_at` / `updated_at` | timestamp | |
| `deleted_at` | timestamp (nullable) | Soft delete |

**Indexes:** `(event, enabled)`, `priority`

#### `event_logs` Table

| Column | Type | Description |
|---|---|---|
| `id` | UUID (primary) | Unique log entry identifier |
| `trigger_id` | UUID (FK → triggers) | Parent trigger reference (cascade delete) |
| `event` | string | The fired event name |
| `payload` | JSON | Event payload data |
| `status` | enum | `pending`, `dispatched`, `completed`, `failed` |
| `error` | text (nullable) | Error message on failure |
| `duration_ms` | unsigned int (nullable) | Execution duration in milliseconds |
| `created_at` / `updated_at` | timestamp | |
| `deleted_at` | timestamp (nullable) | Soft delete |

**Indexes:** `(trigger_id, status)`, `event`, `created_at`

#### `event_subscriptions` Table

| Column | Type | Description |
|---|---|---|
| `id` | UUID (primary) | Unique subscription identifier |
| `event` | string | Subscribed event pattern (supports wildcards) |
| `url` | string | Webhook delivery endpoint |
| `conditions` | JSON (nullable) | Condition filters for selective delivery |
| `priority` | unsigned int | Delivery priority (higher = first) |
| `active` | boolean | Whether the subscription is active |
| `secret` | string (nullable) | HMAC signing secret for payload verification |
| `last_fired_at` | timestamp (nullable) | Last successful delivery timestamp |
| `failure_count` | unsigned int | Consecutive delivery failures |
| `delivery_count` | unsigned int | Total successful deliveries |
| `created_at` / `updated_at` | timestamp | |
| `deleted_at` | timestamp (nullable) | Soft delete |

**Indexes:** `(event, active)`, `url`

## Advanced Topics

### Event Sourcing with DomainEvent

The `DomainEvent` class enables event sourcing patterns. Each event is an immutable value object with a UUID, timestamp, type, and payload:

```php
use ZeroBoiler\Events\Domain\DomainEvent;

// Record a domain event
$event = DomainEvent::occur('order.created', [
    'order_id' => 'uuid-123',
    'customer_id' => 'uuid-456',
    'total' => 99.99,
]);

// Persist the event (e.g., to an event store table)
DB::table('event_store')->insert($event->toArray());

// Replay events — preserves original UUID and timestamp
$stored = DB::table('event_store')->where('event_id', $event->eventId->toString())->first();
$reconstructed = DomainEvent::fromArray((array) $stored);
// $reconstructed->eventId === $event->eventId (same UUID)
// $reconstructed->occurredAt === $event->occurredAt (same timestamp)
```

**Key design decisions:**
- `eventId` and `occurredAt` are preserved during reconstruction to maintain event identity across replays
- Invalid UUIDs or dates in `fromArray()` silently fall back to fresh values (non-throwing for resilience)
- `eventType` is required — `fromArray()` throws `InvalidArgumentException` if missing

### Custom Triggerable Actions

Create custom action handlers by implementing the `Triggerable` interface:

```php
use ZeroBoiler\Events\Contracts\Triggerable;

final class SendSlackNotification implements Triggerable
{
    public function __construct(
        private readonly SlackClient $client,
    ) {}

    public function handle(array $payload): void
    {
        $channel = $payload['channel'] ?? '#general';
        $message = $payload['message'] ?? 'No message';

        $this->client->sendMessage($channel, $message);
    }
}
```

Register the action:

```php
EventManager::on('alert.critical')
    ->action(SendSlackNotification::class)
    ->when(['severity' => ['>=', 8]])
    ->async()
    ->save();
```

### Testing Strategies

#### Unit Testing Trigger Conditions

```php
use ZeroBoiler\Events\ConditionEngine;

$engine = new ConditionEngine();

// Test complex conditions
$payload = ['amount' => 150, 'status' => 'active', 'tags' => ['urgent', 'billing']];
$conditions = [
    'amount' => ['>', 100],
    'status' => 'in', ['active', 'pending'],
    'tags' => ['contains', 'urgent'],
];

expect($engine->matches($conditions, $payload))->toBeTrue();
```

#### Testing with Fakes

The package does not include a built-in `fake()` method. To prevent actual dispatch during tests, use one of these strategies:

```php
// Strategy 1: Disable the event system globally in tests
EventManager::setEnabled(false);

// Strategy 2: Use SQLite :memory: and assert on event_logs
EventManager::fire('test.event', ['key' => 'value']);
$this->assertDatabaseHas('event_logs', [
    'event' => 'test.event',
    'status' => 'completed',
]);

// Strategy 3: Mock the ActionResolver to intercept dispatch
$mockResolver = Mockery::mock(ActionResolver::class);
$mockResolver->shouldReceive('resolve')->andReturn(new NullAction());
$this->app->instance(ActionResolver::class, $mockResolver);
```

#### Integration Testing with SQLite

```php
// phpunit.xml — use :memory: for fast tests
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>

// In your test
EventManager::on('test.event')
    ->action(TestAction::class)
    ->save();

EventManager::fire('test.event', ['key' => 'value']);

$this->assertDatabaseHas('event_logs', [
    'event' => 'test.event',
    'status' => 'completed',
]);
```

### Performance Considerations

- **Wildcard caching**: Enabled wildcard triggers are cached for `events.wildcard_cache_ttl` seconds (default: 300s). Set to `0` to disable.
- **Cache invalidation**: Automatically triggered on trigger create, enable, and disable operations. Call `invalidateTriggerCache()` manually after direct DB edits.
- **Queue tuning**: Set `EVENTS_QUEUE_CONNECTION` to a dedicated Redis connection for high-throughput scenarios.
- **Index usage**: The `triggers` table has a composite index on `(event, enabled)` for fast exact-match lookups.
- **No orphaned logs**: Async jobs create `EventLog` entries inside the job handler, preventing orphaned records if the queue fails.

## Security Considerations

### HMAC Webhook Signing
- All webhook payloads are signed with HMAC using the subscription's secret. The algorithm defaults to `sha256` but can be changed via `events.subscriptions.signature_algorithm`.
- Secrets are auto-generated as `whsec_` + 32 random characters when not explicitly provided. Set `events.subscriptions.auto_generate_secret` to `false` to disable auto-generation.
- Secrets are hidden from serialization (`$hidden = ['secret', 'deleted_at']`).

### ReDoS Protection
- The `matches` (regex) operator rejects patterns longer than 500 characters and patterns with nested quantifiers (e.g., `(a+)+`).
- PCRE backtrack limit is temporarily reduced to 1000 during regex evaluation.

### SQL Injection Prevention
- Event wildcard patterns are properly escaped before being used in SQL LIKE queries (`%`, `_`, `\` characters).
- All database queries use Eloquent's parameterized query builder.

### Action Resolution
- Only classes that implement `Triggerable` can be dispatched. Non-existent classes or non-implementing classes are rejected with an `InvalidArgumentException`.

### Rate Limiting & Abuse
- The package does **not** include built-in rate limiting. Use the [zeroboiler/security](../security) package or Laravel's built-in rate limiting middleware to protect webhook endpoints.

### Webhook URL Scheme Enforcement
- `SubscriptionBuilder::save()` rejects non-HTTP(S) URL schemes (e.g., `ftp://`, `file://`, `mailto:`) to prevent SSRF-like abuse. Only `http://` and `https://` URLs are accepted for webhook subscriptions.

## Troubleshooting

| Issue | Cause | Solution |
|---|---|---|
| Triggers not firing | Trigger is disabled | Check `enabled` column; use `zeroboiler:events:list --enabled` |
| Wildcard triggers not matching | Cache stale after manual DB edit | Run `EventManager::invalidateTriggerCache()` or re-save via API |
| Webhook returns 401 | Missing signature header | Verify subscription has a secret and it hasn't been rotated |
| Subscription auto-deactivated | Failure threshold exceeded | Check `failure_count` vs `events.subscriptions.max_failures`; reset with `resetFailures()` |
| Queue jobs stuck | Queue worker not running | Ensure `php artisan queue:work` is running; check `events.queue.connection` config |
| `ActionResolver` throws | Action class not found or not Triggerable | Verify the class exists, is autoloaded, and implements `Triggerable` |
| EventLog entries with no trigger | Trigger deleted after fire | EventLog references trigger via FK — use soft deletes to preserve referential integrity |
| Regex condition always false | Pattern exceeds 500 chars or has nested quantifiers | Simplify the regex or increase `ConditionEngine::MAX_REGEX_LENGTH` |
| Events not firing globally | `events.disabled` is `true` | Check `EVENTS_DISABLED` env var or call `EventManager::setEnabled(true)` |

## Production Deployment Checklist

Before deploying to production, verify:

- [ ] **Migrations run**: `php artisan migrate` — all 3 tables created (`triggers`, `event_logs`, `event_subscriptions`)
- [ ] **Config published**: `php artisan vendor:publish --tag=events-config` — verify `config/events.php` exists
- [ ] **Queue worker running**: `php artisan queue:work` — required for async triggers
- [ ] **Queue connection configured**: Set `EVENTS_QUEUE_CONNECTION` if not using the default
- [ ] **Cache driver configured**: Wildcard trigger caching requires a working cache driver
- [ ] **Webhook secrets reviewed**: Set `EVENTS_SUB_MAX_FAILURES` for auto-deactivation threshold
- [ ] **Log retention configured**: Set `EVENTS_LOG_RETENTION_DAYS` to match your compliance requirements
- [ ] **Rate limiting**: Protect webhook endpoints with the `zeroboiler/security` package or Laravel middleware
- [ ] **Health check**: Run `php artisan zeroboiler:events:health --check-cache` to verify all systems operational

## API Reference

### EventManager (Facade)

| Method | Returns | Description |
|--------|---------|-------------|
| `on(string $event)` | `TriggerBuilder` | Start building a new trigger |
| `register(string $event)` | `TriggerBuilder` | Alias for `on()` |
| `fire(string $event, array $payload, bool $async)` | `void` | Fire an event with optional payload; force async with `$async = true` |
| `fireModel(string $modelClass, string $action, object $model)` | `void` | Fire a model event (flattens attributes) |
| `enable(string $triggerId)` | `bool` | Enable a trigger by ID |
| `disable(string $triggerId)` | `bool` | Disable a trigger by ID |
| `invalidateTriggerCache()` | `void` | Clear the wildcard trigger cache |
| `isDisabled()` | `bool` | Check if the event system is globally disabled |
| `setEnabled(bool $enabled)` | `void` | Enable or disable the event system at runtime |
| `listTriggers(?string $event, ?bool $enabled, int $limit)` | `Collection` | List triggers with optional filtering |
| `getTrigger(string $triggerId)` | `Trigger\|null` | Get a trigger by ID |
| `deleteTrigger(string $triggerId)` | `bool` | Delete a trigger by ID (invalidates cache) |
| `subscribe(string $event, string $url)` | `SubscriptionBuilder` | Start building a webhook subscription |
| `unsubscribe(string $subscriptionId)` | `bool` | Remove a subscription by ID |
| `listSubscriptions(?string $event, bool $activeOnly)` | `Collection` | List subscriptions with optional filtering |
| `getSubscription(string $id)` | `Subscription\|null` | Get a subscription by ID |
| `subscribeWebhook(string $event, string $url, array $conditions, int $priority)` | `string` | Quick-create a trigger that dispatches a WebhookAction (no Subscription record — use `subscribe()` for full webhook subscriptions with HMAC signing) |
| `getEventHistory(?string $event, ?string $status, ?string $triggerId, int $limit)` | `Collection` | Query event log history |
| `getStats(?Carbon $since)` | `array` | Get aggregate statistics |
| `purgeLogs(Carbon $before, bool $includePending)` | `int` | Purge old event logs |
| `getStalePendingLogs(Carbon $before, int $limit)` | `Collection` | Get stuck pending logs older than threshold |
| `deactivateExceededSubscriptions()` | `int` | Deactivate all subscriptions that exceeded failure threshold |
| `executeTrigger(Trigger $trigger, EventLog $log)` | `void` | Execute a trigger synchronously (throws on failure) |

### TriggerBuilder

| Method | Returns | Description |
|--------|---------|-------------|
| `name(string $name)` | `self` | Set trigger display name |
| `on(string $event)` | `self` | Set event name (called internally by `EventManager::on()`) |
| `action(string $class)` | `self` | Set single action handler class |
| `actions(array $classes)` | `self` | Set multiple action handler classes |
| `when(array $conditions)` | `self` | Set condition filters |
| `async(bool $async)` | `self` | Set async dispatch mode |
| `priority(int $priority)` | `self` | Set priority (higher = first) |
| `actionParams(array $params)` | `self` | Set action parameters (e.g., webhook URL) |
| `save()` | `Trigger` | Persist trigger to database |

### SubscriptionBuilder

| Method | Returns | Description |
|--------|---------|-------------|
| `on(string $event)` | `self` | Set event name (called internally by `subscribe()`) |
| `to(string $url)` | `self` | Set webhook endpoint URL |
| `withSecret(string $secret)` | `self` | Set HMAC signing secret |
| `withFilter(array $conditions)` | `self` | Set condition filters |
| `priority(int $priority)` | `self` | Set subscription priority |
| `async(bool $async)` | `self` | Set async delivery mode |
| `save()` | `Subscription` | Persist subscription and register internal trigger |

### EventScheduler

| Method | Returns | Description |
|--------|---------|-------------|
| `register(Schedule $schedule)` | `void` | Register all scheduled tasks (log purge + subscription cleanup) |

### DomainEvent

| Method | Returns | Description |
|--------|---------|-------------|
| `occur(string $type, array $payload)` | `self` | Factory: create a new domain event |
| `toArray()` | `array` | Serialize to array |
| `fromArray(array $data)` | `self` | Reconstruct from array (preserves UUID/timestamp) |

### ConditionEngine Operators

| Operator | Syntax | Description |
|----------|--------|-------------|
| `>`, `>=`, `<`, `<=` | `['amount', ['>', 100]]` | Numeric comparison (null-safe; both operands must be non-null) |
| `=`, `===` | `['status', 'paid']` / `['flag', ['===', true]]` | Equality / strict equality |
| `!=`, `!==` | `['status', ['!=', 'draft']]` | Inequality / strict inequality |
| `in` | `['role', ['in', ['admin', 'mod']]]` | Value in array |
| `not_in` | `['role', ['not_in', ['guest']]]` | Value not in array |
| `contains` | `['tags', ['contains', 'urgent']]` | String contains / array membership |
| `not_contains` | `['tags', ['not_contains', 'spam']]` | Negated contains |
| `between` | `['age', ['between', [18, 65]]]` | Inclusive range (auto-normalizes inverted) |
| `null` | `['deleted_at', ['null']]` | Value is null |
| `not_null` | `['email', ['not_null']]` | Value is not null |
| `empty` | `['notes', ['empty']]` | Value is empty (null-safe) |
| `not_empty` | `['notes', ['not_empty']]` | Value is not empty |
| `starts_with` | `['email', ['starts_with', 'admin@']]` | String prefix |
| `ends_with` | `['domain', ['ends_with', '.com']]` | String suffix |
| `matches` | `['code', ['matches', '/^[A-Z]{3}$/']]` | Regex match (ReDoS-protected) |

## Testing

```bash
composer test        # Run Pest test suite (211 test files)
composer analyse     # PHPStan level 8 (uses phpstan.neon.dist; PHPStan 2.x)
composer lint        # Laravel Pint
composer rector      # Rector code upgrades
composer ci          # All checks (lint → analyse → rector → test)
```

Test coverage spans:
- Core EventManager (fire, match, dispatch, CRUD, cache, async)
- ConditionEngine (19 operators + AND logic + dot notation + ReDoS protection)
- WildcardMatcher (single-segment, cross-segment, catch-all, extraction)
- TriggerBuilder / SubscriptionBuilder (fluent interface, validation, action merging)
- ActionResolver (class resolution, error handling)
- DomainEvent (serialization, reconstruction, immutability)
- WebhookAction (HMAC signing, payload stripping, failure tracking, auto-deactivation)
- DispatchTriggerJob (config-driven retry/backoff/queue)
- Event history, statistics, and log purging
- All 12 console commands
- Service provider bindings, config completeness, migrations, factories
- EventScheduler registration and cron configuration

## Changelog

### v4.56.0

- Added: `EventsPhase130ProductionReadinessTest.php` — 25+ tests covering: EventManager::registerScheduler facade path, subscribeWebhook creates trigger only (not Subscription), Subscription::signPayload edge cases (null/empty secret, sha512 algorithm), listSubscriptions ordering and wildcard filtering, EscapesWildcardLike via Subscription scopeForEvent, DomainEvent roundtrip identity, DispatchTriggerJob config-driven backoff (comma-separated and array formats), ServiceProvider provides() completeness, Subscription::matchesEvent edge cases (exact/wildcard/cross-segment), WildcardMatcher::findMatchingPatterns, ManagesHistory::getStats with empty database.
- Fixed: README test file count corrected from 215 to 211.
- Fixed: README package structure tree — added `rector.php` and `phpstan.neon.dist` entries.
- Fixed: README `subscribeWebhook` API reference clarified — creates a trigger only, not a Subscription record.
- Fixed: README `EVENTS_RETRY_BACKOFF` env var documentation — added JSON array format support.
- Improved: Added `@property-read` annotation for `$app` on EventManager class-level docblock.
- Improved: Added `@see` annotations on ManagesHistory and ManagesSubscriptions traits linking to EventManager.
- Improved: DispatchTriggerJob public property docblocks enhanced with config key references.
- Bumped: Version 4.56.0.

### v4.55.0

- Added: `EventsPhase129ProductionAuditTest.php` — 50+ tests covering: ConditionEngine empty/unknown/null operator edge cases, numeric comparison null/non-numeric guards, string operator non-string guards, not_contains/not_in/not_empty operators, WildcardMatcher cross-segment/multi-segment/empty-event edge cases, extractWildcards multiple-wildcard extraction, DomainEvent fromArray edge cases (invalid UUID, invalid date, non-string eventId, non-array payload, extra fields, numeric eventType), EventLog auto-UUID/preserve-UUID/markAsCompleted/markAsFailed, Trigger model scopes, fire validation edge cases (zero-string, empty action), strictEquals cross-type and array edge cases, config key completeness verification.
- Updated: README test file count to 215.
- Bumped: Version 4.55.0.

### v4.54.0

- Refactored: `EventScheduler` now uses `resolveEventManager()` consistently in both `registerLogPurge()` and `registerSubscriptionCleanup()`, eliminating duplicated container resolution logic.
- Fixed: README "Testing with Fakes" section — removed reference to non-existent `EventManager::fake()` method; replaced with practical testing strategies (global disable, SQLite assertions, ActionResolver mock).

### v4.53.0

- Improved: README test file counts updated to 206 (matching actual file count on disk).
- Improved: Changelog streamlined — older entries consolidated to reduce noise. Detailed history available in version control.
- Added: `EventsPhase126ProductionAuditTest.php` — 35+ tests covering: ConditionEngine type coercion edge cases (float vs int, cross-type, array vs scalar, deeply nested dot notation, inverted between, invalid regex, long pattern), WildcardMatcher edge cases (exact match, empty pattern, regex special chars, findMatchingPatterns, extractWildcards with multiple wildcards), DomainEvent edge cases (missing/invalid UUID/date, extra fields, roundtrip identity, readonly property access), EventManager cache invalidation, global disable toggle, fire validation (empty/zero-string event, empty model class/action), Subscription edge cases (null/empty secret signing, hasExceededFailures config default, recordDelivery, resetFailures), DispatchTriggerJob constructor config handling (array backoff, missing config defaults).

### v4.52.0

- Added: Comprehensive PHPStan 2.x config fixes, 60+ audit tests.
- Fixed: `sortBy` compatibility for PHPStan level 8.

### v4.49.0 – v4.45.0

- Fixed: PHPStan configuration level corrected from `9` to `8` (PHPStan 2.x only supports levels 0–8).
- Fixed: Deprecated `$created_at->timestamp` property replaced with `getTimestamp()` method.
- Fixed: Removed `#[\Pure]` from `ConditionEngine::evaluateCondition()` (side-effect via `ini_set`).
- Added: 80+ production audit test files covering all aspects of the codebase.

### v4.30.0 – v4.44.0

- Removed: All deprecated `setAccessible()` calls from 43 test files (removed in PHP 8.5).
- Removed: Unused composer dependencies.
- Added: `#[\Pure]` attributes on pure methods, `@internal` annotations on protected methods.
- Added: `GetsWebhookTimeout` trait extracted from `WebhookAction` and `EventsRedeliverCommand`.
- Added: `EventScheduler` with constructor injection (no global helpers).
- Added: Factory `$model` properties updated to `static string` for Laravel 13+.
- Added: Full lifecycle integration tests.
- Added: `EventsFullLifecycleIntegrationTest.php`, `EventsPhase94ProductionAuditTest.php`.

### v4.15.0

- Added: `EventScheduler` — automated scheduled tasks for log retention and subscription cleanup.
- Added: `events.retention.schedule_cron` and `events.subscriptions.cleanup_cron` config options.
- Added: `EventManager::registerScheduler($schedule)` convenience method.

### v4.9.0

- Fixed: `EventManager::getConfig()` and `on()` — replaced `assert()` with proper `instanceof` + `RuntimeException`.

### v4.6.0

- Production readiness consolidation — PHPStan level 8, strict types, final classes, readonly properties.

### v4.0.0

- Initial production-ready release.

### Earlier versions

See git history for detailed changelog.

## Contributing

This is a private package. Contribution guidelines:

1. **Code style**: Follow PSR-12. Run `composer lint` (Laravel Pint) before committing.
2. **Static analysis**: Run `composer analyse` (PHPStan level 8). Zero errors allowed.
3. **Tests**: Run `composer test` (Pest). All tests must pass. Add tests for new features.
4. **Rector**: Run `composer rector` to apply automated code improvements.
5. **Full CI**: Run `composer ci` to execute all checks in order.
6. **Commit format**: `feat/fix/refactor: description` (conventional commit prefix).
7. **PHP version**: Target PHP 8.5+. Use strict types (`declare(strict_types=1)`), typed properties, and return type declarations on all methods.

## License

Proprietary — see LICENSE file for details.
