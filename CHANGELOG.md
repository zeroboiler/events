# Changelog

All notable changes to the **ZeroBoiler Events** package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [1.29.0] — 2026-08-07

### Fixed
- **CRITICAL**: `DispatchTriggerJob::$tries` was promoted as `#[\Readonly]` constructor property but reassigned in the constructor body — this would cause a fatal error on PHP 8.5. Changed `$tries` to a declared class property (`public int $tries = 3`) that can be safely overridden from config.
- `ReadonlyPropertiesTest` — updated test to no longer assert `$tries` as `#[\Readonly]` promoted (was incorrect after fix)

### Added
- `DispatchTriggerJob` constructor docblock explaining config-driven retry/backoff/queue behavior
- `DispatchTriggerJobTest` — 2 new tests: tries property typed int default, tries config override
- `ReadonlyPropertiesTest` — 1 new test: verifies `$tries` is typed `int`, NOT `#[\Readonly]`, NOT promoted, has default value

### Changed
- Version bumped to 1.29.0

---

## [1.23.0] — 2026-08-06

### Added
- `EdgeCasesPhase3Test.php` — 25 tests: empty conditions, inverted between, in/not_in single-element, TriggerBuilder "0" validation, actionParams multi-action classes key, SubscriptionBuilder "0"/invalid URL validation, fire no-match, fire empty payload, enable/disable non-existent, fireModel non-Eloquent, EventLog status/mark methods, Subscription signPayload null/empty, signPayload consistency, resetFailures, WildcardMatcher boundary, findMatchingPatterns empty

### Fixed
- `phpstan.neon.dist` — added `checkGenericClassInNonGenericObjectType: false` and `checkUninitializedProperties: false`
- `phpstan.neon.dist` — added ignore patterns for Eloquent `__call` magic and Facade type resolution
- `helpers.php` — removed unused `use Faker\Generator` import

### Changed
- `DomainEvent::__construct()` — simplified null-coalescing to `??` operator
- `EventsRedeliverCommand::handle()` — replaced null+assert with instanceof type narrowing

---

## [1.22.0] — 2026-08-06

### Added
- `ContractBindingTest.php` — contract binding, constructor types, config defaults, strict-types enforcement
- `#[\Pure]` attribute on `WildcardMatcher::extractWildcards()`
- `#[\Override]` attribute on `casts()` methods in `Trigger`, `EventLog`, `Subscription`

### Fixed
- `TypedPropertiesTest.php` double-escaped `use` imports corrected
- `phpstan.neon` now includes `phpstan.neon.dist` instead of gitignored baseline
- `phpstan.neon.dist` added missing `treatPhpDocTypesAsCertain: false`

---

## [1.20.0] — 2026-08-06

### Added
- `WebhookActionTest.php` — comprehensive test suite for `WebhookAction` (14 tests): URL validation, payload signing, HMAC headers, failure recording, auto-deactivation, edge cases

### Fixed
- `config/events.php` — `queue.connection` default used `config('queue.default')` inside `env()` which is invalid; changed to `'default'` string fallback
- `WebhookAction::handle()` — eliminated duplicate `Subscription::find()` query on success path by reusing the already-loaded subscription reference
- `phpstan.neon` — added missing `bootstrapFiles`, `checkMissingIterableValueType: false`, and `Access to an undefined property` ignore rules from `.neon.dist`

### Changed
- `Pest.php` — registered `WebhookActionTest.php` in test suite
- Version bumped to 1.20.0

---

## [1.18.0] — 2026-08-06

### Added
- `phpstan.neon.dist` — PHPStan 9 configuration file (was missing)
- `rector.php` — Rector configuration with Laravel 11+ set and type declaration rules
- `WildcardMatcherTest.php` — comprehensive test suite for `WildcardMatcher` (22 tests)
- `EscapesWildcardLikeTest.php` — comprehensive test suite for `EscapesWildcardLike` trait (11 tests)

### Fixed
- `helpers.php` `config()` function used stale `static $config` variable — replaced with per-call resolution from current app instance to prevent cross-test contamination
- `CreatesApplication.php` removed invalid imports for non-existent `Tests\Faker\Factory` and `Tests\Faker\Generator` classes; cache binding now uses fully-qualified `Illuminate\Cache\CacheManager`

### Changed
- `EventManager::getMatchingTriggers()` sortBy call now uses explicit `descending: false` named parameter for PHPStan clarity
- Test file count updated from 38 to 40
- Version bumped to 1.18.0

---

## [1.17.0] — 2026-08-06

### Added
- `EventManagerAdvancedTest` — tests for TriggerBuilder action() + actions() merge/dedup (BUG-2), actionParams encoding variants, executeTrigger exception propagation (log status update + re-throw), fire with no triggers / empty event
- `fireModel()` tests now verify payload flattening (attributesToArray, toArray fallback, plain object)
- `#[\Pure]` attribute added to `WildcardMatcher::findMatchingPatterns()`
- Enhanced docblocks: `fireModel()`, `resolveActions()`, `findMatchingPatterns()`, `extractWildcards()`

### Changed
- Test file count updated from 37 to 38
- Pest.php `uses()` updated to include `EventManagerAdvancedTest.php`
- README test coverage table and test count updated

---

## [1.16.0] — 2026-08-06

### Added
- `EventsFireCommandTest` — unit tests for fire command JSON parsing, option validation, and edge cases (invalid JSON, scalar JSON, empty object, missing @file)
- `EventManagerRegisterAliasTest` — tests for `register()` alias, empty event fire, disable/enable non-existent triggers, multiple cache invalidation
- Updated README test coverage table with 2 new test files (37 total)

### Changed
- README test file count updated from 35 to 37
- Pest.php `uses()` updated to include new test files

---

## [1.15.0]

### Fixed
- `Subscription::hasExceededFailures()` now reads default threshold from `events.subscriptions.max_failures` config instead of hardcoded `10`
- All test action classes are now `final`
- Misleading comment in `EventManager::parseActions()`

---

## [1.14.0]

### Added
- `EventsRedeliverCommandTest` — unit tests for redeliver command validation
- Security Considerations section and Troubleshooting table in README

### Fixed
- `DispatchTriggerJob::$tries` moved to constructor property promotion
- `ConditionEngine::evaluateCondition()` now type-guards `$expected[0]` as string

---

## [1.13.0]

### Fixed
- Factory and model typed properties migration completed
- Relation docblocks use covariant generics
- Stale PHPStan baseline entries removed

### Added
- `WildcardIntegrationTest` — cross-segment, catch-all, multiple wildcards
- `TypedPropertiesTest` now verifies `EventLog::$hidden`

---

## [1.12.0]

### Changed
- All model properties use native PHP typed declarations
- `DomainEvent` properties use `#[\Readonly]` attribute
- `#[\Pure]` attribute on `WildcardMatcher` methods
- `EventsRedeliverCommand` config-driven timeout

### Added
- `TypedPropertiesTest` — comprehensive property type verification

---

## [1.11.0]

### Changed
- All core classes and console commands are now `final`
- Factory state closures have explicit `: array` return type annotations
- `EventsRetryCommand` uses strict null comparison
- Traits declare `@mixin` for PHPStan trait property resolution

---

## [1.10.0]

### Fixed
- `EventLog` model explicitly declares `$table`
- Factory state closures have explicit return type annotations

### Added
- `EventsFacadeProxyTest` — facade proxy, cache, action resolver, condition engine, wildcard matcher tests
- `ServiceProviderBindingTest` — singleton/transient verification, config merge completeness

---

## [1.9.0]

### Fixed
- `EventsLogCommand` null-safe operator for `$log->created_at`
- Factory state closures have explicit return type annotations

### Added
- `EventsComprehensiveTest` — 60+ new tests covering all components

---

## [1.8.0]

### Fixed
- `EventManager::getTriggerCacheTtl()` uses `assert()` type guard
- Console commands use null-safe operator for `created_at`
- Explicit `$table` on `Trigger` model
- `#[\Override]` on model `boot()` methods

### Added
- `ServiceProviderBindingTest` — comprehensive binding verification

---

## [1.7.0]

### Fixed
- `EventManager::parseActions()` type-checks classes array entries
- `WildcardMatcher::extractWildcards()` returns empty for `**` patterns
- `SubscriptionBuilder::save()` respects `auto_generate_secret` config

### Added
- `EventsEdgeCaseTest` — comprehensive edge case suite

---

## [1.6.0]

### Fixed
- `DomainEvent::__construct()` explicit null checks
- `DispatchTriggerJob::$tries` default value
- `EventManager::getMatchingTriggers()` null-safe operator
- Console command map closures refactored
- `EventsSubscribeCommand` is_string guards
- 7 stale PHPStan baseline entries removed

### Added
- `ProductionReadyTest` — comprehensive production readiness verification

---

## [1.5.0]

### Changed
- `Subscription::signPayload()` reads algorithm from config
- `DispatchTriggerJob` reads retry/backoff from config
- `WebhookAction` reads timeout/max_failures from config

### Added
- `SubscriptionSignConfigTest`
- `ConfigCompletenessTest`

### Fixed
- `EventManagerCacheTtlTest.php` was missing from Pest.php `uses()` list
- `CreatesApplication` test config was missing keys

---

## [1.4.0]

### Changed
- `DispatchTriggerJob` config-driven tries/backoff
- `WebhookAction` config-driven timeout/max_failures

---

## [1.3.0]

### Fixed
- PHPStan 9 compliance — `assert()` for container resolution
- Null-safe type checks in console commands
- `DispatchTriggerJob::failed()` instanceof check

### Added
- `EventManagerParseActionsTest`
- `EdgeCasesPhase2Test`

---

## [1.2.0]

### Fixed
- PHPStan 9 type safety — 20+ baseline errors resolved
- Model scope return types

### Changed
- README enriched

---

## [1.1.1]

### Fixed
- `ActionResolver` explicit Triggerable check
- `ConditionEngine` empty array condition handling
- `DispatchTriggerJob::failed()` uses `update()`
- SQL LIKE injection in console commands

### Added
- `{classes: [...], params: {...}}` action format support

---

## [1.1.0]

### Added
- Initial production-ready release
- Dynamic event triggers with wildcard matching
- Condition engine with 15+ operators
- Webhook subscriptions with HMAC-SHA256 signing
- Domain event value object
- Event history, statistics, and log retention
- Full CLI command set
- PHPStan level 9, Laravel Pint, Rector

## License

Proprietary. All rights reserved. © [ZeroBoiler](https://github.com/zeroboiler).
