# Changelog

All notable changes to the **ZeroBoiler Events** package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [1.45.0] — 2026-08-07

### Fixed
- `TriggerFactory::action` field now generates realistic action class names (`App\Actions\{word}Action`) instead of random sentences — produces valid-looking class FQNs for factory-created triggers, improving test realism and preventing parseActions from encountering unexpected string formats.

### Added
- `EventsPhase14ProductionTest.php` — 62 new tests covering: TriggerBuilder action merging integration, ConditionEngine strictEquals edge cases (0 vs false vs empty string vs null, array vs string, in/not_in with empty array, numeric string vs int comparison, matches operator with null subject/value), WildcardMatcher edge cases (regex special chars, empty pattern, #[\Pure] attribute verification), EventManager cache TTL edge cases (non-integer, negative, zero, custom), EventManager enable/disable non-existent triggers, DomainEvent freshness (UUID/timestamp), DomainEvent fromArray edge cases, DispatchTriggerJob constructor edge cases (empty backoff, single backoff, property types), Subscription edge cases (empty secret, non-integer config, zero config, matchesEvent patterns), Factory default state validation for all 3 models, TriggerFactory realistic action format, ActionResolver error cases, EventManager fire/fireModel validation, TriggerBuilder/SubscriptionBuilder validation and fluent interface, WebhookAction missing URL variants, ConditionEngine empty conditions.

### Changed
- Version bumped to 1.45.0, test file count updated to 72.

---

## [1.44.0] — 2026-08-07

### Improved
- `TriggerBuilder::resolveActions()` now deduplicates action classes preserving insertion order (first-occurrence wins). Previously, duplicate dispatch could occur when `action()` and `actions()` both contained the same class, or when `actions()` itself contained duplicates.
- `EventManager::getMatchingTriggers()` now uses an O(1) hash set for trigger ID deduplication instead of O(n) `Collection::firstWhere()` — significant performance improvement when many wildcard triggers are registered.

### Added
- `EventsPhase13ProductionTest.php` — 40 new tests covering: TriggerBuilder deduplication, ConditionEngine full operator coverage, WildcardMatcher comprehensive matching, EscapesWildcardLike trait, DomainEvent immutability, parseActions formats, Config type validation, Singleton/transient bindings, Facade accessor, strict types, Final classes, status constants, Subscription signing.

### Changed
- Version bumped to 1.44.0, test file count updated to 71.

---

## [1.40.0] — 2026-08-07

### Fixed
- **SECURITY**: `EventsRedeliverCommand` leaked internal payload keys (`url`, `subscription_id`, `event`, `headers`) to webhook endpoints during redelivery. Extracted `buildRedeliverBody()` method that strips these keys before sending — consistent with `WebhookAction::handle()`.

### Added
- `EventsPhase9ProductionTest.php` — 38 new tests: redeliver `buildRedeliverBody()` payload stripping, timestamp/redelivered/original_log_id preservation, non-array payload handling, `getTimeout()` config reads, `ConditionEngine` null-safe operators (matches/starts_with/ends_with), model boot UUID auto-generation, TriggerBuilder/SubscriptionBuilder validation, WebhookAction error cases, DispatchTriggerJob edge config, EventLog mark methods, Subscription signing determinism, config key type validation, contract singleton identity, ActionResolver error cases.

### Changed
- Version bumped to 1.40.0, test file count updated to 67.

---

## [1.39.0] — 2026-08-07

### Added
- `EventsPhase8ProductionTest.php` — 26 new tests: ConditionEngine triple-nested dot notation and null intermediate, WildcardMatcher backslash/empty pattern/order preservation, EventManager fire with empty payload, cache invalidation cycle, TriggerBuilder action params encoding, Subscription scopeForEvent/matchesEvent/recordDelivery/recordFailure/resetFailures, DomainEvent fromArray edge cases, contract singleton identity, ActionResolver error cases, config key type validation.

### Improved
- Subscription model docblocks: `recordDelivery()` documents side effects, `matchesEvent()` references `WildcardMatcher::matches()`, `hasExceededFailures()` has `@param` annotation.

### Changed
- Version bumped to 1.39.0, test file count updated to 66.

---

## [1.36.0] — 2026-08-07

### Fixed
- **CRITICAL**: ACTUALLY replaced `#[\Readonly]` attribute with `readonly` keyword modifier in constructor property promotions across 5 source files (`EventManager`, `ActionResolver`, `TriggerBuilder`, `SubscriptionBuilder`, `DispatchTriggerJob`). The v1.35.0 changelog claimed this fix but the code still contained `#[\Readonly]` — PHP 8.5 would throw fatal parse errors.
- `Pest.php` was missing 3 test files in `uses()` call (`EventSourcingTest.php`, `MigrationConfigDrivenTest.php`, `WildcardMatcherEdgeCasesTest.php`) — these tests were not getting Laravel bootstrap.

### Added
- `ProductionHardeningTest.php` — 13 new tests: readonly keyword verification via reflection, `#[\Readonly]` attribute absence scan across all source files, ServiceProvider binding verification, config merge completeness, Pest.php test inclusion completeness.

### Changed
- Cleaned up `phpstan-baseline.neon` — removed 13 redundant individual Eloquent static method suppressions (already covered by blanket ignore in `phpstan.neon.dist`).
- Version bumped to 1.36.0, test file count updated to 62.

---

## [1.35.0] — 2026-08-07

### Fixed
- **CRITICAL**: Replaced all `#[\Readonly]` attribute usages with `readonly` modifier keyword across 6 source files (`DomainEvent`, `EventManager`, `ActionResolver`, `TriggerBuilder`, `SubscriptionBuilder`, `DispatchTriggerJob`). The `#[\Readonly]` attribute was removed in PHP 8.5 and caused fatal parse errors on PHP 8.5+.

---

## [1.34.0] — 2026-08-07

### Added
- `MigrationConfigDrivenTest.php` — tests verifying all 3 migrations read table names from config.
- `EventSourcingTest.php` — comprehensive DomainEvent tests: factory, serialization, reconstruction, immutability.
- `WildcardMatcherEdgeCasesTest.php` — comprehensive edge case tests for wildcard pattern matching.
- Package Structure section to README with full directory tree.

### Fixed
- **CRITICAL** All 3 database migrations now read table names from `events.table_names` config instead of hardcoded strings. Previously only models read from config, causing inconsistency when custom table names were configured.
- README test file count corrected from 58 to 57.

### Changed
- `Subscription::scopeForEvent()` docblock updated to mention `**` wildcard support.
- Version bumped to 1.34.0, test file count updated to 60.

---

## [1.33.0] — 2026-08-07

### Added
- `EventsPhase7FinalTest.php` — 30+ new tests covering: `fireModel()` attribute flattening, `toArray` fallback, plain object edge cases; `WildcardMatcher` regex special char escaping, backslash patterns, `extractWildcards` multi-wildcard, `findMatchingPatterns` order preservation; `DomainEvent` `occur()` fresh UUID/timestamp, explicit constructor args, `toArray` key completeness, `fromArray` empty/non-string eventType; `DispatchTriggerJob` config edge cases (backoff array, zero tries, non-int tries); `EventManager` deterministic priority ordering with `created_at`/`id` tiebreakers; `ConditionEngine` `not_contains`, `not_empty`, triple-nested dot notation, inverted `between`.

### Changed
- `EventManager::parseActions()` docblock — return type annotation improved from `array<int, mixed>` to `list<string|array{class: string, params?: array<string, mixed>}>`.
- Enhanced `@param` docblocks on `EventManager::on()`, `register()`, `fireModel()`, `enable()`, `disable()`.
- Version bumped to 1.33.0.

---

## [1.32.0] — 2026-08-07

### Added
- Comprehensive API Reference section in README with tables for EventManager, TriggerBuilder, SubscriptionBuilder, DomainEvent methods and all 19 ConditionEngine operators.
- `EventsPhase6ProductionTest.php` — 46 new production readiness tests.

### Changed
- Version bumped to 1.32.0.

---

## [1.31.0] — 2026-08-07

### Added
- `DispatchTriggerJob::$connection` — explicitly declared as `public ?string $connection = null`, replacing the previously undeclared dynamic property. Ensures PHPStan 9 type safety.
- `ReadonlyPropertiesTest` — new test verifying `$connection` is typed nullable string, not readonly, not promoted, has null default.
- `EventsPhase5QualityTest.php` — 25 new tests covering: connection property behavior (null/string/empty/numeric config), all declared properties have native types, ConditionEngine null-safe operators, WildcardMatcher comprehensive matching, EventManager cache invalidation, enable/disable non-existent, EventLog status constants, Trigger scopes, factory defaults.

### Fixed
- `ManagesSubscriptions::subscribeWebhook()` docblock — removed redundant `@param` annotations for parameters already documented by type declarations.

### Changed
- Version bumped to 1.31.0, test file count updated to 56

---

## [1.30.0] — 2026-08-07

### Added
- `EventsPhase4Test.php` — 30+ new tests covering: ReDoS protection, not_contains/not_empty operators, WildcardMatcher edge cases, DomainEvent::fromArray edge cases, wildcard cache invalidation on save/disable/enable, Subscription signPayload, EventLog markAs*, ConditionEngineContract singleton binding

### Fixed
- `Trigger` model `$hidden` property now has `@var array<int, string>` typed docblock
- `Trigger::casts()` docblock closing tag corrected to proper multi-line format
- Missing `handle()` method docblocks on `EventsUnsubscribeCommand` and `EventsRedeliverCommand`

### Changed
- Version bumped to 1.30.0
- README test file count updated from 53 to 54

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
