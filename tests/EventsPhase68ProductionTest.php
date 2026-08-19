<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEmpty;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotEquals;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\expectException;

beforeEach(function (): void {
    // Verify fresh app state
    assertNotNull(app());
});

// ─── Strict Types Enforcement ────────────────────────────────────────────────

test('all source files declare strict_types=1', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    assertNotEmpty($srcFiles, 'src/ directory should contain PHP files');

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        assertIsString($contents);
        assertStringContainsString('declare(strict_types=1)', $contents, "File {$file} must declare strict_types");
    }
});

// ─── Final Class Verification ──────────────────────────────────────────────────

test('core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        WildcardMatcher::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        assertTrue($ref->isFinal(), "{$class} must be final");
    }
});

test('all console commands are final', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');
    assertNotEmpty($commandFiles);

    foreach ($commandFiles as $file) {
        $className = basename($file, '.php');
        $fqcn = "ZeroBoiler\\Events\\Console\\{$className}";
        $ref = new ReflectionClass($fqcn);
        assertTrue($ref->isFinal(), "{$fqcn} must be final");
    }
});

// ─── PHPStan Config Verification ─────────────────────────────────────────────

test('phpstan.neon.dist uses level 9', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assertIsString($config);
    assertStringContainsString('level: 9', $config);
    assertStringContainsString('reportUnmatchedIgnoredErrors: true', $config);
});

test('phpstan.neon.dist has targeted ignore patterns — not overly broad', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    assertIsString($config);

    // Must NOT have broad catch-all patterns
    assertFalse(
        str_contains($config, '#Call to an undefined method#'),
        'Should not have overly broad undefined method ignore'
    );

    // Must have targeted Eloquent-specific ignores
    assertStringContainsString('orderByPriority', $config);
    assertStringContainsString('ZeroBoiler\\Events\\Models\\', $config);
});

// ─── Interface Contracts ────────────────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine;
    assertInstanceOf(ConditionEngineContract::class, $engine);
});

test('WebhookAction implements Triggerable', function (): void {
    $action = new WebhookAction;
    assertInstanceOf(Triggerable::class, $action);
});

// ─── Readonly Promoted Properties ────────────────────────────────────────────

test('EventManager uses readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    assertEquals(3, count($params), 'EventManager constructor should have 3 parameters');
    foreach ($params as $param) {
        assertTrue(
            $param->isReadOnly() && $param->isPromoted(),
            "Parameter \${$param->getName()} should be readonly promoted"
        );
    }
});

test('ActionResolver uses readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    assertEquals(1, count($params));
    assertTrue($params[0]->isReadOnly() && $params[0]->isPromoted());
});

// ─── WildcardMatcher: readonly + #[Pure] ─────────────────────────────────────

test('WildcardMatcher is readonly final class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    assertTrue($ref->isReadOnly());
    assertTrue($ref->isFinal());
});

test('WildcardMatcher public methods have #[Pure] attribute', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $method) {
        $m = $ref->getMethod($method);
        $attrNames = array_map(fn (\ReflectionAttribute $a): string => $a->getName(), $m->getAttributes());
        assertContains(
            'Pure',
            $attrNames,
            "WildcardMatcher::{$method} should have #[Pure] attribute"
        );
    }
});

// ─── Config Completeness ─────────────────────────────────────────────────────

test('config/events.php has all required sections', function (): void {
    $config = include __DIR__.'/../config/events.php';
    assertIsArray($config);

    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        assertArrayHasKey($key, $config, "Config must have '{$key}' section");
    }
});

test('config table_names has all 3 entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $tables = $config['table_names'];
    assertArrayHasKey('triggers', $tables);
    assertArrayHasKey('event_logs', $tables);
    assertArrayHasKey('subscriptions', $tables);
});

// ─── EventLog Status Constants ───────────────────────────────────────────────

test('EventLog has 4 status constants', function (): void {
    assertEquals('pending', EventLog::STATUS_PENDING);
    assertEquals('dispatched', EventLog::STATUS_DISPATCHED);
    assertEquals('completed', EventLog::STATUS_COMPLETED);
    assertEquals('failed', EventLog::STATUS_FAILED);

    assertCount(4, EventLog::$statuses);
    assertContains(EventLog::STATUS_PENDING, EventLog::$statuses);
    assertContains(EventLog::STATUS_FAILED, EventLog::$statuses);
});

// ─── Model Config-Driven Table Names ──────────────────────────────────────────

test('models read table names from config', function (): void {
    assertSame('triggers', (new Trigger)->getTable());
    assertSame('event_logs', (new EventLog)->getTable());
    assertSame('event_subscriptions', (new Subscription)->getTable());
});

test('models use UUID string keys and non-incrementing', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $ref = new ReflectionClass($model);
        $inst = $ref->newInstanceWithoutConstructor();
        assertSame('string', $inst->getKeyName());
        assertFalse($inst->getIncrementing());
    }
});

// ─── DomainEvent Readonly + Roundtrip ─────────────────────────────────────────

test('DomainEvent properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = ['eventId', 'eventType', 'payload', 'occurredAt'];

    foreach ($props as $prop) {
        $p = $ref->getProperty($prop);
        assertTrue(
            $p->isReadOnly(),
            "DomainEvent::\${$prop} should be readonly"
        );
    }
});

test('DomainEvent roundtrip preserves identity', function (): void {
    $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
    $restored = DomainEvent::fromArray($original->toArray());

    assertSame($original->eventId->toString(), $restored->eventId->toString());
    assertSame($original->eventType, $restored->eventType);
    assertEquals($original->payload, $restored->payload);
    assertEquals($original->occurredAt->format('U'), $restored->occurredAt->format('U'));
});

// ─── Facade Accessor ─────────────────────────────────────────────────────────

test('Facade getFacadeAccessor returns correct class', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $ref->getMethod('getFacadeAccessor');
    $attrs = $method->getAttributes(\Override::class);
    assertNotEmpty($attrs, 'getFacadeAccessor should have #[Override]');

    $facade = new \ZeroBoiler\Events\Facades\EventManager;
    $accessor = $facade->getFacadeAccessor();
    assertSame(\ZeroBoiler\Events\EventManager::class, $accessor);
});

// ─── ServiceProvider Bindings ────────────────────────────────────────────────

test('ServiceProvider registers all bindings correctly', function (): void {
    $container = app();

    // Singletons
    assertSame(
        $container->make(ConditionEngineContract::class),
        $container->make(ConditionEngineContract::class),
        'ConditionEngineContract should be singleton'
    );
    assertSame(
        $container->make(ConditionEngine::class),
        $container->make(ConditionEngine::class),
        'ConditionEngine should be singleton'
    );
    assertSame(
        $container->make(ActionResolver::class),
        $container->make(ActionResolver::class),
        'ActionResolver should be singleton'
    );

    // Transients (should resolve new instance each time)
    assertNotEquals(
        spl_object_id($container->make(TriggerBuilder::class)),
        spl_object_id($container->make(TriggerBuilder::class)),
        'TriggerBuilder should be transient'
    );
    assertNotEquals(
        spl_object_id($container->make(SubscriptionBuilder::class)),
        spl_object_id($container->make(SubscriptionBuilder::class)),
        'SubscriptionBuilder should be transient'
    );
});

test('ServiceProvider has #[Override] on register and boot', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);

    foreach (['register', 'boot'] as $method) {
        $m = $ref->getMethod($method);
        $attrs = $m->getAttributes(\Override::class);
        assertNotEmpty($attrs, "EventsServiceProvider::{$method} should have #[Override]");
    }
});

// ─── Composer.json Structure ───────────────────────────────────────────────────

test('composer.json has correct autoload and extra config', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    assertIsArray($json);

    // PSR-4 autoload
    assertArrayHasKey('ZeroBoiler\\Events\\', $json['autoload']['psr-4']);

    // Extra
    assertContains(
        EventsServiceProvider::class,
        $json['extra']['laravel']['providers']
    );
    assertArrayHasKey('EventManager', $json['extra']['laravel']['aliases']);
});

// ─── Version Consistency ─────────────────────────────────────────────────────

test('composer.json version is a valid semver string', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $json['version'];
    assertIsString($version);
    assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'Version must be semver format');
});

test('composer.json version matches README badge', function (): void {
    $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $json['version'];
    assertStringContainsString("version-{$version}", $readme, 'README should reference the same version as composer.json');
});

// ─── Model Boot Methods ──────────────────────────────────────────────────────

test('model boot methods check for empty or null id', function (): void {
    $triggerReflection = new ReflectionMethod(Trigger::class, 'boot');
    $contents = file_get_contents((string) $triggerReflection->getFileName());
    $startLine = $triggerReflection->getStartLine();
    $endLine = $triggerReflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('Str::uuid()');
    // The boot method should check for empty/null id
    expect($methodBody)->toMatch('/\$model->id\s*===\s*[\'\"]\s*[\'\"]|\$model->id\s*===\s*null/');
});

// ─── EscapesWildcardLike ──────────────────────────────────────────────────────

test('EscapesWildcardLike trait usage in correct classes', function (): void {
    $usesTrait = function (string $class): bool {
        return in_array(
            'ZeroBoiler\\Events\\Concerns\\EscapesWildcardLike',
            class_uses($class) ?: [],
            true,
        );
    };

    assertTrue($usesTrait(Subscription::class));

    // ManagesHistory uses EscapesWildcardLike
    $managesHistoryTraits = class_uses('ZeroBoiler\\Events\\Concerns\\ManagesHistory');
    assertTrue(in_array(
        'ZeroBoiler\\Events\\Concerns\\EscapesWildcardLike',
        $managesHistoryTraits ?: [],
        true,
    ));
});

// ─── Trait Method Presence ──────────────────────────────────────────────────

test('ManagesHistory provides expected methods on EventManager', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    $methods = ['getEventHistory', 'getStats', 'purgeLogs', 'getStalePendingLogs', 'deactivateExceededSubscriptions'];

    foreach ($methods as $method) {
        $ref = new ReflectionMethod($manager, $method);
        assertTrue($ref->isPublic(), "EventManager::{$method} should be public");
    }
});

test('ManagesSubscriptions provides expected methods on EventManager', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    $methods = ['subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook'];

    foreach ($methods as $method) {
        $ref = new ReflectionMethod($manager, $method);
        assertTrue($ref->isPublic(), "EventManager::{$method} should be public");
    }
});

// ─── Fluent Interface ────────────────────────────────────────────────────────

test('TriggerBuilder methods return self for fluent chaining', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    $builder = $manager->on('test.event');

    $methods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];
    foreach ($methods as $method) {
        $ref = new ReflectionMethod($builder, $method);
        $returnType = $ref->getReturnType();
        assertNotNull($returnType);
        assertSame('self', $returnType->getName(), "TriggerBuilder::{$method} should return self");
    }
});

test('SubscriptionBuilder methods return self for fluent chaining', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $methods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];
    foreach ($methods as $method) {
        $ref = new ReflectionMethod($builder, $method);
        $returnType = $ref->getReturnType();
        assertNotNull($returnType);
        assertSame('self', $returnType->getName(), "SubscriptionBuilder::{$method} should return self");
    }
});

// ─── Migration & Factory Existence ───────────────────────────────────────────

test('all 3 migration files exist', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');
    assertGreaterThanOrEqual(3, count($migrations));

    $contents = array_map(fn (string $f): string => file_get_contents($f), $migrations);
    $hasUp = count(array_filter($contents, fn (string $c): bool => str_contains($c, 'public function up')));

    assertEquals(count($migrations), $hasUp, 'All migrations should have up() method');
});

test('all 3 factory files exist and extend Factory', function (): void {
    $factories = [
        'TriggerFactory',
        'EventLogFactory',
        'SubscriptionFactory',
    ];

    foreach ($factories as $factory) {
        $fqcn = "ZeroBoiler\\Events\\Database\\Factories\\{$factory}";
        assertTrue(class_exists($fqcn), "{$fqcn} must exist");
        $ref = new ReflectionClass($fqcn);
        assertSame('Illuminate\\Database\\Eloquent\\Factories\\Factory', $ref->getParentClass()->getName());
    }
});

// ─── Command Prefix Verification ─────────────────────────────────────────────

test('all console commands use zeroboiler:events: prefix', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');
    assertNotEmpty($commandFiles);

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        assertMatchesRegularExpression(
            '/zeroboiler:events:/',
            $contents,
            basename($file).' command signature must use zeroboiler:events: prefix'
        );
    }
});

// ─── ConditionEngine Full Operator Matrix ────────────────────────────────────

test('ConditionEngine supports all 19 operators', function (): void {
    $engine = new ConditionEngine;

    // Comparison operators
    assertTrue($engine->matches(['val' => ['>', 5]], ['val' => 10]));
    assertTrue($engine->matches(['val' => ['>=', 5]], ['val' => 5]));
    assertTrue($engine->matches(['val' => ['<', 10]], ['val' => 5]));
    assertTrue($engine->matches(['val' => ['<=', 5]], ['val' => 5]));

    // Equality operators
    assertTrue($engine->matches(['val' => 'Test'], ['val' => 'Test']));
    assertTrue($engine->matches(['val' => ['=', 'Test']], ['val' => 'Test']));
    assertTrue($engine->matches(['val' => ['===', true]], ['val' => true]));
    assertFalse($engine->matches(['val' => ['!=', 'x']], ['val' => 'x']));
    assertTrue($engine->matches(['val' => ['!==', 0]], ['val' => false]));

    // Array operators
    assertTrue($engine->matches(['role' => ['in', ['admin', 'user']]], ['role' => 'admin']));
    assertTrue($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']));

    // Contains operators
    assertTrue($engine->matches(['tags' => ['contains', 'x']], ['tags' => ['a', 'x', 'b']]));
    assertTrue($engine->matches(['text' => ['not_contains', 'bad']], ['text' => 'good stuff']));
    assertTrue($engine->matches(['text' => ['contains', 'hello']], ['text' => 'hello world']));

    // Between
    assertTrue($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]));
    assertTrue($engine->matches(['age' => ['between', [65, 18]]], ['age' => 30])); // inverted

    // Null operators
    assertTrue($engine->matches(['x' => ['null']], ['x' => null]));
    assertTrue($engine->matches(['x' => ['not_null']], ['x' => 'value']));

    // Empty operators
    assertTrue($engine->matches(['x' => ['empty']], ['x' => '']));
    assertTrue($engine->matches(['x' => ['not_empty']], ['x' => 'value']));

    // String operators
    assertTrue($engine->matches(['email' => ['starts_with', 'admin']], ['email' => 'admin@test.com']));
    assertTrue($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']));
    assertTrue($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']));

    // Simple equality (non-array value)
    assertTrue($engine->matches(['status' => 'active'], ['status' => 'active']));
});

test('ConditionEngine AND logic: all conditions must match', function (): void {
    $engine = new ConditionEngine;

    assertTrue($engine->matches(
        ['val' => ['>', 5], 'name' => 'test'],
        ['val' => 10, 'name' => 'test'],
    ));

    assertFalse($engine->matches(
        ['val' => ['>', 5], 'name' => 'test'],
        ['val' => 3, 'name' => 'test'],
    ));
});

test('ConditionEngine dot notation nesting', function (): void {
    $engine = new ConditionEngine;

    assertTrue($engine->matches(
        ['user.role' => 'admin'],
        ['user' => ['role' => 'admin']],
    ));

    assertTrue($engine->matches(
        ['order.total' => ['>', 100]],
        ['order' => ['total' => 150]],
    ));
});

// ─── WildcardMatcher Comprehensive ────────────────────────────────────────────

test('WildcardMatcher handles all pattern types', function (): void {
    // Exact match
    assertTrue(WildcardMatcher::matches('order.placed', 'order.placed'));
    assertFalse(WildcardMatcher::matches('order.placed', 'order.shipped'));

    // Single-segment wildcard
    assertTrue(WildcardMatcher::matches('order.*', 'order.placed'));
    assertFalse(WildcardMatcher::matches('order.*', 'order.placed.extra'));

    // Cross-segment wildcard
    assertTrue(WildcardMatcher::matches('order.**', 'order.placed.extra'));

    // Catch-all
    assertTrue(WildcardMatcher::matches('*', 'order.placed'));
    assertTrue(WildcardMatcher::matches('**', 'order.placed'));

    // Empty rejection
    assertFalse(WildcardMatcher::matches('order.*', ''));
    assertFalse(WildcardMatcher::matches('*', ''));
});

test('WildcardMatcher findMatchingPatterns returns correct subset', function (): void {
    $patterns = ['order.*', 'user.created', '*.error', 'order.**'];
    $matched = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    assertTrue(in_array('order.*', $matched, true));
    assertTrue(in_array('order.**', $matched, true));
    assertEquals(2, count($matched));
});

test('WildcardMatcher extractWildcards for single-segment only', function (): void {
    assertEquals(['placed'], WildcardMatcher::extractWildcards('order.*', 'order.placed'));
    assertEquals([], WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'));
});

// ─── fire/fireModel Validation ────────────────────────────────────────────────

test('fire throws on empty event name', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    expect(fn (): mixed => $manager->fire(''))
        ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
});

test('fireModel throws on empty model class', function (): void {
    $manager = app()->make(\ZeroBoiler\Events\EventManager::class);
    expect(fn (): mixed => $manager->fireModel('', 'created', new \stdClass))
        ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
});

// ─── Subscription signPayload Edge Cases ─────────────────────────────────────

test('signPayload returns empty string for null secret', function (): void {
    $sub = new Subscription;
    $sub->secret = null;
    assertEquals('', $sub->signPayload('test payload'));
});

test('signPayload is deterministic', function (): void {
    $sub = new Subscription;
    $sub->secret = 'test_secret';
    $sig1 = $sub->signPayload('payload');
    $sig2 = $sub->signPayload('payload');
    assertSame($sig1, $sig2);
});

// ─── License Headers ─────────────────────────────────────────────────────────

test('license headers present on all source files', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        assertStringContainsString(
            'This file is part of ZeroBoiler',
            $contents,
            basename($file).' must have license header'
        );
    }
});

// ─── #[Override] Verification on Models ───────────────────────────────────────

test('models have #[Override] on boot, casts, newFactory, getTable', function (): void {
    $methods = ['boot', 'casts', 'newFactory', 'getTable'];

    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $ref = new ReflectionClass($model);
        foreach ($methods as $method) {
            if ($ref->hasMethod($method)) {
                $m = $ref->getMethod($method);
                $attrs = $m->getAttributes(\Override::class);
                assertNotEmpty($attrs, "{$model}::{$method} should have #[Override]");
            }
        }
    }
});

// ─── EventManager public API surface completeness ─────────────────────────────

test('EventManager public API surface completeness', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $publicMethods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    $expectedMethods = [
        'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
        'invalidateTriggerCache', 'listTriggers', 'getTrigger', 'deleteTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
        'subscribeWebhook', 'getEventHistory', 'getStats', 'purgeLogs',
        'executeTrigger',
    ];

    foreach ($expectedMethods as $method) {
        assertContains($method, $publicMethods, "EventManager should have public method {$method}");
    }
});

test('all EventManager public methods have return type declarations', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        assertTrue($method->hasReturnType(), "EventManager::{$method->getName()}() should have return type");
    }
});
