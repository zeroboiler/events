# ZeroBoiler Events

[![Latest Version](https://img.shields.io/badge/version-1.9.0-blue)]()
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)]()
[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)]()
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-success)]()

Database-driven dynamic event manager for Laravel — register, manage, and fire event triggers via admin panel, API, or CLI without code changes.

## Table of Contents

- [Quick Start](#quick-start)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [CLI Commands](#cli-commands)
- [Architecture](#architecture)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

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

### Service Container Bindings

| Service | Binding | Lifetime |
|---|---|---|
| `EventManager` | Singleton | Shared across app |
| `ConditionEngine` | Singleton | Shared across app |
| `ActionResolver` | Singleton | Shared across app |
| `SubscriptionBuilder` | Transient | Fresh instance per resolution |
| `EventManager` (Facade) | `getFacadeAccessor()` → `EventManager::class` | Resolved from container |

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
composer test        # Run Pest test suite (31 test files)
composer analyse     # PHPStan level 9
composer lint        # Laravel Pint
composer ci          # All checks (lint → analyse → rector → test)
```

### Test Coverage

| Area | Tests | File |
|------|-------|------|
| EventManager core | ✅ | `EventManagerTest.php` |
| Condition engine (15+ operators) | ✅ | `ConditionEngineTest.php` |
| Wildcard matcher ( *, **, extract ) | ✅ | `WildcardMatcherTest.php` |
| Trigger builder (single/multi/params) | ✅ | `TriggerBuilderExtendedTest.php` |
| Subscription builder | ✅ | `SubscriptionBuilderTest.php` |
| Action resolver | ✅ | `ActionResolverTest.php` |
| Action string parsing (5 formats) | ✅ | `EventManagerParseActionsTest.php` |
| Domain event (serialization, reconstruction) | ✅ | `DomainEventTest.php` |
| Event log model | ✅ | `EventLogTest.php` |
| Trigger model | ✅ | `TriggerModelTest.php` |
| Subscription model (signing, failures) | ✅ | `SubscriptionTest.php` |
| DispatchTriggerJob (config retry/backoff) | ✅ | `DispatchTriggerJobTest.php` |
| WebhookAction (HMAC, timeout, failures) | ✅ | `EventsComprehensiveTest.php` |
| Event history & stats | ✅ | `EventHistoryStatsTest.php` |
| ManagesHistory (purge, aggregate) | ✅ | `EventsEdgeCaseTest.php` |
| EscapesWildcardLike | ✅ | `EscapesWildcardLikeTest.php` |
| Service provider bindings | ✅ | `ServiceProviderBindingTest.php` |
| Config completeness | ✅ | `ConfigCompletenessTest.php` |
| Subscription HMAC config | ✅ | `SubscriptionSignConfigTest.php` |
| Cache TTL config | ✅ | `EventManagerCacheTtlTest.php` |
| Trait consistency | ✅ | `TraitConsistencyTest.php` |
| Integration (full fire flow) | ✅ | `EventManagerIntegrationTest.php` |
| Production readiness | ✅ | `ProductionReadyTest.php` |
| Edge cases (phase 1 + 2) | ✅ | `EdgeCasesTest.php`, `EdgeCasesPhase2Test.php` |

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

### v1.9.0

- **Fixed**: `EventsLogCommand` now uses null-safe operator (`?->`) for `$log->created_at` — prevents crash if `created_at` is null for in-memory log entries
- **Fixed**: Factory state closures in `TriggerFactory` and `EventLogFactory` now have explicit `: array` return type annotations for PHPStan 9 compliance
- **Added**: `EventsComprehensiveTest` — comprehensive test suite with 60+ new tests covering:
  - DomainEvent serialization roundtrip, invalid UUID/datetime handling, explicit constructor args
  - WildcardMatcher edge cases: empty pattern, double-star in middle, regex special chars, findMatchingPatterns
  - ConditionEngine full operator coverage: `>`, `>=`, `<`, `<=`, `=`, `===`, `!=`, `!==`, `in`, `not_in`, `contains`, `not_contains`, `between`, `null`, `not_null`, `empty`, `not_empty`, `starts_with`, `ends_with`, `matches` (with ReDoS protection), nested dot-notation, null guards, AND logic
  - Subscription model: `matchesEvent` (exact + wildcard + cross-segment), `hasExceededFailures` (default + custom), `recordDelivery`, `recordFailure`, `resetFailures`, `signPayload` (null/empty/valid/config algorithm), `scopeActive`
  - DispatchTriggerJob config-driven `tries`/`backoff` constructor tests
  - WebhookAction missing/empty URL validation
  - TriggerBuilder: action params encoding (single + multiple actions), name generation, event "0" rejection
  - EventManager cache TTL config (normal + fallback)
  - Event statistics: empty state, aggregate values, `since` parameter
  - Log purge: completed-only, pending skip, include-pending
  - EscapesWildcardLike: asterisk, percent, underscore, backslash escaping, multiple wildcards
- **Changed**: Version bumped to 1.9.0

### v1.8.0

- **Fixed**: `EventManager::getTriggerCacheTtl()` now uses `$this->app->get('config')` with `assert()` type guard instead of `$this->app->make('config')->get()` — fixes PHPStan level 9 `mixed` return type from container
- **Fixed**: `EventsListCommand` and `EventsSubscriptionsCommand` now use null-safe operator for `$model->created_at?->format()` instead of direct property access — prevents null pointer when `created_at` is null
- **Added**: Explicit `$table = 'triggers'` property on `Trigger` model for consistency with `Subscription` model
- **Added**: `#[\Override]` attribute on all model `boot()` methods (`Trigger`, `EventLog`, `Subscription`) for PHPStan override verification
- **Added**: `ServiceProviderBindingTest` — comprehensive test suite covering:
  - Singleton binding verification (EventManager, ConditionEngine, ActionResolver)
  - Transient binding verification (SubscriptionBuilder)
  - ConditionEngineContract implementation check
  - Facade accessor and root resolution
  - Config merge completeness (all keys, types, and values)
  - Model metadata (table names, key types, incrementing, soft deletes)
  - Interface method existence checks
- **Changed**: Version bumped to 1.8.0

### v1.7.0

- **Fixed**: `EventManager::parseActions()` now type-checks `classes` array entries as `mixed` instead of assuming `string`, and guards `params` with `is_array()` check — fixes PHPStan level 9 potential type mismatch when JSON contains non-string values
- **Fixed**: `WildcardMatcher::extractWildcards()` now returns empty array for patterns containing `**` (cross-segment wildcards) since they match variable-length segments and can't reliably extract values
- **Fixed**: `SubscriptionBuilder::save()` now respects the `events.subscriptions.auto_generate_secret` config flag — setting it to `false` prevents secret auto-generation when none is explicitly provided
- **Added**: `EventsEdgeCaseTest` — comprehensive test suite covering:
  - `EventManager::fireModel()` with `attributesToArray()`, `toArray()` fallback, and condition filtering
  - `EventManager::executeTrigger()` failure re-throw behavior with log status update
  - `EventManager::executeTrigger()` with multiple actions (JSON array format)
  - `WildcardMatcher::extractWildcards()` with `**` patterns, mismatched segments, and non-matching patterns
  - `SubscriptionBuilder` auto_generate_secret config (default true, false, and provided secret override)
  - `Trigger` and `Subscription` soft delete behavior (exclude from queries, restore)
  - `EventManager::listSubscriptions()` with exact event, wildcard event, and active-only filtering
  - `EventManager::getEventHistory()` with event, status, trigger ID, and limit filtering
  - `ConditionEngine` edge cases (`===`, `!==`, cross-type string comparison, empty array condition, missing fields)
- **Changed**: Version bumped to 1.7.0

### v1.6.0

- **Fixed**: `DomainEvent::__construct()` now uses explicit `!== null` checks instead of `??` to satisfy PHPStan level 9 property assignment rules
- **Fixed**: `DispatchTriggerJob::$tries` now has a default value (`= 3`) to avoid uninitialized property; config read uses `is_int()` guard instead of direct cast
- **Fixed**: `EventManager::getMatchingTriggers()` uses null-safe operator (`?->`) for `$t->created_at->timestamp` to handle nullable timestamps
- **Fixed**: `EventsLogCommand` and `EventsSubscriptionsCommand` map closures refactored from arrow functions to named closures with proper variable extraction, resolving PHPStan `Collection::map` type mismatch
- **Fixed**: `EventsLogCommand` uses `$log->duration_ms !== null` instead of truthy check to correctly handle zero-duration logs
- **Fixed**: `EventsSubscribeCommand` uses `is_string()` guards for option values instead of direct `(string)` casts, resolving PHPStan cast errors
- **Fixed**: `EventsSubscriptionsCommand` uses `is_string()` guard for event filter option instead of direct cast
- **Fixed**: `EventsUnsubscribeCommand` defers cast to point of use, avoiding mixed-type cast error
- **Removed**: 7 stale PHPStan baseline entries for console command casts and Collection map closures
- **Added**: `ProductionReadyTest` — comprehensive test suite covering:
  - SubscriptionBuilder validation (empty event, invalid URL, secret generation, conditions)
  - TriggerBuilder action merging (BUG-2 fix: action() + actions() deduplication, single/multi/params formats)
  - EventManager::subscribeWebhook() integration
  - ManagesHistory::purgeLogs() with/without includePending
  - WildcardMatcher::findMatchingPatterns() (exact, wildcard, multiple, empty, catch-all)
  - DomainEvent::occur/fromArray edge cases (missing fields, invalid UUID, invalid datetime)
  - Subscription model (signPayload, hasExceededFailures, recordFailure, resetFailures, matchesEvent)
  - EventLog model (markAsCompleted, markAsFailed)
  - Trigger model scopes (enabled, async, orderByPriority, eventLogs relation)
  - ConditionEngine additional operators (not_contains, not_empty, inverted between, nested access, long regex)
- **Changed**: Version bumped to 1.6.0

### v1.5.0

- **Changed**: `Subscription::signPayload()` now reads the hash algorithm from `events.subscriptions.signature_algorithm` config instead of hardcoded `sha256`
- **Added**: `SubscriptionSignConfigTest` — tests for config-driven HMAC algorithm (sha256, sha384, sha512, invalid fallback, null fallback)
- **Added**: `ConfigCompletenessTest` — validates all config keys exist with correct types at bootstrap
- **Fixed**: `EventManagerCacheTtlTest.php` was missing from Pest.php `uses()` list — cache TTL tests were not getting Laravel bootstrap
- **Fixed**: `CreatesApplication` test config was missing `retention`, `subscriptions`, and `wildcard_cache_ttl` keys — tests relying on these values would get incorrect defaults
- **Fixed**: Stale PHPStan baseline entry for `getEventHistory()` return type (method correctly returns Eloquent Collection)
- **Fixed**: Added missing `use ZeroBoiler\Events\Models\Trigger` import in `EventManager` facade
- **Removed**: 1 stale PHPStan baseline entry
- **Changed**: Version bumped to 1.5.0

### v1.4.0

- **Changed**: `DispatchTriggerJob` now reads `tries` and `backoff` from `events.retry` config instead of hardcoded constants
- **Changed**: `WebhookAction` now reads `timeout` and `max_failures` from `events.subscriptions` config instead of hardcoded constants
- **Added**: Config-driven retry/backoff tests for `DispatchTriggerJob`
- **Added**: Config-driven timeout/max_failures tests for `WebhookAction`
- **Changed**: Version bumped to 1.4.0

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

Proprietary. All rights reserved. © [ZeroBoiler](https://github.com/zeroboiler).
