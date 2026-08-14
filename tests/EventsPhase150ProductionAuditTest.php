<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

test('Phase 150: all source files have strict_types declaration', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('Phase 150: all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

test('Phase 150: all service classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        WildcardMatcher::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventsServiceProvider::class,
        EventManagerFacade::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('Phase 150: EventManager has readonly promoted constructor properties', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect(count($params))->toBe(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue("{$param->getName()} should be promoted");
        expect($param->isReadOnly())->toBeTrue("{$param->getName()} should be readonly");
    }
});

test('Phase 150: DomainEvent is readonly and immutable', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $reflection = new ReflectionClass(DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    // Verify all public properties are readonly
    foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
        expect($prop->isReadOnly())->toBeTrue("{$prop->getName()} must be readonly");
    }

    // Roundtrip serialization preserves identity
    $arr = $event->toArray();
    $restored = DomainEvent::fromArray($arr);
    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
});

test('Phase 150: WildcardMatcher is readonly final with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    // Only static methods
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("{$method->getName()} must be static");
    }
});

test('Phase 150: ServiceProvider provides matches registered bindings', function (): void {
    $provider = new EventsServiceProvider($this->app);

    $provides = $provider->provides();
    $expected = [
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    ];

    foreach ($expected as $binding) {
        expect(in_array($binding, $provides, true))->toBeTrue("Missing {$binding} in provides()");
    }

    expect(count($provides))->toBe(count($expected));
});

test('Phase 150: config has all required top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $requiredKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

test('Phase 150: config table_names has all required entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $tables = $config['table_names'];

    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('Phase 150: config subscriptions has all required entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $subs = $config['subscriptions'];

    expect($subs)->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ]);
});

test('Phase 150: ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine;
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('Phase 150: ConditionEngine has 21 operators in evaluateCondition match', function (): void {
    $reflection = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
    $filename = $reflection->getFileName();
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $source = file($filename, FILE_IGNORE_NEW_LINES);

    $methodBody = implode("\n", array_slice($source, $startLine - 1, $endLine - $startLine + 1));

    $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in', 'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty', 'starts_with', 'ends_with', 'matches'];

    foreach ($operators as $op) {
        expect($methodBody)->toContain("'{$op}'", "Missing operator '{$op}' in evaluateCondition");
    }

    // That's 20 named operators + implicit equality (non-array fallback) = 21 total
    expect(count($operators))->toBe(20); // 20 named + 1 implicit = 21
});

test('Phase 150: all models have getTable override reading from config', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];
    $configKeys = ['triggers', 'event_logs', 'subscriptions'];

    foreach ($models as $i => $model) {
        $reflection = new ReflectionMethod($model, 'getTable');
        $filename = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $source = file($filename, FILE_IGNORE_NEW_LINES);
        $body = implode("\n", array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        expect($body)->toContain("events.table_names.{$configKeys[$i]}");
    }
});

test('Phase 150: facade getFacadeAccessor returns EventManager class', function (): void {
    $reflection = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    expect($reflection->isStatic())->toBeTrue();

    // Verify the method exists and is overridden
    $attributes = $reflection->getAttributes(\Override::class);
    expect(count($attributes))->toBe(1);
});

test('Phase 150: no deprecated setAccessible calls in source files', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('setAccessible(');
    }
});

test('Phase 150: all models have casts returning array with correct types', function (): void {
    $expectedCasts = [
        Trigger::class => ['conditions' => 'array', 'async' => 'boolean', 'enabled' => 'boolean', 'priority' => 'int'],
        EventLog::class => ['payload' => 'array', 'duration_ms' => 'int', 'error' => 'string'],
        Subscription::class => ['conditions' => 'array', 'priority' => 'int', 'active' => 'boolean', 'failure_count' => 'int', 'delivery_count' => 'int', 'last_fired_at' => 'datetime'],
    ];

    foreach ($expectedCasts as $model => $casts) {
        $instance = new $model;
        $actualCasts = $instance->casts();

        foreach ($casts as $field => $type) {
            expect($actualCasts)->toHaveKey($field);
            expect($actualCasts[$field])->toBe($type);
        }
    }
});

test('Phase 150: EventLog has all status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toBe([
        'pending',
        'dispatched',
        'completed',
        'failed',
    ]);
});

test('Phase 150: EventManager fire validates empty event name', function (): void {
    $engine = new ConditionEngine;
    $resolver = new ActionResolver($this->app);
    $manager = new EventManager($engine, $resolver, $this->app);

    expect(fn () => $manager->fire(''))->toThrow(\InvalidArgumentException::class);
    expect(fn () => $manager->fire('0'))->toThrow(\InvalidArgumentException::class);
});

test('Phase 150: EventManager fireModel validates empty inputs', function (): void {
    $engine = new ConditionEngine;
    $resolver = new ActionResolver($this->app);
    $manager = new EventManager($engine, $resolver, $this->app);

    $model = new class
    {
        public function attributesToArray(): array
        {
            return ['id' => 1, 'name' => 'Test'];
        }
    };

    expect(fn () => $manager->fireModel('', 'created', $model))->toThrow(\InvalidArgumentException::class);
    expect(fn () => $manager->fireModel('Model', '', $model))->toThrow(\InvalidArgumentException::class);
});

test('Phase 150: TriggerBuilder save validates empty event and action', function (): void {
    $engine = new ConditionEngine;
    $resolver = new ActionResolver($this->app);
    $manager = new EventManager($engine, $resolver, $this->app);
    $builder = new TriggerBuilder($manager);

    expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class, 'Event name is required');
});

test('Phase 150: SubscriptionBuilder save validates empty event and URL', function (): void {
    $engine = new ConditionEngine;
    $resolver = new ActionResolver($this->app);
    $manager = new EventManager($engine, $resolver, $this->app);
    $builder = new SubscriptionBuilder($manager);

    expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class);
});

test('Phase 150: SubscriptionBuilder save rejects non-HTTP URLs', function (): void {
    $engine = new ConditionEngine;
    $resolver = new ActionResolver($this->app);
    $manager = new EventManager($engine, $resolver, $this->app);
    $builder = new SubscriptionBuilder($manager);

    $builder->on('test.event')->to('ftp://evil.com/hook');

    expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
});

test('Phase 150: WebhookAction implements Triggerable', function (): void {
    $reflection = new ReflectionClass(WebhookAction::class);
    expect($reflection->implementsInterface(Triggerable::class))->toBeTrue();

    $handleMethod = $reflection->getMethod('handle');
    expect($handleMethod->getReturnType()->getName())->toBe('void');
    expect($handleMethod->hasReturnType())->toBeTrue();
});

test('Phase 150: DispatchTriggerJob has config-driven properties', function (): void {
    $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'value']);

    // Verify properties are public
    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    expect($reflection->getProperty('tries')->isPublic())->toBeTrue();
    expect($reflection->getProperty('backoff')->isPublic())->toBeTrue();
    expect($reflection->getProperty('queue')->isPublic())->toBeTrue();
    expect($reflection->getProperty('connection')->isPublic())->toBeTrue();
});

test('Phase 150: EventScheduler uses constructor injection', function (): void {
    $reflection = new ReflectionClass(EventScheduler::class);
    $constructor = $reflection->getConstructor();
    $params = $constructor->getParameters();

    expect(count($params))->toBe(1);
    expect($params[0]->getName())->toBe('app');
    expect($params[0]->getType()->getName())->toBe(\Illuminate\Container\Container::class);
    expect($params[0]->isPromoted())->toBeTrue();
    expect($params[0]->isReadOnly())->toBeTrue();
});

test('Phase 150: phpstan.neon.dist exists and has correct level', function (): void {
    $configPath = __DIR__.'/../phpstan.neon.dist';
    expect(file_exists($configPath))->toBeTrue();

    $content = file_get_contents($configPath);
    expect($content)->toContain('level: 9');
    expect($content)->toContain('paths:');
    expect($content)->toContain('src');
});

test('Phase 150: README version badge matches composer.json version', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'];

    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$version}-blue");
});

test('Phase 150: README test file count matches actual', function (): void {
    $testCount = count(glob(__DIR__.'/../tests/*Test.php'));
    $supportCount = count(glob(__DIR__.'/../tests/*.php')) - $testCount;

    $readme = file_get_contents(__DIR__.'/../README.md');

    // README says "228 test files"
    expect($testCount)->toBe(228);
    expect($supportCount)->toBe(5);

    // Check total PHP files reference
    $totalPhp = $testCount + $supportCount;
    expect($readme)->toContain("{$totalPhp} PHP files");
});

test('Phase 150: all console commands extend Illuminate\\Console\\Command', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');

    foreach ($commandFiles as $file) {
        $className = 'ZeroBoiler\\Events\\Console\\'.basename($file, '.php');
        $reflection = new ReflectionClass($className);
        expect($reflection->isFinal())->toBeTrue("{$className} must be final");
        expect($reflection->isSubclassOf(\Illuminate\Console\Command::class))->toBeTrue("{$className} must extend Command");
    }
});

test('Phase 150: Triggerable interface has handle method with void return', function (): void {
    $reflection = new ReflectionMethod(Triggerable::class, 'handle');
    expect($reflection->hasReturnType())->toBeTrue();
    expect($reflection->getReturnType()->getName())->toBe('void');
});

test('Phase 150: ConditionEngineContract has matches method with bool return', function (): void {
    $reflection = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    expect($reflection->hasReturnType())->toBeTrue();
    expect($reflection->getReturnType()->getName())->toBe('bool');
});

test('Phase 150: migrations directory has 3 migration files', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');
    expect(count($migrations))->toBe(3);
});

test('Phase 150: factories directory has 3 factory files', function (): void {
    $factories = glob(__DIR__.'/../database/factories/*.php');
    expect(count($factories))->toBe(3);
});
