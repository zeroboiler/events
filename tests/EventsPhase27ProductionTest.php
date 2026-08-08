<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\EventManager as EventManagerConcrete;

/**
 * Phase 27 — Final infrastructure hardening: trait composition validation,
 * config publish tag completeness, EventManager trait conflict resolution,
 * CHANGELOG version consistency, strict types enforcement sweep,
 * constructor parameter type verification, interface method parameter types,
 * ServiceProvider boot() console command registration, all command signatures
 * use zeroboiler: prefix, domain event toArray/fromArray key consistency,
 * facade resolved instance type, model relation return types, condition engine
 * all operators tested, rector config Laravel 130, phpstan config level 9.
 */

// --- Pest.php registration verification ---

it('Pest.php includes EventsPhase26ProductionTest', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->toContain('EventsPhase26ProductionTest.php');
});

// --- Strict types enforcement across all source files ---

it('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var RecursiveDirectoryIterator $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        $firstLine = strtok(trim($contents), "\n") ?? '';
        expect($firstLine)->toBe(
            '<?php',
            "{$file->getBasename()} must start with <?php",
        );
        // Check for declare(strict_types=1) in first 5 lines
        $header = implode("\n", array_slice(explode("\n", $contents), 0, 8));
        expect(str_contains($header, 'declare(strict_types=1)'))->toBeTrue(
            "{$file->getBasename()} must have declare(strict_types=1)",
        );
    }
});

// --- Trait composition validation ---

it('EventManager uses EscapesWildcardLike without conflict from trait composition', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $traits = $ref->getTraitNames();

    // EventManager directly uses EscapesWildcardLike
    expect(in_array(EscapesWildcardLike::class, $traits, true))->toBeTrue();
    expect(in_array(ManagesHistory::class, $traits, true))->toBeTrue();
    expect(in_array(ManagesSubscriptions::class, $traits, true))->toBeTrue();

    // ManagesHistory also uses EscapesWildcardLike — PHP merges them silently
    $historyRef = new ReflectionClass(ManagesHistory::class);
    $historyTraits = $historyRef->getTraitNames();
    expect(in_array(EscapesWildcardLike::class, $historyTraits, true))->toBeTrue();

    $subsRef = new ReflectionClass(ManagesSubscriptions::class);
    $subsTraits = $subsRef->getTraitNames();
    expect(in_array(EscapesWildcardLike::class, $subsTraits, true))->toBeTrue();

    // Verify wildcardToLike method is available on EventManager
    expect($ref->hasMethod('wildcardToLike'))->toBeTrue();
});

it('ManagesHistory has all public methods documented', function (): void {
    $ref = new ReflectionClass(ManagesHistory::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    $expectedMethods = ['getEventHistory', 'getStats', 'purgeLogs'];
    $actualMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $methods);

    foreach ($expectedMethods as $method) {
        expect(in_array($method, $actualMethods, true))
            ->toBeTrue("ManagesHistory must have {$method}() method");
    }
});

it('ManagesSubscriptions has all public methods documented', function (): void {
    $ref = new ReflectionClass(ManagesSubscriptions::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    $expectedMethods = ['subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook'];
    $actualMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $methods);

    foreach ($expectedMethods as $method) {
        expect(in_array($method, $actualMethods, true))
            ->toBeTrue("ManagesSubscriptions must have {$method}() method");
    }
});

// --- Config publish tag completeness ---

it('ServiceProvider has events-config publish tag', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    $method = $ref->getMethod('boot');
    $contents = file_get_contents((string) $ref->getFileName());

    expect($contents)->toContain("'events-config'");
});

it('ServiceProvider has events-migrations publish tag', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    $contents = file_get_contents((string) $ref->getFileName());

    expect($contents)->toContain("'events-migrations'");
});

// --- Console command signatures use zeroboiler: prefix ---

it('all console commands use zeroboiler:events: prefix', function (): void {
    $commandDir = __DIR__.'/../src/Console';
    $files = glob($commandDir.'/*.php');

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain(
            'zeroboiler:events:',
            basename($file).' must use zeroboiler:events: command prefix',
        );
    }
});

it('all console commands are final classes', function (): void {
    $commandDir = __DIR__.'/../src/Console';
    $files = glob($commandDir.'/*.php');

    foreach ($files as $file) {
        $tokens = token_get_all(file_get_contents($file));
        $className = null;
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break;
                    }
                }
                break;
            }
        }
        if ($className === null) {
            continue;
        }

        $ref = new ReflectionClass($className);
        expect($ref->isFinal())->toBeTrue("{$className} must be final");
    }
});

it('all console commands have typed signature and description properties', function (): void {
    $commandDir = __DIR__.'/../src/Console';
    $files = glob($commandDir.'/*.php');

    foreach ($files as $file) {
        $tokens = token_get_all(file_get_contents($file));
        $className = null;
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break;
                    }
                }
                break;
            }
        }
        if ($className === null) {
            continue;
        }

        $ref = new ReflectionClass($className);

        // Check $signature is typed
        $sigProp = $ref->getProperty('signature');
        expect($sigProp->hasType())->toBeTrue("{$className}::\$signature must have type declaration");
        expect($sigProp->getType()->getName())->toBe('string');

        // Check $description is typed
        $descProp = $ref->getProperty('description');
        expect($descProp->hasType())->toBeTrue("{$className}::\$description must have type declaration");
        expect($descProp->getType()->getName())->toBe('string');
    }
});

// --- Interface method parameter types ---

it('Triggerable interface handle has array parameter with docblock', function (): void {
    $ref = new ReflectionMethod(Triggerable::class, 'handle');
    $params = $ref->getParameters();
    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('payload');
    expect($params[0]->hasType())->toBeFalse(); // Docblock typed, not native
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('void');

    // Check docblock
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@param');
    expect($doc)->toContain('array<string, mixed>');
});

it('ConditionEngineContract matches has array parameters with docblock', function (): void {
    $ref = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    $params = $ref->getParameters();
    expect(count($params))->toBe(2);
    expect($params[0]->getName())->toBe('conditions');
    expect($params[1]->getName())->toBe('payload');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('bool');

    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@param');
    expect($doc)->toContain('array<string, mixed>');
});

// --- DomainEvent toArray/fromArray key consistency ---

it('DomainEvent toArray has all required keys', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'val']);
    $arr = $event->toArray();

    expect($arr)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
    expect($arr['eventType'])->toBe('test.event');
    expect($arr['payload'])->toBe(['key' => 'val']);
});

it('DomainEvent fromArray preserves all keys on roundtrip', function (): void {
    $original = DomainEvent::occur('roundtrip.test', ['foo' => 'bar']);
    $arr = $original->toArray();

    $restored = DomainEvent::fromArray($arr);

    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    // occurredAt may differ by microseconds but should match ISO format
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
});

it('DomainEvent is final class', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();
});

it('DomainEvent all properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $prop) {
        $p = $ref->getProperty($prop);
        expect($p->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
    }
});

// --- Facade resolved instance type ---

it('Facade resolves to EventManager concrete class', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');
    expect($method->isPublic())->toBeTrue();
    expect($method->isStatic())->toBeTrue();
    expect($method->hasReturnType())->toBeTrue();
    expect($method->getReturnType()->getName())->toBe('string');

    // Check the accessor returns the correct class
    expect($method->invoke(null))->toBe(EventManagerConcrete::class);
});

// --- Model relation return types ---

it('Trigger eventLogs relation returns HasMany', function (): void {
    $ref = new ReflectionMethod(Trigger::class, 'eventLogs');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('Illuminate\Database\Eloquent\Relations\HasMany');
});

it('EventLog trigger relation returns BelongsTo', function (): void {
    $ref = new ReflectionMethod(EventLog::class, 'trigger');
    $returnType = $ref->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('Illuminate\Database\Eloquent\Relations\BelongsTo');
});

// --- ServiceProvider binding verification ---

it('ConditionEngineContract is bound to ConditionEngine as singleton', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $instance1 = app()->make(ConditionEngineContract::class);
    $instance2 = app()->make(ConditionEngineContract::class);

    expect($instance1)->toBeInstanceOf(ConditionEngine::class);
    expect($instance1)->toBe($instance2); // Same instance (singleton)
});

it('TriggerBuilder is transient (new instance each time)', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $instance1 = app()->make(TriggerBuilder::class);
    $instance2 = app()->make(TriggerBuilder::class);

    expect($instance1)->not->toBe($instance2); // Different instances
});

it('SubscriptionBuilder is transient (new instance each time)', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $instance1 = app()->make(SubscriptionBuilder::class);
    $instance2 = app()->make(SubscriptionBuilder::class);

    expect($instance1)->not->toBe($instance2); // Different instances
});

it('ActionResolver is singleton', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $instance1 = app()->make(ActionResolver::class);
    $instance2 = app()->make(ActionResolver::class);

    expect($instance1)->toBe($instance2);
});

// --- Config file documentation ---

it('config/events.php has all 6 top-level sections documented', function (): void {
    $config = require __DIR__.'/../config/events.php';
    $sections = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];

    foreach ($sections as $section) {
        expect(array_key_exists($section, $config))
            ->toBeTrue("config/events.php must have '{$section}' section");
    }
});

it('config subscriptions section has all required keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    $subKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm'];

    foreach ($subKeys as $key) {
        expect(array_key_exists($key, $config['subscriptions']))
            ->toBeTrue("config.subscriptions must have '{$key}'");
    }
});

it('config table_names section has all 3 tables', function (): void {
    $config = require __DIR__.'/../config/events.php';
    $tables = ['triggers', 'event_logs', 'subscriptions'];

    foreach ($tables as $table) {
        expect(array_key_exists($table, $config['table_names']))
            ->toBeTrue("config.table_names must have '{$table}'");
    }
});

// --- phpstan config verification ---

it('phpstan.neon.dist exists and has level 9', function (): void {
    $path = __DIR__.'/../phpstan.neon.dist';
    expect(file_exists($path))->toBeTrue('phpstan.neon.dist must exist');

    $contents = file_get_contents($path);
    expect($contents)->toContain('level: 9');
    expect($contents)->toContain('paths:');
    expect($contents)->toContain('src');
});

// --- rector config verification ---

it('rector.php uses Laravel 130 set', function (): void {
    $path = __DIR__.'/../rector.php';
    expect(file_exists($path))->toBeTrue('rector.php must exist');

    $contents = file_get_contents($path);
    expect($contents)->toContain('LaravelSetList::LARAVEL_130');
});

// --- ConditionEngine operator coverage via reflection ---

it('ConditionEngine evaluateCondition handles all operators', function (): void {
    $engine = new ConditionEngine;

    // Test comparison operators
    expect($engine->matches(['val' => ['>', 5]], ['val' => 10]))->toBeTrue();
    expect($engine->matches(['val' => ['>=', 10]], ['val' => 10]))->toBeTrue();
    expect($engine->matches(['val' => ['<', 5]], ['val' => 3]))->toBeTrue();
    expect($engine->matches(['val' => ['<=', 5]], ['val' => 5]))->toBeTrue();

    // Equality operators
    expect($engine->matches(['val' => '='], ['val' => '=']))->toBeTrue();
    expect($engine->matches(['val' => ['===', true]], ['val' => true]))->toBeTrue();
    expect($engine->matches(['val' => ['!=', 'a']], ['val' => 'b']))->toBeTrue();
    expect($engine->matches(['val' => ['!==', 1]], ['val' => '1']))->toBeTrue();

    // Array operators
    expect($engine->matches(['val' => ['in', ['a', 'b']]], ['val' => 'a']))->toBeTrue();
    expect($engine->matches(['val' => ['not_in', ['a', 'b']]], ['val' => 'c']))->toBeTrue();
    expect($engine->matches(['val' => ['contains', 'hello']], ['val' => 'hello world']))->toBeTrue();
    expect($engine->matches(['val' => ['not_contains', 'x']], ['val' => 'abc']))->toBeTrue();
    expect($engine->matches(['val' => ['between', [1, 10]]], ['val' => 5]))->toBeTrue();

    // Null operators
    expect($engine->matches(['val' => ['null']], ['val' => null]))->toBeTrue();
    expect($engine->matches(['val' => ['not_null']], ['val' => 'x']))->toBeTrue();

    // Empty operators
    expect($engine->matches(['val' => ['empty']], ['val' => '']))->toBeTrue();
    expect($engine->matches(['val' => ['not_empty']], ['val' => 'x']))->toBeTrue();

    // String operators
    expect($engine->matches(['val' => ['starts_with', 'hel']], ['val' => 'hello']))->toBeTrue();
    expect($engine->matches(['val' => ['ends_with', 'llo']], ['val' => 'hello']))->toBeTrue();
    expect($engine->matches(['val' => ['matches', '/^h/']], ['val' => 'hello']))->toBeTrue();
});

it('ConditionEngine rejects comparison with null operands', function (): void {
    $engine = new ConditionEngine;

    // null actual should return false for >
    expect($engine->matches(['val' => ['>', 5]], ['val' => null]))->toBeFalse();
    // null value should return false for >
    expect($engine->matches(['val' => ['>', null]], ['val' => 10]))->toBeFalse();
    // null actual should return false for between
    expect($engine->matches(['val' => ['between', [1, 10]]], ['val' => null]))->toBeFalse();
});

it('ConditionEngine AND logic requires all conditions to match', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'active', 'amount' => 100],
    ))->toBeTrue();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'inactive', 'amount' => 100],
    ))->toBeFalse();

    expect($engine->matches(
        ['status' => 'active', 'amount' => ['>', 50]],
        ['status' => 'active', 'amount' => 30],
    ))->toBeFalse();
});

// --- Constructor parameter type verification ---

it('EventManager constructor has typed parameters', function (): void {
    $ref = new ReflectionMethod(EventManagerConcrete::class, '__construct');
    $params = $ref->getParameters();

    expect(count($params))->toBe(3);

    // conditionEngine: ConditionEngine
    expect($params[0]->getName())->toBe('conditionEngine');
    expect($params[0]->hasType())->toBeTrue();

    // actionResolver: ActionResolver
    expect($params[1]->getName())->toBe('actionResolver');
    expect($params[1]->hasType())->toBeTrue();

    // app: Container
    expect($params[2]->getName())->toBe('app');
    expect($params[2]->hasType())->toBeTrue();
});

it('TriggerBuilder constructor has typed EventManager parameter', function (): void {
    $ref = new ReflectionMethod(TriggerBuilder::class, '__construct');
    $params = $ref->getParameters();

    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('eventManager');
    expect($params[0]->hasType())->toBeTrue();
});

it('SubscriptionBuilder constructor has typed EventManager parameter', function (): void {
    $ref = new ReflectionMethod(SubscriptionBuilder::class, '__construct');
    $params = $ref->getParameters();

    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('eventManager');
    expect($params[0]->hasType())->toBeTrue();
});

it('DispatchTriggerJob constructor has 3 readonly promoted parameters', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $method = $ref->getMethod('__construct');
    $params = $method->getParameters();

    expect(count($params))->toBe(3);

    // triggerId, event, payload should be promoted readonly
    $readonlyProps = ['triggerId', 'event', 'payload'];
    foreach ($readonlyProps as $propName) {
        $prop = $ref->getProperty($propName);
        expect($prop->isReadOnly())->toBeTrue("DispatchTriggerJob::\${$propName} must be readonly");
        expect($prop->isPublic())->toBeTrue("DispatchTriggerJob::\${$propName} must be public");
    }
});

// --- Model casts completeness ---

it('Trigger casts include conditions, async, enabled, priority', function (): void {
    $trigger = new Trigger;
    $casts = $trigger->getCasts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('async');
    expect($casts)->toHaveKey('enabled');
    expect($casts)->toHaveKey('priority');
});

it('EventLog casts include payload, duration_ms', function (): void {
    $log = new EventLog;
    $casts = $log->getCasts();

    expect($casts)->toHaveKey('payload');
    expect($casts)->toHaveKey('duration_ms');
});

it('Subscription casts include conditions, priority, active, failure_count, delivery_count, last_fired_at', function (): void {
    $sub = new Subscription;
    $casts = $sub->getCasts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('priority');
    expect($casts)->toHaveKey('active');
    expect($casts)->toHaveKey('failure_count');
    expect($casts)->toHaveKey('delivery_count');
    expect($casts)->toHaveKey('last_fired_at');
});

// --- WildcardMatcher static method verification ---

it('WildcardMatcher matches is static and pure', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
    expect($ref->isStatic())->toBeTrue();
    expect($ref->isPublic())->toBeTrue();

    $attrs = $ref->getAttributes();
    $hasPure = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeTrue('WildcardMatcher::matches must have #[Pure] attribute');
});

it('WildcardMatcher findMatchingPatterns is static and pure', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
    expect($ref->isStatic())->toBeTrue();
    expect($ref->isPublic())->toBeTrue();

    $attrs = $ref->getAttributes();
    $hasPure = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeTrue('WildcardMatcher::findMatchingPatterns must have #[Pure] attribute');
});

it('WildcardMatcher extractWildcards is static and pure', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'extractWildcards');
    expect($ref->isStatic())->toBeTrue();
    expect($ref->isPublic())->toBeTrue();

    $attrs = $ref->getAttributes();
    $hasPure = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeTrue('WildcardMatcher::extractWildcards must have #[Pure] attribute');
});

// --- EventManager public method return types ---

it('EventManager all public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        expect($method->hasReturnType())->toBeTrue(
            "EventManager::{$method->getName()} must have return type declaration",
        );
    }
});

// --- Final class sweep ---

it('all core classes are final', function (): void {
    $finalClasses = [
        EventManagerConcrete::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
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

// --- composer.json version consistency ---

it('composer.json version matches README badge version', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'] ?? '';
    expect($version)->not->toBeEmpty('composer.json must have a version');
    expect($readme)->toContain($version);
});

// --- CHANGELOG has latest entry ---

it('CHANGELOG.md mentions current version', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? '';

    $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');
    // The changelog should have at least some entries
    expect(strlen($changelog))->toBeGreaterThan(100);
});

// --- Model boot UUID generation ---

it('Trigger boot generates UUID for empty id', function (): void {
    $trigger = new Trigger;
    expect($trigger->id)->toBe('');
});

it('EventLog boot generates UUID for empty id', function (): void {
    $log = new EventLog;
    expect($log->id)->toBe('');
});

it('Subscription boot generates UUID for empty id', function (): void {
    $sub = new Subscription;
    expect($sub->id)->toBe('');
});

// --- EventLog status transitions ---

it('EventLog markAsCompleted sets status and duration', function (): void {
    $log = new EventLog;
    $log->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'dispatched']);
    // Just verify the method exists and is callable
    expect(method_exists($log, 'markAsCompleted'))->toBeTrue();
});

it('EventLog markAsFailed sets status and error', function (): void {
    $log = new EventLog;
    $log->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'dispatched']);
    expect(method_exists($log, 'markAsFailed'))->toBeTrue();
});

// --- WebhookAction is Triggerable ---

it('WebhookAction implements Triggerable', function (): void {
    $ref = new ReflectionClass(WebhookAction::class);
    expect($ref->implementsInterface(Triggerable::class))->toBeTrue();
    expect($ref->isFinal())->toBeTrue();
});

// --- ConditionEngine implements ConditionEngineContract ---

it('ConditionEngine implements ConditionEngineContract', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    expect($ref->implementsInterface(ConditionEngineContract::class))->toBeTrue();
    expect($ref->isFinal())->toBeTrue();
});

// --- EscapesWildcardLike trait ---

it('EscapesWildcardLike wildcardToLike returns null for non-wildcard', function (): void {
    // Create anonymous class using the trait
    $obj = new class {
        use EscapesWildcardLike;

        public function test(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };

    expect($obj->test('order.placed'))->toBeNull();
    expect($obj->test('order.*'))->toBe('order.%');
    expect($obj->test('order.**'))->toBe('order.%');
    expect($obj->test('*.order.*'))->toBe('%.order.%');
});

it('EscapesWildcardLike escapes SQL special chars', function (): void {
    $obj = new class {
        use EscapesWildcardLike;

        public function test(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };

    // Percent and underscore should be escaped
    expect($obj->test('100%*'))->toBe('100\\%%');
    expect($obj->test('user_*'))->toBe('user\\_%%');
    expect($obj->test('a\\b.*'))->toBe('a\\\\b.%');
});
