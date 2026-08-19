# ZeroBoiler Events

![Latest Version](https://img.shields.io/badge/version-5.98.0-blue)
![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)
![Laravel](https://img.shields.io/badge/Laravel-13.x-red)
![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209%20(2.x)-success)
![Tests: 396](https://img.shields.io/badge/Tests-396-brightgreen)
![CI](https://github.com/zeroboiler/events/actions/workflows/ci.yml/badge.svg)

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
- [Security Considerations](#security-considerations)
- [Limitations](#limitations)
- [Troubleshooting](#troubleshooting)
- [Production Deployment Checklist](#production-deployment-checklist)
- [API Reference](#api-reference)
- [Testing](#testing)
- [Production Readiness Summary](#production-readiness-summary)
- [Changelog](#changelog)
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
- **zeroboiler/security** — Rate limiting for webhook endpoints, HMAC verification middleware, API key authentication
- **zeroboiler/observability** — Structured logging, metrics (Prometheus/StatsD), distributed tracing (OpenTelemetry)
- **zeroboiler/config** — Centralized config management with caching, cascading overrides, and validation
- **zeroboiler/errors** — Structured error handling, automated reporting, error classification
- **zeroboiler/module** — Module registration, discovery, and dependency resolution
- **zeroboiler/i18n** — Internationalization with locale management, translation caching, and pluralization
- **zeroboiler/search** — Full-text search abstraction with driver support (Algolia, Meilisearch, Elasticsearch)

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
- **Condition Engine** — Rich condition operators: `>`, `<`, `>=`, `<=`, `=`, `!=`, `in`, `not_in`, `contains`, `not_contains`, `between`, `null`, `not_null`, `empty`, `not_empty`, `starts_with`, `ends_with`, `matches` (regex with ReDoS protection), and nested dot-notation fields.
- **Sync & Async Dispatch** — Execute triggers synchronously or queue them for async processing with configurable retry and backoff. Force async mode with `fire($event, $payload, async: true)` or `--async` CLI flag.
- **Webhook Subscriptions** — Subscribe external systems to events with HMAC-SHA256 payload signing, failure tracking, and auto-deactivation.
- **Domain Events** — First-class `DomainEvent` value object for event sourcing patterns with UUID, timestamp, serialization, and reconstruction.
- **Event History & Stats** — Query event logs, filter by event/status/trigger, and get aggregate statistics (success rates, avg duration, top events).
- **Log Retention** — Configurable automatic purge of old event logs.
- **CLI Commands** — 12 Artisan commands for managing triggers, subscriptions, event logs, and a health check diagnostic for ops monitoring.

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
        'secret_length' => env('EVENTS_SUB_SECRET_LENGTH', 32),
        'max_failures' => env('EVENTS_SUB_MAX_FAILURES', 10),
        'timeout' => env('EVENTS_SUB_TIMEOUT', 30),
        'signature_algorithm' => env('EVENTS_SUB_SIGNATURE_ALGORITHM', 'sha256'),
        'cleanup_cron' => env('EVENTS_SUB_CLEANUP_CRON', '0 3 * * *'),
    ],

    'disabled' => env('EVENTS_DISABLED') === 'true' || env('EVENTS_DISABLED') === '1' || env('EVENTS_DISABLED') === true,

    'payload_max_bytes' => (int) env('EVENTS_PAYLOAD_MAX_BYTES', 1048576),

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
| `EVENTS_SUB_SECRET_LENGTH` | `32` | Length of auto-generated webhook secrets (minimum 16) |
| `EVENTS_SUB_CLEANUP_CRON` | `0 3 * * *` | Cron expression for automatic subscription cleanup schedule |
| `EVENTS_PAYLOAD_MAX_BYTES` | `1048576` | Maximum JSON-encoded payload size in bytes for `fire()`; set to `0` to disable |
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
if (EventManager::isDisabled()) {
    // ...
}
EventManager::setEnabled(false);  // In-memory only
EventManager::setEnabled(true);   // Re-enable
```

Or via environment variable (persistent across requests):

```env
EVENTS_DISABLED=true
```

When disabled, all `fire()` calls silently return without dispatching any triggers.

## CLI Commands

| Command | Description |
|---|---|
| `zeroboiler:events:list` | List triggers with optional filtering (`--event`, `--enabled`, `--disabled`, `--per-page`, `--page`) |
| `zeroboiler:events:fire {event}` | Manually fire an event (`--async`, `--json`, `--payload=*`) |
| `zeroboiler:events:register` | Register a new trigger (`--name`, `--async`, `--priority`) |
| `zeroboiler:events:enable {id}` | Enable a trigger |
| `zeroboiler:events:disable {id}` | Disable a trigger |
| `zeroboiler:events:retry` | Retry failed or pending event dispatches (`--status=failed|pending`) |
| `zeroboiler:events:redeliver {logId}` | Redeliver a failed/completed webhook (`--force`) |
| `zeroboiler:events:log` | View event logs (`--event`, `--status`, `--trigger`, `--limit`) |
| `zeroboiler:events:subscribe` | Create a webhook subscription (`--secret`, `--filter`, `--priority`, `--async`) |
| `zeroboiler:events:unsubscribe {id}` | Remove a webhook subscription |
| `zeroboiler:events:subscriptions` | List webhook subscriptions (`--event`, `--active`, `--inactive`, `--per-page`, `--page`) |
| `zeroboiler:events:health` | Diagnostic health check (`--json`, `--check-cache`) |

### Scheduled Tasks

Register the scheduler via the `EventManager` facade (recommended):

```php
// app/Console/Kernel.php
use Illuminate\Support\Facades\Schedule;
use ZeroBoiler\Events\Facades\EventManager;

protected function schedule(Schedule $schedule): void
{
    EventManager::registerScheduler($schedule);
}
```

Or resolve the `EventScheduler` directly:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;
use ZeroBoiler\Events\EventScheduler;

app(EventScheduler::class)->register(app(Schedule::class));
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
├── config/events.php
├── database/
│   ├── factories/          (3 factories)
│   └── migrations/         (3 migrations)
├── src/
│   ├── Actions/            WebhookAction
│   ├── Console/            12 Artisan commands
│   ├── Contracts/          ConditionEngineContract, Triggerable
│   ├── Concerns/           EscapesWildcardLike, GetsWebhookTimeout,
│   │                       ManagesHistory, ManagesSubscriptions
│   ├── Domain/             DomainEvent
│   ├── Exceptions/         EventException + 4 leaf exceptions
│   ├── Facades/            EventManager
│   ├── Jobs/               DispatchTriggerJob
│   ├── Models/             Trigger, EventLog, Subscription
│   ├── ActionResolver.php
│   ├── ConditionEngine.php
│   ├── EventManager.php
│   ├── EventScheduler.php
│   ├── EventsServiceProvider.php
│   ├── SubscriptionBuilder.php
│   ├── TriggerBuilder.php
│   └── WildcardMatcher.php
└── tests/                  396 test files
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
| `EventScheduler` | Singleton | Scheduled tasks for log purge & cleanup |
| `EventManager` (Facade) | `getFacadeAccessor()` → `EventManager::class` | Resolved from container |

### Performance Optimizations

- **Wildcard cache** — Enabled wildcard triggers are cached for 5 minutes (configurable), avoiding a DB query on every `fire()` call.
- **Exact-match fast path** — Non-wildcard events skip the cache entirely and query directly (indexed, fast).
- **No orphaned logs** — Async jobs create their `EventLog` inside the job handler, so queue failures don't leave orphaned entries.
- **O(1) dedup** — Trigger deduplication uses a hash set instead of O(n) linear scans.
- **Cache invalidation** — Automatically triggered on trigger create, enable, and disable operations.
- **Payload size guard** — `fire()` rejects payloads exceeding the configurable `events.payload_max_bytes` limit (default: 1 MB; set to `0` to disable). Non-encodable payloads (containing NaN/Inf) throw `InvalidArgumentException` before any DB/queue operations.

### PHP 8.5 Compatibility

- **`#[\Override]` attribute** — Applied to all method overrides for compile-time verification.
- **`#[\Pure]` attribute** — Applied to side-effect-free methods in `ConditionEngine` and `WildcardMatcher`.
- **`readonly` classes** — `WildcardMatcher` is a `readonly final class` with only static methods.
- **`readonly` promoted properties** — Used across `EventManager`, `ActionResolver`, `EventScheduler`, `TriggerBuilder`, `SubscriptionBuilder`, `DomainEvent`, and `DispatchTriggerJob`.
- **`final` classes** — All service classes, commands, models, and leaf exceptions are `final`. The `EventException` base class is intentionally non-final to allow extension.
- **Typed properties** — Every class property has an explicit type declaration.
- **Return type declarations** — Every method has an explicit return type.
- **Strict types** — All source files use `declare(strict_types=1)`.

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
| `trigger_id` | UUID (FK → triggers) | Parent trigger reference |
| `event` | string | The fired event name |
| `payload` | JSON | Event payload data |
| `status` | string | `pending`, `dispatched`, `completed`, `failed` |
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
| `secret` | string (nullable) | HMAC signing secret |
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

$event = DomainEvent::occur('order.created', [
    'order_id' => 'uuid-123',
    'customer_id' => 'uuid-456',
    'total' => 99.99,
]);

// Persist (e.g., to an event store table)
DB::table('event_store')->insert($event->toArray());

// Replay — preserves original UUID and timestamp
$stored = DB::table('event_store')->where('event_id', $event->eventId->toString())->first();
$reconstructed = DomainEvent::fromArray((array) $stored);
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

### Exception Hierarchy

```
RuntimeException
└── ZeroBoiler\Events\Exceptions\EventException          (base)
    ├── ActionResolutionException    (class not found / not Triggerable)
    ├── ConditionEvaluationException (unrecoverable condition error)
    ├── SubscriptionException        (webhook subscription failure)
    └── TriggerNotFoundException      (trigger lookup failed)
```

Catch the base `EventException` for blanket error handling, or catch specific leaf exceptions for targeted handling. Action resolution failures in synchronous triggers re-throw after logging, so callers can `try/catch` around `fire()` or `executeTrigger()`.

### Testing Strategies

```php
// Strategy 1: Disable the event system globally in tests
EventManager::setEnabled(false);

// Strategy 2: Use SQLite :memory: and assert on event_logs
EventManager::fire('test.event', ['key' => 'value']);
$this->assertDatabaseHas('event_logs', [
    'event' => 'test.event',
    'status' => 'completed',
]);

// Strategy 3: Mock the ActionResolver
$mockResolver = Mockery::mock(ActionResolver::class);
$mockResolver->shouldReceive('resolve')->andReturn(new NullAction());
$this->app->instance(ActionResolver::class, $mockResolver);
```

### Performance Considerations

- **Wildcard caching**: Enabled wildcard triggers are cached for `events.wildcard_cache_ttl` seconds (default: 300s). Set to `0` to disable.
- **Cache invalidation**: Automatically triggered on trigger create, enable, and disable operations. Call `invalidateTriggerCache()` manually after direct DB edits.
- **Queue tuning**: Set `EVENTS_QUEUE_CONNECTION` to a dedicated Redis connection for high-throughput scenarios.
- **Index usage**: The `triggers` table has a composite index on `(event, enabled)` for fast exact-match lookups.
- **No orphaned logs**: Async jobs create `EventLog` entries inside the job handler, preventing orphaned records if the queue fails.

### Error Handling Patterns

**Synchronous triggers:** Failed action handlers throw the original exception after logging the error and marking the `EventLog` as `failed`. Callers can `try/catch` around `fire()`.

**Asynchronous triggers:** Failed jobs are retried according to `events.retry.tries` and `events.retry.backoff`. After all retries are exhausted, `DispatchTriggerJob::failed()` marks the `EventLog` as `failed`. Use `zeroboiler:events:retry --status=failed` to manually re-dispatch.

**Webhook subscriptions:** Non-2xx responses increment `failure_count`. After exceeding `events.subscriptions.max_failures` (default: 10), the subscription is auto-deactivated. Use `zeroboiler:events:redeliver {logId}` to manually retry a failed webhook delivery.

## Security Considerations

### HMAC Webhook Signing
- All webhook payloads are signed with HMAC using the subscription's secret. Configurable algorithm via `events.subscriptions.signature_algorithm`.
- Secrets are auto-generated as `whsec_` + 32 random characters. Set `events.subscriptions.auto_generate_secret` to `false` to disable.
- Secrets are hidden from serialization (`$hidden = ['secret', 'deleted_at']`).

### ReDoS Protection
- The `matches` operator rejects patterns longer than 500 characters and patterns with nested quantifiers (e.g., `(a+)+`).
- PCRE backtrack limit is temporarily reduced to 1000 during regex evaluation.

### SQL Injection Prevention
- Event wildcard patterns are properly escaped before being used in SQL LIKE queries (`%`, `_`, `\` characters).
- All database queries use Eloquent's parameterized query builder.

### Action Resolution
- Only classes that implement `Triggerable` can be dispatched. Non-existent or non-implementing classes are rejected with `ActionResolutionException`.

### Webhook URL Scheme Enforcement
- `SubscriptionBuilder::save()` rejects non-HTTP(S) URL schemes (`ftp://`, `file://`, etc.) to prevent SSRF-like abuse.

### When to Use This Package vs Laravel Events

| Scenario | Laravel Events | ZeroBoiler Events |
|---|---|---|
| Intra-application domain events | ✅ Ideal | Overkill |
| Dynamic triggers from admin panel | ❌ Requires code deployment | ✅ Database-driven |
| External webhook subscriptions | ❌ Manual implementation | ✅ Built-in HMAC, retry, auto-deactivate |
| Event sourcing | ❌ No built-in support | ✅ `DomainEvent` value object |
| Condition-based filtering | ❌ Manual | ✅ 21 operators with ReDoS protection |
| Wildcard patterns (`order.*`) | ❌ Not supported | ✅ Single/cross-segment/catch-all |
| Runtime trigger management | ❌ Code changes required | ✅ CLI + API + admin panel |
| Event history & statistics | ❌ Not built-in | ✅ Log retention, success rates, dashboards |
| Simple event→listener | ✅ `Event::dispatch()` | Unnecessary complexity |
| High-frequency intra-request events | ✅ Minimal overhead | DB lookup on each fire() |

**Rule of thumb:** Use Laravel's native events for intra-application communication where handlers are known at compile time. Use ZeroBoiler Events when you need database-driven dynamic triggers, external webhook delivery, condition-based filtering, or runtime-managed event workflows.

## Limitations

- **No built-in rate limiting** — Protect webhook endpoints with the [zeroboiler/security](../security) package or Laravel's built-in rate limiting middleware.
- **No event replay/rebuild** — The `DomainEvent` value object supports serialization/reconstruction, but the package does not include automatic event store replay or aggregate rebuild functionality.
- **SQLite limitations** — `ENUM` columns are not natively supported by SQLite; the migrations use `$table->enum()` which Laravel converts to `VARCHAR` with CHECK constraints on SQLite.
- **Single-server scheduling** — `EventScheduler` uses `onOneServer()` which requires a shared cache driver (Redis, Memcached) in multi-server deployments.
- **No dead-letter queue** — Failed async dispatches after all retries are exhausted are logged but not automatically routed to a dead-letter queue. Use `zeroboiler:events:retry --status=failed` for manual intervention.

## Troubleshooting

| Issue | Cause | Solution |
|---|---|---|
| Triggers not firing | Trigger is disabled | Check `enabled` column; use `zeroboiler:events:list --enabled` |
| Wildcard triggers not matching | Cache stale after manual DB edit | Run `invalidateTriggerCache()` or re-save via API |
| Webhook returns 401 | Missing signature header | Verify subscription has a secret |
| Subscription auto-deactivated | Failure threshold exceeded | Check `failure_count` vs `max_failures`; reset with `resetFailures()` |
| Queue jobs stuck | Queue worker not running | Ensure `php artisan queue:work` is running |
| `ActionResolver` throws | Action class not found or not Triggerable | Verify the class exists and implements `Triggerable` |
| Events not firing globally | `events.disabled` is `true` | Check `EVENTS_DISABLED` env var or call `setEnabled(true)` |
| Redelivery returns non-2xx | Target endpoint is down or returns error | Check webhook URL, verify endpoint health, retry with `--force` |
| Stale pending logs visible | Queue worker crashed or not running | Check queue worker; use `zeroboiler:events:retry --status=pending` |

## Production Deployment Checklist

- [ ] **Migrations run**: `php artisan migrate` — all 3 tables created
- [ ] **Config published**: `php artisan vendor:publish --tag=events-config`
- [ ] **Queue worker running**: `php artisan queue:work` — required for async triggers
- [ ] **Queue connection configured**: Set `EVENTS_QUEUE_CONNECTION` if not using the default
- [ ] **Cache driver configured**: Wildcard trigger caching requires a working cache driver
- [ ] **Webhook secrets reviewed**: Set `EVENTS_SUB_MAX_FAILURES` for auto-deactivation threshold
- [ ] **Log retention configured**: Set `EVENTS_LOG_RETENTION_DAYS` to match compliance requirements
- [ ] **Rate limiting**: Protect webhook endpoints with middleware
- [ ] **Health check**: Run `php artisan zeroboiler:events:health --check-cache`

## API Reference

### EventManager (Facade)

| Method | Returns | Description |
|--------|---------|-------------|
| `on(string $event)` | `TriggerBuilder` | Start building a new trigger |
| `register(string $event)` | `TriggerBuilder` | Alias for `on()` |
| `fire(string $event, array $payload, bool $async)` | `void` | Fire an event; force async with `$async = true` |
| `fireModel(string $modelClass, string $action, object $model)` | `void` | Fire a model event (flattens attributes) |
| `enable(string $triggerId)` | `bool` | Enable a trigger by ID |
| `disable(string $triggerId)` | `bool` | Disable a trigger by ID |
| `invalidateTriggerCache()` | `void` | Clear the wildcard trigger cache |
| `isDisabled()` | `bool` | Check if the event system is globally disabled |
| `setEnabled(bool $enabled)` | `void` | Enable or disable at runtime |
| `listTriggers(?string, ?bool, int)` | `Collection` | List triggers with optional filtering |
| `getTrigger(string $id)` | `Trigger\|null` | Get a trigger by ID |
| `deleteTrigger(string $id)` | `bool` | Delete a trigger by ID |
| `subscribe(string $event, string $url)` | `SubscriptionBuilder` | Start building a webhook subscription |
| `unsubscribe(string $id)` | `bool` | Remove a subscription by ID |
| `listSubscriptions(?string, bool)` | `Collection` | List subscriptions with optional filtering |
| `getSubscription(string $id)` | `Subscription\|null` | Get a subscription by ID |
| `subscribeWebhook(string, string, array, int)` | `string` | Quick-create a webhook trigger |
| `getEventHistory(...)` | `Collection` | Query event log history |
| `getStats(?Carbon)` | `array` | Get aggregate statistics |
| `purgeLogs(Carbon, bool)` | `int` | Purge old event logs |
| `getStalePendingLogs(Carbon, int)` | `Collection` | Get stuck pending logs |
| `deactivateExceededSubscriptions()` | `int` | Deactivate failed subscriptions |
| `executeTrigger(Trigger, EventLog)` | `void` | Execute a trigger synchronously |
| `container()` | `Container` | Get the application container |

### ConditionEngine Operators

| Operator | Syntax | Description |
|----------|--------|-------------|
| `>`, `>=`, `<`, `<=` | `['amount', ['>', 100]]` | Numeric comparison (null-safe) |
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
composer test        # Run Pest test suite (396 files)
composer analyse     # PHPStan level 9 (PHPStan 2.x)
composer lint        # Laravel Pint
composer rector      # Rector code upgrades
composer ci          # All checks (lint → analyse → rector → test)
```

## Production Readiness Summary

| Criterion | Status |
|---|---|
| PHP 8.5+ strict types | ✅ All 38 source files |
| Final classes | ✅ All classes |
| Readonly properties | ✅ Promoted constructor props |
| Typed properties | ✅ All declared |
| Return type declarations | ✅ All methods |
| Docblocks | ✅ All classes/methods |
| #[Override] attributes | ✅ All overrides |
| #[Pure] attributes | ✅ Side-effect-free methods |
| PHPStan level 9 | ✅ phpstan.neon.dist |
| ServiceProvider provides() | ✅ 7 bindings |
| Config completeness | ✅ 9 top-level keys |
| Migrations | ✅ 3 tables |
| CLI commands | ✅ 12 commands |
| Facade | ✅ EventManager |
| Test coverage | ✅ 396 test files |

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

Proprietary — see [LICENSE](LICENSE) file.
