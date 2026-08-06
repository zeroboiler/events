# Changelog

All notable changes to the **ZeroBoiler Events** package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [1.2.0] — 2026-08-06

### Fixed
- **PHPStan 9 type safety** — Resolved 20+ baseline errors with proper type guards:
  - `DomainEvent::fromArray()` now catches invalid UUID/datetime exceptions instead of letting them bubble
  - `ConditionEngine::contains()`, `starts_with`, `ends_with`, `matches` operators now guard string types before calling string functions
  - `WebhookAction::handle()` validates URL is non-empty string before processing
  - `WebhookAction` subscription ID properly typed as `string|null` throughout
  - Console commands properly cast `$this->argument()` return values to `string` before use
  - `EventManager::on()` and `subscribe()` return types narrowed with PHPDoc
  - `TriggerBuilder::resolveActions()` return type corrected to `list<string>`
- **Model scope return types** — `Trigger::scopeOrderByPriority()` and `Subscription::scopeOrderByPriority()` now use PHPDoc var annotations to satisfy PHPStan's generic Builder return types

### Changed
- **README enriched** — Added Quick Start section, PHPStan Level 9 badge, improved structure
- **Version bump** to 1.2.0

---

## [1.1.0] — 2026-08-01

### Added
- **Webhook subscription system** — external HTTP POST notifications with HMAC-SHA256 payload signing, delivery tracking, retry with backoff, and auto-deactivation after configurable failure threshold.
  - `Subscription` model (`event_subscriptions` table) with `SubscriptionBuilder` fluent API
  - `subscribe()`, `unsubscribe()`, `listSubscriptions()`, `getSubscription()`, `subscribeWebhook()` on `EventManager`
  - `WebhookAction` — HTTP POST delivery with HMAC signing, timeout, and failure tracking
  - CLI commands: `events:subscribe`, `events:unsubscribe`, `events:subscriptions`, `events:redeliver`
- **Event history & statistics** — `getEventHistory()`, `getStats()`, and `purgeLogs()` on `EventManager`
  - Aggregate counts, success/failure rates, average duration, top-fired and top-failed events
  - Wildcard-aware event filtering for history queries
- **Log retention** — `events:cleanup` console command with configurable retention days and pending-purge option
- **Dispatch depth guard** — prevents infinite recursion when triggers fire other events
- **Domain event support** — `DomainEvent` class for integration with the `zeroboiler/domain` package, including `occur()`, `fromArray()`, and `toArray()` for event sourcing workflows
- **Condition engine operators** — `starts_with`, `ends_with`, `matches` (regex), `null`, `not_null`, `empty`, `not_empty`
- **ReDoS protection** — `matches` operator limits regex length (500 chars), PCRE backtrack limit (1000), and rejects nested-quantifier patterns
- **`#[Trace]` attributes** on `EventManager` public methods for observability auto-instrumentation
- PHPStan level 6 configuration
- CI workflow (GitHub Actions) with Pint, Rector, PHPStan, and Pest coverage

### Fixed
- **DomainEvent::fromArray() null payload** — `TypeError` when `payload` key missing from persisted data; now defaults to `[]`
- **Subscription wildcard handling** — wildcard matching delegated to `WildcardMatcher` for consistent semantics; LIKE special characters (`%`, `_`, `\`) properly escaped in SQL queries
- **TriggerBuilder actions merge** — `action()` + `actions()` no longer silently discards the single action; merged with deduplication
- **Trigger matching cache** — consolidated into single cached query; exact matches queried directly, wildcards cached with 5-minute TTL
- **EventManager::fire() failure isolation** — dispatch loop continues on individual trigger failure, re-throws after all triggers attempted
- **Atomic status transition** — `DispatchTriggerJob` prevents race condition in `executeTrigger` with atomic status update
- **Duplicate UUID generation** — removed in favor of model boot callbacks as single source of truth
- **WebhookAction internal key leakage** — `url`, `event`, `headers`, `subscription_id` stripped from webhook body before delivery
- **ConditionEngine between() inverted ranges** — auto-normalizes `[100, 50]` → `[50, 100]`
- **ConditionEngine in/not_in null handling** — null value parameter no longer causes type error
- **WildcardMatcher event "0"** — catch-all pattern `*` no longer rejects the string `"0"` as empty
- **fireModel() JSON serialization** — model attributes flattened into payload root for condition engine access
- **Deterministic trigger ordering** — priority DESC, then `created_at` ASC, then `id` as final tiebreaker
- **Orphaned EventLog on queue failure** — `EventLog` now created inside `DispatchTriggerJob::handle()` instead of `dispatchTrigger()`, so no DB row if the job never runs
- **RecordDelivery race condition** — `last_fired_at` and `delivery_count` updated atomically

### Changed
- **EventManager refactored** — extracted `ManagesHistory`, `ManagesSubscriptions`, and `EscapesWildcardLike` traits to reduce class size (572 → 280 lines)
- **ConditionEngine type-safe comparison** — `strictEquals()` uses `===` for same-type, falls back to string comparison for mixed scalars
- **SubscriptionBuilder URL validation** — `filter_var(FILTER_VALIDATE_URL)` before persisting
- **TriggerBuilder save() validation** — throws `InvalidArgumentException` for missing event name or action
- PHP `^8.5`, Laravel `^13.0` minimum requirements

---

## [1.0.0] — 2026-07-15

### Added
- **DB-driven trigger management** — create, update, enable, disable, and delete triggers without code changes
- **Wildcard event matching** — `order.*` matches `order.placed`, `order.shipped`; `**` matches across segment boundaries
- **JSON condition engine** — 14 operators: `>`, `>=`, `<`, `<=`, `=`, `===`, `!=`, `!==`, `in`, `not_in`, `contains`, `not_contains`, `between`, `null`
- **Async dispatch** — queue-based execution via `DispatchTriggerJob` with configurable tries and exponential backoff
- **Trigger priority** — higher priority triggers execute first; deterministic tiebreaker via `created_at` and `id`
- **Event logging** — all dispatches logged with status (`pending`, `dispatched`, `completed`, `failed`), error message, and duration
- **Fluent TriggerBuilder** — `on()`, `action()`, `actions()`, `when()`, `async()`, `priority()`, `actionParams()`, `save()`
- **CLI suite** — 8 commands: `events:list`, `events:register`, `events:fire`, `events:log`, `events:retry`, `events:enable`, `events:disable`, `events:cleanup`
- **Eloquent models** — `Trigger` and `EventLog` with scopes (`enabled`, `failed`, `completed`, `pending`), factories, and soft deletes
- **Configurable table names** — override via `config/events.php`
- **Cache invalidation** — `invalidateTriggerCache()` called automatically on register/enable/disable
- Configuration publishable via `vendor:publish --tag="events-config"`
- Migration auto-discovery via `loadMigrationsFrom`
