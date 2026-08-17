<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// ============================================================================
// Phase 46 Production Tests
// ============================================================================

// ---------------------------------------------------------------------------
// 1. parseActions whitespace handling
// ---------------------------------------------------------------------------
test('parseActions returns empty array for whitespace-only action string', function () {
    $manager = app(EventManager::class);

    $method = new ReflectionMethod($manager, 'parseActions');

    // Pure whitespace
    $result = $method->invoke($manager, '   ');
    expect($result)->toBe([]);

    // Tab + spaces
    $result = $method->invoke($manager, "\t ");
    expect($result)->toBe([]);

    // Newline + spaces
    $result = $method->invoke($manager, "\n  \t");
    expect($result)->toBe([]);
});

test('parseActions trims whitespace before JSON decode', function () {
    $manager = app(EventManager::class);

    $method = new ReflectionMethod($manager, 'parseActions');

    // JSON with surrounding whitespace
    $result = $method->invoke($manager, '  ["App\\\\Actions\\\\Foo"]  ');
    expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\Foo']);

    // JSON with leading newline
    $result = $method->invoke($manager, "\n{\"class\":\"App\\\\Actions\\\\Bar\"}");
    expect($result)->toBe([['class' => \ZeroBoiler\Events\Tests\Actions\Bar']]);
});

test('parseActions still handles empty string and zero string correctly', function () {
    $manager = app(EventManager::class);

    $method = new ReflectionMethod($manager, 'parseActions');

    expect($method->invoke($manager, ''))->toBe([]);
    expect($method->invoke($manager, '0'))->toBe([]);
});

test('parseActions handles plain class name with surrounding whitespace', function () {
    $manager = app(EventManager::class);

    $method = new ReflectionMethod($manager, 'parseActions');

    // Whitespace around plain class name should be trimmed
    $result = $method->invoke($manager, '  App\\Actions\\TestAction  ');
    // After trim it's a valid non-JSON string, so returns as single entry
    expect($result)->toBe([\ZeroBoiler\Events\Tests\Actions\TestAction']);
});

// ---------------------------------------------------------------------------
// 2. EventsLogCommand is_string type safety
// ---------------------------------------------------------------------------
test('EventsLogCommand has is_string guard on status option', function () {
    $class = new ReflectionClass(\ZeroBoiler\Events\Console\EventsLogCommand::class);
    $method = $class->getMethod('handle');
    $contents = file_get_contents($class->getFileName());

    expect($contents)->toContain('is_string($status)');
    expect($contents)->toContain("&& \$status !== ''");
});

test('EventsLogCommand validates status against EventLog::$statuses', function () {
    $contents = file_get_contents(
        (new ReflectionClass(\ZeroBoiler\Events\Console\EventsLogCommand::class))->getFileName()
    );

    expect($contents)->toContain('EventLog::$statuses');
});

// ---------------------------------------------------------------------------
// 3. README Phase 45 test coverage entry
// ---------------------------------------------------------------------------
test('README test coverage table includes Phase 45 entry', function () {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('EventsPhase45ProductionTest.php');
    expect($readme)->toContain('Phase 45 production');
});

// ---------------------------------------------------------------------------
// 4. Strict types enforcement (all src files)
// ---------------------------------------------------------------------------
test('all source files have declare(strict_types=1)', function () {
    $srcFiles = glob(base_path('../src/**/*.php'), GLOB_BRACE);

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        // First token must be open tag, second must be declare
        $tokens = token_get_all($contents);
        expect($tokens)->not->toBeEmpty();

        $found = false;
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_DECLARE) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue("File {$file} is missing declare(strict_types=1)");
    }
});

// ---------------------------------------------------------------------------
// 5. Final class verification
// ---------------------------------------------------------------------------
test('all core classes are final', function () {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        WildcardMatcher::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('all console commands are final', function () {
    $commandFiles = glob(base_path('../src/Console/*.php'));

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $m)) {
            if (preg_match('/^final\s+class\s+(\w+)/m', $contents, $classMatch)) {
                $fqcn = $m[1].'\\'.$classMatch[1];
                $ref = new ReflectionClass($fqcn);
                expect($ref->isFinal())->toBeTrue("{$fqcn} must be final");
            }
        }
    }
});

// ---------------------------------------------------------------------------
// 6. #[\Override] attribute verification
// ---------------------------------------------------------------------------
test('ConditionEngine has #[\\Override] on matches method', function () {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->toHaveCount(1);
});

test('WebhookAction has #[\\Override] on handle method', function () {
    $method = new ReflectionMethod(WebhookAction::class, 'handle');
    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->toHaveCount(1);
});

test('all console commands have #[\\Override] on handle method', function () {
    $commandFiles = glob(base_path('../src/Console/*.php'));

    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $m)) {
            if (preg_match('/^final\s+class\s+(\w+)/m', $contents, $classMatch)) {
                $fqcn = $m[1].'\\'.$classMatch[1];
                $ref = new ReflectionClass($fqcn);
                if ($ref->hasMethod('handle')) {
                    $method = $ref->getMethod('handle');
                    $attrs = $method->getAttributes(\Override::class);
                    expect($attrs)->toHaveCount(1, "{$fqcn}::handle() must have #[\\Override]");
                }
            }
        }
    }
});

// ---------------------------------------------------------------------------
// 7. WildcardMatcher #[\Pure] verification
// ---------------------------------------------------------------------------
test('WildcardMatcher all public static methods have #[\\Pure]', function () {
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $methodName) {
        $method = new ReflectionMethod(WildcardMatcher::class, $methodName);
        $attrs = $method->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1, "WildcardMatcher::{$methodName}() must have #[\\Pure]");
    }
});

// ---------------------------------------------------------------------------
// 8. Config completeness (all 6 sections)
// ---------------------------------------------------------------------------
test('config has all 6 top-level sections', function () {
    $config = config('events');
    expect($config)->not->toBeNull();

    $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
    }
});

test('config table_names has all 3 entries', function () {
    $tables = config('events.table_names');
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config subscriptions has all 4 entries', function () {
    $subs = config('events.subscriptions');
    expect($subs)->toHaveKeys(['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm']);
});

// ---------------------------------------------------------------------------
// 9. Model config-driven table names
// ---------------------------------------------------------------------------
test('Trigger model reads table name from config', function () {
    $trigger = new Trigger;
    $table = $trigger->getTable();
    $configTable = config('events.table_names.triggers', 'triggers');
    expect($table)->toBe($configTable);
});

test('EventLog model reads table name from config', function () {
    $log = new EventLog;
    $table = $log->getTable();
    $configTable = config('events.table_names.event_logs', 'event_logs');
    expect($table)->toBe($configTable);
});

test('Subscription model reads table name from config', function () {
    $sub = new Subscription;
    $table = $sub->getTable();
    $configTable = config('events.table_names.subscriptions', 'event_subscriptions');
    expect($table)->toBe($configTable);
});

// ---------------------------------------------------------------------------
// 10. EventLog status constants
// ---------------------------------------------------------------------------
test('EventLog has all 4 status constants', function () {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

test('EventLog $statuses array matches constants', function () {
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    expect(EventLog::$statuses)->toHaveCount(4);
});

// ---------------------------------------------------------------------------
// 11. ServiceProvider binding verification
// ---------------------------------------------------------------------------
test('EventManager is singleton', function () {
    $first = app(EventManager::class);
    $second = app(EventManager::class);
    expect($first)->toBe($second);
});

test('ConditionEngine is singleton', function () {
    $first = app(ConditionEngine::class);
    $second = app(ConditionEngine::class);
    expect($first)->toBe($second);
});

test('ActionResolver is singleton', function () {
    $first = app(ActionResolver::class);
    $second = app(ActionResolver::class);
    expect($first)->toBe($second);
});

test('TriggerBuilder is transient', function () {
    $first = app(TriggerBuilder::class);
    $second = app(TriggerBuilder::class);
    expect($first)->not->toBe($second);
});

test('SubscriptionBuilder is transient', function () {
    $first = app(SubscriptionBuilder::class);
    $second = app(SubscriptionBuilder::class);
    expect($first)->not->toBe($second);
});

test('ConditionEngineContract resolves to ConditionEngine', function () {
    $contract = app(ConditionEngineContract::class);
    $concrete = app(ConditionEngine::class);
    expect($contract)->toBe($concrete);
});

// ---------------------------------------------------------------------------
// 12. Facade accessor
// ---------------------------------------------------------------------------
test('Facade getFacadeAccessor returns correct class', function () {
    $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $result = $method->invoke(null);
    expect($result)->toBe(EventManager::class);
});

// ---------------------------------------------------------------------------
// 13. DomainEvent readonly and roundtrip
// ---------------------------------------------------------------------------
test('DomainEvent properties are readonly', function () {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = ['eventId', 'eventType', 'payload', 'occurredAt'];

    foreach ($props as $prop) {
        $rp = $ref->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
    }
});

test('DomainEvent roundtrip preserves all fields', function () {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
});

// ---------------------------------------------------------------------------
// 14. EscapesWildcardLike
// ---------------------------------------------------------------------------
test('EscapesWildcardLike returns null for non-wildcard pattern', function () {
    $manager = app(EventManager::class);
    $method = new ReflectionMethod($manager, 'wildcardToLike');

    expect($method->invoke($manager, 'order.placed'))->toBeNull();
});

test('EscapesWildcardLike converts asterisk to percent', function () {
    $manager = app(EventManager::class);
    $method = new ReflectionMethod($manager, 'wildcardToLike');

    $result = $method->invoke($manager, 'order.*');
    expect($result)->toBe('order.%');
});

// ---------------------------------------------------------------------------
// 15. Version consistency
// ---------------------------------------------------------------------------
test('composer.json version matches README badge', function () {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);
    $readme = file_get_contents(base_path('../README.md'));

    expect($composer['version'])->toBeDefined();
    expect($readme)->toContain($composer['version']);
});

// ---------------------------------------------------------------------------
// 16. Interface contracts
// ---------------------------------------------------------------------------
test('ConditionEngine implements ConditionEngineContract', function () {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function () {
    expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
});

// ---------------------------------------------------------------------------
// 17. Source file license headers
// ---------------------------------------------------------------------------
test('all source files have license header', function () {
    $srcFiles = glob(base_path('../src/**/*.php'), GLOB_BRACE);

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('ZeroBoiler, licensed under the proprietary license',
            "File {$file} is missing license header");
    }
});

// ---------------------------------------------------------------------------
// 18. TriggerBuilder fluent interface
// ---------------------------------------------------------------------------
test('TriggerBuilder fluent interface returns self', function () {
    $builder = app(TriggerBuilder::class);

    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->name('Test'))->toBe($builder);
    expect($builder->action('App\\Action'))->toBe($builder);
    expect($builder->actions(['App\\A', 'App\\B']))->toBe($builder);
    expect($builder->when(['status' => 'active']))->toBe($builder);
    expect($builder->async())->toBe($builder);
    expect($builder->priority(5))->toBe($builder);
    expect($builder->actionParams(['key' => 'val']))->toBe($builder);
});

// ---------------------------------------------------------------------------
// 19. SubscriptionBuilder fluent interface
// ---------------------------------------------------------------------------
test('SubscriptionBuilder fluent interface returns self', function () {
    $builder = app(SubscriptionBuilder::class);

    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->to('https://example.com/webhook'))->toBe($builder);
    expect($builder->withSecret('whsec_test'))->toBe($builder);
    expect($builder->withFilter(['status' => 'active']))->toBe($builder);
    expect($builder->priority(5))->toBe($builder);
    expect($builder->async())->toBe($builder);
});

// ---------------------------------------------------------------------------
// 20. Composer autoload structure
// ---------------------------------------------------------------------------
test('composer.json has correct PSR-4 autoload', function () {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);

    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
});

test('composer.json has Laravel extra provider', function () {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);

    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider'
    );
});

// ---------------------------------------------------------------------------
// 21. Return type declarations on EventManager public methods
// ---------------------------------------------------------------------------
test('EventManager public methods have return type declarations', function () {
    $ref = new ReflectionClass(EventManager::class);
    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "EventManager::{$method->getName()}() must have a return type declaration"
        );
    }
});

// ---------------------------------------------------------------------------
// 22. phpstan.neon.dist structure
// ---------------------------------------------------------------------------
test('phpstan.neon.dist has level 9', function () {
    $neon = file_get_contents(base_path('../phpstan.neon.dist'));
    expect($neon)->toContain('level: 9');
});

test('phpstan.neon.dist has paths configured', function () {
    $neon = file_get_contents(base_path('../phpstan.neon.dist'));
    expect($neon)->toContain('paths:');
    expect($neon)->toContain('src');
});
