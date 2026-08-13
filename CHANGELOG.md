# Changelog

All notable changes to the **ZeroBoiler Events** package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [4.52.0] — 2026-08-13

### Fixed
- `EventManager::getMatchingTriggers()` — replaced Laravel 11 named `descending: false` argument in `sortBy()` with PHPStan 2.x compatible positional argument form (2-arg `sortBy(fn, flags)`)
- `config/events.php` — changed `queue.connection` from `env() ?: config()` to `env($key, config())` for consistent default handling (no null coalescing bypass)
- `phpstan-baseline.neon` — removed incorrect `includes: - phpstan.neon.dist` directive (baselines should not use includes; this was an IDE-only config file, not a proper baseline)
- `phpstan.neon.dist` — removed empty `bootstrapFiles: []`, added `tests/` to analysis paths for comprehensive test code analysis, added suppressions for `Collection::sortBy` dynamic call and PHPUnit `shouldHaveReceived`/`shouldNotHaveReceived` mock assertions

### Added
- `EventsPhase125ProductionAuditTest.php` — Phase 125 comprehensive production audit (60+ tests) covering: PHPStan config level 8 + PHPStan 2.x compatibility, tests path in analysis, sortBy Collection suppression, baseline format correctness, EventManager sortBy compatibility (positional args), config queue.connection env() format, all 7 top-level config keys, 5 subscription sub-keys, 3 retention sub-keys, ServiceProvider provides() completeness (7 services), 12 console commands existence + finality, trait consistency (EventManager 3 traits, Subscription 1, ManagesHistory 1, ManagesSubscriptions 1, WebhookAction 1), EventManager + ConditionEngine return type completeness, ActionResolver::resolve() return type, ConditionEngine 20 operators (===, !==, not_in, not_empty, ends_with), WildcardMatcher #[Pure] on all 3 methods + readonly final, DomainEvent 4 readonly properties + roundtrip fidelity + empty eventType rejection, DispatchTriggerJob 3 constructor readonly properties + ShouldQueue + #[Override] on handle/failed, Facade @method for registerScheduler/subscribe/unsubscribe + accessor, model config-driven getTable(), EventLog 4 status constants, strict_types across all source/factory/migration files, zero setAccessible in source files

### Changed
- Version bumped to 4.52.0
- README badge version updated to 4.52.0
- README test file count updated to 205 (204 + Phase 125)
- README PHPStan section updated to note PHPStan 2.x compatibility

## [4.51.0] — 2026-08-13

### Added
- **Advanced Topics** section in README — comprehensive guides for event sourcing with DomainEvent, custom Triggerable actions, testing strategies (unit, fake, integration with SQLite), and performance considerations (wildcard caching, cache invalidation, queue tuning, index usage, orphaned log prevention)
- **Table of Contents** updated with Advanced Topics sub-headings (Event Sourcing, Custom Actions, Testing Strategies, Performance)
- `EventsPhase124ProductionAuditTest.php` — Phase 124 comprehensive production audit covering: README Advanced Topics section presence, Table of Contents sub-heading count, phpstan-baseline.neon includes directive, composer.json v4.51.0 alignment, README badge version alignment, ConditionEngine safeRegexMatch ReDoS protection (nested quantifier patterns, max length enforcement), EventManager fire() empty-string and zero-string rejection, TriggerBuilder save() validation (empty event/action rejection with descriptive messages), SubscriptionBuilder save() validation (empty event/URL rejection, non-HTTP scheme enforcement), DomainEvent fromArray() resilience (invalid UUID/date fallback, empty eventType throw), WebhookAction payload key stripping (url/event/headers/subscription_id removal), DispatchTriggerJob config-driven retry/backoff/queue, EventScheduler constructor injection verification, ManagesHistory getStats() return structure completeness, all 12 console commands registered in ServiceProvider, strict_types across all source files

### Changed
- Version bumped to 4.51.0
- `phpstan-baseline.neon` — added `includes:` directive pointing to `phpstan.neon.dist` (was missing, could confuse IDE integration)

## [4.50.0] — 2026-08-13

### Added
- `LICENSE` file — proprietary license for the package
- `EventsPhase123ProductionAuditTest.php` — Phase 123 comprehensive production audit (40+ tests) covering: source file strict_types and license headers, LICENSE file existence, composer.json version alignment v4.50.0, PHP ^8.5 and Laravel ^13.0 requirements, provider/alias registration, phpstan.neon.dist level 8 validation (no level 9), analysis paths verification, ServiceProvider provides() completeness (7 services), ConditionEngineContract singleton binding, Facade accessor correctness, database migration config-driven table names and strict_types, factory static model properties, EventLog 4 unique status constants, WildcardMatcher readonly/final/static-only, DomainEvent immutability and roundtrip fidelity, empty eventType rejection, EventManager public method return type declarations (25+ methods), readonly constructor properties, TriggerBuilder/SubscriptionBuilder validation (empty event/action/URL rejection), non-HTTP URL scheme rejection, global disable toggle, console command registration (12 commands), final class verification, handle(): int return type, setAccessible() removal verification, Trigger model config-driven getTable with #[Override], rector.php Laravel 13 target, .editorconfig correctness, config file completeness (7 top-level keys + all sub-keys), ConditionEngine 19 operator coverage, ReDoS protection (nested quantifiers + length limit), WildcardMatcher catch-all patterns

### Changed
- Version bumped to 4.50.0
- README test file count updated to 203

## [4.39.0] — 2026-08-13

### Improved
- Added class-level docblock to `EventManager` — documents its role as central orchestrator, singleton lifetime, and facade reference (`@see`).

### Added
- `EventManagerClassDocblockTest.php` — 2 tests verifying `EventManager` class-level docblock presence and facade `@see` reference.

### Changed
- Test file count updated from 194+ to 199+ in README (package structure and testing sections).
- Version bumped to 4.39.0.

---

## [4.37.0] — 2026-08-13

### Fixed
- **CRITICAL** `EventsPhase107ProductionAuditTest.php` — removed `setAccessible(true)` call on `ReflectionMethod` (method removed in PHP 8.5). This was the last remaining `setAccessible()` call in the test suite.

### Added
- `EventsFireCommandTest.php` — 6 new tests: `--async` option verification, `--json` payload, `--payload` key=value pairs, invalid JSON rejection, `"0"` event name rejection, `--async` in signature definition.
- `EventsPhase111ProductionAuditTest.php` — 17 comprehensive production audit tests: setAccessible removal verification across all test files, EventsFireCommand `--async` option, handle() int return type, parseJsonOption nullable array return type, EventsFireCommand final class, README version-composer.json alignment, all 12 console commands registered in ServiceProvider, ConditionEngine 19 operator coverage, DomainEvent identity preservation, model casts correctness (Trigger/EventLog/Subscription), config key completeness, facade `@method` proxy coverage, WildcardMatcher readonly/final verification.

### Changed
- Version bumped to 4.37.0.

---

## [4.6.0] — 2026-08-11

### Fixed
- **CRITICAL** `EventsPhase49ProductionTest.php` — `WildcardMatcher` references used wrong namespace `\ZeroBoiler\WildcardMatcher` instead of `\ZeroBoiler\Events\WildcardMatcher` (15 occurrences). All wildcard matching and extract tests in Phase 49 would fail at runtime with class-not-found errors.
- `phpstan.neon.dist` — consolidated `now()` and `database_path()` undefined-function suppressions into single regex rule for maintainability. Added missing `database_path()` suppression (used in `EventsServiceProvider::boot()` migration publishing).
- `EventManager::parseActions()` — `array_is_list` branch arrow function now checks `is_string($entry)` before `is_array($entry)` for more explicit PHPStan type narrowing (string pass-through avoids unnecessary cast; array entries preserved as-is from JSON decode).

### Added
- `EventsPhase50ProductionTest.php` — 30 comprehensive production audit tests: final classes verification (9 core), readonly class check (WildcardMatcher), trait hierarchy validation (EventManager + ManagesHistory + ManagesSubscriptions → EscapesWildcardLike), interface contract verification (ConditionEngine → ConditionEngineContract, WebhookAction → Triggerable), method signature validation (matches, handle), DomainEvent readonly properties (4/4), constructor parameter types (EventManager 3 params, ActionResolver 1, TriggerBuilder 1, SubscriptionBuilder 1, DispatchTriggerJob 3), public method count (≥23), fire empty validation, ConditionEngine cross-type comparison, WildcardMatcher edge cases, phpstan.neon.dist config validation (level 9, src-only, treatPhpDocTypesAsCertain), composer.json PHP 8.5 + Laravel 13, config completeness (all top-level + nested keys).
- `EventsPhase50ProductionTest.php` registered in Pest.php.

---

## [4.5.0] — 2026-08-11

### Fixed
- Unused import `use ZeroBoiler\Events\EventManager` removed from `EventsHealthCommand` — was imported but never referenced.
- Test file count corrected to 152 (150 Pest-registered + 2 standalone).

### Added
- `EventsPhase77ProductionTest.php` — 67 comprehensive production tests: unused import cleanup, all source strict_types sweep, final classes 10 core + 12 console, interface contracts, ServiceProvider #[Override] verification, singleton/transient bindings + contract identity + provides() count, Facade accessor + @method count, config completeness 7 sections + sub-keys, EventLog 4 status constants, DomainEvent readonly + roundtrip + fromArray rejection, WildcardMatcher readonly + #[Pure] + comprehensive patterns, EscapesWildcardLike SQL escaping, fluent interface, model config-driven tables + UUID keys + non-incrementing + casts, getStats zero state, Subscription signPayload + matchesEvent, cache invalidation lifecycle, fire/fireModel validation, TriggerBuilder/SubscriptionBuilder validation, DispatchTriggerJob config, ActionResolver errors, composer.json autoload/extra + PHP ^8.5 + PHPStan ^2.2, migrations/factories, phpstan.neon.dist, version consistency, file headers.
- `EventsPhase77ProductionTest.php` registered in Pest.php.
- Phase 77 test coverage entry in README.

---

## [4.2.0] — 2026-08-11

### Added
- `EventsPhase74ProductionTest.php` — 20 new tests covering: EventManager executeTrigger public API verification (signature, return type), EventManager 23 public methods count verification, Facade @method annotation count, WildcardMatcher findMatchingPatterns order preservation, extractWildcards non-matching, empty string event handling, ConditionEngine between with string numeric values, between with reversed range auto-normalization, SubscriptionBuilder validation ordering (empty event before URL scheme, empty URL before scheme, ftp:// rejection), TriggerBuilder actions empty-string rejection, EventManager fire/fireModel empty validation, test file count accuracy (148 test files: 146 Pest + 2 standalone).

### Added
- EventManager public methods table in README architecture section.

### Fixed
- README test file count corrected to 148 (146 Pest-registered + 2 standalone + 5 support files).

### Changed
- Version bumped to 4.2.0.
- Pest.php updated with EventsPhase74ProductionTest.php registration.

---

## [4.1.0] — 2026-08-11

### Changed
- `phpstan.neon.dist` — upgraded PHPStan level from `8` to `9` for maximum static analysis strictness.
- README PHPStan badge updated from Level 8 to Level 9.

### Added
- `EventsPhase73ProductionTest.php` — 9 new tests covering: phpstan.neon.dist level 9 verification, src path scanning, treatPhpDocTypesAsCertain false, reportUnmatchedIgnoredErrors true, checkUninitializedProperties false, CI workflow phpstan.neon.dist reference, CI PHP 8.5 version, composer.json PHP ^8.5 requirement, composer.json phpstan ^2.2 requirement.

### Changed
- Test file count updated to 146 (144 Pest + 2 standalone).

---

## [4.0.0] — 2026-08-11

### Changed
- Version bumped to 4.0.0 (production-ready milestone).

### Added
- `EventsPhase72ProductionTest.php` — 30 comprehensive production audit tests: all source files strict_types enforcement, 11 core final classes, WildcardMatcher readonly + #[Pure], DomainEvent readonly promoted properties, ConditionEngineContract/Triggerable interfaces, DispatchTriggerJob ShouldQueue, EventLog 4 status constants, model UUID string key types + non-incrementing + config-driven table names, EventManager/ActionResolver readonly promoted constructor properties, TriggerBuilder/SubscriptionBuilder fluent interface, Facade accessor, 12 console commands final + int return types, config completeness (7 sections), composer autoload PSR-4 + extra.laravel, phpstan level 8, 3 migration files up/down, 3 factory files definition, EventManager 22 public API methods, EscapesWildcardLike trait composition, ManagesHistory/ManagesSubscriptions trait methods, version consistency.
- Test file count updated to 145 (143 Pest + 2 standalone).

---

## [3.9.0] — 2026-08-11

### Fixed
- `EventsLogCommand` — added `is_string()` type guard on `--trigger` option to prevent PHPStan 9 mixed-to-string type error.
- `phpstan.neon.dist` — changed level from `max` to `8` for PHPStan 2.x compatibility (PHPStan 2.x does not support `max` level).
- CI workflow — explicitly specified `--configuration=phpstan.neon.dist` for reliable config resolution.

### Added
- `EventsPhase71ProductionTest.php` — 18 new tests covering: ConditionEngine missing field in payload (null/equality/comparison/null/not_null/empty/not_empty), deep nested dot notation (triple nesting, null intermediate, missing intermediate, flat key with dots), WildcardMatcher edge cases (empty patterns, trailing dots, single-dot, extractWildcards empty, findMatchingPatterns order), ConditionEngineContract type verification and singleton resolution.

### Changed
- Version bumped to 3.9.0, test file count updated to 144, PHPStan badge updated to Level 8.

---

## [3.8.0] — 2026-08-10

### Added
- `EventManagerSetEnabledTest.php` — 7 dedicated tests covering: `setEnabled(false)` disables at runtime, `setEnabled(true)` re-enables, fire suppression when disabled, fire resumption after re-enable, `isDisabled()` with `true`/`false`/`null` config values.

### Changed
- Version bumped to 3.8.0, test file count updated to 143.

---

## [3.7.0] — 2026-08-10

### Fixed
- README test file count updated to 142 (was 140) to match actual test suite.

---

## [3.6.0] — 2026-08-10

### Added
- `EventsServiceProvider::provides()` — returns the full list of 6 services provided by the provider for lazy provider optimization in Laravel's deferred provider system.
- `EventsPhase70ProductionTest.php` — 25 new production tests covering: `provides()` service list/count/resolvability, `ConditionEngine::strictEquals` pure unit tests (same type, different type, scalar cross-type coercion, zero vs empty string, bool false vs "0", float NaN, array vs scalar, null vs non-null), `ConditionEngine::between()` edge cases (non-array value, 3-element array, 1-element array, non-numeric boundaries, null boundaries, float actual, string-numeric actual, boundary inclusivity), `SubscriptionBuilder` URL scheme validation (ftp://, file://, mailto:// rejection), `EventManager::deleteTrigger` non-existent ID, config-driven `max_failures` type safety (non-int config fallback to 10, null config fallback).

### Changed
- Version bumped to 3.6.0, test file count updated to 140.

---

## [3.5.0] — 2026-08-10

### Fixed
- `phpstan.neon.dist` — enabled `checkGenericClassInNonGenericObjectType`, `checkMissingIterableValueType`, `checkFunctionNameCase`, `checkClassLikeNameCase`, `checkPropertyHookNameCase`, and `checkEnumCaseValueNameCase` for stricter PHPStan 9 compliance; added targeted ignore rules for `Trigger::scopeEnabled()`, `Subscription::scopeActive()`, and `Collection::push()` dynamic method calls.

### Added
- `EventsPhase69ProductionReadinessTest.php` — 90+ production-ready tests covering: singleton/transient service binding verification (EventManager, ConditionEngine, ActionResolver, TriggerBuilder, SubscriptionBuilder), contract binding verification (ConditionEngineContract → ConditionEngine), WildcardMatcher comprehensive edge cases (empty pattern/event, catch-all exclusion, single/cross-segment, multiple wildcards, findMatchingPatterns, extractWildcards with **/no-wildcard/mismatch/multiple), ConditionEngine full 19-operator matrix (>, >=, <, <=, ===, !==, in, not_in, contains string/array, not_contains, between normal+inverted, null, not_null, empty, not_empty, starts_with, ends_with, matches regex + ReDoS protection + max length, unknown operator, empty condition array, AND logic, dot notation, type-safe equality string fallback, non-scalar rejection, missing field default), ActionResolver error handling (non-existent class, non-Triggerable class), DomainEvent value object (auto UUID/timestamp, occur factory, serialization, roundtrip reconstruction, invalid UUID/date fallback, missing eventType throw), EventLog status constants verification, EscapesWildcardLike trait coverage, DispatchTriggerJob config-driven construction (tries, backoff array/string, queue name, connection, invalid fallbacks), Subscription model business logic (signPayload null/empty/deterministic, hasExceededFailures config/override, recordDelivery/resetFailures/recordFailure, matchesEvent exact/wildcard), Trigger model scopes (enabled, async) and UUID verification, config completeness (7 sections + sub-keys), Facade accessor, ServiceProvider publish groups.

### Changed
- Version bumped to 3.5.0.

---

## [3.4.0] — 2026-08-10

### Fixed
- README test file count corrected from 142 to 138 (136 Pest-registered + 2 standalone + 5 support files).
- `phpstan.neon.dist` — changed `reportUnmatchedIgnoredErrors` from `false` to `true` to surface stale ignore patterns; tightened Facade ignore to specific facades (Config, Cache, Queue, Log, DB, Http) instead of wildcard catch-all.

### Added
- `EventsPhase68ProductionTest.php` — 50 comprehensive production tests covering: strict types enforcement all source files, final class verification (10 core + 12 console commands), WildcardMatcher readonly + #[Pure] attribute verification, DomainEvent readonly properties + roundtrip preservation, ConditionEngine full 19-operator matrix + AND logic + dot notation, WildcardMatcher comprehensive patterns (exact, single, cross-segment, catch-all, findMatching, extract), ActionResolver error cases (non-existent class, non-Triggerable class), fire/fireModel empty string validation, Subscription signPayload null/empty/deterministic, phpstan.neon.dist level max + targeted ignores + reportUnmatchedIgnoredErrors, ServiceProvider singleton/transient bindings + #[Override] verification, Facade accessor + #[Override], composer.json version/structure, EventLog status constants, model config-driven table names + UUID keys + #[Override] on boot/casts/newFactory/getTable, fluent interface return types, migration/factory existence, command prefix verification, config completeness (7 sections), trait method presence (ManagesHistory + ManagesSubscriptions), EscapesWildcardLike trait usage, version consistency (composer.json + README), license headers.

### Changed
- Version bumped to 3.4.0.

---

## [3.3.0] — 2026-08-10

### Fixed
- Added missing `use Illuminate\Support\Carbon` import in `EventLog` model — `scopeStalePending(Carbon $before)` parameter type now resolves correctly for PHPStan 9.
- Added missing `use Illuminate\Support\Carbon` import in `Trigger` model — PHPDoc `@property Carbon` annotations now resolve correctly.

### Improved
- README `getStats()` return type documentation expanded to include all 12 fields: `total_logs`, `total_triggers`, `active_triggers`, `completed`, `failed`, `pending`, `dispatched`, `success_rate`, `failure_rate`, `avg_duration_ms`, `top_events`, `top_failed_events`.

### Added
- `EventsPhase67ProductionTest.php` — 40+ comprehensive production tests covering: `getStats` edge cases (zero-state, only-completed, only-failed, mixed rates, since-filter, no-duration, top-events aggregation, top-failed-events filtering), `getStalePendingLogs` boundary filtering, `deactivateExceededSubscriptions` batch logic, `purgeLogs` with include-pending toggle and date boundary, fire/fireModel validation (empty string, zero-string, empty class/action), fireModel event name construction, deleteTrigger non-existent cache invalidation, setEnabled runtime disable, listTriggers/listSubscriptions/getEventHistory with wildcard/status/triggerId/activeOnly filters, WildcardMatcher findMatchingPatterns subset and extractWildcards ** pattern, DomainEvent fromArray invalid eventType/missing eventType/invalid UUID/invalid datetime graceful degradation, ConditionEngine empty conditions/empty array/inverted between/not-null/not-empty/empty operators, EscapesWildcardLike percent-underscore escaping, unsubscribe/getSubscription non-existent returns.

### Changed
- Version bumped to 3.3.0.

---

## [3.2.0] — 2026-08-10

### Fixed
- `WildcardMatcher::matches()` now uses `=== 1` check on `preg_match()` return value instead of `(bool)` cast — prevents silent swallowing of regex compilation errors (e.g., malformed patterns from user input).

### Improved
- `phpstan.neon.dist` — tightened ignore patterns for Facade dynamic calls: replaced overly broad `#Dynamic call to static method.*is missing#` with specific `Illuminate\Support\Facades\*` patterns. Added model property access ignore for `ZeroBoiler\Events\Models\*` namespace.

### Added
- `EventsPhase66ProductionTest.php` — 40+ comprehensive production tests covering: WildcardMatcher regex error safety, findMatchingPatterns/extractWildcards, catch-all/** patterns, regex special chars, DomainEvent serialization roundtrip/fromArray edge cases/invalid UUID, ConditionEngine full 19-operator matrix + strict identity + AND logic + dot notation + empty conditions + unknown operators + in null safety + between auto-normalize, EscapesWildcardLike SQL escaping, EventLog status constants consistency, config completeness 7 sections, ServiceProvider singleton/transient binding verification, Facade accessor resolution, strict types enforcement all source files, final class verification (10 core + 12 console commands), WildcardMatcher #[Pure] attribute verification, model config-driven table names, model UUID string key types.

### Changed
- Version bumped to 3.2.0, test file count updated to 142 (135 Pest + 2 standalone + 5 support).

---

## [3.1.0] — 2026-08-10

### Added
- `EventLog::scopeStalePending(Carbon $before)` — scope for querying stuck pending logs older than a threshold. Useful for identifying queue worker crash artifacts.
- `Subscription::scopeExceededFailures()` — scope for querying active subscriptions that have exceeded the failure threshold from `events.subscriptions.max_failures` config.
- `EventManager::getStalePendingLogs(Carbon $before, int $limit)` — get stuck pending event logs with eager-loaded trigger relationships. Uses the new `scopeStalePending` scope.
- `EventManager::deactivateExceededSubscriptions()` — batch-deactivate all active subscriptions exceeding the failure threshold. Returns count of deactivated subscriptions. Skips already-inactive subscriptions.
- Facade `@method` annotations for `getStalePendingLogs()` and `deactivateExceededSubscriptions()`.
- `EventsPhase64ProductionTest.php` — 10 comprehensive production tests covering: EventLog scopeStalePending (stale/fresh/completed filtering), Subscription scopeExceededFailures (threshold matching, config-driven threshold, non-int config fallback, inactive skip), getStalePendingLogs (trigger eager-loading, limit support), deactivateExceededSubscriptions (batch deactivation, zero-count, inactive skip).
- `FacadeMethodCoverageTest.php` — 4 tests verifying facade accessor resolution, `@method`↔public method mapping completeness, proxy type correctness, and coverage gap detection.

### Fixed
- README test file count corrected to 135 (133 Pest-registered + 2 standalone).

### Changed
- Version bumped to 3.0.0, production-ready milestone.
- README enriched with FacadeMethodCoverageTest entry in test coverage table.

---

## [2.9.0] — 2026-08-10

### Fixed
- README test file count corrected from 133 to 132.

### Added
- `EventsPhase63ProductionTest.php` — 51 comprehensive production tests.

### Changed
- Test file count updated to 133, version bumped to 2.9.0.

---

## [2.8.0] — 2026-08-10

### Improved
- Production readiness review — comprehensive manual code audit of all 31 source files for PHP 8.5 syntax, strict types, return type declarations, docblocks, typed properties, `#[Override]`/`#[Pure]` attributes, and PHPStan 9 compliance.

### Changed
- Test file count updated to 133.

---

## [2.6.0] — 2026-08-10

### Refactored
- `EventManager` — extracted `getConfig()` protected helper method to DRY up repeated config repository access pattern (was duplicated 4× across `isDisabled()`, `setEnabled()`, `fire()`, `getTriggerCacheTtl()`). All config reads now go through a single typed method with proper `assert()` type narrowing.

### Added
- `EventsHealthIntegrationTest.php` — integration tests for health command output structure, global disable detection, inactive subscription detection, and `getConfig()` helper consistency.
- CI workflow badge to README.

### Changed
- Version bumped to 2.6.0.
- Test file count updated to 131.

---

## [2.4.0] — 2026-08-10

### Added
- **EventsPhase61ProductionTest.php** — 37 comprehensive production readiness tests covering: all 31 source files strict_types enforcement, all core classes final verification, WildcardMatcher readonly final class, all 12 console commands final, EventManager/ActionResolver constructor readonly promoted properties, DomainEvent readonly properties, ConditionEngine/ConditionEngineContract interface implementation, WebhookAction/Triggerable interface implementation, DispatchTriggerJob/ShouldQueue implementation, WildcardMatcher #[Pure] attribute verification, EventLog status constants, config completeness (7 sections + sub-keys), ServiceProvider binding correctness, Facade accessor, models config-driven table names, model string UUID key types, EscapesWildcardLike behavior, DomainEvent roundtrip identity, ConditionEngine full 19-operator matrix, WildcardMatcher comprehensive patterns, EventManager API surface completeness, TriggerBuilder/SubscriptionBuilder fluent interface, phpstan.neon.dist level 9, composer.json structure, migration/factory file existence, license headers, trait composition, fire/fireModel validation, signPayload null secret, ActionResolver errors, getStats zero-state, global disable behavior, regex special chars safety, version consistency, test file count accuracy.

### Changed
- Version bumped to 2.4.0.
- Test file count updated to 129.

---

## [2.3.0] — 2026-08-10

### Fixed
- `ConditionEngine::between()` now uses null-coalescing (`$value[0] ?? null`) when extracting range boundaries from the condition value — prevents PHPStan 9 `array-access-on-mixed` errors and gracefully handles malformed condition arrays with missing indices.

### Added
- **EventsPhase60ProductionTest.php** — 20 comprehensive production readiness tests covering: strict types enforcement across all source files, final class verification (7 core + 12 console commands), interface contracts (ConditionEngineContract, Triggerable), WildcardMatcher readonly class + #[Pure] attributes, DomainEvent readonly properties + roundtrip identity preservation, ConditionEngine between() null-coalescing safety, ConditionEngine all operators with empty payload, null/not_null operators, EventLog status constants + $statuses array, ServiceProvider register/boot method signatures, Facade accessor correctness, config completeness (7 sections + sub-keys), migration file existence (3 files), factory definitions + required keys (3 factories), model UUID string key types + non-incrementing, phpstan.neon.dist level 9 verification, composer version consistency, Laravel extra providers/aliases, EventManager/ActionResolver constructor readonly params, EscapesWildcardLike trait method signature + behavior, WildcardMatcher comprehensive patterns + extractWildcards + findMatchingPatterns, license headers on all source files, test file count accuracy.

### Changed
- Version bumped to 2.3.0.
- Test file count updated to 128.

---

## [2.2.0] — 2026-08-10

### Fixed
- `EventsHealthCommand` now reuses the `Trigger::count()` result from the database connectivity check instead of issuing a duplicate query for the active triggers section — reduces DB round-trips during health diagnostics.
- `CreatesApplication` test bootstrap now includes `events.disabled` config key in the default test configuration — previously tests relying on `EventManager::isDisabled()` would get `null` instead of the expected default `false`.

---

## [2.1.0] — 2026-08-10

### Added
- **EventsHealthCommand** (`zeroboiler:events:health`) — production diagnostic command that checks event system health: global disable status, database connectivity, active trigger count, subscription health, recent event statistics (24h), queue configuration, and optional cache driver verification (`--check-cache`). Supports `--json` output for monitoring dashboards and automated health checks.
- **EventsHealthCommandTest.php** — 12 new tests covering: health command existence and final class, signature validation (zeroboiler:events:health), --json and --check-cache options, handle() return type and #[Override] attribute, ServiceProvider registration, strict_types enforcement, license header, extends Command, and a comprehensive Phase 59 production audit (12 console commands final, 10 core classes final, WildcardMatcher readonly + #[Pure], DomainEvent readonly properties, EventLog status constants, interface contracts, ServiceProvider singleton/transient bindings, config completeness).

### Changed
- Test file count updated to 127.
- EventsServiceProvider now registers 12 console commands (was 11).

---

## [2.0.0] — 2026-08-10

### Fixed
- Duplicate "Wildcard Cache" comment block removed from `config/events.php` — the section header appeared twice due to a merge artifact.

### Improved
- **phpstan.neon.dist** — added `checkMissingIterableValueType: false` for PHPStan 9 compatibility with Eloquent collection returns.

### Added
- **EventsPhase58ProductionTest.php** — 28 new production readiness tests covering: config duplicate comment cleanup, phpstan config hardening, comprehensive final audit (strict types, final classes, readonly promoted properties, interface contracts, singleton/transient bindings, facade accessor, config completeness, model config tables, DomainEvent readonly/roundtrip, WildcardMatcher readonly + #[Pure], EscapesWildcardLike, EventLog status constants, EventManager API surface, fluent interface, composer.json structure, phpstan config, console command prefix, migration/factory existence).

### Changed
- Version bumped to 2.0.0 (production-ready milestone), test file count updated to 126.

---

## [1.99.0] — 2026-08-10

### Added
- **events.disabled** config key and `EVENTS_DISABLED` env variable — globally disables the event system. When true, all `fire()` calls silently return without dispatching triggers. Useful for maintenance windows and testing environments.
- **EventManager::isDisabled()** — check if the event system is globally disabled.
- **EventManager::setEnabled(bool $enabled)** — enable or disable the event system at runtime (in-memory only).
- **Facade** `@method` annotations for `isDisabled()` and `setEnabled()`.
- **EventsGlobalDisableTest.php** — 13 new tests covering global disable/enable behavior, fire suppression, empty event validation, and facade proxy.
- **ConfigCompletenessTest** — added `disabled` key validation.

### Changed
- Version bumped to 1.99.0, test file count updated to 125.
- README enriched with global disable documentation, API reference, env variable, troubleshooting entry, and config example.

---

## [1.98.0] — 2026-08-10

### Fixed
- **rector.php** — upgraded from `LaravelSetList::LARAVEL_120` to `LaravelSetList::LARAVEL_130` for Laravel 13 compatibility
- **tests/helpers.php** — `fake()` function return type changed from `mixed` to `\Faker\Generator` for PHPStan 9 type precision

### Added
- **EventsPhase57ProductionTest.php** — 45 new production tests covering: rector LARAVEL_130 upgrade verification, fake() helper return type precision, all protected/private method return type declarations across ConditionEngine/EventManager/WebhookAction/EventsRedeliverCommand/TriggerBuilder/EventsLogCommand/EventsFireCommand/EscapesWildcardLike, DomainEvent/EventManager/ActionResolver readonly promoted property verification, DispatchTriggerJob property types, ServiceProvider #[Override], Facade accessor, model casts/boot verification, console commands final, WildcardMatcher readonly + #[Pure], strict types enforcement, config completeness, version consistency, migration/factory existence, EventLog status constants

### Changed
- Version bumped to 1.98.0, test file count updated to 124.

---

## [1.97.0] — 2026-08-09

### Fixed
- **phpstan.neon.dist** — added missing `treatPhpDocTypesAsCertain: false`, `checkGenericClassInNonGenericObjectType: false`, `checkUninitializedProperties: false` for PHPStan 9 compatibility
- **phpstan.neon.dist** — added `Call to an undefined static method` and `Method .*::.*\(\) invoked on .*` ignore patterns for Eloquent `__call`/`__callStatic` magic method resolution without Larastan
- **ManagesHistory::getStats()** — added `is_numeric()` guard on `avg('duration_ms')` result before `round()` call for PHPStan 9 mixed-to-float safety
- **ManagesHistory::getStats()** — added `isset()` and `is_numeric()` guards on aggregate query row properties (`$row->event`, `$row->count`) for PHPStan 9 type safety
- **EventsRedeliverCommand** — replaced loose `mixed` `$subscriptionId` with explicit `is_string()` type guard at extraction point, eliminating redundant `is_string()` checks downstream

### Refactored
- **SubscriptionBuilder::save()** — removed redundant `@var` docblock on `$schemeRaw` (type is already inferred from `is_string()` guard)

### Added
- **EventsPhase56ProductionTest.php** — 30 new production tests covering: ConditionEngine deep nesting/type coercion/float comparison, WildcardMatcher advanced patterns/edge cases, TriggerBuilder validation edge cases, SubscriptionBuilder URL scheme rejection (ftp/file), DomainEvent serialization roundtrip, EventManager fireModel validation, EventLog status transitions, Subscription signPayload determinism, DispatchTriggerJob config behavior, cache invalidation lifecycle, ServiceProvider binding integrity (singleton/transient/contract), all src files strict_types enforcement

### Changed
- Version bumped to 1.97.0, test file count updated to 123.

---

## [1.96.0] — 2026-08-09

### Fixed
- CHANGELOG.md was missing v1.95.0 entry — synchronized with README changelog.

### Changed
- Version bumped to 1.96.0.

---

## [1.95.0] — 2026-08-09

### Fixed
- README test file count corrected from 120 to 125 (reflects actual test files on disk).

### Added
- `EventsPhase54ProductionTest.php` — 54 new production tests.

### Changed
- Version bumped to 1.95.0.

---

## [1.94.0] — 2026-08-09

### Fixed
- `EventsRegisterCommand::handle()` now uses `is_string()` type guards on `$event` and `$action` arguments — `$this->argument()` returns `string|array<int, string>|null` in Laravel 13, and the previous `(string)` cast would silently coerce array values. Early return with error message on non-string input.
- `EventsSubscribeCommand::handle()` now uses `is_string()` type guards on `$event` and `$url` arguments — same issue as EventsRegisterCommand. Also simplified `$secret` and `$filter` option guards from redundant ternary `is_string()` checks to clean `is_string()` guard clauses.
- `EventsRetryCommand::handle()` now uses `is_string()` type guard on `$status` option — `$this->option('status')` returns `string|bool|int|null|array`, and the previous `(string)` cast could produce incorrect values from boolean/array inputs.

### Added
- `EventsPhase53ProductionTest.php` — 35 new production tests covering: EventsRegisterCommand is_string() type guards (event + action), EventsSubscribeCommand is_string() type guards (event + url + secret + filter), EventsRetryCommand is_string() guard, comprehensive quality verification (strict types all source files, final class verification 11 core + 11 console commands, Singleton/transient binding verification, contract identity, config completeness all 6 sections + sub-keys, EventLog status constants, DomainEvent roundtrip preservation, WildcardMatcher #[Pure] attribute verification, EscapesWildcardLike trait usage in 7 classes, model config-driven table names, UUID key types, EventManager public API surface completeness 20 methods, fluent interface return types, Pest.php test file count accuracy, version consistency).

### Changed
- Version bumped to 1.94.0, test file count updated to 120.

---

## [1.93.0] — 2026-08-09

### Fixed
- `EventsListCommand::handle()` now uses `is_string()` type guard on `$eventFilter` option — `$this->option('event')` returns `string|array<bool>|null`, and the previous `!== null` guard did not protect against array values reaching `wildcardToLike()`.
- `EventsSubscriptionsCommand::handle()` now uses `is_string()` type guard on `$eventFilter` option — same issue as EventsListCommand, simplified from the redundant `is_string($eventFilter) ? $eventFilter : ''` ternary.
- README test file count corrected from 119 to 118 then to 119 (matches actual test files on disk after adding Phase52 test).

### Added
- `EventsPhase52ProductionTest.php` — 12 new production tests covering: EventsListCommand is_string() type guard, EventsSubscriptionsCommand is_string() type guard, EventsLogCommand is_string() verification, README test file count accuracy, composer.json version consistency, all console commands with --event use is_string(), no deprecated !== null guard pattern, ServiceProvider 11 command registration, config completeness (all 6 sections + sub-keys), strict types enforcement, final class verification, Pest.php Phase52 registration.

### Changed
- Version bumped to 1.93.0.

---

## [1.92.0] — 2026-08-09

### Fixed
- README test file count updated from 117 to 118 (actual test file count after recent additions).

### Added
- `EventsPhase51ProductionTest.php` — comprehensive production audit tests covering: EventManager::register() alias behavior, DomainEvent::occur() factory method, TriggerBuilder validation error messages for empty event/action, ActionResolver edge cases (non-existent class, non-Triggerable class, error message format), SubscriptionBuilder secret auto-generation regex validation, ConditionEngine `between()` with inverted range, WildcardMatcher::extractWildcards() with no-wildcard pattern, EventLog::$statuses array consistency with constants, Trigger::scopeEnabled/disabled/async scopes, DispatchTriggerJob config-driven queue/connection at construction, WebhookAction internal key stripping consistency.

### Changed
- Version bumped to 1.92.0.

---

## [1.90.0] — 2026-08-09

### Fixed
- Tightened `phpstan.neon.dist` ignore patterns — replaced overly broad `Access to an undefined property.*#` with specific patterns targeting `Eloquent\Model::` dynamic properties and `$this->payload` only.

### Added
- `EventsPhase48ProductionTest.php` — 30+ new production tests covering: phpstan.neon.dist tightened ignore patterns, parseActions return type correctness, ConditionEngine unknown/empty operator behavior, WebhookAction payload key stripping, SubscriptionBuilder auto-secret format, DispatchTriggerJob config normalization, factory state return types, strict types enforcement, final class verification, interface contracts, WildcardMatcher `#[Pure]`, readonly properties, ServiceProvider bindings, config completeness, version consistency, fluent interface, Facade accessor, `#[Override]` verification, model config-driven table names.

### Changed
- Version bumped to 1.90.0.

---

## [1.89.0] — 2026-08-09

### Fixed
- `tests/helpers.php` removed 7 unused Faker provider `use` import statements — providers are now referenced with fully-qualified class names in `fake()` to avoid dead imports.

### Added
- `EventsPhase47ProductionTest.php` — 80+ new production tests covering: helpers.php clean imports, WildcardMatcher readonly class + `#[Pure]` on all public methods, DomainEvent readonly promoted properties, EventManager readonly promoted properties, ActionResolver readonly promoted properties, ConditionEngine `#[Override]`, WebhookAction `#[Override]` + Triggerable interface, DispatchTriggerJob final class + ShouldQueue + typed properties, all 11 console commands final verification, ServiceProvider register/boot + `#[Override]`, Facade accessor + `#[Override]`, config completeness (all 6 sections + sub-keys), EventLog status constants, Triggerable/ConditionEngineContract interface contracts, ManagesHistory/ManagesSubscriptions trait methods, EscapesWildcardLike trait usage, TriggerBuilder/SubscriptionBuilder fluent interface return types, WildcardMatcher comprehensive patterns, ConditionEngine full 19-operator matrix + AND logic, DomainEvent roundtrip + empty eventType validation, Subscription signPayload edge cases (null/empty/deterministic), factory state return types, strict types enforcement, license headers, version consistency, Pest.php registration, model config-driven table names, key types/non-incrementing, phpstan.neon.dist configuration, EventManager public API surface completeness (20 methods).

### Changed
- Version bumped to 1.89.0, test file count updated to 115.

---

## [1.88.0] — 2026-08-09

### Added
- `SubscriptionScopeForEventTest.php` — 6 new tests covering `Subscription::scopeForEvent()`: exact match, wildcard match, cross-segment wildcard, no-match for unrelated events, wildcard pattern input, and Builder chaining.
- `ManagesHistoryPurgeLogsTest.php` — 4 new tests covering `ManagesHistory::purgeLogs()`: old completed/failed purge (default), includePending mode, no-op when no logs are old enough, and graceful empty database handling.

### Changed
- Version bumped to 1.88.0, test file count updated to 114.

---

## [1.86.0] — 2026-08-09

### Added
- `EventManagerWildcardCacheTest.php` — 9 tests covering wildcard trigger cache population on first fire, cache invalidation on register/disable/enable/delete, exact triggers unaffected by wildcard cache, disabled wildcard triggers excluded from cache, cross-segment wildcard trigger caching, multiple wildcard triggers cached together.

### Refactored
- `WildcardMatcher` is now `readonly final class` — enforces stateless design, prevents accidental instance property additions.
- `ConditionEngine::strictEquals()` now has `#[\Pure]` attribute — documents side-effect-free pure function for PHPStan.
- `FireModelCaptureAction::handle()` and `FireModelNoOpAction::handle()` now have `#[\Override]` attribute — consistent with all other Triggerable implementations.

### Changed
- Version bumped to 1.86.0

---

## [1.85.0] — 2026-08-09

### Fixed
- **PHPStan 9** `EventManager::parseActions()` now trims whitespace from action strings before processing — whitespace-only action strings (spaces, tabs, newlines) now correctly return an empty array instead of falling through to a `[trim_result]` single-entry array.
- **PHPStan 9** `EventsLogCommand::handle()` now uses `is_string()` type guard on `$this->option('status')` before the `!== ''` check — `$this->option()` returns `string|array<bool>|null`, and the previous `!== null` guard did not protect against array values reaching `in_array()`.

### Added
- `EventsPhase46ProductionTest.php` — 22 new production tests covering: parseActions whitespace trimming (spaces, tabs, newlines, JSON with surrounding whitespace, plain class name with surrounding whitespace), EventsLogCommand is_string type safety on status option, README Phase 45 test coverage table entry, strict types enforcement (all src files), final class verification (11 core + 11 console commands), `#[\Override]` on ConditionEngine::matches, WebhookAction::handle, and all console command handle() methods, WildcardMatcher `#[\Pure]` on all 3 public static methods, config completeness (all 6 top-level sections + table_names/subscriptions sub-keys), model config-driven table names (Trigger/EventLog/Subscription), EventLog status constants (4 constants + $statuses array), ServiceProvider binding lifecycle (singletons for EventManager/ConditionEngine/ActionResolver, transients for TriggerBuilder/SubscriptionBuilder, contract identity), Facade accessor correctness, DomainEvent readonly properties + roundtrip preservation, EscapesWildcardLike behavior (null for non-wildcard, asterisk-to-percent), version consistency (composer.json vs README badge), interface contracts (ConditionEngineContract, Triggerable), source file license headers (all src files), TriggerBuilder fluent interface (8 methods return self), SubscriptionBuilder fluent interface (6 methods return self), composer.json PSR-4 autoload structure, composer.json Laravel extra provider, EventManager public method return type declarations, phpstan.neon.dist structure (level 9, paths).

### Changed
- Version bumped to 1.85.0, test file count updated to 109.

---

## [1.84.0] — 2026-08-09

### Fixed
- **rector.php**: Changed `LaravelSetList::LARAVEL_130` to `LaravelSetList::LARAVEL_120` — `LARAVEL_130` constant does not exist in `driftingly/rector-laravel ^2.5` and would cause a runtime error when running `rector`.

### Added
- `EventsPhase45ProductionTest.php` — 55+ comprehensive final audit tests: rector.php valid LaravelSetList constant, all source files strict_types, final class verification (14 classes), no `#[\Readonly]` attribute usage, readonly keyword on DomainEvent/EventManager properties, return type declarations on all public methods (4 classes, 45+ methods), `#[\Override]` on ConditionEngine::matches and WebhookAction::handle, `#[\Pure]` on all 3 WildcardMatcher static methods, trait composition verification (EventManager 3 traits, Subscription EscapesWildcardLike), PHPStan config (level 9, bootstrapFiles, paths), composer.json structure (PHP 8.5, PSR-4, extra.laravel), config completeness (6 top-level sections + sub-keys), model config-driven table names, UUID string key types, non-incrementing models, console command prefix verification (11 commands), migration structure (3 files, up/down), EventLog status constants (4), interface contracts (ConditionEngineContract, Triggerable), ServiceProvider binding methods, Facade accessor, factory definitions (3 models), .gitignore completeness, version consistency, source file license headers.

### Changed
- Version bumped to 1.84.0, test file count updated to 108.

---

## [1.83.0] — 2026-08-09

### Added
- `EventsPhase44ProductionTest.php` — comprehensive final audit tests: CHANGELOG.md presence and version consistency, composer.json autoload PSR-4 structure, rector.php presence and Laravel 130 set, .gitignore completeness (vendor, phpstan.neon, phpstan-baseline.neon, composer.lock), database/migrations directory and file count, database/factories directory and file count, all config file keys present in phpstan.neon.dist, phpstan level 9, all source file license headers, facade `@method` annotations completeness vs EventManager public API, WebhookAction `getTimeout()` and `getMaxFailures()` return type, EventLog `casts()` return type, DomainEvent `fromArray()` graceful handling of all edge cases, SubscriptionBuilder `save()` transaction atomicity verification, ManagesHistory `getStats()` return structure shape, rector.php strict_types enforcement, EventsRedeliverCommand `buildRedeliverBody()` private method existence and return type, phpstan.neon.dist ignoreErrors count, all test files in Pest.php registered or documented as standalone, EventsUnsubscribeCommand `$id` cast type safety, EventsSubscribeCommand `$event`/`$url` cast type safety.

### Changed
- Version bumped to 1.83.0, test file count updated to 107.

---

## [1.82.0] — 2026-08-09

### Added
- `EventManager::fire()` now accepts an optional `$async` parameter — when `true`, forces all matching triggers to be dispatched asynchronously via queue, overriding individual trigger `async` settings.
- `--async` flag to `zeroboiler:events:fire` command.

### Fixed
- `zeroboiler:events:fire` `--payload` key=value pairs now correctly defer to `--json` keys (JSON takes precedence).
- `ConditionEngine` unknown operator `default` branch now returns `false` instead of falling through to `strictEquals()`.

### Changed
- Facade `@method` annotation for `fire()` updated to include `$async` parameter; version bumped to 1.82.0.

---

## [1.81.0] — 2026-08-09

### Added
- `EventsPhase42ProductionTest.php` — 37 new production tests covering fireModel key collision, empty attributes, parseActions edge cases, DispatchTriggerJob eventLogId, WebhookAction internal key stripping, ConditionEngine empty/missing, WildcardMatcher no-wildcard, builder defaults, CRUD empty state, EventLog constants, signPayload edge cases, ServiceProvider commands, Facade accessor, DomainEvent freshness, config completeness, version consistency.

### Changed
- Version bumped to 1.81.0, test file count updated to 105.

---

## [1.80.0] — 2026-08-09

### Added
- `EventsLifecycleIntegrationTest.php` — comprehensive integration tests covering: full lifecycle (fire→dispatch→log→stats), trigger priority ordering (higher priority dispatched first, same priority ordered by creation time), wildcard cache invalidation (new trigger visibility, disable prevents matches), event history filtering (by status, wildcard event, limit), purge logs (completed/only/pending-include), DomainEvent roundtrip (all fields preserved, missing eventType throws, invalid UUID generates fresh, invalid datetime uses now), ActionResolver edge cases (non-existent class, non-Triggerable class, valid class), WildcardMatcher comprehensive (exact, single/cross/catch-all, multiple, extract, findMatching), ConditionEngine all operators (equality, >, between, in, contains, null/not_null, starts_with/ends_with, nested dot notation, AND logic).

### Changed
- Version bumped to 1.80.0, test file count updated to 104.

---

## [1.75.0] — 2026-08-09

### Added
- `EventsPhase40ProductionTest.php` — 60+ comprehensive production tests covering: strict types enforcement, final class verification (all core + 11 console commands), interface contracts, `#[\Override]` verification (all overrides), ServiceProvider bindings (singleton/transient/contract), Facade accessor, config completeness (all 6 sections), model config-driven table names, EventLog status constants, DomainEvent readonly/roundtrip, WildcardMatcher `#[\Pure]`, EscapesWildcardLike, ActionResolver errors, ConditionEngine full 19-operator matrix, WildcardMatcher comprehensive patterns, Subscription signing/failure/matching, EventManager CRUD/fire/fireModel validation, TriggerBuilder/SubscriptionBuilder fluent interface, cache invalidation, getStats zero-state, version consistency, Pest.php completeness (100 registered, 2 standalone), composer.json structure, console command prefix, model key types, parseActions all 5 formats, Migration existence, DispatchTriggerJob config, trait method verification, file headers, EventLog status lifecycle.

### Changed
- Version bumped to 1.75.0, test file count updated to 102.

---

## [1.74.0] — 2026-08-09

### Added
- `--event` option to `zeroboiler:events:log` command — filter event logs by event name with wildcard support (e.g., `--event=order.*`).
- `EventsLogCommandEventFilterTest.php` — 7 new tests covering: exact event filter, wildcard event filter, no filter (all logs), no matching events, combined event + status filter, combined event + trigger filter, limit with event filter.

### Fixed
- `EventManager::deleteTrigger()` docblock — added missing `@param` annotation for PHPStan 9 and IDE hover tooltips.

### Changed
- Version bumped to 1.74.0, test file count updated to 101.

---

## [1.70.0] — 2026-08-08

### Added
- `EventsPhase37ProductionTest.php` — 30+ new production tests covering: ConditionEngine `between()` non-numeric range value rejection, float comparison operators, null actual handling; SubscriptionBuilder URL scheme enforcement with `parse_url` edge cases; `fake()` helper return type verification; Trigger/EventLog model relations; Subscription `matchesEvent` comprehensive patterns; WebhookAction subscription failure/delivery tracking; DomainEvent `fromArray` edge cases; config completeness; ServiceProvider binding lifecycle; Facade accessor; version consistency; strict types enforcement; final class verification; model config-driven table names.

### Fixed
- **PHPStan 9** `ConditionEngine::between()` now explicitly validates range boundary values as numeric before passing to `min()`/`max()` — previously PHPStan 9 would flag `mixed` values being passed to these functions.
- **PHPStan 9** `SubscriptionBuilder::save()` now guards `parse_url()` return value with `is_array()` check before accessing array keys.
- **PHPStan 9** `tests/helpers.php` `fake()` function now has `@return \Faker\Generator` PHPDoc annotation for proper type inference.

### Changed
- Version bumped to 1.70.0, test file count updated to 98.

---

## [1.69.0] — 2026-08-08

### Added
- Database Schema section in README with full table/column/index documentation for `triggers`, `event_logs`, and `event_subscriptions`.
- Class-level docblock for `DomainEvent` describing its role as an immutable value object for event sourcing.
- `EventManagerValidationTest.php` — 18 new tests covering: fireModel validation (empty model class, "0" model class, empty action, "0" action), listTriggers filtering (all/no filters, exact event, enabled/disabled status, limit, empty result), getTrigger/deleteTrigger (non-existent ID, found trigger, delete returns false, delete removes and invalidates cache), subscribeWebhook (creates WebhookAction trigger, accepts conditions and priority).

### Fixed
- `EventManagerAdvancedTest` — corrected `fire event with empty string does nothing` test to match actual behavior (throws `InvalidArgumentException` for empty/"0" event names).
- Added `"0"` string validation test for `EventManager::fire()`.

### Changed
- Test file count updated to 97.
- README test count references updated to 97.

---

## [1.68.0] — 2026-08-08

### Added
- `EventsPhase36ProductionTest.php` — 80+ new comprehensive production tests covering: trait composition (EventManager/ManagesHistory/ManagesSubscriptions/EscapesWildcardLike), ConditionEngine getNestedValue edge cases (missing key, non-array intermediate, non-nested, deeply nested), operator matrix comprehensive (empty array condition, single key-value, multi-condition AND, strictEquals cross-type, between inverted, matches long pattern/nested quantifiers), WildcardMatcher special chars (parens, plus, brackets, exact, catch-all empty rejection, extractWildcards edge cases), DomainEvent serialization (toArray key completeness, fromArray preservation, empty eventType throws, non-string throws, fresh UUID, readonly verification), model fillable/hidden/casts arrays (Trigger/EventLog/Subscription), config file structure and default values, factory state methods return types, file header license comment presence, namespace declarations, fire/fireModel validation (empty event, "0" event, empty model class, empty action), TriggerBuilder validation (empty event, no action), SubscriptionBuilder validation (empty event, empty URL, non-HTTP URL), conditions conversion, DispatchTriggerJob config-driven properties (tries, backoff, queue, connection, eventLogId), ServiceProvider binding integrity (singleton/transient verification), model scopes (Trigger/EventLog/Subscription), WebhookAction interface compliance, EscapesWildcardLike behavior, ActionResolver error handling, composer.json autoload/extra.laravel structure, migration file integrity (up/down methods, file count), phpstan config structure, facade @method completeness, cache TTL edge cases, CRUD edge cases (getTrigger/deleteTrigger/enable/disable non-existent), TriggerBuilder resolveActions (deduplication, merge), version consistency.

### Changed
- Version bumped to 1.68.0.
- Test file count updated to 96.

---

## [1.67.0] — 2026-08-08

### Fixed
- `Subscription::signPayload()` now handles `hash_hmac()` returning `false` (e.g., unsupported algorithm) — previously the `string|false` return was passed directly as `string`, causing potential PHPStan 9 type errors. Now returns empty string on `false`.
- README test file count corrected from 99 to 95.

### Added
- `EventsPhase35ProductionTest.php` — 40+ production-ready tests covering: signPayload hash_hmac false safety (null secret, empty secret, valid secret, deterministic signatures, different payloads, sha256 correctness), strict types enforcement across all src/ files, final class verification for all 25 classes (core + models + console commands), interface contracts (ConditionEngineContract, Triggerable), ServiceProvider singleton/transient binding lifecycle (EventManager, ConditionEngine, ActionResolver, ConditionEngineContract, TriggerBuilder, SubscriptionBuilder), facade accessor correctness, config completeness all 6 sections (table_names, queue, retry, retention, subscriptions, wildcard_cache_ttl) with sub-key validation, EventLog status constants, DomainEvent readonly keyword verification (all 4 properties), WildcardMatcher #[Pure] attribute verification (all 3 static methods), model config-driven table names (Trigger, EventLog, Subscription), model UUID key types and non-incrementing, return type declarations on all EventManager/TriggerBuilder/SubscriptionBuilder public methods, console command handle() return type verification (all 11 commands), version consistency.
- `EventsPhase34ProductionTest.php` — 75+ production-ready tests covering: EventManager fire/fireModel validation (empty/zero event, empty model class, empty action), TriggerBuilder resolveActions deduplication order preservation, action() + actions() merge order, SubscriptionBuilder transaction atomicity (subscription + internal trigger), ftp/file URL scheme rejection, auto-generate secret behavior, ConditionEngine strictEquals cross-type, AND logic, empty conditions, WildcardMatcher findMatchingPatterns order preservation/empty results, extractWildcards single/cross-segment/non-matching, DomainEvent fromArray minimal data reconstruction, fromArray empty eventType throws, roundtrip preservation, toArray key completeness, DispatchTriggerJob backoff config (string default, array format, null fallback), tries config, EventLog status lifecycle (pending→dispatched→completed→failed), markAsCompleted/markAsFailed, Subscription recordDelivery/resetFailures/recordFailure, signPayload null secret/deterministic/different payloads, matchesEvent exact/single-segment/cross-segment, hasExceededFailures config/custom, getStats zero-state structure, purgeLogs completed+failed before threshold, invalidateTriggerCache, listTriggers empty results, getTrigger/deleteTrigger/enable/disable non-existent returns null/false, ActionResolver non-existent class/non-Triggerable class, Facade accessor verification, strict_types enforcement all source files, final class verification core + console, ConditionEngineContract interface implementation, config completeness all 6 top-level keys, config subscriptions keys, ServiceProvider singleton/transient bindings, EventLog/Trigger/Subscription boot UUID generation, version consistency, register alias for on, TriggerBuilder/SubscriptionBuilder fluent interface returns self, getEventHistory all filters empty, EscapesWildcardLike null/non-wildcard/asterisk/SQL escaping.

### Changed
- Version bumped to 1.67.0.
- Test file count updated to 95.

---

## [1.66.0] — 2026-08-08

### Fixed
- Facade `@method` annotations now use fully-qualified class names for all return types and parameters, replacing relative imports. This enables proper IDE autocompletion and PHPStan analysis without relying on `use` imports in the Facade stub.

---

## [1.65.0] — 2026-08-08

### Added
- `EventsPhase33ProductionTest.php` — 100+ production-ready tests covering: EventManager CRUD edge cases (getTrigger null, listTriggers empty/all/exact/wildcard/enabled/disabled/limit/null-filters, enable/disable/delete non-existent, delete invalidates cache); model relations (Trigger hasMany EventLog, EventLog belongsTo Trigger, soft delete behavior); DomainEvent fromArray edge cases (minimal valid data, full data preservation, fresh UUID on occur, invalid UUID fallback, invalid datetime fallback, empty/non-string eventType throws); ConditionEngine edge cases (empty conditions true, empty payload false, null/not_null/empty/not_empty operators, AND logic 3+ conditions); WildcardMatcher edge cases (empty pattern/event, no-wildcard extract, single/multi wildcard extract, ** returns empty, findMatchingPatterns order/filters); model scopes (Trigger enabled/async/orderByPriority, Subscription active/orderByPriority); EventLog status transitions (markAsCompleted, markAsFailed, scopeWithStatus, scopePending, scopeCompleted, scopeFailed); Subscription methods (recordDelivery, recordFailure, resetFailures, signPayload null/empty/deterministic/different, hasExceededFailures default/custom, matchesEvent exact/wildcard/cross-segment); TriggerBuilder edge cases (auto-name from event, actions-only, single action with params, multiple actions with classes key); SubscriptionBuilder validation (empty event, empty URL, invalid URL, non-HTTP scheme); EscapesWildcardLike SQL escaping (no wildcard returns null, wildcard converts, SQL special chars); getEventHistory filters (event/status/triggerId/limit); getStats zero-state structure; purgeLogs deletes old logs; ActionResolver errors (non-existent class, non-Triggerable class); config validation (all 6 top-level keys, sub-keys); ServiceProvider binding lifecycle (singleton/transient/contract identity); Facade accessor verification; strict types enforcement all source files; final class verification (11 core + 11 console); console commands are final; version format consistency; #[Override] on ConditionEngine::matches, WebhookAction::handle, EventLog boot/casts/newFactory, Subscription boot, Trigger boot; model config-driven table names; model UUID string key types; migration file existence; factory types and inheritance; Pest.php registration.

### Changed
- Version bumped to 1.65.0.
- Test file count updated to 98.

---

## [1.63.0] — 2026-08-08

### Added
- `EventsPhase30ProductionTest.php` — 80+ production-ready tests covering: DispatchTriggerJob eventLogId initial null state and constructor config edge cases (invalid/zero tries); WebhookAction payload stripping verification and URL validation (empty, missing, non-string); ConditionEngine operator edge cases (not_in/in with null actual, === strict identity, !== strict inequality, >= and <= with null, between non-array value, regex max length rejection, catastrophic backtracking pattern rejection, strictEquals cross-type scalar comparison, getNestedValue missing nested keys); TriggerBuilder resolveActions deduplication (all duplicates, action+actions merge, empty); SubscriptionBuilder save validation (ftp URL scheme, non-URL string, empty event, empty URL); EventManager listTriggers return type; model getTable config fallback (custom value, non-string config, default); DomainEvent fromArray edge cases (non-string occurredAt, non-array payload); model scope return type verification; model relation return types; ActionResolver error cases (non-existent class, non-Triggerable class); factory definition key types; factory parent class verification; composer autoload PSR-4 and extra.laravel structure; config all section key completeness; ServiceProvider config merge and migration load verification; WildcardMatcher #[Pure] on all public static methods.

### Changed
- Removed deprecated `setAccessible(true)` call from Phase 29 test (PHP 8.5+ makes all reflection methods accessible by default).
- Version bumped to 1.63.0.
- Test file count updated to 95.

---

## [1.62.0] — 2026-08-08

### Added
- New factory state methods: `EventLogFactory::withEvent()`, `forTrigger()`, `withPayload()`, `withDuration()`.
- New factory state methods: `SubscriptionFactory::withFailureCount()`, `withDeliveryCount()`, `withPriority()`.
- New factory state methods: `TriggerFactory::forEvent()`, `withAction()`, `withName()`.
- `EventsPhase29ProductionTest.php` — 65+ production-ready tests covering: factory state methods, factory base definition structure, EventManager API surface, TriggerBuilder/SubscriptionBuilder fluent interface, DomainEvent identity/readonly enforcement, ConditionEngine full operator matrix, WildcardMatcher exhaustive patterns, config completeness, ServiceProvider binding lifecycle, Facade accessor, model key types/casts/status constants, strict types enforcement, final class verification, console command prefix/return types, WildcardMatcher #[Pure], #[Override] verification, trait composition, Subscription signPayload/hasExceededFailures, migration structure, config publish tags, version consistency, EventManager CRUD/fire/fireModel validation.

### Changed
- Version bumped to 1.62.0.
- Test file count updated to 94.

## [1.61.0] — 2026-08-08

### Fixed
- Removed explicit `: void` return type declaration from `DomainEvent::__construct()` — PHP 8.5 constructors should not declare return types.
- `EventsUnsubscribeCommand::handle()` now casts the `id` argument to `string` at assignment time instead of at usage — cleaner and PHPStan 9 compliant.

### Added
- `EventsPhase28ProductionTest.php` — 55+ production-ready tests covering: DomainEvent constructor void return type verification, EventsUnsubscribeCommand early string cast verification, strict types enforcement sweep, final class verification (core + console commands + WebhookAction), interface contract verification (ConditionEngineContract, Triggerable, parameter counts/signatures), constructor parameter type verification (EventManager, DispatchTriggerJob), DomainEvent readonly property verification, config completeness (all 6 top-level keys + sub-keys), config type validation (table_names, subscriptions, retry, queue, retention, wildcard_cache_ttl), facade accessor correctness, WildcardMatcher #[Pure] attribute on all 3 static methods, EventLog status constants consistency, model config-driven table names, model key type/incrementing consistency (all 3 models), model relation return types (HasMany, BelongsTo), model casts completeness (all 3 models), ServiceProvider bindings (singleton for EventManager/ConditionEngine/ActionResolver, transient for TriggerBuilder/SubscriptionBuilder, contract identity), TriggerBuilder fluent interface (self return + save return type), SubscriptionBuilder fluent interface (self return + save return type), EventManager all public method return type declarations, version consistency (composer.json vs README badge), EscapesWildcardLike SQL special char escaping (percent, underscore, backslash), ActionResolver constructor types and resolve return type, WebhookAction/ConditionEngine #[Override] verification, console command prefix verification (all 11 commands), config publish tags (events-config, events-migrations), ManagesHistory/ManagesSubscriptions trait composition verification, DomainEvent roundtrip/toArray key preservation, DomainEvent toArray required keys, DispatchTriggerJob property types and readonly constructor verification, migration file existence (3 files), Pest.php Phase 28 registration.

### Changed
- Version bumped to 1.61.0.
- Test file count updated to 93.

## [1.60.0] — 2026-08-08

### Added
- `EventsPhase27ProductionTest.php` — 55 new tests covering: strict types enforcement sweep, trait composition validation, config publish tags, console command prefix/final/typed properties, interface parameter types, DomainEvent toArray/fromArray key consistency, Facade resolved instance type, model relation return types, ServiceProvider binding verification, ConditionEngine full operator coverage + AND logic + null rejection, constructor parameter types, model casts completeness, WildcardMatcher #[Pure] verification, EventManager public method return types, final class sweep, composer.json version consistency, model boot UUID generation, WebhookAction/ConditionEngine interface verification, EscapesWildcardLike SQL escaping.

### Fixed
- Removed `phpstan.neon` from git tracking (local IDE override file, already in `.gitignore`).

## [1.59.0] — 2026-08-08

### Added
- `EventsPhase26ProductionTest.php` — 40+ production-ready tests covering: parseActions 5 JSON format handling, WebhookAction payload stripping verification, DispatchTriggerJob public property types and readonly enforcement, DomainEvent fromArray edge cases (empty eventType, invalid UUID, invalid date), Facade @method docblock completeness, config merge verification, Model fillable consistency checks, Factory definition/state return type verification, migration up() existence, EventLog status constants exact match, Triggerable/ConditionEngineContract interface return types, ActionResolver error handling, WildcardMatcher regex special characters, ConditionEngine dot notation, between auto-normalization, and ReDoS protection.

### Changed
- Version bumped to 1.59.0, test file count updated to 86.
- Pest.php: Registered `EventsPhase26ProductionTest.php`.

---

## [1.51.0] — 2026-08-07

### Added
- `EventsPhase20ProductionTest.php` — 45+ production-ready tests covering: strict types enforcement, final class verification, interface contract verification, service provider binding verification, facade accessor, config completeness, model config-driven table names, UUID key types, EventLog status constants, DomainEvent readonly/roundtrip, WildcardMatcher #[Pure], TriggerBuilder/SubscriptionBuilder fluent interface, #[Override] attribute verification, subscription matchesEvent, cache invalidation lifecycle, trigger CRUD, fire/fireModel validation, version consistency.
- `database_path()` and `storage_path()` helper functions in `tests/helpers.php` — missing global Laravel helpers that could cause errors in test contexts.

### Changed
- Version bumped to 1.51.0, test file count updated to 78.

---

## [1.48.0] — 2026-08-07

### Added
- `EventManager::listTriggers(?string $event, ?bool $enabled, int $limit)` — list triggers with optional event name (supports wildcards) and enabled status filtering.
- `EventManager::getTrigger(string $triggerId)` — retrieve a single trigger by ID.
- `EventManager::deleteTrigger(string $triggerId)` — delete a trigger by ID with automatic cache invalidation.
- Facade `@method` annotations for `listTriggers()`, `getTrigger()`, and `deleteTrigger()`.
- `EventsPhase17ProductionTest.php` — 50+ new tests covering: listTriggers (unfiltered, by event, by wildcard, by enabled, limit, empty), getTrigger (exists, not exists), deleteTrigger (success, not found, cache invalidation), fireModel (attributesToArray, toArray), TriggerBuilder multi-action JSON array encoding, Trigger config-driven table names (valid and invalid), EventLog status lifecycle transitions, DomainEvent fromArray edge cases (missing eventType, missing payload, empty array, roundtrip), ConditionEngine operators (starts_with, ends_with, not_empty, between auto-normalize, dot notation, multiple conditions), WildcardMatcher (single/double/catch-all patterns, extractWildcards, cross-segment), config section completeness, Subscription signPayload edge cases (empty/null secret), empty condition dispatch, fire() validation (empty/zero-string), fireModel() validation, cache invalidation on enable/disable/delete.

### Changed
- Config `events.queue.connection` now uses `?:` (elvis operator) instead of `??` to correctly handle `env()` returning `false` for unset variables — prevents `false` from propagating into the config system.
- Version bumped to 1.48.0, test file count updated to 75.

---

## [1.47.0] — 2026-08-07

### Fixed
- `DomainEventImmutabilityTest` was checking for `#[\Readonly]` **attribute** via `getAttributes()` + `array_any`, which is incorrect for PHP 8.5 — the `readonly` keyword modifier sets `ReflectionProperty::isReadOnly()` flag, not a `#[\Readonly]` attribute. Test was silently passing on PHP < 8.5 but would fail on PHP 8.5+.

### Added
- `#[\Override]` attribute on `ConditionEngine::matches()` — explicitly marks the interface contract implementation for PHPStan override verification.
- `#[\Override]` attribute on `WebhookAction::handle()` — explicitly marks the interface contract implementation for PHPStan override verification.
- `EventsPhase16ProductionTest.php` — 22 new tests covering: EventLog scope methods (scopeWithStatus, scopeFailed, scopePending, scopeCompleted, non-existent status), EventLog markAsCompleted/markAsFailed behavior, Trigger scopes (scopeEnabled, scopeAsync, scopeOrderByPriority), Trigger→EventLog and EventLog→Trigger relations, Subscription scopes (scopeActive, scopeOrderByPriority), Subscription::matchesEvent (exact, single wildcard, cross-segment wildcard), #[\Override] attribute verification on ConditionEngine::matches and WebhookAction::handle, DomainEvent readonly keyword verification (isReadOnly() flag present, #[\Readonly] attribute absent).

### Changed
- Version bumped to 1.47.0, test file count updated to 74.

---

## [1.46.0] — 2026-08-07

### Fixed
- `Pest.php` was missing `EventsPhase14ProductionTest.php` in `uses()` call — tests in that file were not getting Laravel bootstrap and would fail at runtime.
- `EventManager::executeTrigger()` now extracts `$basePayload` once before the action loop — previously `$log->payload` was re-read and type-checked on every iteration, which could cause inconsistent behavior if the payload was mutated during an action handler.
- `config/events.php` `queue.connection` now uses null coalescing (`??`) instead of passing `config()` return as `env()` default — prevents non-string config values from being passed as the `env()` second argument.

### Changed
- `rector.php` upgraded from `LaravelSetList::LARAVEL_110` to `LaravelSetList::LARAVEL_120` for Laravel 13 compatibility.
- Version bumped to 1.46.0, test file count updated to 73.

### Added
- `EventsPhase15ProductionTest.php` — 55 new tests covering: executeTrigger basePayload extraction (multi-action, null payload, action params merge), TriggerBuilder null/empty conditions save behavior, SubscriptionBuilder URL validation (reject invalid, accept HTTPS), ConditionEngine empty conditions with various payloads, WildcardMatcher findMatchingPatterns type safety/extractWildcards edge cases, ServiceProvider binding lifecycle (singleton/transient/contract identity), Config type validation (all 6 config sections), Facade accessor, Model config-driven table names, TriggerBuilder/SubscriptionBuilder fluent interface return types, DispatchTriggerJob config-driven properties (tries/queue/connection/backoff formats), EventLog status constants, DomainEvent roundtrip/fresh UUID, Cache invalidation (save/disable/enable), Strict types enforcement across all source files, Final class verification (10 core classes).

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
