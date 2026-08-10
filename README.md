# ZeroBoiler Events

|[![version](https://img.shields.io/badge/version-2.7.0-blue)]()|
|[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)]()|
|[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)]()|
|[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-success)]()|
|[![CI](https://github.com/zeroboiler/events/actions/workflows/ci.yml/badge.svg)](https://github.com/zeroboiler/events/actions/workflows/ci.yml)

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
    ],

    'subscriptions' => [
        'auto_generate_secret' => true,
        'max_failures' => env('EVENTS_SUB_MAX_FAILURES', 10),
        'timeout' => env('EVENTS_SUB_TIMEOUT', 30),
        'signature_algorithm' => 'sha256',
    ],

    'disabled' => env('EVENTS_DISABLED', false),

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
| `zeroboiler:events:list` | List triggers with optional filtering |
| `zeroboiler:events:fire {event}` | Manually fire an event (supports `--async`) |
| `zeroboiler:events:register` | Register a new trigger |
| `zeroboiler:events:enable {id}` | Enable a trigger |
| `zeroboiler:events:disable {id}` | Disable a trigger |
| `zeroboiler:events:retry {logId}` | Retry a failed event log |
| `zeroboiler:events:redeliver {logId}` | Redeliver an event log |
| `zeroboiler:events:log` | View event logs (supports `--event` with wildcards) |
| `zeroboiler:events:subscribe` | Create a webhook subscription |
| `zeroboiler:events:unsubscribe {id}` | Remove a webhook subscription |
| `zeroboiler:events:subscriptions` | List webhook subscriptions |
| `zeroboiler:events:health` | Diagnostic health check (supports `--json`) |

## Database Schema

### `triggers` Table

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

### `event_logs` Table

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

### `event_subscriptions` Table

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
│   ├── 2024_01_01_000001_create_triggers_table.php
│   ├── 2024_01_01_000002_create_event_logs_table.php
│   └── 2025_06_28_000001_create_event_subscriptions_table.php
├── src/
│   ├── Actions/
│   │   └── WebhookAction.php   # Triggerable: HTTP POST webhook dispatch
│   ├── Console/
│   │   ├── EventsDisableCommand.php
│   │   ├── EventsEnableCommand.php
│   │   ├── EventsFireCommand.php
│   │   ├── EventsHealthCommand.php
│   │   ├── EventsListCommand.php
│   │   ├── EventsLogCommand.php
│   │   ├── EventsRedeliverCommand.php
│   │   ├── EventsRegisterCommand.php
│   │   ├── EventsRetryCommand.php
│   │   ├── EventsSubscribeCommand.php
│   │   ├── EventsSubscriptionsCommand.php
│   │   └── EventsUnsubscribeCommand.php
│   ├── Contracts/
│   │   ├── ConditionEngineContract.php
│   │   └── Triggerable.php
│   ├── Concerns/
│   │   ├── EscapesWildcardLike.php
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
│   ├── EventsServiceProvider.php
│   ├── SubscriptionBuilder.php
│   ├── TriggerBuilder.php
│   └── WildcardMatcher.php
└── tests/                      # 131 test files (Pest)
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
composer test        # Run Pest test suite (130 test files)
composer analyse     # PHPStan level 9 (uses phpstan.neon.dist)
composer lint        # Laravel Pint
composer rector      # Rector code upgrades
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
| EventLog casts type, error string cast | ✅ | `EventLogTest.php` |
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
| Production deployment (bindings, contracts, config, immutability) | ✅ | `ProductionDeploymentTest.php` |
| Contract binding (singleton, interface resolution) | ✅ | `ContractBindingTest.php` |
| Typed properties (models, factories, commands, attributes) | ✅ | `TypedPropertiesTest.php` |
| EventManager wildcard cache (population, invalidation, disabled exclusion, cross-segment) | ✅ | `EventManagerWildcardCacheTest.php` |
| Edge cases (phase 1 + 2) | ✅ | `EdgeCasesTest.php`, `EdgeCasesPhase2Test.php` |
| Edge cases (phase 3 — empty conditions, between inverted, in single, fire no-match, sign null secret) | ✅ | `EdgeCasesPhase3Test.php` |
| Migration structure (columns, types, foreign keys) | ✅ | `MigrationStructureTest.php` |
| DomainEvent immutability (readonly keyword, identity, roundtrip, fromArray edge cases) | ✅ | `DomainEventImmutabilityTest.php` |
| ServiceProvider config (TriggerBuilder transient, contract binding, all config keys, all services resolvable) | ✅ | `EventsServiceProviderConfigTest.php` |
| Readonly properties (readonly promoted, PHP 8.5) | ✅ | `ReadonlyPropertiesTest.php` |
| Wildcard integration (cross-segment, catch-all, multi) | ✅ | `WildcardIntegrationTest.php` |
| Wildcard matcher (exact, *, **, extract, findMatching) | ✅ | `WildcardMatcherTest.php` |
| EscapesWildcardLike (%, _, \, *, mixed) | ✅ | `EscapesWildcardLikeTest.php` |
| Fire command (JSON parsing, options, edge cases) | ✅ | `EventsFireCommandTest.php` |
| Redeliver command (validation, status checks) | ✅ | `EventsRedeliverCommandTest.php` |
| Enable command (enable disabled, already enabled, non-existent) | ✅ | `EventsEnableCommandTest.php` |
| Disable command (disable enabled, already disabled, non-existent) | ✅ | `EventsDisableCommandTest.php` |
| Register command (sync/async, name, priority, auto-name) | ✅ | `EventsRegisterCommandTest.php` |
| Retry command (invalid status, empty, disabled skip) | ✅ | `EventsRetryCommandTest.php` |
| EventManager register alias, empty fire, disable/enable non-existent | ✅ | `EventManagerRegisterAliasTest.php` |
| TriggerBuilder action merging, executeTrigger exception propagation, empty fire | ✅ | `EventManagerAdvancedTest.php` |
| List command (pagination, event/enable/disable filters) | ✅ | `EventsListCommandTest.php` |
| Log command (trigger/status filters, limit, validation) | ✅ | `EventsLogCommandTest.php` |
| Log command event filter (exact, wildcard, combined, limit) | ✅ | `EventsLogCommandEventFilterTest.php` |
| Subscribe command (secret, filter, async, priority) | ✅ | `EventsSubscribeCommandTest.php` |
| Unsubscribe command (remove, non-existent) | ✅ | `EventsUnsubscribeCommandTest.php` |
| Subscriptions command (event/active/inactive/wildcard filters, pagination) | ✅ | `EventsSubscriptionsCommandTest.php` |
| Phase 4 (ReDoS, not_contains, not_empty, WildcardMatcher edges, fromArray edges, cache invalidation on save/disable/enable, signPayload edges, model markAs*, contract singleton) | ✅ | `EventsPhase4Test.php` |
| Phase 5 quality (connection property type, null config, numeric config, ConditionEngine null-safe operators, WildcardMatcher comprehensive, cache invalidation, status constants, factory defaults, scope instances) | ✅ | `EventsPhase5QualityTest.php` |
| Phase 6 production (transient/singleton bindings, contract identity, status constants, null-safe operators, TriggerBuilder encoding variants, DomainEvent identity, WildcardMatcher edges, fire no-match, cache invalidation, model config, DispatchTriggerJob config, Subscription sign/match, getStats structure) | ✅ | `EventsPhase6ProductionTest.php` |
| Phase 7 final (fireModel attribute flattening, toArray fallback, plain object; WildcardMatcher regex escape/backslash/extractWildcards/findMatchingPatterns order; DomainEvent occur/fresh UUID/explicit args/toArray/empty fromArray/non-string eventType; DispatchTriggerJob backoff array/zero tries/non-int config; priority deterministic ordering with created_at tiebreaker; ConditionEngine not_contains/not_empty/triple-nested/between inverted) | ✅ | `EventsPhase7FinalTest.php` |
| Migration config-driven (config reads in migrations, FK reference) | ✅ | `MigrationConfigDrivenTest.php` |
| DomainEvent sourcing (occur, toArray, fromArray, identity, readonly) | ✅ | `EventSourcingTest.php` |
| WildcardMatcher edge cases (multi-wildcard, extract, backslash, boundary) | ✅ | `WildcardMatcherEdgeCasesTest.php` |
| Production hardening (readonly keyword, attribute scan, bindings, config, Pest completeness) | ✅ | `ProductionHardeningTest.php` |
| ParseActions return type (string|array entries, all 5 JSON formats, mixed edge cases) | ✅ | `EventManagerParseActionsTypeTest.php` |
| Migration unsigned integers (priority, duration_ms, failure_count, delivery_count) | ✅ | `MigrationStructureTest.php` |
| fireModel (attributesToArray, toArray fallback, plain object, event name, empty payload, no-match, metadata override) | ✅ | `EventManagerFireModelTest.php` |
| ConditionEngine edge cases (strictEquals, operators, nested null, between auto-normalize, regex limits, AND logic) | ✅ | `ConditionEngineEdgeCasesTest.php` |
| Phase 8 production (dot notation, cache invalidation, trigger builder params, subscription scopes, domain event extras, contract singleton, config validation) | ✅ | `EventsPhase8ProductionTest.php` |
| Phase 9 production (redeliver payload stripping, timeout config, ConditionEngine null operators, model boot UUID, TriggerBuilder/SubscriptionBuilder validation, WebhookAction error cases, DispatchTriggerJob edge config, EventLog mark methods, Subscription signing determinism, config type validation, contract singleton, ActionResolver errors) | ✅ | `EventsPhase9ProductionTest.php` |
| Null-value comparison operators (>, >=, <, <= with null value/actual, between null, in/not_in null, null/not_null operators) | ✅ | `ConditionEngineNullComparisonTest.php` |
| Phase 10 production (fire/fireModel empty validation, DispatchTriggerJob array backoff config, ConditionEngine operators, WildcardMatcher edge cases, DomainEvent roundtrip, config completeness, ServiceProvider bindings, final classes, readonly properties, strict types enforcement, facade accessor) | ✅ | `EventsPhase10ProductionTest.php` |
| Phase 11 production (SubscriptionBuilder transaction atomicity, validation-before-transaction, action params verification, WebhookAction subscription failure tracking optimization, hasExceededFailures config/custom, delivery tracking, signPayload determinism) | ✅ | `EventsPhase11ProductionTest.php` |
| Phase 12 production (ServiceProvider bindings, Facade accessor, WildcardMatcher edge cases, ConditionEngine operator coverage, DomainEvent roundtrip/edge cases, Trigger model scopes, EventLog mark methods, Subscription signing/failures/matching, EventManager fire/fireModel, Config completeness, Strict types enforcement, Final class verification, EscapesWildcardLike) | ✅ | `EventsPhase12ProductionTest.php` |
| Phase 13 production (TriggerBuilder deduplication order-preservation, ConditionEngine full operator coverage, O(1) trigger dedup set, DomainEvent immutability, Config type validation, parseActions all 5 formats, WildcardMatcher comprehensive, EscapesWildcardLike, Singleton/transient binding verification, strict types enforcement, Final class verification) | ✅ | `EventsPhase13ProductionTest.php` |
| Phase 14 production (fireModel edge cases, TriggerBuilder action merging integration, ConditionEngine strictEquals edge cases (0 vs false vs empty string, array vs string, in empty array), WildcardMatcher regex special chars/empty pattern/pure attribute, EventManager cache TTL edge cases (negative/zero/non-integer/custom), EventManager enable/disable non-existent, DomainEvent UUID freshness/timestamp freshness/toArray keys/fromArray edge cases, DispatchTriggerJob constructor edge cases (empty backoff/single backoff/property types), Subscription signPayload empty secret/hasExceededFailures config edge cases/matchesEvent patterns, Factory default state validation, ActionResolver error cases, TriggerBuilder/SubscriptionBuilder validation and fluent interface, WebhookAction missing URL variants, ConditionEngine empty conditions/numeric string/matches null) | ✅ | `EventsPhase14ProductionTest.php` |
| Phase 15 production (executeTrigger basePayload extraction/null payload/action params merge, TriggerBuilder null/empty conditions save, SubscriptionBuilder URL validation (reject invalid, accept HTTPS), ConditionEngine empty conditions with various payloads, WildcardMatcher findMatchingPatterns type/extractWildcards edge cases, ServiceProvider binding lifecycle (singleton/transient/contract identity), Config type validation (all 6 sections), Facade accessor, Model config-driven table names, TriggerBuilder/SubscriptionBuilder fluent interface, DispatchTriggerJob config-driven properties (tries/queue/connection/backoff formats), EventLog status constants, DomainEvent roundtrip/fresh UUID, Cache invalidation (save/disable/enable), Strict types enforcement, Final class verification) | ✅ | `EventsPhase15ProductionTest.php` |
| Phase 16 production (EventLog scopes, markAsCompleted/markAsFailed, Trigger scopes and relations, Subscription scopes and matchesEvent patterns, #[\Override] attribute verification, DomainEvent readonly keyword verification) | ✅ | `EventsPhase16ProductionTest.php` |
| Phase 17 production (listTriggers CRUD with filters/wildcards/limit, getTrigger/deleteTrigger, fireModel with attributesToArray/toArray, TriggerBuilder multi/single action save, config-driven model table names, EventLog status transitions, DomainEvent fromArray edge cases, ConditionEngine operators (starts_with, ends_with, not_empty, between, dot notation, AND logic), WildcardMatcher comprehensive, config type validation, signPayload edge cases, empty conditions dispatch, fire/fireModel validation, cache invalidation lifecycle, enable/disable cache) | ✅ | `EventsPhase17ProductionTest.php` |
| Phase 18 production (fire/fireModel validation, TriggerBuilder save validation/encoding, SubscriptionBuilder save validation/HMAC, DomainEvent fromArray edge cases, trigger management CRUD, cache invalidation, alias behavior, WildcardMatcher special patterns, ConditionEngine operator coverage) | ✅ | `EventsPhase18ProductionTest.php` |
| Phase 19 production (console #[\Override] attributes, final classes, typed properties, strict types, config completeness, ServiceProvider bindings, handle() return types) | ✅ | `EventsPhase19ProductionTest.php` |
| Phase 20 production (strict types, final classes, interface contracts, binding lifecycle, facade accessor, config completeness, model config, status constants, DomainEvent readonly/roundtrip, WildcardMatcher #[Pure], fluent interface, #[Override] verification, matchesEvent, cache invalidation, CRUD, fire/fireModel validation, version consistency) | ✅ | `EventsPhase20ProductionTest.php` |
| Phase 21 production (return type verification, unused import cleanup, config merge verification, migration config-driven tables, TriggerBuilder deduplication, ConditionEngine null/edge cases, WildcardMatcher comprehensive, DomainEvent readonly/roundtrip, EscapesWildcardLike comprehensive, EventLog status transitions, Pest.php completeness, strict_types enforcement, version format) | ✅ | `EventsPhase21ProductionTest.php` |
| Phase 22 production (Pest.php duplicate detection, strict types enforcement, final class verification, return type declarations, #[\Override] verification, ServiceProvider binding lifecycle, config completeness, Facade accessor, ConditionEngine operator matrix, WildcardMatcher comprehensive, EscapesWildcardLike, DomainEvent readonly/immutability, model scopes/relations, parseActions @phpstan-return annotation, version consistency, WildcardMatcher #[\Pure], Subscription signPayload, TriggerBuilder/SubscriptionBuilder validation, ActionResolver errors, ManagesHistory/ManagesSubscriptions methods, fluent interface return types, config merge) | ✅ | `EventsPhase22ProductionTest.php` |
| Phase 23 production (Facade #[\Override] on getFacadeAccessor, DispatchTriggerJob backoff re-indexing with array_values, config type validation all sections, strict types enforcement, final class verification, WildcardMatcher #[\Pure] attributes, ConditionEngine #[\Override], WebhookAction #[\Override], ServiceProvider binding lifecycle, EventLog status constants consistency, version consistency) | ✅ | `EventsPhase23ProductionTest.php` |
| Phase 24 production (trait @property-read annotations for PHPStan 9, Pest.php Phase23 inclusion, strict types enforcement, final class verification, #[\Override] on all commands, return type declarations, DomainEvent readonly properties, WildcardMatcher #[\Pure], config completeness, ServiceProvider binding lifecycle, version consistency) | ✅ | `EventsPhase24ProductionTest.php` |
| Phase 25 production (Pest.php Phase24 registration, comprehensive final audit: strict types all files, final class verification, #[\Override] on all overrides, return type declarations, typed properties, interface contracts, config completeness, singleton/transient bindings, version consistency, facade accessor, DomainEvent readonly/roundtrip, WildcardMatcher #[\Pure], EscapesWildcardLike, ConditionEngine full operator matrix, fluent interface verification, model #[\Override] on boot/casts/newFactory/getTable) | ✅ | `EventsPhase25ProductionTest.php` |
| Phase 26 production (parseActions 5 formats, WebhookAction payload stripping, DispatchTriggerJob property types/readonly, DomainEvent edge cases, Facade @method completeness, Config merge verification, Model fillable consistency, Factory definition/state return types, migration up() existence, EventLog status constants, interface return types, ActionResolver errors, WildcardMatcher regex specials, ConditionEngine dot notation/between-inverted/ReDoS, Subscription signPayload edge cases) | ✅ | `EventsPhase26ProductionTest.php` |
| Phase 27 production (strict types sweep, trait composition validation, config publish tags, console command prefix/final/typed properties, interface parameter types, DomainEvent toArray/fromArray key consistency, Facade resolved instance type, model relation return types, ServiceProvider binding verification, ConditionEngine full operator coverage + AND logic + null rejection, constructor parameter types, model casts completeness, WildcardMatcher #[\Pure] verification, EventManager public method return types, final class sweep, composer.json version consistency, model boot UUID generation, WebhookAction/ConditionEngine interface verification, EscapesWildcardLike SQL escaping) | ✅ | `EventsPhase27ProductionTest.php` |
| Phase 28 production (DomainEvent constructor void return type removal, EventsUnsubscribeCommand early string cast, comprehensive verification: strict types, final classes, interface contracts, constructor types, readonly properties, config completeness, config type validation, facade accessor, WildcardMatcher #[\Pure], EventLog status constants, model config-driven table names, model key type/incrementing consistency, model relation return types, model casts completeness, ServiceProvider bindings (singleton/transient/contract identity), TriggerBuilder/SubscriptionBuilder fluent interface, EventManager public method return types, version consistency, EscapesWildcardLike SQL escaping, ActionResolver types, WebhookAction/ConditionEngine #[Override], console command prefix verification, config publish tags, ManagesHistory/ManagesSubscriptions trait composition, DomainEvent roundtrip/toArray keys, DispatchTriggerJob property types, migration file existence, Pest.php registration) | ✅ | `EventsPhase28ProductionTest.php` |
| Phase 29 production (new factory states: EventLogFactory withEvent/forTrigger/withPayload/withDuration, SubscriptionFactory withFailureCount/withDeliveryCount/withPriority, TriggerFactory forEvent/withAction/withName; factory base definition structure; EventManager API surface on/register/enable/disable/deleteTrigger/invalidateTriggerCache; TriggerBuilder/SubscriptionBuilder fluent interface; DomainEvent identity roundtrip/readonly/fromArray edge cases; ConditionEngine full operator matrix + AND logic; WildcardMatcher exhaustive patterns; config completeness/type validation; ServiceProvider binding lifecycle; Facade accessor; model UUID key types/casts/status constants; strict types enforcement; final class verification; console command prefix/return types; WildcardMatcher #[\Pure]; #[Override] verification; trait composition; Subscription signPayload/hasExceededFailures; migration structure; config publish tags; version consistency; EventManager CRUD/fire/fireModel validation) | ✅ | `EventsPhase29ProductionTest.php` |
| Phase 30 production (DispatchTriggerJob eventLogId initial null/constructor config edge cases; WebhookAction payload stripping verification/URL validation; ConditionEngine not_in/in null actual/===/!==/>=/<=/between non-array/regex length/ReDoS rejection/strictEquals cross-type/nested value null; TriggerBuilder resolveActions deduplication/merge/empty; SubscriptionBuilder save validation (ftp URL/non-URL/empty event/empty URL); EventManager listTriggers return type; model getTable config fallback/custom config; DomainEvent fromArray non-string occurredAt/non-array payload; model scopes return types; model relations return types; ActionResolver error cases; factory definition key types; factory parent class; composer autoload PSR-4/extra.laravel; config all section key completeness; ServiceProvider config merge/migration load; WildcardMatcher #[\Pure] on all public methods) | ✅ | `EventsPhase30ProductionTest.php` |
| Phase 31 production (SubscriptionBuilder HTTP-only URL validation — reject ftp://, file://, mailto:; accept http:// and https://; validation ordering — event name before URL scheme, empty URL before scheme check) | ✅ | `EventsPhase31ProductionTest.php` |
| Phase 32 production (DomainEvent explicit constructor args roundtrip, SubscriptionBuilder auto-generate secret config, conditions empty→null, WebhookAction URL validation edge cases (missing/empty/non-string), signPayload custom algorithm, DispatchTriggerJob property types, ConditionEngine AND logic 2+3 conditions, WildcardMatcher special patterns extraction, TriggerBuilder deduplication integration, EventLog status transitions, Subscription matchesEvent comprehensive, fire no-match, config type validation, ServiceProvider singleton/transient, Facade accessor, strict types sweep, final class verification, model config-driven tables, version consistency, EscapesWildcardLike, getStats zero-state, Trigger/Subscription scopes, DomainEvent fromArray roundtrip) | ✅ | `EventsPhase32ProductionTest.php` |
| Phase 33 production (EventManager CRUD edge cases, model relations, DomainEvent fromArray edge cases, ConditionEngine empty/null/AND logic, WildcardMatcher extract/findMatching, model scopes, EventLog status transitions, Subscription delivery/failure/signing/matching, TriggerBuilder auto-name/actions/params, SubscriptionBuilder validation/rejection, EscapesWildcardLike SQL escaping, getEventHistory filters, getStats zero-state, purgeLogs, ActionResolver errors, config validation, ServiceProvider singleton/transient/contract, Facade accessor, strict types enforcement, final class verification, console command final, version consistency, #[Override] verification, model config tables, model key types, migration existence, factory types, Pest.php registration) | ✅ | `EventsPhase33ProductionTest.php` |
| Phase 34 production (fire/fireModel validation, TriggerBuilder deduplication/order, SubscriptionBuilder transaction/URL validation/auto-secret, ConditionEngine strictEquals/AND/empty, WildcardMatcher findMatchingPatterns/extract order, DomainEvent fromArray minimal/roundtrip, DispatchTriggerJob backoff/tries config, EventLog status lifecycle, Subscription delivery/failure/signing/matchesEvent/hasExceededFailures, getStats zero-state, purgeLogs, EscapesWildcardLike, ActionResolver errors, Facade accessor, strict types, final classes, config completeness, ServiceProvider bindings, model boot UUID, version consistency, fluent interface) | ✅ | `EventsPhase34ProductionTest.php` |
| Phase 35 production (signPayload hash_hmac false safety, strict types enforcement, final class verification, interface contracts, ServiceProvider singleton/transient bindings, facade accessor, config completeness all 6 sections, EventLog status constants, DomainEvent readonly keyword, WildcardMatcher #[\Pure] attributes, model config-driven table names, return type declarations, console command return types, version consistency) | ✅ | `EventsPhase35ProductionTest.php` |
| Phase 36 production (trait composition, ConditionEngine getNestedValue edge cases, operator matrix comprehensive, WildcardMatcher special chars, DomainEvent serialization, model fillable/hidden/casts, config structure, factory states, file headers, namespaces, fire/fireModel validation, TriggerBuilder/SubscriptionBuilder validation, DispatchTriggerJob config properties, ServiceProvider bindings, model scopes, WebhookAction interface, EscapesWildcardLike, ActionResolver errors, composer.json structure, migrations, phpstan config, facade completeness, cache TTL, CRUD edge cases, TriggerBuilder resolveActions, version consistency) | ✅ | `EventsPhase36ProductionTest.php` |
| Phase 37 production (ConditionEngine between non-numeric rejection, float operators, null actual; SubscriptionBuilder parse_url type safety; fake() helper return type; model relations; Subscription matchesEvent; WebhookAction delivery/failure tracking; DomainEvent fromArray edge cases; config completeness; ServiceProvider bindings; Facade accessor; strict types; final classes; model config tables; version consistency) | ✅ | `EventsPhase37ProductionTest.php` |
| Phase 38 production (README table format, save() @throws docblocks, DispatchTriggerJob typed $backoff) | ✅ | `EventsPhase38ProductionTest.php` |
| Phase 39 production (README version consistency, test file count accuracy, standalone test verification, Pest.php completeness) | ✅ | `EventsPhase39ProductionTest.php` |
| Phase 40 production (strict types, final classes, interface contracts, #[Override] verification, ServiceProvider bindings, config completeness, model config tables, EventLog status constants, DomainEvent readonly/roundtrip, WildcardMatcher #[Pure], EscapesWildcardLike, ActionResolver errors, ConditionEngine full operator matrix, WildcardMatcher comprehensive, Subscription signing/failure/matching, EventManager CRUD/fire/fireModel validation, TriggerBuilder/SubscriptionBuilder fluent interface, cache invalidation, getStats zero-state, version consistency, Pest.php completeness, composer.json structure, console command prefix, model key types, parseActions edge cases, Migration existence, DispatchTriggerJob config properties, trait method verification, file headers, EventLog status lifecycle) | ✅ | `EventsPhase40ProductionTest.php` |
| Full lifecycle integration (fire→dispatch→log→stats, priority ordering, cache invalidation, history filtering, purge logs, DomainEvent roundtrip, ActionResolver edge cases, WildcardMatcher comprehensive, ConditionEngine all operators) | ✅ | `EventsLifecycleIntegrationTest.php` |
| Phase 42 production (fireModel key collision, empty attributes, parseActions edge cases, DispatchTriggerJob eventLogId, WebhookAction payload stripping, ConditionEngine empty/missing, WildcardMatcher no-wildcard, builder defaults, CRUD empty state, EventLog constants, signPayload edge cases, ServiceProvider commands, Facade accessor, DomainEvent freshness, config completeness, version consistency) | ✅ | `EventsPhase42ProductionTest.php` |
| Phase 43 production (fire() async parameter, ConditionEngine unknown operator fix, EventsFireCommand JSON precedence fix, EventsFireCommand --async flag, Facade annotation, all 19 operators, config consistency, strict types, final classes) | ✅ | `EventsPhase43ProductionTest.php` |
| Phase 44 production (CHANGELOG presence, composer.json autoload/extra, rector.php Laravel set, .gitignore completeness, database directories, phpstan config, license headers, facade @method completeness, WebhookAction private method return types, EventLog casts type, DomainEvent fromArray edge cases, getStats structure, test file registration, command type safety, version consistency) | ✅ | `EventsPhase44ProductionTest.php` |
| Phase 45 production (rector.php valid LaravelSetList constant, all source files strict_types, final class verification — 14 classes, no `#[Readonly]` attribute usage, readonly keyword on DomainEvent/EventManager properties, return type declarations on all public methods, `#[\Override]` on ConditionEngine::matches and WebhookAction::handle, `#[\Pure]` on all 3 WildcardMatcher static methods, trait composition verification, PHPStan config, composer.json structure, config completeness, model config-driven table names, console command prefix verification, migration structure, EventLog status constants, interface contracts, ServiceProvider binding methods, Facade accessor, factory definitions, .gitignore completeness, version consistency, source file license headers) | ✅ | `EventsPhase45ProductionTest.php` |
| Subscription scopeForEvent (exact, wildcard, cross-segment, no-match, non-wildcard chaining) | ✅ | `SubscriptionScopeForEventTest.php` |
| ManagesHistory purgeLogs (old completed/failed purge, includePending, empty DB, recent log preservation) | ✅ | `ManagesHistoryPurgeLogsTest.php` |
| Phase 47 production (helpers clean imports, readonly class audit, DomainEvent roundtrip, ConditionEngine full operator matrix, WildcardMatcher comprehensive, Subscription signPayload edge cases, Factory states, strict types, license headers, version consistency, Pest.php registration, model config tables, key types, API surface completeness) | ✅ | `EventsPhase47ProductionTest.php` |
| Phase 48 production (phpstan.neon.dist tightened ignores, parseActions 5 formats return types, ConditionEngine unknown/empty operators, WebhookAction payload stripping, SubscriptionBuilder auto-secret, DispatchTriggerJob config normalization, factory state returns, strict types, final classes, interfaces, #[Pure], #[Override], readonly properties, ServiceProvider bindings, config completeness, version consistency, fluent interface, Facade accessor) | ✅ | `EventsPhase48ProductionTest.php` |
| Phase 49 production (strict types verification, config completeness, ConditionEngine operator coverage, WildcardMatcher all documented patterns + extractWildcards, DomainEvent serialization round-trip + fromArray invalid eventType, Triggerable interface signature, ConditionEngineContract implementation, Facade accessor, EventLog status constants, DispatchTriggerJob constructor params, EscapesWildcardLike trait usage in EventManager/ManagesHistory/ManagesSubscriptions) | ✅ | `EventsPhase49ProductionTest.php` |
| Phase 52 production (EventsListCommand/EventsSubscriptionsCommand is_string() type guards, EventsLogCommand is_string() verification, README test file count accuracy, version consistency, all console --event commands type safety, no deprecated !== null guard, ServiceProvider 11 commands, config completeness, strict types enforcement, final class verification, Pest.php registration) | ✅ | `EventsPhase52ProductionTest.php` |
| Phase 53 production (EventsRegisterCommand/EventsSubscribeCommand is_string() type guards, EventsRetryCommand is_string() guard, comprehensive quality verification: strict types, final classes, console commands, Singleton/transient bindings, contract identity, config completeness, status constants, DomainEvent roundtrip, WildcardMatcher #[Pure], EscapesWildcardLike trait usage, model config-driven tables, key types, API surface completeness, fluent interface, Pest.php completeness, version consistency) | ✅ | `EventsPhase53ProductionTest.php` |
| Phase 54 production (strict types sweep, final class verification, interface contracts, ServiceProvider binding lifecycle, Facade accessor, Config completeness all 6 sections, EventLog status constants, DomainEvent roundtrip + empty eventType, WildcardMatcher comprehensive, EscapesWildcardLike SQL escaping, model config-driven table names, model key types, DispatchTriggerJob property types, console command prefix verification, version consistency, Subscription signPayload edge cases, Subscription matchesEvent patterns, ActionResolver errors, composer.json autoload PSR-4, trait method presence, fluent interface return types, PHPStan config, license headers, EventManager public method return types) | ✅ | `EventsPhase54ProductionTest.php` |
| Phase 55 production (CHANGELOG sync, comprehensive audit: strict types all source files, final classes 10 core + 11 console commands, interface contracts, ServiceProvider binding lifecycle, Facade accessor, Config completeness 6 sections + sub-keys, EventLog status constants + $statuses array, model config-driven table names + UUID string keys + non-incrementing, DomainEvent readonly + roundtrip, WildcardMatcher readonly class + #[Pure] + comprehensive patterns, EscapesWildcardLike SQL escaping, ActionResolver error cases, Subscription signPayload edge cases, ConditionEngine full 19-operator matrix + AND logic + dot notation, WildcardMatcher exact/cross-segment/catch-all/empty, ConditionEngine comparison/equality/array/string/null/between/regex operators, license headers, command prefix verification, version consistency, composer.json autoload/extra, migration existence, CHANGELOG sync, phpstan.neon.dist level 9) | ✅ | `EventsPhase55ProductionTest.php` |
| Phase 56 production (ConditionEngine deep nesting/type coercion/float comparison, WildcardMatcher advanced patterns/edge cases, TriggerBuilder validation, SubscriptionBuilder URL scheme rejection, DomainEvent serialization roundtrip, EventManager fireModel validation, EventLog status transitions, Subscription signPayload determinism, DispatchTriggerJob config, cache invalidation lifecycle, ServiceProvider binding integrity, strict_types enforcement) | ✅ | `EventsPhase56ProductionTest.php` |
| Phase 57 production (rector LARAVEL_130 upgrade, fake() return type precision, all protected/private method return type declarations across core classes, DomainEvent/EventManager/ActionResolver readonly promoted property verification, DispatchTriggerJob property types, ServiceProvider #[Override], Facade accessor, model casts/boot verification, console command final classes, WildcardMatcher readonly + #[Pure], strict types enforcement, config completeness, version consistency, migration/factory existence, EventLog status constants) | ✅ | `EventsPhase57ProductionTest.php` |
| Phase 58 production (config duplicate comment cleanup, phpstan config hardening, comprehensive final audit: strict types, final classes, readonly promoted properties, interface contracts, singleton/transient bindings, facade accessor, config completeness, model config tables, DomainEvent readonly/roundtrip, WildcardMatcher readonly + #[Pure], EscapesWildcardLike, EventLog status constants, EventManager API surface, fluent interface, composer.json structure, phpstan config, console command prefix, migration/factory existence) | ✅ | `EventsPhase58ProductionTest.php` |
| Health command (diagnostic, JSON output, cache check, ServiceProvider registration) | ✅ | `EventsHealthCommandTest.php` |
| Phase 59 production (12 console commands final, 10 core classes final, WildcardMatcher readonly + #[Pure], DomainEvent readonly properties, EventLog status constants, interface contracts, ServiceProvider singleton/transient bindings, config completeness) | ✅ | `EventsHealthCommandTest.php` |
| Phase 60 production (strict types, final classes, readonly + #[Pure], ConditionEngine between() null-coalescing safety, all operators with empty payload, config completeness, model key types, factory states, facade accessor, phpstan config, WildcardMatcher comprehensive, license headers, version consistency) | ✅ | `EventsPhase60ProductionTest.php` |
| Phase 61 production (source strict_types, core/console finals, readonly promoted props, DomainEvent readonly, interfaces, #[Pure], status constants, config 7 sections, ServiceProvider bindings, Facade accessor, config-driven tables, UUID key types, trait composition, API surface, fluent interface, 19-operator matrix, WildcardMatcher comprehensive, version consistency, test count) | ✅ | `EventsPhase61ProductionTest.php` |
| Health command integration (JSON output keys, healthy system checks, global disable detection, inactive subscription detection, getConfig() helper refactor consistency) | ✅ | `EventsHealthIntegrationTest.php` |

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
| `subscribeWebhook(string $event, string $url, array $conditions, int $priority)` | `string` | Quick-create a webhook subscription |
| `getEventHistory(?string $event, ?string $status, ?string $triggerId, int $limit)` | `Collection` | Query event log history |
| `getStats(?Carbon $since)` | `array` | Get aggregate statistics |
| `purgeLogs(Carbon $before, bool $includePending)` | `int` | Purge old event logs |
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
| `empty` | `['notes', ['empty']]` | Value is empty |
| `not_empty` | `['notes', ['not_empty']]` | Value is not empty |
| `starts_with` | `['email', ['starts_with', 'admin@']]` | String prefix |
| `ends_with` | `['domain', ['ends_with', '.com']]` | String suffix |
| `matches` | `['code', ['matches', '/^[A-Z]{3}$/']]` | Regex match (ReDoS-protected) |

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `EVENTS_QUEUE_CONNECTION` | `queue.default` | Queue connection for async triggers |
| `EVENTS_QUEUE` | `default` | Queue name for async triggers |
| `EVENTS_RETRY_TRIES` | `3` | Max retry attempts for async jobs |
| `EVENTS_RETRY_BACKOFF` | `60,300,900` | Comma-separated backoff seconds |
| `EVENTS_LOG_RETENTION_DAYS` | `30` | Days before logs are eligible for purge |
| `EVENTS_LOG_PURGE_PENDING` | `false` | Include pending logs in purge |
| `EVENTS_SUB_MAX_FAILURES` | `10` | Webhook failure threshold for auto-deactivation |
| `EVENTS_SUB_TIMEOUT` | `30` | Webhook HTTP timeout (seconds) |
| `EVENTS_SUB_SIGNATURE_ALGORITHM` | `sha256` | HMAC signature algorithm for webhook payloads |
| `EVENTS_WILDCARD_CACHE_TTL` | `300` | Wildcard trigger cache TTL (seconds) |
| `EVENTS_DISABLED` | `false` | Globally disable the event system |

## Changelog

### v2.7.0

- **Added**: `EventsFinalProductionAuditTest.php` — 22 comprehensive production audit tests covering: strict types enforcement across all source files, final class verification for core classes and console commands, readonly properties on DomainEvent/EventManager/ActionResolver, interface contract verification (ConditionEngineContract, Triggerable), ServiceProvider binding correctness, Facade accessor verification, config completeness (all 7 sections + sub-keys), model config-driven table names, EventLog status constants, WildcardMatcher readonly class + #[Pure] attributes, EscapesWildcardLike trait composition, DispatchTriggerJob ShouldQueue implementation, migration/factory file existence, composer.json autoload/extra structure, phpstan.neon.dist level 9, EventManager public API surface (21 methods), version consistency.
- **Changed**: Version bumped to 2.7.0, test file count updated to 132.

### v2.6.0

- **Refactored**: `EventManager` — extracted `getConfig()` helper method to DRY up repeated config repository access pattern (was duplicated 4× across `isDisabled()`, `setEnabled()`, `fire()`, `getTriggerCacheTtl()`). All config reads now go through a single typed method.
- **Added**: `EventsHealthIntegrationTest.php` — integration tests for health command output structure, global disable detection, inactive subscription detection, and `getConfig()` helper consistency.
- **Added**: CI workflow badge to README.
- **Changed**: Version bumped to 2.6.0, test file count updated to 131.

### v2.5.0

- **Improved**: `phpstan.neon.dist` — tightened `ignoreErrors` to only target genuinely dynamic Eloquent magic methods (orderByPriority, scopeOrderByPriority, facade dynamic calls, model payload access). Removed overly broad catch-all patterns that could mask real type errors.
- **Added**: `TriggerBuilder::actions()` — input validation that rejects non-string and empty string entries with `InvalidArgumentException`.
- **Added**: `EventsPhase62ProductionTest.php` — 11 new production tests covering: EventManager delete trigger (existing/non-existent/with-logs), TriggerBuilder actions validation (non-string/empty/valid/dedup), WildcardMatcher #[Pure] attribute verification (all 3 static methods), EventManager global disable integration (setEnabled toggle, fire suppression).
- **Changed**: Version bumped to 2.5.0, test file count updated to 130.

### v2.4.0

- **Added**: `EventsPhase61ProductionTest.php` — 37 comprehensive production readiness tests covering: all 31 source files strict_types enforcement, all core classes final verification, WildcardMatcher readonly final class, all 12 console commands final, EventManager constructor readonly promoted properties, ActionResolver constructor readonly promoted properties, DomainEvent readonly properties (promoted + body-set), ConditionEngine/ConditionEngineContract interface implementation, WebhookAction/Triggerable interface implementation, DispatchTriggerJob/ShouldQueue implementation, WildcardMatcher #[Pure] attribute verification, EventLog status constants (4 statuses), config completeness (7 sections + sub-keys), ServiceProvider binding correctness (singletons, transients, contract identity), Facade accessor, models config-driven table names, model string UUID key types + non-incrementing, EscapesWildcardLike null return for non-wildcard + asterisk-to-percent, DomainEvent roundtrip identity preservation, ConditionEngine full 19-operator matrix + AND logic, WildcardMatcher comprehensive patterns (exact, single/cross-segment, catch-all, multi-wildcard, extract), EventManager API surface completeness (21 public methods), TriggerBuilder/SubscriptionBuilder fluent interface, phpstan.neon.dist level 9, composer.json structure (name/type/PHP version/autoload/extra.laravel), migration + factory file existence (3+3), license headers on all source files, ManagesHistory/ManagesSubscriptions/EscapesWildcardLike trait composition, EventManager fire/fireModel validation, Subscription signPayload null secret, ActionResolver non-existent class error, getStats zero-state structure, EventManager global disable behavior, WildcardMatcher regex special characters safety, version consistency, test file count accuracy.
- **Changed**: Version bumped to 2.4.0, test file count updated to 129.

### v2.3.0

- **Fixed**: `ConditionEngine::between()` now uses null-coalescing (`$value[0] ?? null`) when extracting range boundaries from the condition value — prevents PHPStan 9 array-access-on-mixed errors and gracefully handles malformed condition arrays with missing indices.
- **Added**: `EventsPhase60ProductionTest.php` — 20 comprehensive production tests covering: strict types enforcement, final class verification (7 core + 12 console commands), interface contracts (ConditionEngineContract, Triggerable), WildcardMatcher readonly class + #[Pure] attributes, DomainEvent readonly properties + roundtrip identity, ConditionEngine between() null-coalescing safety, ConditionEngine all operators with empty payload, null/not_null operators, EventLog status constants, ServiceProvider register/boot methods, Facade accessor, config completeness (7 sections + sub-keys), migration file existence, factory definitions + required keys, model UUID string key types + non-incrementing, phpstan config level 9, composer version consistency, Laravel extra providers/aliases, EventManager/ActionResolver constructor readonly params, EscapesWildcardLike trait method + behavior, WildcardMatcher comprehensive patterns + extract + findMatchingPatterns, license headers, test file count accuracy.
- **Changed**: Version bumped to 2.3.0, test file count updated to 128.

### v2.2.0

- **Fixed**: `EventsHealthCommand` now reuses the `Trigger::count()` result from the database connectivity check instead of issuing a duplicate query for the active triggers section — reduces DB round-trips during health diagnostics.
- **Fixed**: `CreatesApplication` test bootstrap now includes `events.disabled` config key in the default test configuration — previously tests relying on `EventManager::isDisabled()` would get `null` instead of the expected default `false`.
- **Changed**: Version bumped to 2.2.0.

### v2.1.0

- **Added**: `zeroboiler:events:health` diagnostic command — checks event system health including database connectivity, trigger counts, subscription health, recent event statistics, queue configuration, and optional cache connectivity verification. Supports `--json` output for monitoring dashboards and `--check-cache` for cache driver validation.
- **Added**: `EventsHealthCommandTest.php` — 12 new tests covering health command existence, signature, options, return types, #[Override] attribute, ServiceProvider registration, strict types enforcement, license header, and a comprehensive Phase 59 production audit (12 console commands final, 10 core classes final, WildcardMatcher readonly + #[Pure], DomainEvent readonly, EventLog status constants, interface contracts, ServiceProvider bindings, config completeness).
- **Changed**: Test file count updated to 127.

### v2.0.0

- **Fixed**: Duplicate "Wildcard Cache" comment block removed from `config/events.php` — the section appeared twice due to a merge artifact.
- **Improved**: `phpstan.neon.dist` — added `checkMissingIterableValueType: false` for PHPStan 9 compatibility with Eloquent collection returns.
- **Added**: `EventsPhase58ProductionTest.php` — 28 new production readiness tests covering: config duplicate comment cleanup, phpstan config hardening, comprehensive final audit (strict types, final classes, readonly promoted properties, interface contracts, singleton/transient bindings, facade accessor, config completeness, model config tables, DomainEvent readonly/roundtrip, WildcardMatcher readonly + #[Pure], EscapesWildcardLike, EventLog status constants, EventManager API surface, fluent interface, composer.json structure, phpstan config, console command prefix, migration/factory existence).
- **Changed**: Version bumped to 2.0.0 (production-ready milestone), test file count updated to 126.

### v1.99.0

- **Added**: `events.disabled` config key and `EVENTS_DISABLED` env variable — globally disables the event system. When true, all `fire()` calls silently return without dispatching triggers.
- **Added**: `EventManager::isDisabled()` — check if the event system is globally disabled.
- **Added**: `EventManager::setEnabled(bool $enabled)` — enable or disable the event system at runtime (in-memory only).
- **Added**: Facade `@method` annotations for `isDisabled()` and `setEnabled()`.
- **Added**: `EventsGlobalDisableTest.php` — 13 new tests covering global disable/enable behavior, fire suppression, edge cases, and facade proxy.
- **Added**: Config completeness test for `disabled` key.
- **Updated**: README with global disable documentation, API reference, env variable, troubleshooting entry, and config example.
- **Changed**: Version bumped to 1.99.0, test file count updated to 125.

### v1.98.0

- **Fixed**: `rector.php` upgraded from `LaravelSetList::LARAVEL_120` to `LaravelSetList::LARAVEL_130` for Laravel 13 compatibility.
- **Fixed**: `tests/helpers.php` `fake()` function return type changed from `mixed` to `\Faker\Generator` for PHPStan 9 type precision.
- **Added**: `EventsPhase57ProductionTest.php` — 45 new production tests covering: rector LARAVEL_130 upgrade verification, fake() helper return type precision, all protected/private method return type declarations across ConditionEngine/EventManager/WebhookAction/EventsRedeliverCommand/TriggerBuilder/EventsLogCommand/EventsFireCommand/EscapesWildcardLike, DomainEvent/EventManager/ActionResolver readonly promoted property verification, DispatchTriggerJob property types (triggerId/event/payload/tries/backoff/queue/connection/eventLogId), ServiceProvider #[Override] on register/boot, Facade getFacadeAccessor, model casts/boot return type verification, all 11 console commands final class verification, all 9 core classes final verification, WildcardMatcher readonly class + #[Pure] on all public methods, strict types enforcement across all source files, config completeness 6 sections, version consistency, all 3 migration files existence, all 3 factory files existence, EventLog status constants.
- **Changed**: Version bumped to 1.98.0, test file count updated to 124.

### v1.97.0

- **Fixed**: `phpstan.neon.dist` — added missing `treatPhpDocTypesAsCertain: false`, `checkGenericClassInNonGenericObjectType: false`, `checkUninitializedProperties: false` for PHPStan 9 compatibility
- **Fixed**: `phpstan.neon.dist` — added `Call to an undefined static method` and `Method .*::.*\(\) invoked on .*` ignore patterns for Eloquent `__call`/`__callStatic` magic method resolution without Larastan
- **Fixed**: `ManagesHistory::getStats()` — added `is_numeric()` guard on `avg('duration_ms')` result before `round()` call for PHPStan 9 mixed-to-float safety
- **Fixed**: `ManagesHistory::getStats()` — added `isset()` and `is_numeric()` guards on aggregate query row properties (`$row->event`, `$row->count`) for PHPStan 9 type safety
- **Fixed**: `EventsRedeliverCommand` — replaced loose `mixed` `$subscriptionId` with explicit `is_string()` type guard at extraction point, eliminating redundant `is_string()` checks downstream
- **Refactored**: `SubscriptionBuilder::save()` — removed redundant `@var` docblock on `$schemeRaw` (type is already inferred from `is_string()` guard)
- **Added**: `EventsPhase56ProductionTest.php` — 30 new production tests covering: ConditionEngine deep nesting/type coercion/float comparison, WildcardMatcher advanced patterns/edge cases, TriggerBuilder validation edge cases, SubscriptionBuilder URL scheme rejection (ftp/file), DomainEvent serialization roundtrip, EventManager fireModel validation, EventLog status transitions, Subscription signPayload determinism, DispatchTriggerJob config behavior, cache invalidation lifecycle, ServiceProvider binding integrity (singleton/transient/contract), all src files strict_types enforcement
- **Changed**: Version bumped to 1.97.0, test file count updated to 123.

### v1.96.0

- **Fixed**: CHANGELOG.md was missing v1.95.0 entry — synchronized with README changelog.
- **Added**: `EventsPhase55ProductionTest.php` — 55 new production tests covering: CHANGELOG sync verification, comprehensive Phase 55 audit (strict types enforcement across all source files, final class verification for 10 core + 11 console commands, interface contracts — ConditionEngineContract and Triggerable, ServiceProvider binding lifecycle — singleton/transient/contract identity, Facade accessor correctness, Config completeness — all 6 sections with sub-keys: table_names 3 entries, queue 2 entries, retry 2 entries, retention 2 entries, subscriptions 4 entries, wildcard_cache_ttl 1 entry, EventLog status constants + $statuses array consistency, model config-driven table names for Trigger/EventLog/Subscription, UUID string key type verification, non-incrementing verification, DomainEvent readonly properties + roundtrip preservation, WildcardMatcher readonly class verification + #[Pure] attribute on all 3 public methods + comprehensive patterns — exact/single-segment/cross-segment/catch-all/empty, EscapesWildcardLike SQL escaping — null for non-wildcard/asterisk-to-percent/special chars, ActionResolver error handling — non-existent class and non-Triggerable class, Subscription signPayload edge cases — null/empty/deterministic, ConditionEngine full 19-operator matrix + AND logic + dot notation nesting, license headers on all source files, all console commands zeroboiler:events: prefix verification, composer.json version consistency with README badge, composer.json PSR-4 autoload + dev autoload + extra.laravel structure, all 3 migration file existence, phpstan.neon.dist level 9 configuration presence).
- **Changed**: Version bumped to 1.96.0, test file count updated to 122.

### v1.95.0

- **Fixed**: README test file count corrected from 120 to 125 (reflects actual test files on disk).
- **Added**: `EventsPhase54ProductionTest.php` — 54 new production tests covering: strict types sweep (all source files), final class verification (6 core classes), interface contracts (Triggerable, ConditionEngineContract), ServiceProvider binding lifecycle (singleton for EventManager/ConditionEngine/ActionResolver, transient for TriggerBuilder/SubscriptionBuilder, contract identity), Facade accessor, Config completeness (all 6 sections + sub-keys: table_names, subscriptions, retry, queue, retention, wildcard_cache_ttl), EventLog status constants, DomainEvent roundtrip + empty eventType rejection, WildcardMatcher comprehensive patterns (exact, single/cross-segment, catch-all, multi-wildcard), EscapesWildcardLike SQL escaping (null for non-wildcard, asterisk-to-percent, special char escaping), model config-driven table names (all 3 models), model key types (string, non-incrementing), DispatchTriggerJob property types via reflection, console command prefix verification (all 11 commands), version consistency (composer.json vs README badge), Subscription signPayload edge cases (null/empty/deterministic), Subscription matchesEvent (exact, single-segment, cross-segment wildcard), ActionResolver error handling (non-existent, non-Triggerable), composer.json autoload PSR-4 structure, ManagesHistory/ManagesSubscriptions trait method presence, TriggerBuilder/SubscriptionBuilder fluent interface return types, PHPStan config structure, license headers, EventManager public method return types, Pest.php registration.

### v1.94.0

- **Fixed**: `EventsRegisterCommand` now uses `is_string()` type guards on `$event` and `$action` arguments instead of `(string)` casts — `$this->argument()` returns `string|array|null` in Laravel 13, and silent coercion of array values could cause unexpected behavior.
- **Fixed**: `EventsSubscribeCommand` now uses `is_string()` type guards on `$event`, `$url`, `$secret`, and `$filter` inputs — simplified from redundant ternary checks to clean guard clauses.
- **Fixed**: `EventsRetryCommand` now uses `is_string()` type guard on `$status` option — `$this->option('status')` can return `bool|int|array`.
- **Added**: `EventsPhase53ProductionTest.php` — 35 new production tests covering: type guard verification on 3 console commands, strict types enforcement, final class verification, Singleton/transient bindings, config completeness, DomainEvent roundtrip, WildcardMatcher #[Pure], EscapesWildcardLike usage, model config-driven tables, API surface completeness, fluent interface, Pest.php registration, version consistency.

### v1.91.0

- **Fixed**: `EventsPhase49ProductionTest.php` — corrected 3 wrong class references (`\ZeroBoiler\ConditionEngine` → `\ZeroBoiler\Events\ConditionEngine`) and fixed `DomainEvent` round-trip assertion (`user.eventType` → `user.registered`).
- **Fixed**: Registered `EventsPhase49ProductionTest.php` in `tests/Pest.php` so it runs with the full test suite.
- **Updated**: README test file count from 118 to 117 (reflects actual test files on disk).

### v1.90.0

- **Fixed**: Tightened `phpstan.neon.dist` ignore patterns — replaced overly broad `Access to an undefined property.*#` with specific patterns targeting `Eloquent\Model::` dynamic properties and `$this->payload` only, preventing real type errors from being silently ignored.
- **Added**: `EventsPhase48ProductionTest.php` — 30+ new production tests covering: phpstan.neon.dist tightened ignore patterns, parseActions return type correctness for all 5 formats (single, JSON array, JSON object, classes+params, empty), ConditionEngine unknown/empty operator behavior, WebhookAction payload key stripping completeness, SubscriptionBuilder auto-secret format validation, DispatchTriggerJob constructor config normalization (string/array backoff, invalid config defaults, queue/connection), factory state method return type consistency, strict types enforcement, final class verification (11 core + 11 console commands), interface contracts, WildcardMatcher `#[Pure]` verification, DomainEvent/EventManager readonly properties, ServiceProvider binding lifecycle (singleton/transient/contract identity), config completeness (all 6 sections + sub-keys), EventLog status constants, version consistency, fluent interface return types, Facade accessor, `#[Override]` verification, model config-driven table names.
- **Changed**: Version bumped to 1.90.0.

### v1.89.0

- **Fixed**: `tests/helpers.php` removed 7 unused Faker provider `use` import statements — providers are now referenced with fully-qualified class names in `fake()` to avoid dead imports.
- **Added**: `EventsPhase47ProductionTest.php` — 80+ new production tests covering: helpers.php clean imports, WildcardMatcher readonly class + `#[Pure]` on all public methods, DomainEvent readonly promoted properties, EventManager readonly promoted properties, ActionResolver readonly promoted properties, ConditionEngine `#[Override]`, WebhookAction `#[Override]` + Triggerable interface, DispatchTriggerJob final class + ShouldQueue + typed properties, all 11 console commands final verification, ServiceProvider register/boot + `#[Override]`, Facade accessor + `#[Override]`, config completeness (all 6 sections + sub-keys), EventLog status constants, Triggerable/ConditionEngineContract interface contracts, ManagesHistory/ManagesSubscriptions trait methods, EscapesWildcardLike trait usage, TriggerBuilder/SubscriptionBuilder fluent interface return types, WildcardMatcher comprehensive patterns (exact, single, cross-segment, catch-all, extract, findMatching), ConditionEngine full 19-operator matrix + AND logic + dot notation + empty conditions + unknown operators, DomainEvent roundtrip + empty eventType validation, Subscription signPayload edge cases (null/empty/deterministic), factory state return types (all 3 factories), strict types enforcement, license headers, version consistency, Pest.php registration, model config-driven table names, key types/non-incrementing, phpstan.neon.dist configuration, EventManager public API surface completeness (20 methods).
- **Changed**: Version bumped to 1.89.0, test file count updated to 115.

### v1.88.0

- **Added**: `SubscriptionScopeForEventTest.php` — 6 new tests covering `Subscription::scopeForEvent()`: exact match, wildcard match, cross-segment wildcard, no-match for unrelated events, wildcard pattern input, and Builder chaining.
- **Added**: `ManagesHistoryPurgeLogsTest.php` — 4 new tests covering `ManagesHistory::purgeLogs()`: old completed/failed purge (default), includePending mode, no-op when no logs are old enough, and graceful empty database handling.
- **Changed**: Version bumped to 1.88.0, test file count updated to 114.

### v1.87.0

- **Added**: `phpstan.neon.dist` — PHPStan level 9 configuration file (previously missing).
- **Added**: `EventLog::$error` explicit `string` cast — ensures PHPStan 9 type safety for `markAsFailed()` assignments.
- **Added**: `EventLogTest` — error string cast and null error tests.
- **Added**: `PhpstanConfigTest.php` — verifies phpstan.neon.dist existence, level 9, and configuration.
- **Added**: `ProductionFinalAuditTest.php` — comprehensive final audit: EventLog casts, rector.php structure, strict_types enforcement across all source files, final class verification (all core classes + console commands), readonly WildcardMatcher.
- **Fixed**: README table row formatting — Phase 42/43/44 rows had malformed `||` prefixes, now corrected to single `|`.
- **Fixed**: README attribute references — `#[\Readonly]` and `#[\Override]` backslash-escaped references corrected to `#[Readonly]` and `#[Override]`.
- **Changed**: Version bumped to 1.87.0, test file count updated to 112.

### v1.86.0

- **Fixed**: `EventManager::parseActions()` now trims whitespace from action strings before processing — whitespace-only strings return empty array instead of producing invalid single-entry arrays.
- **Fixed**: `EventsLogCommand::handle()` now uses `is_string()` type guard on `$this->option('status')` — `$this->option()` returns `string|array<bool>|null` and the previous null guard did not protect against array values.
- **Added**: `EventsPhase46ProductionTest.php` — 22 new production tests covering: parseActions whitespace trimming, EventsLogCommand type safety, README Phase 45 coverage entry, strict types enforcement, final class verification, `#[\Override]` verification, WildcardMatcher `#[\Pure]`, config completeness, model config-driven tables, EventLog status constants, ServiceProvider bindings, Facade accessor, DomainEvent readonly/roundtrip, EscapesWildcardLike, version consistency, interface contracts, license headers, TriggerBuilder/SubscriptionBuilder fluent interface, composer.json structure, phpstan.neon.dist structure.
- **Added**: Phase 45 test coverage table entry in README.
- **Changed**: Version bumped to 1.85.0, test file count updated to 109.

### v1.84.0

- **Added**: `EventsPhase45ProductionTest.php` — 55+ comprehensive final audit tests: rector.php valid LaravelSetList constant, all source files strict_types, final class verification (14 classes), no `#[\Readonly]` attribute usage, readonly keyword on DomainEvent/EventManager properties, return type declarations on all public methods, `#[\Override]` on ConditionEngine::matches and WebhookAction::handle, `#[\Pure]` on all 3 WildcardMatcher static methods, trait composition verification, PHPStan config, composer.json structure, config completeness, model config-driven table names, console command prefix verification, migration structure, EventLog status constants, interface contracts, ServiceProvider binding methods, Facade accessor, factory definitions, .gitignore completeness, version consistency, source file license headers.
- **Changed**: Version bumped to 1.84.0, test file count updated to 108.

### v1.83.0

- **Added**: `EventsPhase44ProductionTest.php` — 22 new production tests covering: CHANGELOG.md presence and version consistency, composer.json autoload PSR-4 structure, rector.php presence and Laravel 130 set, .gitignore completeness, database directories (migrations/factories), phpstan config level and ignore errors, all source file license headers, facade `@method` annotations completeness, WebhookAction private method return types, EventLog `casts()` return type, DomainEvent `fromArray()` edge cases (empty array, non-string eventType, invalid UUID, invalid datetime, full roundtrip), ManagesHistory `getStats()` return structure shape, test file registration in Pest.php vs standalone, EventsUnsubscribeCommand `$id` string cast, EventsSubscribeCommand `$event`/`$url` string casts, EventsRedeliverCommand `buildRedeliverBody()` private method existence and return type, version consistency (composer.json vs README badge).
- **Added**: CHANGELOG.md entries for v1.81.0, v1.82.0, v1.83.0 (previously only in README changelog section).
- **Changed**: Version bumped to 1.83.0, test file count updated to 107.

### v1.82.0

- **Added**: `EventManager::fire()` now accepts an optional `$async` parameter — when `true`, forces all matching triggers to be dispatched asynchronously via queue, overriding individual trigger `async` settings. Useful for CLI fire-and-forget scenarios.
- **Added**: `--async` flag to `zeroboiler:events:fire` command — allows firing events asynchronously from the CLI (`php artisan zeroboiler:events:fire order.placed --async`).
- **Fixed**: **BUG** `zeroboiler:events:fire` `--payload` key=value pairs now correctly defer to `--json` keys (JSON takes precedence). Previously, `--payload` would silently override JSON keys despite the comment claiming otherwise.
- **Fixed**: `ConditionEngine` unknown operator `default` branch now returns `false` instead of falling through to `strictEquals()` with the entire array operand — prevents misleading match results for unrecognized array-syntax operators.
- **Changed**: Facade `@method` annotation for `fire()` updated to include `$async` parameter; CLI commands table updated; API reference updated.
- **Changed**: README test file count updated from 105 to 106; version bumped to 1.82.0.

### v1.81.0

- **Added**: `EventsPhase42ProductionTest.php` — 37 new production tests covering: fireModel key collision with model attributes named `model`/`model_class`/`action` (metadata overrides), fireModel with empty attributes, parseActions whitespace-only/empty classes array/single-in-classes-format edge cases, DispatchTriggerJob eventLogId initial null state, WebhookAction internal key stripping verification, ConditionEngine empty conditions/missing key, WildcardMatcher dot-separated no-wildcard patterns/extractWildcards no-wildcard, SubscriptionBuilder/TriggerBuilder default priority, EventManager CRUD empty state (listTriggers/getTrigger/deleteTrigger/getEventHistory), EventLog status constants completeness, Subscription signPayload edge cases (null/empty/deterministic/different payloads), ServiceProvider command registration completeness, Facade getFacadeAccessor correctness, DomainEvent UUID freshness/timestamp freshness/toArray key completeness, config completeness all 6 sections (table_names/subscriptions/retry/retention/queue/wildcard_cache_ttl), composer.json version badge consistency.
- **Changed**: README test file count updated from 104 to 105; version bumped to 1.81.0.

### v1.80.0

- **Added**: `EventsLifecycleIntegrationTest.php` — comprehensive integration tests covering: full lifecycle (fire→dispatch→log→stats), trigger priority ordering (higher priority dispatched first, same priority ordered by creation time), wildcard cache invalidation (new trigger visibility, disable prevents matches), event history filtering (by status, wildcard event, limit), purge logs (completed/only/pending-include), DomainEvent roundtrip (all fields preserved, missing eventType throws, invalid UUID generates fresh, invalid datetime uses now), ActionResolver edge cases (non-existent class, non-Triggerable class, valid class), WildcardMatcher comprehensive (exact, single/cross/catch-all, multiple, extract, findMatching), ConditionEngine all operators (equality, >, between, in, contains, null/not_null, starts_with/ends_with, nested dot notation, AND logic).
- **Changed**: README test file count updated from 103 to 104; version bumped to 1.80.0.

### v1.79.0

- **Fixed**: README test file count corrected from 104 to 103 (one test file removed).

### v1.78.0

- **Fixed**: `EventsPhase41ProductionTest.php` was missing from `Pest.php` `uses()` call — tests in that file were not getting Laravel bootstrap and would fail at runtime.

### v1.77.0

- **Fixed**: README test count updated from 103 to 104 to match actual test files on disk.
- **Changed**: Version bumped to 1.77.0.

### v1.76.0

- **Added**: `EventsPhase41ProductionTest.php` — README test count accuracy verification, composer.json autoload structure, phpstan level 9, final class verification (EventManager, ConditionEngine, WildcardMatcher), DomainEvent readonly properties, WildcardMatcher #[Pure] on key public methods, console command zeroboiler:events: prefix validation, config completeness (all 6 sections), version badge consistency.
- **Fixed**: README test file count verified accurate (103 files on disk).

### v1.75.0

- **Added**: `EventsPhase40ProductionTest.php` — 60+ comprehensive production tests covering: strict types enforcement across all src/ files, final class verification (all core classes + 11 console commands), interface contracts (ConditionEngineContract, Triggerable), `#[\Override]` attribute verification (all overrides on ConditionEngine, WebhookAction, all 11 console commands, all 3 models), ServiceProvider binding lifecycle (EventManager/ConditionEngine/ActionResolver singletons, TriggerBuilder/SubscriptionBuilder transients, ConditionEngineContract identity), Facade accessor, config completeness (all 6 sections + sub-keys), config type validation, model config-driven table names (Trigger/EventLog/Subscription), EventLog status constants, DomainEvent readonly properties + roundtrip preservation + UUID freshness, WildcardMatcher `#[\Pure]` attributes on all public methods, WildcardMatcher comprehensive pattern matching (exact/single-segment/cross-segment/catch-all/multi-wildcard/extract/findMatching), EscapesWildcardLike behavior (null for non-wildcard, asterisk-to-percent conversion), ActionResolver error handling (non-existent class, non-Triggerable class), ConditionEngine full 19-operator matrix + AND logic + null safety + dot notation + between auto-normalize + ReDoS protection, Subscription signing (null/empty secret, deterministic signatures, config-driven algorithm), Subscription failure tracking (hasExceededFailures config/custom), Subscription matchesEvent (exact, single-segment wildcard, cross-segment wildcard), EventManager CRUD edge cases (enable/disable/deleteTrigger non-existent, getTrigger non-existent), EventManager fire/fireModel validation (empty event, zero event, empty class), TriggerBuilder fluent interface (all methods return self), TriggerBuilder resolveActions deduplication + action/merge, SubscriptionBuilder fluent interface, cache invalidation lifecycle, getStats zero-state structure, version consistency (composer.json vs README badge), Pest.php completeness (all 100 registered files exist on disk, no duplicates, standalone test files exist), total test file count accuracy (102 files), composer.json structure (PSR-4 autoload, extra.laravel providers, PHP ^8.5), console command zeroboiler:events: prefix verification, model key types/incrementing, parseActions all 5 JSON formats + edge cases, Migration file existence, DispatchTriggerJob config-driven properties, ManagesHistory/ManagesSubscriptions trait method verification, file license headers, EventLog status lifecycle (markAsCompleted/markAsFailed), phpstan.neon.dist existence.
- **Changed**: Version bumped to 1.75.0, test file count updated to 102.

### v1.74.0

- **Added**: `--event` option to `zeroboiler:events:log` command — filter event logs by event name with wildcard support (e.g., `--event=order.*`). Previously, the log command only supported `--trigger` and `--status` filters, despite `EventManager::getEventHistory()` supporting event filtering.
- **Added**: `EventsLogCommandEventFilterTest.php` — 7 new tests covering: exact event filter, wildcard event filter, no filter (all logs), no matching events, combined event + status filter, combined event + trigger filter, limit with event filter.
- **Fixed**: `EventManager::deleteTrigger()` docblock — added missing `@param` annotation for PHPStan 9 and IDE hover tooltips.
- **Changed**: Version bumped to 1.74.0, test file count updated to 101.

### v1.73.0

- **Fixed**: README test file count corrected to 100 (98 in Pest.php + 2 standalone plain PHP tests).
- **Added**: `EventsPhase39ProductionTest.php` — 10 new tests covering: README version badge consistency (composer.json vs README), test file count accuracy (100 files on disk, 98 in Pest.php + 2 standalone), standalone test file verification (EscapesWildcardLikeTest, WildcardMatcherTest not in Pest.php), composer.json version format validation, Pest.php test file listing completeness (all 98 registered files exist on disk).
- **Changed**: Version bumped to 1.73.0.

### v1.71.0

- **Fixed**: README Test Coverage table had malformed markdown rows (Phase 26–37) starting with `||` instead of `|` — these rows would not render correctly in GitHub/VS Code markdown previews.
- **Added**: `@throws \InvalidArgumentException` docblock on `TriggerBuilder::save()` and `SubscriptionBuilder::save()` — documents validation exceptions for PHPStan 9 and IDE hover tooltips.
- **Improved**: `DispatchTriggerJob::$backoff` docblock upgraded from `@var array<int, int>` to `@var list<int>` with description — PHPStan 9 semantic `list` type is more precise for sequential integer arrays.
- **Added**: `EventsPhase38ProductionTest.php` — 12 new tests covering: README table row format (no leading `||`), TriggerBuilder save @throws docblock, SubscriptionBuilder save @throws docblock, DispatchTriggerJob backoff typed docblock, version consistency.
- **Changed**: Version bumped to 1.71.0, test file count updated to 99.

### v1.70.0

- **Added**: `EventsPhase37ProductionTest.php` — 30+ new production tests covering: ConditionEngine `between()` non-numeric range value rejection, float comparison operators, null actual handling; SubscriptionBuilder URL scheme enforcement with `parse_url` edge cases; `fake()` helper return type verification; Trigger/EventLog model relations; Subscription `matchesEvent` comprehensive patterns; WebhookAction subscription failure/delivery tracking; DomainEvent `fromArray` edge cases (missing payload, non-array payload, invalid UUID, roundtrip preservation); config completeness (all top-level and sub-keys); ServiceProvider binding lifecycle (singleton/transient/contract identity); Facade accessor; version consistency; strict types enforcement; final class verification; model config-driven table names.
- **Fixed**: **PHPStan 9** `ConditionEngine::between()` now explicitly validates range boundary values as numeric before passing to `min()`/`max()` — previously PHPStan 9 would flag `mixed` values being passed to these functions.
- **Fixed**: **PHPStan 9** `SubscriptionBuilder::save()` now guards `parse_url()` return value with `is_array()` check before accessing array keys — `parse_url()` can return `false|int` in edge cases (e.g., malformed URLs), and PHPStan 9 flags direct array access on non-array values.
- **Fixed**: **PHPStan 9** `tests/helpers.php` `fake()` function now has `@return \Faker\Generator` PHPDoc annotation for proper type inference when `fake()->word()` and similar methods are called in tests.
- **Changed**: Version bumped to 1.70.0, test file count updated to 98.

### v1.69.0

- **Added**: Database Schema section in README with full table/column/index documentation for `triggers`, `event_logs`, and `event_subscriptions`.
- **Added**: Class-level docblock for `DomainEvent` describing its role as an immutable value object for event sourcing.
- **Added**: `EventManagerValidationTest.php` — 18 new tests covering: fireModel validation, listTriggers filtering, getTrigger/deleteTrigger edge cases, subscribeWebhook.
- **Fixed**: `EventManagerAdvancedTest` — corrected empty event fire test to match actual behavior (throws `InvalidArgumentException`).
- **Changed**: Version bumped to 1.69.0, test file count updated to 97.

### v1.68.0

- **Added**: `EventsPhase36ProductionTest.php` — 80+ new comprehensive tests covering: trait composition (EventManager/ManagesHistory/ManagesSubscriptions/EscapesWildcardLike), ConditionEngine getNestedValue edge cases (missing key, non-array intermediate, non-nested, deeply nested), operator matrix comprehensive (empty array condition, single key-value, multi-condition AND, strictEquals cross-type, between inverted, matches long pattern/nested quantifiers), WildcardMatcher special chars (parens, plus, brackets, exact, catch-all empty rejection, extractWildcards edge cases), DomainEvent serialization (toArray key completeness, fromArray preservation, empty eventType throws, non-string throws, fresh UUID, readonly verification), model fillable/hidden/casts arrays (Trigger/EventLog/Subscription), config file structure and default values, factory state methods return types, file header license comment presence, namespace declarations, fire/fireModel validation (empty event, "0" event, empty model class, empty action), TriggerBuilder validation (empty event, no action), SubscriptionBuilder validation (empty event, empty URL, non-HTTP URL), conditions conversion, DispatchTriggerJob config-driven properties (tries, backoff, queue, connection, eventLogId), ServiceProvider binding integrity (singleton/transient verification), model scopes (Trigger/EventLog/Subscription), WebhookAction interface compliance, EscapesWildcardLike behavior, ActionResolver error handling, composer.json autoload/extra.laravel structure, migration file integrity (up/down methods, file count), phpstan config structure, facade @method completeness, cache TTL edge cases, CRUD edge cases (getTrigger/deleteTrigger/enable/disable non-existent), TriggerBuilder resolveActions (deduplication, merge), version consistency.
- **Changed**: Version bumped to 1.68.0, test file count updated to 96.

### v1.67.0

- **Fixed**: `Subscription::signPayload()` now handles `hash_hmac()` returning `false` (e.g., if the algorithm is unsupported) — previously the `string|false` return was passed directly as `string`, which could cause PHPStan 9 type errors at runtime. Now returns empty string on `false`.
- **Added**: `EventsPhase35ProductionTest.php` — 40+ new tests covering: signPayload hash_hmac false safety (null secret, empty secret, valid secret, deterministic signatures, different payloads, sha256 correctness), strict types enforcement across all src/ files, final class verification for all 25 classes (core + models + console commands), interface contracts (ConditionEngineContract, Triggerable), ServiceProvider singleton/transient binding lifecycle (EventManager, ConditionEngine, ActionResolver, ConditionEngineContract, TriggerBuilder, SubscriptionBuilder), facade accessor correctness, config completeness all 6 sections (table_names, queue, retry, retention, subscriptions, wildcard_cache_ttl) with sub-key validation, EventLog status constants, DomainEvent readonly keyword verification (all 4 properties), WildcardMatcher #[Pure] attribute verification (all 3 static methods), model config-driven table names (Trigger, EventLog, Subscription), model UUID key types and non-incrementing, return type declarations on all EventManager/TriggerBuilder/SubscriptionBuilder public methods, console command handle() return type verification (all 11 commands), version consistency.
- **Fixed**: README test file count corrected from 99 to 95.
- **Changed**: Version bumped to 1.67.0, test file count updated to 95.

### v1.64.0

- **Added**: `EventsPhase32ProductionTest.php` — 35+ new comprehensive tests covering: DomainEvent explicit constructor args preservation and fromArray roundtrip, SubscriptionBuilder auto-generate secret config verification, conditions empty-to-null conversion, WebhookAction URL validation edge cases (missing key, empty string, non-string value), signPayload custom algorithm (sha384) and invalid algorithm fallback, DispatchTriggerJob property types via reflection (tries/queue/connection/backoff), ConditionEngine AND logic with 2 and 3 conditions, WildcardMatcher special pattern extraction (single-star, double-star, multi-wildcard, non-matching), TriggerBuilder deduplication integration verification, EventLog status transitions (pending→completed, pending→failed), Subscription matchesEvent comprehensive (exact, single-segment, cross-segment), EventManager fire with no matching triggers, config type validation (table_names strings, subscriptions key types), ServiceProvider binding verification (singleton/transient), Facade accessor verification, strict types enforcement across all source files, final class verification (11 core classes), model config-driven table names (Trigger, EventLog, Subscription), version consistency (composer.json format), EscapesWildcardLike trait (asterisk-to-percent, non-wildcard null), getStats zero-state structure, Trigger enabled scope, Subscription active scope, DomainEvent fromArray roundtrip preservation.
- **Changed**: Version bumped to 1.64.0, test file count updated to 97.

### v1.63.0

- **Fixed**: **SECURITY** `SubscriptionBuilder::save()` now rejects non-HTTP(S) URL schemes (`ftp://`, `file://`, `mailto:`, etc.) — previously `filter_var(FILTER_VALIDATE_URL)` accepted these, allowing SSRF-like abuse through webhook subscriptions.
- **Added**: `EventsPhase31ProductionTest.php` — 8 new tests covering HTTP-only URL scheme enforcement: reject ftp://, file://, mailto://; accept http:// and https://; validation ordering (event name checked before URL scheme, empty URL checked before scheme).
- **Changed**: Version bumped to 1.63.0, test file count updated to 96.

### v1.62.0

- **Added**: New factory state methods: `EventLogFactory::withEvent()`, `forTrigger()`, `withPayload()`, `withDuration()` — allows precise test data creation. `SubscriptionFactory::withFailureCount()`, `withDeliveryCount()`, `withPriority()` — useful for failure/delivery threshold testing. `TriggerFactory::forEvent()`, `withAction()`, `withName()` — convenient for event-specific trigger testing.
- **Added**: `EventsPhase29ProductionTest.php` — 65+ production-ready tests covering: all new factory state methods (EventLogFactory, SubscriptionFactory, TriggerFactory), factory base definition structure validation, EventManager API surface (on/register/enable/disable/deleteTrigger/invalidateTriggerCache), TriggerBuilder/SubscriptionBuilder fluent interface verification, DomainEvent identity roundtrip preservation and readonly enforcement, DomainEvent fromArray edge cases (missing/empty eventType, invalid UUID), ConditionEngine comprehensive operator matrix (all 19 operators + AND logic + empty conditions), WildcardMatcher exhaustive patterns (exact, single-segment, cross-segment, catch-all, extract, findMatching), config completeness and type validation (all 6 sections), ServiceProvider binding lifecycle (singleton/transient/contract identity), Facade accessor verification, EventLog/Trigger/Subscription model UUID key types, EventLog status constants consistency, model casts completeness, strict types enforcement (all source files), final class verification (all core classes), console command prefix and return type verification, WildcardMatcher #[Pure] attribute, ConditionEngine/WebhookAction #[Override] verification, trait composition verification (ManagesHistory, ManagesSubscriptions, EscapesWildcardLike), Subscription signPayload edge cases, Subscription hasExceededFailures config-driven, migration file existence and structure, config publish tags, version consistency, EventManager CRUD on non-existent IDs, fire/fireModel empty validation.
- **Changed**: Version bumped to 1.62.0, test file count updated to 94.

### v1.61.0

- **Fixed**: Removed explicit `: void` return type declaration from `DomainEvent::__construct()` — PHP 8.5 constructors should not declare return types.
- **Fixed**: `EventsUnsubscribeCommand::handle()` now casts the `id` argument to `string` at assignment time instead of at usage — cleaner and PHPStan 9 compliant.
- **Added**: `EventsPhase28ProductionTest.php` — 55+ production-ready tests covering: DomainEvent constructor void return type verification, EventsUnsubscribeCommand early string cast verification, strict types enforcement sweep, final class verification, interface contract verification, constructor parameter type verification, DomainEvent readonly property verification, config completeness, config type validation, facade accessor, WildcardMatcher #[Pure], EventLog status constants, model config-driven table names, model key type/incrementing consistency, model relation return types, model casts completeness, ServiceProvider bindings, TriggerBuilder/SubscriptionBuilder fluent interface, EventManager public method return types, version consistency, EscapesWildcardLike SQL escaping, ActionResolver types, WebhookAction/ConditionEngine #[Override], console command prefix verification, config publish tags, ManagesHistory/ManagesSubscriptions trait composition, DomainEvent roundtrip/toArray keys, DispatchTriggerJob property types, migration file existence, Pest.php Phase 28 registration.
- **Changed**: Version bumped to 1.61.0, test file count updated to 93.

### v1.60.0

- **Added**: `EventsPhase27ProductionTest.php` — 55 new tests covering: strict types enforcement sweep (all src files), trait composition validation (EscapesWildcardLike usage in EventManager/ManagesHistory/ManagesSubscriptions), ManagesHistory and ManagesSubscriptions public method existence, config publish tags (events-config, events-migrations), console command zeroboiler:events: prefix verification, all console commands final class + typed signature/description properties, interface parameter types (Triggerable handle, ConditionEngineContract matches), DomainEvent toArray key completeness and fromArray roundtrip preservation, DomainEvent final class and readonly properties, Facade resolved instance type verification, model relation return types (Trigger→EventLog HasMany, EventLog→Trigger BelongsTo), ServiceProvider binding verification (ConditionEngineContract singleton, TriggerBuilder/SubscriptionBuilder transient, ActionResolver singleton), ConditionEngine full 19-operator coverage + AND logic + null operand rejection, constructor parameter types (EventManager, TriggerBuilder, SubscriptionBuilder, DispatchTriggerJob), model casts completeness, WildcardMatcher #[Pure] verification on all 3 static methods, EventManager all public method return type declarations, final class sweep (11 core classes), composer.json version consistency with README badge, model boot UUID generation (empty id), EventLog markAsCompleted/markAsFailed existence, WebhookAction Triggerable interface + final verification, ConditionEngine ConditionEngineContract interface + final verification, EscapesWildcardLike SQL special char escaping.
- **Fixed**: Removed `phpstan.neon` from git tracking (local IDE override file, already in `.gitignore`).
- **Changed**: Test file count updated to 92.
- **Changed**: Version bumped to 1.60.0.

### v1.55.0

- **Fixed**: Duplicate `EventsPhase17ProductionTest.php` entry in `Pest.php` `uses()` list — now correctly lists `EventsPhase18ProductionTest.php` once.
- **Added**: `@phpstan-return` annotation on `EventManager::parseActions()` — explicitly declares the PHPStan return type (`list<string|array{class: string, params?: array<string, mixed>}>`) for stricter type inference at PHPStan level 9.
- **Added**: `EventsPhase22ProductionTest.php` — 80+ new tests covering: Pest.php duplicate detection and completeness, strict types enforcement (all source + test files), final class verification (all core + console commands), return type declarations (all public methods on core classes), `#[Override]` attribute verification (interfaces, models, ServiceProvider), ServiceProvider binding lifecycle (singleton/transient/contract identity), config completeness (all 6 sections + sub-keys), Facade accessor correctness, ConditionEngine full operator matrix (all 19 operators + empty + null + AND logic + dot notation), WildcardMatcher comprehensive patterns (exact, *, **, catch-all, multi-wildcard, findMatching, extractWildcards), EscapesWildcardLike trait behavior (null, *, SQL escaping), DomainEvent readonly properties + roundtrip preservation + immutability, model scopes and relations verification, EventLog status constants consistency, `parseActions` `@phpstan-return` annotation presence, version consistency (composer.json vs README badge), WildcardMatcher `#[Pure]` attribute, Subscription signPayload edge cases, TriggerBuilder/SubscriptionBuilder save validation, ActionResolver error handling, ManagesHistory/ManagesSubscriptions trait method existence, fluent interface return types, config merge verification.
- **Changed**: Version bumped to 1.55.0, test file count updated to 82.

### v1.53.0

- **Changed**: `rector.php` upgraded from `LaravelSetList::LARAVEL_120` to `LaravelSetList::LARAVEL_130` for Laravel 13 compatibility.
- **Added**: `EventsProductionReadinessTest.php` — 40+ new tests covering: ServiceProvider binding lifecycle (singleton/transient/contract identity), Facade resolution and proxy verification, config type safety (all 6 sections), fire/fireModel validation, trigger CRUD (getTrigger/deleteTrigger non-existent), subscription management (unsubscribe/getSubscription/listSubscriptions), cache invalidation lifecycle (enable/disable), EventLog status constants, DomainEvent roundtrip preservation, WildcardMatcher `#[Pure]` attribute verification, TriggerBuilder/SubscriptionBuilder fluent interface return types, final class verification (all core + console command classes), strict types enforcement across all source files, version consistency, Subscription signPayload edge cases (null/empty secret), Subscription hasExceededFailures config-driven, Subscription matchesEvent (exact, single-segment wildcard, cross-segment wildcard).
- **Changed**: Version bumped to 1.53.0, test file count updated to 80.

### v1.52.0

- **Fixed**: **CRITICAL** `ConditionEngine` comparison operators (`>`, `>=`, `<`, `<=`) now guard against null `$value` in addition to null `$actual` — previously, conditions like `['amount', ['>', null]]` would evaluate incorrectly due to PHP type juggling (e.g., `100 > null` → true). All 4 operators now require both operands to be non-null and numeric.
- **Added**: `ConditionEngineNullComparisonTest.php` — 11 new tests covering: null value comparison rejection (all 4 operators), null actual value comparison rejection (all 4 operators), correct non-null comparison evaluation, between operator with null value, between operator with inverted range, `in`/`not_in` with null value, and `null`/`not_null` operator behavior.
- **Changed**: Version bumped to 1.52.0, test file count updated to 79.

### v1.51.0

- **Added**: `EventsPhase20ProductionTest.php` — 45+ new tests covering: strict types enforcement across all src/ and test files, final class verification (core classes, models, console commands), interface contract verification (ConditionEngineContract, Triggerable), service provider binding verification (singleton for EventManager/ConditionEngine/ActionResolver, transient for TriggerBuilder/SubscriptionBuilder, contract identity), facade accessor and proxy resolution, config completeness (all 6 sections, table_names, subscriptions, retry keys), model table name config-driven verification, model UUID key type verification, EventLog status constants, DomainEvent readonly properties and roundtrip preservation, WildcardMatcher #[Pure] attribute verification, TriggerBuilder/SubscriptionBuilder fluent interface return types, ConditionEngine/WebhookAction #[Override] attribute verification, subscription matchesEvent (exact, single-segment, cross-segment), cache invalidation lifecycle, trigger CRUD (getTrigger/deleteTrigger non-existent), EventManager fire/fireModel validation, version consistency between composer.json and README.
- **Added**: `database_path()` and `storage_path()` helper functions in `tests/helpers.php` — previously missing, could cause errors in test contexts that use Laravel global helpers.
- **Changed**: Version bumped to 1.51.0, test file count updated to 78.

### v1.50.0

- **Added**: `#[\Override]` attribute on all 11 console command `handle()` methods for PHPStan override verification — consistent with models, ServiceProvider, ConditionEngine, and WebhookAction which already had this attribute.
- **Added**: `EventsPhase19ProductionTest.php` — 17 new tests covering: console command `#[\Override]` attribute verification (all 11 commands), console command `final` class verification, typed `$signature`/`$description` property verification, `Illuminate\Console\Command` inheritance verification, `handle()` return type verification, strict types enforcement across all src/ and test files, config completeness validation (all 6 top-level keys, all sub-keys), ServiceProvider binding verification (singleton/transient/contract identity), final class verification (11 core classes).
- **Changed**: Version bumped to 1.50.0, test file count updated to 77.

### v1.49.0

- **Added**: `events-migrations` publish tag — migrations can now be published independently with `php artisan vendor:publish --tag=events-migrations`, useful when you want to customize migration files before running them.
- **Added**: `EventManagerCrudTest.php` — 11 new tests covering `getTrigger()` (found, not found, empty string, soft-deleted) and `deleteTrigger()` (success, non-existent, empty string, single target deletion, soft-delete preservation, post-delete fire no-dispatch, combined get+delete workflow).
- **Fixed**: `phpstan.neon.dist` trailing comma on `reportUnmatchedIgnoredErrors` — invalid NEON syntax that could cause PHPStan configuration parsing errors.
- **Fixed**: `phpstan.neon.dist` now includes ignore patterns for Laravel global helper functions (`database_path`, `config_path`) used in ServiceProvider — resolves PHPStan "Undefined function" errors without Larastan.
- **Changed**: Version bumped to 1.49.0, test file count updated to 76.
- **Changed**: `Pest.php` updated with `EventManagerCrudTest.php` in `uses()` call.

### v1.48.0

- **Added**: `EventManager::listTriggers()` — list triggers with optional event name (supports wildcards) and enabled status filtering.
- **Added**: `EventManager::getTrigger()` — retrieve a single trigger by ID.
- **Added**: `EventManager::deleteTrigger()` — delete a trigger by ID with automatic cache invalidation.
- **Added**: Facade `@method` annotations for all new EventManager methods.
- **Added**: `EventsPhase17ProductionTest.php` — 50+ new tests covering: listTriggers (unfiltered, by event, wildcard, enabled, limit, empty), getTrigger (exists/not found), deleteTrigger (success/not found/cache invalidation), fireModel (attributesToArray/toArray), TriggerBuilder multi-action encoding, config-driven table names, EventLog status lifecycle, DomainEvent edge cases, ConditionEngine operators, WildcardMatcher comprehensive, config completeness, Subscription signPayload, fire/fireModel validation, cache invalidation.
- **Fixed**: Config `events.queue.connection` now uses `?:` (elvis operator) instead of `??` to correctly handle `env()` returning `false` for unset variables.
- **Changed**: Version bumped to 1.48.0, test file count updated to 75.

### v1.47.0

- **Fixed**: `DomainEventImmutabilityTest` was checking for `#[\Readonly]` **attribute** via `getAttributes()` + `array_any`, which is incorrect for PHP 8.5 — the `readonly` keyword modifier sets `ReflectionProperty::isReadOnly()` flag, not a `#[\Readonly]` attribute. Test was silently passing on PHP < 8.5 but would fail on PHP 8.5+.
- **Added**: `#[\Override]` attribute on `ConditionEngine::matches()` — explicitly marks the interface contract implementation for PHPStan override verification.
- **Added**: `#[\Override]` attribute on `WebhookAction::handle()` — explicitly marks the interface contract implementation for PHPStan override verification.
- **Added**: `EventsPhase16ProductionTest.php` — 22 new tests covering: EventLog scope methods (scopeWithStatus, scopeFailed, scopePending, scopeCompleted, non-existent status), EventLog markAsCompleted/markAsFailed behavior, Trigger scopes (scopeEnabled, scopeAsync, scopeOrderByPriority), Trigger→EventLog and EventLog→Trigger relations, Subscription scopes (scopeActive, scopeOrderByPriority), Subscription::matchesEvent (exact, single wildcard, cross-segment wildcard), #[\Override] attribute verification on ConditionEngine::matches and WebhookAction::handle, DomainEvent readonly keyword verification (isReadOnly() flag present, #[\Readonly] attribute absent).
- **Changed**: Version bumped to 1.47.0, test file count updated to 74.

### v1.46.0

- **Fixed**: `Pest.php` was missing `EventsPhase14ProductionTest.php` in `uses()` call — tests in that file were not getting Laravel bootstrap and would fail at runtime.
- **Fixed**: `EventManager::executeTrigger()` now extracts `$basePayload` once before the action loop — previously `$log->payload` was re-read and type-checked on every iteration, which could cause inconsistent behavior if the payload was mutated during an action handler.
- **Fixed**: `config/events.php` `queue.connection` now uses null coalescing (`??`) instead of passing `config()` return as `env()` default — prevents non-string config values from being passed as the `env()` second argument.
- **Changed**: `rector.php` upgraded from `LaravelSetList::LARAVEL_110` to `LaravelSetList::LARAVEL_120` for Laravel 13 compatibility.
- **Added**: `EventsPhase15ProductionTest.php` — 55 new tests covering: executeTrigger basePayload extraction (multi-action, null payload, action params merge), TriggerBuilder null/empty conditions save behavior, SubscriptionBuilder URL validation (reject invalid, accept HTTPS), ConditionEngine empty conditions with various payloads, WildcardMatcher findMatchingPatterns type safety/extractWildcards edge cases, ServiceProvider binding lifecycle (singleton/transient/contract identity), Config type validation (all 6 config sections), Facade accessor, Model config-driven table names, TriggerBuilder/SubscriptionBuilder fluent interface return types, DispatchTriggerJob config-driven properties (tries/queue/connection/backoff formats), EventLog status constants, DomainEvent roundtrip/fresh UUID, Cache invalidation (save/disable/enable), Strict types enforcement across all source files, Final class verification (10 core classes).
- **Changed**: Version bumped to 1.46.0, test file count updated to 73.

### v1.45.0

- **Fixed**: `TriggerFactory::action` field now generates realistic action class names (`App\Actions\{word}Action`) instead of random sentences — produces valid-looking class FQNs for factory-created triggers, improving test realism.
- **Added**: `EventsPhase14ProductionTest.php` — 62 new tests covering: TriggerBuilder action merging integration (overlapping action()/actions() deduplication, prepend behavior), ConditionEngine strictEquals edge cases (0 vs false vs empty string vs null, array vs string, same-type strict comparison, in/not_in with empty array, numeric string vs int comparison, matches operator with null subject/value), WildcardMatcher edge cases (regex special chars in event name, empty pattern, empty patterns array, no wildcards extractWildcards, segment count mismatch, #[\Pure] attribute verification), EventManager cache TTL edge cases (non-integer config, negative value, zero value, valid custom value), EventManager enable/disable with non-existent triggers, DomainEvent freshness (UUID uniqueness per occur(), timestamp advancement, toArray required keys), DomainEvent fromArray edge cases (empty array, numeric eventType, string payload), DispatchTriggerJob constructor edge cases (empty backoff string, single-value backoff, comprehensive property type verification via reflection), Subscription signPayload with empty secret, hasExceededFailures with non-integer config and zero config, matchesEvent patterns (exact, single-segment wildcard, cross-segment wildcard), Factory default state validation for all 3 models (Trigger, EventLog, Subscription), TriggerFactory realistic action format verification, ActionResolver error cases (non-existent class, non-Triggerable class), EventManager fire/fireModel empty validation, TriggerBuilder/SubscriptionBuilder validation (empty event, no action, empty URL, invalid URL) and fluent interface (all methods return self), WebhookAction missing URL variants (missing, empty, null, non-string), ConditionEngine empty conditions, WildcardMatcher findMatchingPatterns with empty patterns and no matches.
- **Changed**: Version bumped to 1.45.0, test file count updated to 72

### v1.44.0

- **Improved**: `TriggerBuilder::resolveActions()` now deduplicates action classes preserving insertion order (first-occurrence wins) — previously duplicates could be dispatched when `action()` and `actions()` both contained the same class, or when `actions()` itself contained duplicate entries.
- **Improved**: `EventManager::getMatchingTriggers()` now uses an O(1) hash set for trigger ID deduplication instead of O(n) `Collection::firstWhere()` — significant performance improvement when many wildcard triggers are registered.
- **Added**: `EventsPhase13ProductionTest.php` — 40 new tests covering: TriggerBuilder resolveActions deduplication (order preservation, single-only, empty action, all-same entries, merge scenarios), ConditionEngine full operator coverage (all 19 operators + dot notation + AND logic + ReDoS protection), WildcardMatcher comprehensive (catch-all, cross-segment, single-segment, findMatchingPatterns order, extractWildcards), EscapesWildcardLike trait behavior, DomainEvent immutability via reflection, DomainEvent fromArray preservation/invalid graceful handling, parseActions all 5 JSON formats, Config type validation for all keys, Singleton/transient binding verification (EventManager, ConditionEngine, ActionResolver, TriggerBuilder, SubscriptionBuilder, ConditionEngineContract), Facade accessor verification, strict types enforcement across all source files, Final class verification, EventLog status constants consistency, Subscription signPayload determinism, Subscription signPayload null secret, WebhookAction getTimeout/getMaxFailures config reads.
- **Changed**: Version bumped to 1.44.0, test file count updated to 71

### v1.43.0

- **Fixed**: `EventManager::parseActions()` now returns empty array for empty or `"0"` action strings — previously returned `[""]` which would cause `ActionResolver::resolve()` to fail with an unhelpful "class does not exist" error when a trigger had an empty action field.
- **Improved**: `TriggerBuilder::resolveActions()` now has explicit `@phpstan-return list<string>` annotation for PHPStan 9 strict type inference.
- **Improved**: `ConditionEngine::strictEquals()` docblock now explicitly documents behavior for non-scalar mixed types.
- **Improved**: `EventsRedeliverCommand::handle()` docblock now documents redelivery behavior and return type.
- **Added**: `EventsPhase12ProductionTest.php` — 40 new tests covering: ServiceProvider singleton/transient binding verification (EventManager, ConditionEngine, ActionResolver as singletons; TriggerBuilder, SubscriptionBuilder as transients; ConditionEngineContract binding identity), Facade accessor verification, WildcardMatcher edge cases (exact non-dotted, empty event rejection, regex special char patterns, findMatchingPatterns order preservation, extractWildcards multi-wildcard), ConditionEngine operator coverage (empty conditions, AND logic, between auto-normalize, contains with array, starts_with/ends_with), DomainEvent roundtrip preservation (eventId, occurredAt), DomainEvent edge cases (missing eventType, invalid UUID), Trigger model scopes (enabled, async), EventLog markAsCompleted/markAsFailed, Subscription signing (null/empty/deterministic), Subscription hasExceededFailures config-driven, Subscription matchesEvent (exact, wildcard, cross-segment), EventManager fire with no triggers, EventManager fireModel event name construction, Config completeness verification (all keys with correct types), Strict types enforcement across all source files, Final class verification (10 core classes), EscapesWildcardLike trait behavior (null for non-wildcard, asterisk-to-percent, special char escaping).
- **Changed**: Version bumped to 1.43.0, test file count updated to 70

### v1.42.0

- **Fixed**: **CRITICAL** `SubscriptionBuilder::save()` now wraps subscription + trigger creation in a database transaction — previously, if the trigger save failed (e.g., DB error), an orphaned subscription record was left behind with no corresponding trigger. Both records are now created atomically or rolled back together.
- **Improved**: `WebhookAction::recordSubscriptionFailure()` now accepts an already-loaded `Subscription` instance parameter — avoids a redundant `Subscription::find()` query when the subscription was already loaded in `handle()`, reducing database round-trips during webhook failure scenarios.
- **Fixed**: `EventLog::casts()` docblock closing tag was on the same line as the type annotation — corrected to proper multi-line PHPDoc format.
- **Added**: `EventsPhase11ProductionTest.php` — 13 new tests covering: SubscriptionBuilder transaction atomicity (subscription + trigger creation, validation-before-transaction, action params verification, conditions propagation, null conditions, explicit secret, multiple independent subscriptions), WebhookAction subscription failure tracking optimization (recordFailure increment, resetFailures, hasExceededFailures with config/custom max, delivery tracking with last_fired_at, signPayload determinism).
- **Changed**: Version bumped to 1.42.0, test file count updated to 69

### v1.41.0

- **Fixed**: **SECURITY** `EventManager::fire()` now validates empty event names — throws `InvalidArgumentException` for empty or `"0"` event strings, preventing silent DB queries and log pollution when called with invalid input.
- **Fixed**: `EventManager::fireModel()` now validates empty model class and action parameters — throws `InvalidArgumentException` for empty or `"0"` strings, matching the same validation pattern used by `TriggerBuilder::save()` and `SubscriptionBuilder::save()`.
- **Fixed**: `DispatchTriggerJob` now supports array-format backoff config — previously only comma-separated string format was supported (`'60,300,900'`); now `[60, 300, 900]` array format is also accepted via `events.retry.backoff` config. Float values in arrays are cast to int.
- **Added**: `EventsPhase10ProductionTest.php` — 40+ new tests covering: fire() empty event validation (empty string, "0", non-empty), fireModel() empty parameter validation (empty/zero model class and action), DispatchTriggerJob array/string/invalid backoff config, float-to-int backoff conversion, empty array backoff, tries config edge cases (zero, negative, non-integer, custom), ConditionEngine strict equality/inequality and empty/not_empty operators, WildcardMatcher comprehensive matching (exact, single-segment, cross-segment, catch-all, double-star, multiple wildcards, empty event), DomainEvent factory/roundtrip/missing fields/invalid UUID, config completeness verification (all keys and sub-keys), ServiceProvider binding verification (singleton for EventManager/ConditionEngine/ActionResolver, transient for TriggerBuilder/SubscriptionBuilder, contract binding), EventLog status constants consistency, final class verification (10 core classes), readonly keyword verification (EventManager and DomainEvent properties), strict types enforcement across all source files, facade accessor verification.
- **Changed**: Version bumped to 1.41.0, test file count updated to 68

### v1.40.0

- **Fixed**: **SECURITY** `EventsRedeliverCommand` leaked internal payload keys (`url`, `subscription_id`, `event`, `headers`) to webhook endpoints — extracted `buildRedeliverBody()` method that strips these keys, consistent with `WebhookAction::handle()`.
- **Added**: `EventsPhase9ProductionTest.php` — 38 new tests covering: redeliver `buildRedeliverBody()` payload stripping (url, event, headers, subscription_id), timestamp/redelivered/original_log_id preservation, non-array payload handling, `getTimeout()` config reads (positive, null, zero), `ConditionEngine` null-safe operators (matches/starts_with/ends_with with null actual and null value), EventLog/Subscription/Trigger boot UUID auto-generation, TriggerBuilder empty/"0" event validation and missing action validation, SubscriptionBuilder empty event and invalid URL validation, WebhookAction missing/empty URL error, DispatchTriggerJob non-int backoff config, EventLog `markAsCompleted`/`markAsFailed`, Subscription `signPayload` determinism and null/empty secret, config key type validation (table_names, subscriptions, retry, retention, wildcard_cache_ttl), contract singleton identity, ActionResolver error cases (non-existent class, non-Triggerable class).
- **Changed**: Version bumped to 1.40.0, test file count updated to 67

### v1.39.0

- **Added**: `EventsPhase8ProductionTest.php` — 26 new tests covering: ConditionEngine triple-nested dot notation and null intermediate, WildcardMatcher backslash/empty pattern/order preservation/non-matching extract, EventManager fire with empty payload (with and without conditions), cache invalidation across enable/disable/save cycle, TriggerBuilder action params with single (class key) and multiple (classes key) actions, Subscription scopeForEvent (exact and wildcard), Subscription matchesEvent with cross-segment wildcard, Subscription recordDelivery/recordFailure/resetFailures, DomainEvent fromArray extra keys/non-string eventType, contract singleton identity, ActionResolver error cases (non-existent class, non-Triggerable class), comprehensive config key type validation.
- **Improved**: Subscription model docblocks — `recordDelivery()` now documents `last_fired_at` and `delivery_count` updates, `matchesEvent()` now references `WildcardMatcher::matches()`, `hasExceededFailures()` now has `@param` annotation.
- **Changed**: Version bumped to 1.39.0, test file count updated to 66

### v1.38.0

- **Added**: `EventManagerFireModelTest.php` — 7 tests covering `fireModel()` with `attributesToArray` flattening, `toArray` fallback, plain object without serializable methods, correct event name construction, empty attributes, no-match scenario, and metadata key override behavior.
- **Added**: `ConditionEngineEdgeCasesTest.php` — 22 tests covering `strictEquals` edge cases (same-type, cross-type scalar, array vs non-array, null vs null, float vs int, empty string vs 0, false vs empty string) and operator edge cases (empty conditions, null operator, unknown operator fallback, nested null intermediate, in with empty array, between with non-numeric/non-array/inverted range/boundaries, contains/starts_with/matches with non-string actual, regex max length, regex nested quantifiers, AND logic with multiple conditions).
- **Changed**: `Pest.php` updated with 2 new test files for TestCase bootstrap.
- **Changed**: Version bumped to 1.38.0, test file count updated to 65

### v1.37.0

- **Fixed**: `EventManager::parseActions()` — tightened `array_map` callback return type from `mixed` to `string|array` for PHPStan 9 strict compliance with the documented `@return list<string|array{class: string, params?: array<string, mixed>}>` contract.
- **Fixed**: Migration integer columns changed to `unsignedInteger` — `priority` (triggers), `duration_ms` (event_logs), `priority`/`failure_count`/`delivery_count` (subscriptions) now use `unsignedInteger` instead of `integer` for data integrity (prevents negative values).
- **Added**: `EventManagerParseActionsTypeTest.php` — 7 tests validating parseActions return type correctness: each entry is either a string class name or an array with a `class` key, covering all 5 JSON formats plus mixed array edge cases.
- **Added**: 3 migration column type tests in `MigrationStructureTest.php` — unsigned integer storage verification for triggers priority, event_logs duration_ms, and subscriptions priority/failure_count/delivery_count.
- **Changed**: Version bumped to 1.37.0, test file count updated to 63

### v1.36.0

- **Fixed**: **CRITICAL** ACTUALLY replaced `#[\Readonly]` attribute with `readonly` keyword modifier in constructor property promotions across 5 source files — `EventManager`, `ActionResolver`, `TriggerBuilder`, `SubscriptionBuilder`, `DispatchTriggerJob`. The v1.35.0 changelog claimed this fix but it was not applied to the actual code; PHP 8.5 would throw fatal parse errors with `#[\Readonly]`.
- **Added**: `ProductionHardeningTest.php` — 13 new tests covering: readonly keyword verification (reflection checks on all 5 classes), `#[\Readonly]` attribute absence scan across all source files, ServiceProvider binding verification (Transient/Singleton/Contract), config merge completeness, and Pest.php test inclusion completeness.
- **Fixed**: `Pest.php` was missing 3 test files in `uses()` call — `EventSourcingTest.php`, `MigrationConfigDrivenTest.php`, and `WildcardMatcherEdgeCasesTest.php`. These tests were not getting Laravel bootstrap and would fail at runtime.
- **Changed**: Cleaned up `phpstan-baseline.neon` — removed 13 redundant individual Eloquent static method suppressions that are already covered by the blanket `#Call to an undefined static method#` ignore in `phpstan.neon.dist`.
- **Changed**: Version bumped to 1.36.0, test file count updated to 62

### v1.35.0

- **Fixed**: **CRITICAL** Replaced all `#[\Readonly]` attribute usages with `readonly` modifier keyword across 6 source files — `#[\Readonly]` was removed in PHP 8.5 and caused fatal parse errors. Affected: `DomainEvent`, `EventManager`, `ActionResolver`, `TriggerBuilder`, `SubscriptionBuilder`, `DispatchTriggerJob`.

### v1.34.0

- **Added**: `MigrationConfigDrivenTest.php` — tests verifying all 3 migrations read table names from `events.table_names` config instead of hardcoded strings, and event_logs foreign key references triggers table from config.
- **Added**: `EventSourcingTest.php` — comprehensive DomainEvent tests: factory fresh UUID/timestamp, unique IDs, toArray key completeness, fromArray preservation of eventId/occurredAt, invalid UUID/date handling, missing eventType/payload, empty data, readonly property verification.
- **Added**: `WildcardMatcherEdgeCasesTest.php` — comprehensive edge case tests: empty pattern, exact match, single/double asterisk, single-segment boundary enforcement, cross-segment matching, multiple wildcards, regex special chars, findMatchingPatterns order preservation, extractWildcards multi-wildcard/cross-segment/segment count mismatch.
- **Added**: Package Structure section to README — full directory tree with file descriptions.
- **Fixed**: **CRITICAL** All 3 database migrations now read table names from `events.table_names` config instead of hardcoded strings — previously models read from config but migrations did not, causing inconsistency when custom table names were configured.
- **Fixed**: README test file count corrected from 58 to 57.
- **Changed**: `Subscription::scopeForEvent()` docblock updated to mention `**` wildcard support.
- **Changed**: Version bumped to 1.34.0, test file count updated to 60

### v1.33.0

- **Added**: `EventsPhase7FinalTest.php` — 30+ new tests covering: `fireModel()` attribute flattening, `toArray` fallback, plain object edge cases; `WildcardMatcher` regex special char escaping, backslash patterns, `extractWildcards` multi-wildcard, `findMatchingPatterns` order preservation; `DomainEvent` `occur()` fresh UUID/timestamp, explicit constructor args, `toArray` key completeness, `fromArray` empty/non-string eventType; `DispatchTriggerJob` config edge cases (backoff array, zero tries, non-int tries); `EventManager` deterministic priority ordering with `created_at`/`id` tiebreakers; `ConditionEngine` `not_contains`, `not_empty`, triple-nested dot notation, inverted `between`.
- **Changed**: `EventManager::parseActions()` docblock — return type annotation improved from `array<int, mixed>` to `list<string|array{class: string, params?: array<string, mixed>}>`.
- **Changed**: Enhanced `@param` docblocks on `EventManager::on()`, `register()`, `fireModel()`, `enable()`, `disable()`.
- **Changed**: Version bumped to 1.33.0, test file count updated to 57

### v1.32.0

- **Added**: Comprehensive API Reference section in README — tables for all EventManager, TriggerBuilder, SubscriptionBuilder, DomainEvent methods and all 19 ConditionEngine operators with syntax examples.
- **Added**: `EventsPhase6ProductionTest.php` — 46 new tests covering: transient binding verification (TriggerBuilder, SubscriptionBuilder), singleton binding verification (ConditionEngine, ActionResolver, EventManager), ConditionEngineContract resolution identity, EventLog status constants consistency, ConditionEngine null-safe operator handling (>, >=, <, <=), numeric string strict equality, TriggerBuilder save() action encoding variants (single+params→JSON object, multi+params→classes key, single plain string, multi JSON array), DomainEvent identity semantics (same/different events), WildcardMatcher empty pattern/event/catch-all edge cases, findMatchingPatterns, EventManager fire with no triggers, cache invalidation, model table name config verification, model key type/incrementing verification, DispatchTriggerJob config-driven properties (tries, backoff, queue name, connection with empty-string fallback), Subscription signPayload (null/empty/deterministic), Subscription matchesEvent (exact/wildcard/cross-segment), getEventHistory empty collection, getStats zero-state structure.
- **Changed**: Version bumped to 1.32.0, test file count updated to 57

### v1.31.0

- **Added**: `DispatchTriggerJob::$connection` property — explicitly declared as `?string` with `null` default, replacing the previously undeclared dynamic property set in the constructor. This ensures PHPStan 9 type safety for the queue connection configuration.
- **Added**: `ReadonlyPropertiesTest` — new test verifying `DispatchTriggerJob::$connection` is typed `string` (nullable), not readonly, not promoted, has null default.
- **Added**: `EventsPhase5QualityTest.php` — 25 new tests covering: `DispatchTriggerJob::$connection` property behavior (null config, string config, empty string config, numeric config), all declared properties have native types, `ConditionEngine` null-safe operator handling (>, >=, <, <=, between, in, not_in with null actual values, null/not_null operators), `WildcardMatcher` comprehensive matching (exact, single-segment, cross-segment, findMatchingPatterns, extractWildcards), `EventManager` cache invalidation (multiple calls), `EventManager` enable/disable with non-existent IDs, `EventLog` status constants, `EventLog::$statuses` array completeness, `Trigger` scope builder instances, factory default state validation for all 3 models.
- **Fixed**: `ManagesSubscriptions::subscribeWebhook()` docblock — removed redundant `@param` annotations for parameters already documented by type declarations.
- **Changed**: Version bumped to 1.31.0, test file count updated to 56

### v1.30.0

- **Added**: `EventsPhase4Test.php` — 30+ new tests covering: ReDoS protection (long regex, nested quantifiers, safe patterns, non-matching, non-string actual), not_contains operator (string absent/present, array absent/present), not_empty operator (string, array, empty string, empty array), WildcardMatcher edge cases (empty pattern, empty event, exact match, single/double segment, findMatchingPatterns, extractWildcards with cross-segment/segment mismatch), DomainEvent::fromArray edge cases (missing eventType, invalid UUID, invalid occurredAt, valid preservation, roundtrip), wildcard cache invalidation on save/disable/enable, Subscription signPayload (null secret, empty secret, deterministic, different payloads), EventLog markAsCompleted/markAsFailed, ConditionEngineContract singleton binding
- **Fixed**: `Trigger` model `$hidden` property now has `@var array<int, string>` typed docblock annotation — consistent with EventLog and Subscription models
- **Fixed**: `Trigger::casts()` docblock closing tag was on same line as type annotation — corrected to proper multi-line format
- **Added**: Missing `handle()` method docblocks on `EventsUnsubscribeCommand` and `EventsRedeliverCommand`
- **Changed**: README test file count updated from 53 to 54; version bumped to 1.30.0

### v1.29.0

- **Fixed**: **CRITICAL** `DispatchTriggerJob::$tries` was promoted as `#[\Readonly]` constructor property but reassigned in the constructor body — this would cause a fatal error on PHP 8.5 at runtime. Changed `$tries` to a declared class property (`public int $tries = 3`) that can be safely overridden from config.
- **Fixed**: `ReadonlyPropertiesTest` updated to no longer assert `$tries` as `#[\Readonly]` promoted
- **Added**: `DispatchTriggerJob` constructor docblock explaining config-driven retry/backoff/queue behavior
- **Added**: `DispatchTriggerJobTest` — 2 new tests: tries property typed int default, tries config override
- **Added**: `ReadonlyPropertiesTest` — 1 new test: verifies `$tries` is typed `int`, NOT `#[\Readonly]`, NOT promoted, has default value
- **Changed**: Version bumped to 1.29.0

### v1.28.0

- **Added**: `DispatchTriggerJob` now reads `events.queue.connection` config to set the queue connection for async triggers — previously only queue name was configurable
- **Added**: 3 new tests for queue connection config (reads from config, defaults to null when empty)
- **Added**: `EVENTS_SUB_SIGNATURE_ALGORITHM` environment variable documented in README Environment Variables table
- **Added**: `@throws \Throwable` docblocks to `EventManager::fire()` and `EventManager::executeTrigger()` documenting exception re-throw behavior
- **Fixed**: `events.subscriptions.signature_algorithm` config was hard-coded — now reads from `EVENTS_SUB_SIGNATURE_ALGORITHM` env variable with `sha256` default
- **Changed**: Version bumped to 1.28.0

### v1.27.0

- **Added**: `ProductionDeploymentTest` — 19 tests covering: contract implementations, Triggerable interface, config merge verification, singleton/transient bindings (EventManager, ConditionEngine, ActionResolver, TriggerBuilder, SubscriptionBuilder), config defaults validation, EventLog status constants, DomainEvent immutability, WebhookAction/DispatchTriggerJob interface checks, model UUID key types, WildcardMatcher static methods, facade accessor
- **Added**: `EventsEnableCommandTest` — 4 tests: enable disabled trigger, already enabled, non-existent, cache invalidation
- **Added**: `EventsDisableCommandTest` — 4 tests: disable enabled trigger, already disabled, non-existent, cache invalidation
- **Added**: `EventsRegisterCommandTest` — 6 tests: sync trigger, async trigger, name option, priority option, auto-name generation, empty action failure
- **Added**: `EventsRetryCommandTest` — 6 tests: invalid status, no failed logs, no pending logs, disabled trigger skip, orphaned log skip, default status
- **Added**: Production Deployment Checklist section in README with environment variables reference table
- **Fixed**: `ManagesHistory::getStats()` closure parameters now typed as `object $row` with explicit `(string)` cast — PHPStan 9 compliance for untyped Eloquent aggregate result rows
- **Changed**: Pest.php `uses()` updated with 4 new test files (ProductionDeploymentTest, EventsEnableCommandTest, EventsDisableCommandTest, EventsRegisterCommandTest)
- **Changed**: Version bumped to 1.27.0

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
