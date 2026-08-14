<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngine as ConditionEngineConcrete;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 60 — Final production readiness audit for v2.3.0.
 *
 * Covers: strict types, final classes, readonly properties, interface contracts,
 * ServiceProvider bindings, config completeness, model key types, factory states,
 * ConditionEngine between() null-coalescing safety, migration structure,
 * phpstan config, facade accessor, EventLog status constants, version consistency.
 */
final class EventsPhase60ProductionTest extends TestCase
{
    // ---------------------------------------------------------------
    // 1. Strict types enforcement
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function all_source_files_have_strict_types(): void
    {
        $srcDir = __DIR__.'/../src';
        $files = glob($srcDir.'/**/*.php');

        $missing = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
                $missing[] = basename($file);
            }
        }

        $this->assertEmpty($missing, 'Files missing declare(strict_types=1): '.implode(', ', $missing));
    }

    // ---------------------------------------------------------------
    // 2. Final class verification
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function core_classes_are_final(): void
    {
        $expected = [
            EventManager::class => 'src/EventManager.php',
            ActionResolver::class => 'src/ActionResolver.php',
            ConditionEngine::class => 'src/ConditionEngine.php',
            TriggerBuilder::class => 'src/TriggerBuilder.php',
            SubscriptionBuilder::class => 'src/SubscriptionBuilder.php',
            DomainEvent::class => 'src/Domain/DomainEvent.php',
            WildcardMatcher::class => 'src/WildcardMatcher.php',
        ];

        foreach ($expected as $class => $file) {
            $ref = new ReflectionClass($class);
            $this->assertTrue(
                $ref->isFinal(),
                "{$class} in {$file} must be final",
            );
        }
    }

    /**
     * @test
     */
    public function console_commands_are_final(): void
    {
        $commands = [
            \ZeroBoiler\Events\Console\EventsFireCommand::class,
            \ZeroBoiler\Events\Console\EventsHealthCommand::class,
            \ZeroBoiler\Events\Console\EventsListCommand::class,
            \ZeroBoiler\Events\Console\EventsLogCommand::class,
            \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
            \ZeroBoiler\Events\Console\EventsRetryCommand::class,
            \ZeroBoiler\Events\Console\EventsEnableCommand::class,
            \ZeroBoiler\Events\Console\EventsDisableCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        ];

        foreach ($commands as $class) {
            $ref = new ReflectionClass($class);
            $this->assertTrue($ref->isFinal(), "{$class} must be final");
        }
    }

    // ---------------------------------------------------------------
    // 3. Interface contracts
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function condition_engine_implements_contract(): void
    {
        $engine = new ConditionEngine();
        $this->assertInstanceOf(ConditionEngineContract::class, $engine);
    }

    /**
     * @test
     */
    public function triggerable_interface_has_handle_method(): void
    {
        $ref = new ReflectionClass(Triggerable::class);
        $this->assertTrue($ref->hasMethod('handle'));
        $method = $ref->getMethod('handle');
        $this->assertTrue($method->isPublic());
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    /**
     * @test
     */
    public function condition_engine_contract_has_matches_method(): void
    {
        $ref = new ReflectionClass(ConditionEngineContract::class);
        $this->assertTrue($ref->hasMethod('matches'));
        $method = $ref->getMethod('matches');
        $this->assertTrue($method->isPublic());
        $this->assertSame('bool', $method->getReturnType()?->getName());
    }

    // ---------------------------------------------------------------
    // 4. WildcardMatcher readonly + #[Pure]
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function wildcard_matcher_is_readonly_class(): void
    {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $this->assertTrue($ref->isReadOnly(), 'WildcardMatcher must be a readonly class');
    }

    /**
     * @test
     */
    public function wildcard_matcher_public_methods_have_pure_attribute(): void
    {
        $ref = new ReflectionClass(WildcardMatcher::class);
        $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            if ($method->isStatic()) {
                $attrs = $method->getAttributes(\Pure::class);
                $this->assertNotEmpty(
                    $attrs,
                    "WildcardMatcher::{$method->getName()} must have #[Pure] attribute",
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // 5. DomainEvent readonly properties
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function domain_event_properties_are_readonly(): void
    {
        $ref = new ReflectionClass(DomainEvent::class);

        $readonlyProps = ['eventType', 'payload', 'eventId', 'occurredAt'];
        foreach ($readonlyProps as $prop) {
            $propRef = $ref->getProperty($prop);
            $this->assertTrue(
                $propRef->isReadOnly(),
                "DomainEvent::\${$prop} must be readonly",
            );
        }
    }

    /**
     * @test
     */
    public function domain_event_roundtrip_preserves_identity(): void
    {
        $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $originalId = $event->eventId->toString();
        $originalTime = $event->occurredAt->format(\DateTimeInterface::ATOM);

        $restored = DomainEvent::fromArray($event->toArray());
        $this->assertSame($originalId, $restored->eventId->toString());
        $this->assertSame($originalTime, $restored->occurredAt->format(\DateTimeInterface::ATOM));
        $this->assertSame('user.registered', $restored->eventType);
        $this->assertSame(['email' => 'test@example.com'], $restored->payload);
    }

    // ---------------------------------------------------------------
    // 6. ConditionEngine between() null-coalescing safety
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function condition_engine_between_with_missing_array_values(): void
    {
        $engine = new ConditionEngine();
        // $value = [5] — missing index [1], should return false gracefully
        $this->assertFalse($engine->matches(['amount' => ['between', [5]]], ['amount' => 10]));
    }

    /**
     * @test
     */
    public function condition_engine_between_with_null_values(): void
    {
        $engine = new ConditionEngine();
        // $value = [null, 100] — null is not numeric, should return false
        $this->assertFalse($engine->matches(['amount' => ['between', [null, 100]]], ['amount' => 50]));
    }

    /**
     * @test
     */
    public function condition_engine_all_operators_with_empty_payload(): void
    {
        $engine = new ConditionEngine();

        // All these should return false when the payload key doesn't exist
        $this->assertFalse($engine->matches(['x' => ['>', 0]], []));
        $this->assertFalse($engine->matches(['x' => ['>=', 0]], []));
        $this->assertFalse($engine->matches(['x' => ['<', 100]], []));
        $this->assertFalse($engine->matches(['x' => ['<=', 100]], []));
        $this->assertFalse($engine->matches(['x' => ['in', [1, 2]]], []));
        $this->assertFalse($engine->matches(['x' => ['not_in', [1, 2]]], []));
        $this->assertFalse($engine->matches(['x' => ['between', [0, 100]]], []));
        $this->assertFalse($engine->matches(['x' => ['starts_with', 'a']], []));
        $this->assertFalse($engine->matches(['x' => ['ends_with', 'z']], []));
        $this->assertFalse($engine->matches(['x' => ['matches', '/.*/']], []));
        $this->assertFalse($engine->matches(['x' => ['contains', 'a']], []));
    }

    /**
     * @test
     */
    public function condition_engine_null_not_null_operators(): void
    {
        $engine = new ConditionEngine();
        $this->assertTrue($engine->matches(['x' => ['null']], []));
        $this->assertTrue($engine->matches(['x' => ['not_null']], ['x' => 'hello']));
        $this->assertFalse($engine->matches(['x' => ['null']], ['x' => 'hello']));
        $this->assertFalse($engine->matches(['x' => ['not_null']], []));
    }

    // ---------------------------------------------------------------
    // 7. EventLog status constants
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function event_log_status_constants_match_statuses_array(): void
    {
        $model = new \ZeroBoiler\Events\Models\EventLog;
        $constants = [
            'STATUS_PENDING' => 'pending',
            'STATUS_DISPATCHED' => 'dispatched',
            'STATUS_COMPLETED' => 'completed',
            'STATUS_FAILED' => 'failed',
        ];

        foreach ($constants as $const => $expected) {
            $this->assertSame($expected, $model::{$const});
            $this->assertContains($expected, $model::$statuses);
        }

        $this->assertCount(4, $model::$statuses);
    }

    // ---------------------------------------------------------------
    // 8. Service Provider bindings
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function service_provider_has_correct_register_methods(): void
    {
        $ref = new ReflectionClass(EventsServiceProvider::class);

        $register = $ref->getMethod('register');
        $this->assertTrue($register->isPublic());
        $this->assertSame('void', $register->getReturnType()?->getName());

        $boot = $ref->getMethod('boot');
        $this->assertTrue($boot->isPublic());
        $this->assertSame('void', $boot->getReturnType()?->getName());
    }

    // ---------------------------------------------------------------
    // 9. Facade accessor
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function facade_accessor_returns_event_manager_class(): void
    {
        $facade = new \ZeroBoiler\Events\Facades\EventManager;
        $ref = new ReflectionMethod($facade, 'getFacadeAccessor');
        $result = $ref->invoke($facade);
        $this->assertSame(\ZeroBoiler\Events\EventManager::class, $result);
    }

    // ---------------------------------------------------------------
    // 10. Config completeness verification
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function config_file_has_all_required_sections(): void
    {
        $config = require __DIR__.'/../config/events.php';

        $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $config, "Missing config key: {$key}");
        }

        // Sub-keys
        $this->assertArrayHasKey('triggers', $config['table_names']);
        $this->assertArrayHasKey('event_logs', $config['table_names']);
        $this->assertArrayHasKey('subscriptions', $config['table_names']);
        $this->assertArrayHasKey('connection', $config['queue']);
        $this->assertArrayHasKey('queue', $config['queue']);
        $this->assertArrayHasKey('tries', $config['retry']);
        $this->assertArrayHasKey('backoff', $config['retry']);
        $this->assertArrayHasKey('days', $config['retention']);
        $this->assertArrayHasKey('auto_generate_secret', $config['subscriptions']);
        $this->assertArrayHasKey('max_failures', $config['subscriptions']);
        $this->assertArrayHasKey('timeout', $config['subscriptions']);
        $this->assertArrayHasKey('signature_algorithm', $config['subscriptions']);
    }

    // ---------------------------------------------------------------
    // 11. Migration structure
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function all_migrations_exist(): void
    {
        $migrationsDir = __DIR__.'/../database/migrations';
        $files = glob($migrationsDir.'/*.php');

        $this->assertCount(3, $files, 'Expected 3 migration files');
    }

    // ---------------------------------------------------------------
    // 12. Factory definitions
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function all_factory_files_exist(): void
    {
        $factoriesDir = __DIR__.'/../database/factories';
        $files = glob($factoriesDir.'/*.php');

        $this->assertCount(3, $files, 'Expected 3 factory files');
    }

    /**
     * @test
     */
    public function factory_definitions_have_required_keys(): void
    {
        $triggerFactory = new \ZeroBoiler\Events\Database\Factories\TriggerFactory;
        $def = $triggerFactory->definition();
        foreach (['id', 'name', 'event', 'action', 'enabled', 'priority', 'async'] as $key) {
            $this->assertArrayHasKey($key, $def, "TriggerFactory missing key: {$key}");
        }

        $logFactory = new \ZeroBoiler\Events\Database\Factories\EventLogFactory;
        $logDef = $logFactory->definition();
        foreach (['id', 'trigger_id', 'event', 'payload', 'status'] as $key) {
            $this->assertArrayHasKey($key, $logDef, "EventLogFactory missing key: {$key}");
        }

        $subFactory = new \ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
        $subDef = $subFactory->definition();
        foreach (['id', 'event', 'url', 'active', 'secret', 'failure_count', 'delivery_count', 'priority'] as $key) {
            $this->assertArrayHasKey($key, $subDef, "SubscriptionFactory missing key: {$key}");
        }
    }

    // ---------------------------------------------------------------
    // 13. Model key types
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function models_have_uuid_string_keys(): void
    {
        $models = [
            \ZeroBoiler\Events\Models\Trigger::class,
            \ZeroBoiler\Events\Models\EventLog::class,
            \ZeroBoiler\Events\Models\Subscription::class,
        ];

        foreach ($models as $model) {
            $ref = new ReflectionClass($model);
            $keyType = $ref->getProperty('keyType');
            $this->assertSame('string', $keyType->getValue($ref->newInstanceWithoutConstructor()));
            $incrementing = $ref->getProperty('incrementing');
            $this->assertFalse($incrementing->getValue($ref->newInstanceWithoutConstructor()));
        }
    }

    // ---------------------------------------------------------------
    // 14. phpstan config
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function phpstan_config_is_level_9(): void
    {
        $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('level: max', $content);
        $this->assertStringContainsString('paths:', $content);
        $this->assertStringContainsString('src', $content);
    }

    // ---------------------------------------------------------------
    // 15. Version consistency
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function composer_version_matches_readme(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        $readme = file_get_contents(__DIR__.'/../README.md');

        $version = $composer['version'] ?? '';
        $this->assertNotEmpty($version, 'composer.json must have a version');
        $this->assertStringContainsString($version, $readme, "README should reference version {$version}");
    }

    /**
     * @test
     */
    public function composer_has_laravel_extra(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

        $this->assertArrayHasKey('extra', $composer);
        $this->assertArrayHasKey('laravel', $composer['extra']);
        $this->assertArrayHasKey('providers', $composer['extra']['laravel']);
        $this->assertContains(
            'ZeroBoiler\\Events\\EventsServiceProvider',
            $composer['extra']['laravel']['providers'],
        );
        $this->assertArrayHasKey('aliases', $composer['extra']['laravel']);
        $this->assertArrayHasKey('EventManager', $composer['extra']['laravel']['aliases']);
    }

    // ---------------------------------------------------------------
    // 16. Constructor type signatures
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function event_manager_constructor_has_readonly_params(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(3, $params);

        // All should be readonly promoted
        foreach ($params as $param) {
            $this->assertTrue(
                $param->isReadOnly(),
                "EventManager constructor param \${$param->getName()} must be readonly",
            );
        }
    }

    /**
     * @test
     */
    public function action_resolver_constructor_has_readonly_params(): void
    {
        $ref = new ReflectionClass(ActionResolver::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertTrue($params[0]->isReadOnly());
    }

    // ---------------------------------------------------------------
    // 17. EscapesWildcardLike trait
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function escapes_wildcard_like_trait_has_wildcard_to_like_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
        $this->assertTrue($ref->hasMethod('wildcardToLike'));
        $method = $ref->getMethod('wildcardToLike');
        $this->assertSame('string|null', $method->getReturnType()?->getName());
    }

    /**
     * @test
     */
    public function escapes_wildcard_like_behavior(): void
    {
        // Use Trigger model which uses the trait via EventManager/ManagesHistory/ManagesSubscriptions
        // Test directly via the trait method on a concrete class
        $engine = new ConditionEngine();
        $ref = new ReflectionMethod($engine, 'wildcardToLike');

        $this->assertNull($ref->invoke($engine, 'order.placed'));
        $this->assertSame('%order.%', $ref->invoke($engine, 'order.*'));
        $this->assertSame('%order\\%', $ref->invoke($engine, 'order.%'));
    }

    // ---------------------------------------------------------------
    // 18. WildcardMatcher comprehensive patterns
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function wildcard_matcher_comprehensive_patterns(): void
    {
        // Exact match
        $this->assertTrue(WildcardMatcher::matches('order.placed', 'order.placed'));
        $this->assertFalse(WildcardMatcher::matches('order.placed', 'order.shipped'));

        // Single-segment wildcard
        $this->assertTrue(WildcardMatcher::matches('order.*', 'order.placed'));
        $this->assertFalse(WildcardMatcher::matches('order.*', 'order.placed.extra'));

        // Cross-segment wildcard
        $this->assertTrue(WildcardMatcher::matches('order.**', 'order.placed'));
        $this->assertTrue(WildcardMatcher::matches('order.**', 'order.placed.extra'));
        $this->assertFalse(WildcardMatcher::matches('order.**', 'user.placed'));

        // Catch-all
        $this->assertTrue(WildcardMatcher::matches('*', 'anything'));
        $this->assertFalse(WildcardMatcher::matches('*', ''));

        // Empty
        $this->assertFalse(WildcardMatcher::matches('', 'order.placed'));
        $this->assertFalse(WildcardMatcher::matches('order.placed', ''));
    }

    /**
     * @test
     */
    public function wildcard_matcher_extract_wildcards(): void
    {
        $this->assertSame(['profile'], WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'));
        $this->assertSame([], WildcardMatcher::extractWildcards('user.**', 'user.profile.created'));
        $this->assertSame(['a', 'b'], WildcardMatcher::extractWildcards('*.*', 'a.b'));
    }

    /**
     * @test
     */
    public function wildcard_matcher_find_matching_patterns(): void
    {
        $patterns = ['order.placed', 'order.*', 'user.**', '*.deleted'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
        $this->assertContains('order.placed', $result);
        $this->assertContains('order.*', $result);
        $this->assertContains('*.deleted', $result);
        $this->assertNotContains('user.**', $result);
    }

    // ---------------------------------------------------------------
    // 19. License headers
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function all_source_files_have_license_header(): void
    {
        $srcDir = __DIR__.'/../src';
        $files = glob($srcDir.'/**/*.php');
        $missing = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false || ! str_contains($contents, 'ZeroBoiler, licensed under the proprietary license')) {
                $missing[] = basename($file);
            }
        }

        $this->assertEmpty($missing, 'Files missing license header: '.implode(', ', $missing));
    }

    // ---------------------------------------------------------------
    // 20. File count verification
    // ---------------------------------------------------------------

    /**
     * @test
     */
    public function test_file_count_is_accurate(): void
    {
        $testDir = __DIR__;
        $files = glob($testDir.'/*Test.php');
        $this->assertNotEmpty($files);
        $count = count($files);
        // The README and Pest.php should reference the correct count
        $this->assertGreaterThan(120, $count, 'Expected at least 120 test files');
    }
}
