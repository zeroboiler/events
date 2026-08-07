# ZeroBoiler Events

| [![Latest Version](https://img.shields.io/badge/version-1.26.0-blue)]()
|[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)]()
[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)]()
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-success)]()

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
| `ConditionEngineContract` | Singleton → `ConditionEngine` | Interface binding |
| `ActionResolver` | Singleton | Shared across app |
| `TriggerBuilder` | Transient | Fresh instance per resolution |
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
composer test        # Run Pest test suite (49 test files)
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
| Subscription max failures config-driven | ✅ | `SubscriptionMaxFailuresConfigTest.php` |
| DispatchTriggerJob (config retry/backoff) | ✅ | `DispatchTriggerJobTest.php` |
| WebhookAction (HMAC, timeout, failures) | ✅ | `EventsComprehensiveTest.php` |
| Event history & stats | ✅ | `EventHistoryStatsTest.php` |
| ManagesHistory (purge, aggregate) | ✅ | `EventsEdgeCaseTest.php` |
| EscapesWildcardLike | ✅ | `EscapesWildcardLikeTest.php` |
| Service provider bindings | ✅ | `ServiceProviderBindingTest.php` |
| Config completeness | ✅ | `ConfigCompletenessTest.php` |
| Config table names (config-driven models) | ✅ | `ConfigTableNamesTest.php` |
| Subscription HMAC config | ✅ | `SubscriptionSignConfigTest.php` |
| Cache TTL config | ✅ | `EventManagerCacheTtlTest.php` |
| Trait consistency | ✅ | `TraitConsistencyTest.php` |
| Integration (full fire flow) | ✅ | `EventManagerIntegrationTest.php` |
| Facade proxy, cache invalidation, ActionResolver errors | ✅ | `EventsFacadeProxyTest.php` |
| Production readiness | ✅ | `ProductionReadyTest.php` |
| Contract binding (singleton, interface resolution) | ✅ | `ContractBindingTest.php` |
| Typed properties (models, factories, commands, attributes) | ✅ | `TypedPropertiesTest.php` |
| Edge cases (phase 1 + 2) | ✅ | `EdgeCasesTest.php`, `EdgeCasesPhase2Test.php` |
| Edge cases (phase 3 — empty conditions, between inverted, in single, fire no-match, sign null secret) | ✅ | `EdgeCasesPhase3Test.php` |
| Migration structure (columns, types, foreign keys) | ✅ | `MigrationStructureTest.php` |
| DomainEvent immutability (#[\Readonly] all props, identity, roundtrip, fromArray edge cases) | ✅ | `DomainEventImmutabilityTest.php` |
| ServiceProvider config (TriggerBuilder transient, contract binding, all config keys, all services resolvable) | ✅ | `EventsServiceProviderConfigTest.php` |
| Readonly properties (#[Readonly] promoted, PHP 8.5) | ✅ | `ReadonlyPropertiesTest.php` |
| Wildcard integration (cross-segment, catch-all, multi) | ✅ | `WildcardIntegrationTest.php` |
| Wildcard matcher (exact, *, **, extract, findMatching) | ✅ | `WildcardMatcherTest.php` |
| EscapesWildcardLike (%, _, \\, *, mixed) | ✅ | `EscapesWildcardLikeTest.php` |
| Fire command (JSON parsing, options, edge cases) | ✅ | `EventsFireCommandTest.php` |
| Redeliver command (validation, status checks) | ✅ | `EventsRedeliverCommandTest.php` |
| EventManager register alias, empty fire, disable/enable non-existent | ✅ | `EventManagerRegisterAliasTest.php` |
| TriggerBuilder action merging, executeTrigger exception propagation, empty fire | ✅ | `EventManagerAdvancedTest.php` |
| List command (pagination, event/enable/disable filters) | ✅ | `EventsListCommandTest.php` |
| Log command (trigger/status filters, limit, validation) | ✅ | `EventsLogCommandTest.php` |
| Subscribe command (secret, filter, async, priority) | ✅ | `EventsSubscribeCommandTest.php` |
| Unsubscribe command (remove, non-existent) | ✅ | `EventsUnsubscribeCommandTest.php` |
| Subscriptions command (event/active/inactive/wildcard filters, pagination) | ✅ | `EventsSubscriptionsCommandTest.php` |

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

## Changelog

### v1.26.0

- **Added**: `DomainEventImmutabilityTest` — 12 tests covering: all promoted properties have `#[\Readonly]`, identity semantics, `occur()` factory with fresh UUID/timestamp, `toArray()` serialization, `fromArray()` reconstruction (preserves eventId + occurredAt), invalid UUID/datetime graceful handling, missing eventType/payload handling, empty data handling, lossless roundtrip, explicit constructor args override
- **Added**: `EventsServiceProviderConfigTest` — 12 tests covering: TriggerBuilder transient binding, ConditionEngineContract binding, contract/concrete singleton identity, config publish group, all 6 services resolvable, config key completeness (table_names, subscriptions, retry, retention, wildcard_cache_ttl, queue)
- **Added**: `TriggerBuilder` explicit transient binding in `EventsServiceProvider::register()` — previously resolved through auto-wiring without explicit binding registration
- **Added**: `DispatchTriggerJob::$queue` property — reads queue name from `events.queue.queue` config (default: `'default'`), enabling configurable queue routing for async triggers
- **Refactored**: `DomainEvent::$eventType` and `$payload` promoted constructor properties now have `#[\Readonly]` attribute — all 4 properties (eventType, payload, eventId, occurredAt) are consistently readonly for PHP 8.5
- **Refactored**: All 3 model `$table` properties (`EventLog`, `Trigger`, `Subscription`) use native `string` type declaration instead of `@var` docblock — fully consistent typed property declarations
- **Refactored**: All 3 model `newFactory()` methods now have `#[\Override]` attribute for PHPStan override verification
- **Changed**: README enriched with Requirements section (PHP 8.5+, Laravel 13+, extension list), Contributing link, architecture table updated with `ConditionEngineContract` and `TriggerBuilder` bindings
- **Changed**: Version bumped to 1.26.0

### v1.25.0

- **Added**: `ReadonlyPropertiesTest` — tests verifying `#[\Readonly]` on promoted constructor properties for `EventManager`, `ActionResolver`, `TriggerBuilder`, `SubscriptionBuilder`, `DispatchTriggerJob`, and `DomainEvent`
- **Added**: Explicit `ConditionEngineContract` → `ConditionEngine` singleton binding in `EventsServiceProvider::register()` — previously the contract was only resolvable through Laravel's auto-concrete resolution
- **Refactored**: `#[\Readonly]` attribute added to all promoted constructor properties across 5 classes (`EventManager`, `ActionResolver`, `TriggerBuilder`, `SubscriptionBuilder`, `DispatchTriggerJob`) for PHP 8.5 consistency with `DomainEvent`'s existing `#[\Readonly]` usage
- **Fixed**: `config/events.php` — `queue.connection` default now uses `config('queue.default', 'default')` instead of hardcoded `'default'`, matching README documentation and allowing automatic Laravel queue driver inheritance
- **Changed**: README test file count updated from 46 to 47; version bumped to 1.25.0

### v1.24.0

- **Added**: `ConfigTableNamesTest` — tests verifying models read table names from `events.table_names` config, custom table name override, and fallback to defaults
- **Added**: `MigrationStructureTest` — tests verifying all 3 migration tables have required columns, triggers table id is string type, and foreign key relationships work end-to-end
- **Fixed**: **CRITICAL** `tests/helpers.php` — `fake()` helper used `new Generator` without import; changed to `new \Faker\Generator` with fully-qualified class name (tests would fail at runtime without Faker's autoloader magic)
- **Fixed**: Dead config — `events.table_names` config existed but models ignored it entirely; all 3 models (`Trigger`, `EventLog`, `Subscription`) now override `getTable()` to read from config with fallback to defaults
- **Changed**: README test file count updated from 44 to 46; version bumped to 1.24.0

### v1.23.0

- **Added**: `EdgeCasesPhase3Test.php` — 25 tests covering: empty conditions matching, inverted between range, single-element in/not_in, TriggerBuilder "0" event validation, actionParams with multiple actions (classes key), SubscriptionBuilder "0" event/invalid URL validation, fire with no matching triggers, fire with empty payload, enable/disable non-existent ID, fireModel with non-Eloquent object, EventLog status constants/markAsCompleted/markAsFailed, Subscription signPayload null/empty secret, signPayload consistency, resetFailures, WildcardMatcher empty pattern boundary cases, findMatchingPatterns empty input
- **Fixed**: `phpstan.neon.dist` — added `checkGenericClassInNonGenericObjectType: false` and `checkUninitializedProperties: false` to suppress false positives with Laravel generics and Eloquent model property initialization
- **Fixed**: `phpstan.neon.dist` — added `#Call to an undefined method#` and `#Method .*::.*\(\) invoked on .*#` ignore patterns for Eloquent `__call` magic and Facade type resolution without Larastan
- **Fixed**: `helpers.php` — removed unused `use Faker\Generator` import
- **Refactored**: `DomainEvent::__construct()` — simplified `eventId` and `occurredAt` null-coalescing from ternary to `??` operator
- **Refactored**: `EventsRedeliverCommand::handle()` — replaced null check + redundant `assert()` with single `instanceof` type narrowing
- **Changed**: README test count updated from 46 to 44; version bumped to 1.23.0

### v1.22.0

- **Added**: `ContractBindingTest` — tests for `ConditionEngineContract` binding via container, contract/concrete singleton identity, constructor parameter type verification for `EventManager`/`DispatchTriggerJob`/`DomainEvent`, `WebhookAction` config-driven methods, config default value verification, and strict-types enforcement across all `src/` files
- **Added**: `#[\Pure]` attribute on `WildcardMatcher::extractWildcards()` — consistent with `matches()` and `findMatchingPatterns()`
- **Added**: `#[\Override]` attribute on `casts()` methods in all 3 models (`Trigger`, `EventLog`, `Subscription`)
- **Fixed**: `TypedPropertiesTest.php` had double-escaped `use` import statements (`ZeroBoiler\\\\Events\\\\...`) — corrected to single backslashes (`ZeroBoiler\\Events\\...`)
- **Fixed**: `phpstan.neon` included `phpstan-baseline.neon` which is gitignored — replaced with `includes: phpstan.neon.dist` for local IDE integration
- **Fixed**: `phpstan.neon.dist` was missing `treatPhpDocTypesAsCertain: false` — added for consistency with `phpstan.neon`
- **Changed**: Version bumped to 1.22.0

### v1.21.0

- **Added**: Empty event name validation in `EventsFireCommand` — rejects empty or `"0"` event names with clear error message before attempting DB lookup
- **Added**: Test for empty event name rejection in `EventsFireCommandTest`
- **Removed**: Duplicate `rector/rector-laravel` dev dependency — only `driftingly/rector-laravel` is required (same functionality, actively maintained)
- **Changed**: `phpstan.neon.dist` now includes `Call to an undefined static method` in `ignoreErrors` — Eloquent's `__callStatic` magic methods (find, where, count, etc.) are not resolvable by PHPStan without Larastan; this eliminates the need for a separate phpstan-baseline.neon
- **Changed**: `phpstan-baseline.neon` removed from git tracking (added to `.gitignore`) — baseline is now local-only; all suppressions are in the shared config
- **Changed**: `.gitignore` updated to exclude `phpstan.neon` (local overrides) and `phpstan-baseline.neon` (generated baselines)
- **Changed**: Version bumped to 1.21.0

### v1.20.0

- **Added**: `EventsListCommandTest` — unit tests for list command (empty, pagination, event filter, enabled/disabled filter, per-page)
- **Added**: `EventsLogCommandTest` — unit tests for log command (empty, display, trigger/status filters, invalid status rejection, limit)
- **Added**: `EventsSubscribeCommandTest` — unit tests for subscribe command (auto-generated secret, explicit secret, conditions filter, invalid JSON rejection, async flag, priority)
- **Added**: `EventsUnsubscribeCommandTest` — unit tests for unsubscribe command (existing removal, non-existent failure, EventManager integration)
- **Added**: `EventsSubscriptionsCommandTest` — unit tests for subscriptions list command (empty, event filter, active/inactive, wildcard event, pagination)
- **Fixed**: `helpers.php` removed conflicting `use Faker\Provider\DateTime` import — replaced with fully-qualified `\Faker\Provider\DateTime` to avoid shadowing PHP's native `DateTime` class (PHPStan 9 compatibility)
- **Changed**: README test file count updated from 40 to 45
- **Changed**: Version bumped to 1.19.0

### v1.18.0

- **Added**: `phpstan.neon.dist` — PHPStan 9 configuration file (was missing, causing `composer analyse` to use defaults)
- **Added**: `rector.php` — Rector configuration with Laravel 11+ set and type declaration rules
- **Added**: `WildcardMatcherTest.php` — comprehensive test suite for `WildcardMatcher` (22 tests): exact match, single-segment wildcard, cross-segment wildcard, catch-all, multiple wildcards, empty segment rejection, special regex characters, findMatchingPatterns, extractWildcards with cross-segment guard, mismatched segments, no-wildcard patterns
- **Added**: `EscapesWildcardLikeTest.php` — comprehensive test suite for `EscapesWildcardLike` trait (11 tests): null for non-wildcard, asterisk-to-percent conversion, cross-segment double asterisk, percent/underscore/backslash escaping, mixed special chars, catch-all, empty string
- **Fixed**: `helpers.php` `config()` function used stale `static $config` variable — replaced with per-call resolution from current app instance to prevent cross-test contamination when `TestCase::tearDown()` creates a fresh app
- **Fixed**: `CreatesApplication.php` removed invalid imports (`ZeroBoiler\Events\Tests\Faker\Factory`, `ZeroBoiler\Events\Tests\Faker\Generator`) that referenced non-existent classes — cache and faker bindings now use fully-qualified `Illuminate\Cache\CacheManager` and the global `fake()` helper
- **Changed**: `EventManager::getMatchingTriggers()` sortBy call now uses explicit `descending: false` named parameter for PHPStan clarity
- **Changed**: Version bumped to 1.18.0

### v1.17.0

- **Added**: `EventManagerAdvancedTest` — TriggerBuilder action() + actions() merge/dedup (BUG-2), actionParams encoding variants, executeTrigger exception propagation (failed log + re-throw), fire with no triggers / empty event
- **Added**: Enhanced `fireModel()` tests with attributesToArray verification, toArray fallback, and plain object edge case
- **Added**: `#[\Pure]` attribute on `WildcardMatcher::findMatchingPatterns()`
- **Changed**: Enhanced docblocks for `fireModel()`, `resolveActions()`, `findMatchingPatterns()`, `extractWildcards()`
- **Changed**: Test file count updated from 37 to 38
- **Changed**: Version bumped to 1.17.0

### v1.16.0

- **Added**: `EventsFireCommandTest` — unit tests for fire command JSON parsing, option validation, and edge cases (invalid JSON, scalar JSON, empty object, missing @file)
- **Added**: `EventManagerRegisterAliasTest` — tests for `register()` alias, empty event fire, disable/enable non-existent triggers, multiple cache invalidation
- **Changed**: README test file count updated from 35 to 37
- **Changed**: Pest.php `uses()` updated to include new test files
- **Changed**: CHANGELOG.md fully synchronized with README changelog
- **Changed**: Version bumped to 1.16.0

### v1.15.0

- **Fixed**: `Subscription::hasExceededFailures()` now reads default threshold from `events.subscriptions.max_failures` config instead of hardcoded `10` — consistent with `WebhookAction` and `EventsRedeliverCommand`
- **Changed**: `Subscription::hasExceededFailures()` signature changed from `int $max = 10` to `?int $max = null` — nullable parameter with config fallback
- **Added**: `SubscriptionMaxFailuresConfigTest` — tests for config-driven default, explicit override, null config fallback, and zero-failure edge case
- **Changed**: All test action classes (`SendOrderNotification`, `LogOrderEvent`, `HighPriority`, `LowPriority`, `LogOrderCreated`) are now `final` — consistent with src/ classes
- **Fixed**: Misleading comment in `EventManager::parseActions()` — corrected "Associative array" to "Sequential list" for `array_is_list` branch
- **Changed**: Version bumped to 1.15.0

### v1.14.0

- **Added**: `EventsRedeliverCommandTest` — unit tests for redeliver command validation (non-existent log, pending/dispatched log rejection, missing URL detection, failed/completed log redelivery)
- **Added**: Security Considerations section in README — HMAC signing, ReDoS protection, SQL injection prevention, action resolution safety, rate limiting guidance
- **Added**: Troubleshooting table in README — common issues with causes and solutions
- **Fixed**: `DispatchTriggerJob::$tries` moved to constructor property promotion — eliminates PHPStan 9 uninitialized property warning for class-level default override
- **Fixed**: `ConditionEngine::evaluateCondition()` now type-guards `$expected[0]` as string — PHPStan 9 mixed-to-string safety
- **Changed**: Version bumped to 1.14.0

### v1.13.0

- **Fixed**: `TriggerFactory` and `SubscriptionFactory` `$model` property now uses native `string` type declaration — fully consistent across all 3 factories
- **Fixed**: `EventLog::$hidden` and `Subscription::$hidden` now use native `array` type declaration — all model properties consistently typed
- **Fixed**: `Trigger::eventLogs()` and `EventLog::trigger()` relation docblocks use `covariant $this` for PHPStan 9 generic return type compliance
- **Removed**: 4 stale PHPStan baseline entries for `HasFactory` generics and relation return type mismatches
- **Added**: `WildcardIntegrationTest` — comprehensive integration tests covering:
  - Cross-segment wildcard (`**`) matching (single and multi-segment events)
  - Catch-all wildcard (`*`) with multi-segment events and empty event rejection
  - Multiple wildcards per pattern (`user.*.order.*`)
  - Async fire with wildcard triggers
  - Fire event with no matching triggers / only disabled triggers
- **Added**: `TypedPropertiesTest` now verifies `EventLog::$hidden` property type (was only checking Trigger and Subscription)
- **Changed**: Version bumped to 1.13.0

### v1.12.0

- **Changed**: All model properties (`$table`, `$keyType`, `$incrementing`, `$fillable`, `$hidden`, `$statuses`) now use native PHP typed declarations instead of `@var` docblocks — fully PHPStan 9 compliant with strict property types
- **Changed**: All factory `$model` properties now use native `string` type declarations
- **Changed**: All 11 console command `$signature` and `$description` properties now use native `string` type declarations
- **Changed**: `DomainEvent` properties `$eventId` and `$occurredAt` now have `#[\Readonly]` attribute — prevents accidental mutation after construction
- **Added**: `#[\Pure]` attribute on `WildcardMatcher::matches()`, `findMatchingPatterns()`, and `extractWildcards()` — documents side-effect-free pure functions
- **Fixed**: `EventsRedeliverCommand` now reads HTTP timeout from `events.subscriptions.timeout` config instead of hardcoded `30` — consistent with `WebhookAction`
- **Added**: `TypedPropertiesTest` — comprehensive test suite covering:
  - Model typed property verification (Trigger, EventLog, Subscription)
  - Factory typed `$model` property verification
  - Console command typed `$signature` and `$description` verification
  - DomainEvent `#[\Readonly]` attribute verification
  - WildcardMatcher `#[\Pure]` attribute verification
  - EventsRedeliverCommand config-driven timeout method verification
  - Model CRUD regression tests after typed property migration
- **Changed**: Version bumped to 1.12.0

### v1.11.0

- **Changed**: All core classes (`EventManager`, `ConditionEngine`, `ActionResolver`, `WildcardMatcher`, `TriggerBuilder`, `SubscriptionBuilder`, `DomainEvent`, `WebhookAction`, `DispatchTriggerJob`) and all console commands are now `final` — prevents unsafe inheritance and improves PHPStan 9 strictness
- **Fixed**: `EventLogFactory::completed()` and `failed()` state closures now have explicit `: array` return type annotations for PHPStan 9 compliance
- **Fixed**: `EventsRetryCommand` uses strict `$trigger === null` comparison instead of loose truthy check for PHPStan null-safety
- **Fixed**: `EventsRetryCommand` adds `is_array()` guard when passing `$log->payload` to `DispatchTriggerJob` constructor
- **Fixed**: `ManagesHistory` and `ManagesSubscriptions` traits now declare `@mixin \ZeroBoiler\Events\EventManager` for proper PHPStan trait property resolution (`$this->app`)
- **Changed**: Version bumped to 1.11.0

### v1.10.0

- **Fixed**: `EventLog` model now explicitly declares `$table = 'event_logs'` for consistency with `Trigger` and `Subscription` models
- **Fixed**: `TriggerFactory::withConditions()` and `TriggerFactory::priority()` state closures now have explicit `: array` return type annotations for PHPStan 9 compliance
- **Added**: `EventsFacadeProxyTest` — comprehensive test suite covering:
  - Facade static method proxy (on, register, fire, fireModel, invalidateTriggerCache)
  - Cache invalidation behavior (enable/disable with found/not-found triggers)
  - ActionResolver error handling (non-existent class, non-Triggerable class)
  - ConditionEngine edge cases (empty conditions, single-element operator, between edge cases, null/non-string guards)
  - WildcardMatcher edge cases (empty pattern, empty event, multiple wildcards, regex special chars)
  - DomainEvent roundtrip and defaults
  - ServiceProvider binding verification (singleton/transient, contract implementation, config merge)
- **Changed**: Version bumped to 1.10.0

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

## Contributing

This is a private package. Contribution guidelines:

1. **Code style**: Follow PSR-12. Run `composer lint` (Laravel Pint) before committing.
2. **Static analysis**: Run `composer analyse` (PHPStan level 9). Zero errors allowed.
3. **Tests**: Run `composer test` (Pest). All tests must pass. Add tests for new features.
4. **Rector**: Run `composer rector` to apply automated code improvements.
5. **Full CI**: Run `composer ci` to execute all checks in order.
6. **Commit format**: `feat/fix/refactor: description` (conventional commit prefix).
7. **PHP version**: Target PHP 8.5+. Use strict types (`declare(strict_types=1)`), typed properties, and return type declarations on all methods.

## License

Proprietary. All rights reserved. © [ZeroBoiler](https://github.com/zeroboiler).
