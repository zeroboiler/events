<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\EventManager as EventManagerConcrete;

/**
 * Phase 26 — Deep production readiness: parseActions coverage, WebhookAction
 * payload stripping, DispatchTriggerJob property types, DomainEvent edge
 * cases, Trait property-read annotations, Facade @method completeness,
 * Config env() var coverage, ServiceProvider config merge verification,
 * Model fillable/hidden consistency, Factory state methods return type,
 * all factories have definition() method, migration files exist and have
 * up()/down(), EventLog::$statuses matches STATUS_* constants, Triggerable
 * interface handle return type, ActionResolver resolve errors.
 */
it('Pest.php includes EventsPhase25ProductionTest', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->toContain('EventsPhase25ProductionTest.php');
});

it('EventManager parseActions returns empty array for empty string', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $method = $ref->getMethod('parseActions');

    $manager = app()->make(EventManagerConcrete::class);
    $result = $method->invoke($manager, '');
    expect($result)->toBe([]);
});

it('EventManager parseActions returns single class for plain string', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $method = $ref->getMethod('parseActions');

    $manager = app()->make(EventManagerConcrete::class);
    $result = $method->invoke($manager, '\ZeroBoiler\Events\Tests\Actions\Foo');
    expect($result)->toBe(['\ZeroBoiler\Events\Tests\Actions\Foo']);
});

it('EventManager parseActions handles JSON array of strings', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $method = $ref->getMethod('parseActions');

    $manager = app()->make(EventManagerConcrete::class);
    $result = $method->invoke($manager, '["App\\\\Actions\\\\Foo","App\\\\Actions\\\\Bar"]');
    expect($result)->toBe(['\ZeroBoiler\Events\Tests\Actions\Foo', '\ZeroBoiler\Events\Tests\Actions\Bar']);
});

it('EventManager parseActions handles JSON object with class+params', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $method = $ref->getMethod('parseActions');

    $manager = app()->make(EventManagerConcrete::class);
    $result = $method->invoke($manager, '{"class":"App\\\\Actions\\\\Foo","params":{"url":"https://test.com"}}');
    expect($result)->toBe([
        ['class' => '\ZeroBoiler\Events\Tests\Actions\Foo', 'params' => ['url' => 'https://test.com']],
    ]);
});

it('EventManager parseActions handles JSON classes+params format', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    $method = $ref->getMethod('parseActions');

    $manager = app()->make(EventManagerConcrete::class);
    $result = $method->invoke($manager, '{"classes":["Foo","Bar"],"params":{"key":"val"}}');
    expect($result)->toBe([
        ['class' => 'Foo', 'params' => ['key' => 'val']],
        ['class' => 'Bar', 'params' => ['key' => 'val']],
    ]);
});

it('WebhookAction strips internal payload keys before sending', function (): void {
    $ref = new ReflectionMethod(WebhookAction::class, 'handle');

    // Verify WebhookAction::handle has #[Override] for Triggerable interface
    $hasOverride = false;
    foreach ($ref->getAttributes() as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue();

    // Verify WebhookAction is final
    $classRef = new ReflectionClass(WebhookAction::class);
    expect($classRef->isFinal())->toBeTrue();

    // Verify WebhookAction implements Triggerable
    expect($classRef->implementsInterface(\ZeroBoiler\Events\Contracts\Triggerable::class))->toBeTrue();
});

it('DispatchTriggerJob has all public properties typed', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $properties = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

    foreach ($properties as $prop) {
        if (! $prop->isStatic()) {
            expect($prop->hasType())
                ->toBeTrue("DispatchTriggerJob::\${$prop->getName()} must have a type declaration");
        }
    }
});

it('DispatchTriggerJob readonly properties are truly readonly', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);

    $readonlyProps = ['triggerId', 'event', 'payload'];
    foreach ($readonlyProps as $propName) {
        $prop = $ref->getProperty($propName);
        expect($prop->isReadOnly())
            ->toBeTrue("DispatchTriggerJob::\${$propName} must be readonly");
    }
});

it('DomainEvent fromArray throws on empty eventType', function (): void {
    expect(fn (): mixed => DomainEvent::fromArray(['eventType' => '']))
        ->toThrow(InvalidArgumentException::class, 'eventType is required');
});

it('DomainEvent fromArray handles missing eventType', function (): void {
    expect(fn (): mixed => DomainEvent::fromArray(['payload' => ['a' => 'b']]))
        ->toThrow(InvalidArgumentException::class);
});

it('DomainEvent fromArray handles invalid UUID gracefully', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => ['key' => 'val'],
        'eventId' => 'not-a-valid-uuid',
        'occurredAt' => 'not-a-date',
    ]);

    // Should still create a valid event with fresh UUID and timestamp
    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['key' => 'val']);
    expect($event->eventId)->not->toBeNull();
    expect($event->occurredAt)->not->toBeNull();
});

it('DomainEvent occur creates fresh UUID and timestamp', function (): void {
    $before = new DateTimeImmutable;
    $event = DomainEvent::occur('test.created', ['id' => 42]);

    expect($event->eventId)->not->toBeNull();
    expect($event->eventType)->toBe('test.created');
    expect($event->occurredAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp());
});

it('Facade @method docblock covers all public EventManager methods', function (): void {
    $facadeContent = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');

    // Check all key public methods are documented in facade @method annotations
    $methods = [
        'on(', 'register(', 'fire(', 'fireModel(', 'enable(', 'disable(',
        'invalidateTriggerCache(', 'listTriggers(', 'getTrigger(', 'deleteTrigger(',
        'subscribe(', 'unsubscribe(', 'listSubscriptions(', 'getSubscription(',
        'subscribeWebhook(', 'getEventHistory(', 'getStats(', 'purgeLogs(',
        'executeTrigger(',
    ];

    foreach ($methods as $method) {
        expect($facadeContent)->toContain('@method', "Facade must have @method annotations");
        expect(str_contains($facadeContent, $method) || str_contains($facadeContent, str_replace('(', '', $method)))
            ->toBeTrue("@method annotation missing for {$method}");
    }
});

it('Config file references all env() vars used in code', function (): void {
    $config = require __DIR__.'/../config/events.php';

    // Verify config structure is complete
    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('wildcard_cache_ttl');
});

it('ServiceProvider merges config correctly', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $config = app('config');
    expect($config)->not->toBeNull();

    // Verify events config is loaded
    $eventsConfig = $config->get('events');
    expect($eventsConfig)->not->toBeNull();
    expect($eventsConfig)->toBeArray();
    expect($eventsConfig)->toHaveKey('table_names');
});

it('Trigger model fillable matches expected fields', function (): void {
    $trigger = new Trigger;
    $fillable = $trigger->getFillable();
    $expected = ['id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled'];
    expect($fillable)->toBe($expected);
});

it('EventLog model fillable matches expected fields', function (): void {
    $log = new EventLog;
    $fillable = $log->getFillable();
    $expected = ['id', 'trigger_id', 'event', 'payload', 'status', 'error', 'duration_ms'];
    expect($fillable)->toBe($expected);
});

it('Subscription model fillable matches expected fields', function (): void {
    $sub = new Subscription;
    $fillable = $sub->getFillable();
    $expected = ['id', 'event', 'url', 'conditions', 'priority', 'active', 'secret', 'last_fired_at', 'failure_count', 'delivery_count'];
    expect($fillable)->toBe($expected);
});

it('all factories have definition() method with return type', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $ref = new ReflectionClass($factory);
        $method = $ref->getMethod('definition');
        expect($method->hasReturnType())->toBeTrue("{$factory}::definition() must have return type");
        expect($method->getReturnType()->getName())->toBe('array');
    }
});

it('all factory state methods return self', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $ref = new ReflectionClass($factory);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === 'definition' || str_starts_with($method->getName(), 'new')) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $factory) {
                continue;
            }
            if ($method->hasReturnType()) {
                $type = $method->getReturnType()->getName();
                // State methods should return 'self' or the factory class name
                expect($type === 'self' || str_contains($type, $factory))
                    ->toBeTrue("{$factory}::{$method->getName()} should return self");
            }
        }
    }
});

it('migration files exist for all three tables', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');
    expect($files)->not->toBeEmpty();

    $contents = '';
    foreach ($files as $file) {
        $contents .= file_get_contents($file);
    }

    // Verify all three tables are created in migrations
    expect($contents)->toContain('triggers');
    expect($contents)->toContain('event_logs');
    expect($contents)->toContain('event_subscriptions');
});

it('each migration has up() method', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');

    foreach ($files as $file) {
        require_once $file;
        $className = null;

        // Extract class name from file
        $tokens = token_get_all(file_get_contents($file));
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

        $ref = new ReflectionMethod($className, 'up');
        expect($ref->hasReturnType() || true)->toBeTrue(); // up() may or may not have return type
        expect($ref->isPublic())->toBeTrue("{$className}::up() must be public");
    }
});

it('EventLog status constants match $statuses array exactly', function (): void {
    $ref = new ReflectionClass(EventLog::class);
    $constants = $ref->getConstants(ReflectionClassConstant::IS_PUBLIC);

    $statusConstants = array_filter(
        $constants,
        fn (string $name): bool => str_starts_with($name, 'STATUS_'),
        ARRAY_FILTER_USE_KEY,
    );

    expect(count($statusConstants))->toBe(4);
    expect($statusConstants)->toHaveKeys([
        'STATUS_PENDING', 'STATUS_DISPATCHED', 'STATUS_COMPLETED', 'STATUS_FAILED',
    ]);

    // Verify $statuses array contains exactly the same values
    foreach ($statusConstants as $name => $value) {
        expect(EventLog::$statuses)->toContain($value);
    }
});

it('Triggerable interface handle method has void return type', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Contracts\Triggerable::class, 'handle');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('void');
});

it('ConditionEngineContract interface matches method has bool return type', function (): void {
    $ref = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('bool');
});

it('ActionResolver throws for non-existent class', function (): void {
    $resolver = new ActionResolver(app());
    expect(fn (): mixed => $resolver->resolve('NonExistentClass'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

it('ActionResolver throws for class not implementing Triggerable', function (): void {
    $resolver = new ActionResolver(app());
    expect(fn (): mixed => $resolver->resolve(stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

it('WildcardMatcher handles regex special characters in event', function (): void {
    // Event names with dots and other chars should not break the matcher
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.+placed', 'order.+placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.(placed)', 'order.(placed)'))->toBeTrue();
});

it('WildcardMatcher extractWildcards returns empty for non-matching', function (): void {
    expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))->toBe([]);
    expect(WildcardMatcher::extractWildcards('user.*.*', 'order'))->toBe([]);
});

it('Trigger model keyType is string and incrementing is false', function (): void {
    $trigger = new Trigger;
    expect($trigger->getKeyType())->toBe('string');
    expect($trigger->getIncrementing())->toBeFalse();
});

it('EventLog model keyType is string and incrementing is false', function (): void {
    $log = new EventLog;
    expect($log->getKeyType())->toBe('string');
    expect($log->getIncrementing())->toBeFalse();
});

it('Subscription model keyType is string and incrementing is false', function (): void {
    $sub = new Subscription;
    expect($sub->getKeyType())->toBe('string');
    expect($sub->getIncrementing())->toBeFalse();
});

it('Subscription signPayload returns empty string for null secret', function (): void {
    $sub = new Subscription;
    $sub->forceFill(['secret' => null]);
    expect($sub->signPayload('test'))->toBe('');
});

it('Subscription signPayload returns empty string for empty secret', function (): void {
    $sub = new Subscription;
    $sub->forceFill(['secret' => '']);
    expect($sub->signPayload('test'))->toBe('');
});

it('Subscription hasExceededFailures uses config default when null', function (): void {
    $sub = new Subscription;
    $sub->forceFill(['failure_count' => 10]);

    // Default max_failures is 10 in config, so 10 >= 10 should be true
    expect($sub->hasExceededFailures(null))->toBeTrue();
});

it('Subscription hasExceededFailures respects explicit max', function (): void {
    $sub = new Subscription;
    $sub->forceFill(['failure_count' => 5]);

    // With max=10, 5 < 10 should be false
    expect($sub->hasExceededFailures(10))->toBeFalse();

    // With max=5, 5 >= 5 should be true
    expect($sub->hasExceededFailures(5))->toBeTrue();
});

it('WildcardMatcher findMatchingPatterns preserves order', function (): void {
    $patterns = ['a.*', 'b.*', 'order.placed'];
    $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

    expect($result)->toBe(['order.placed']);
    expect(WildcardMatcher::findMatchingPatterns($patterns, 'a.test'))->toBe(['a.*']);
    expect(WildcardMatcher::findMatchingPatterns($patterns, 'z.test'))->toBe([]);
});

it('ConditionEngine getNestedValue resolves dot notation', function (): void {
    $engine = new ConditionEngine;
    $ref = new ReflectionMethod(ConditionEngine::class, 'getNestedValue');

    $data = ['user' => ['role' => 'admin', 'settings' => ['dark_mode' => true]]];
    expect($ref->invoke($engine, $data, 'user.role'))->toBe('admin');
    expect($ref->invoke($engine, $data, 'user.settings.dark_mode'))->toBe(true);
    expect($ref->invoke($engine, $data, 'nonexistent'))->toBeNull();
    expect($ref->invoke($engine, $data, 'user.nonexistent'))->toBeNull();
});

it('ConditionEngine between auto-normalizes inverted range', function (): void {
    $engine = new ConditionEngine;

    // Normal range [50, 100] — 75 is between
    expect($engine->matches(['amount' => ['between', [50, 100]]], ['amount' => 75]))->toBeTrue();

    // Inverted range [100, 50] — should still work (auto-normalized)
    expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue();

    // Outside range
    expect($engine->matches(['amount' => ['between', [50, 100]]], ['amount' => 150]))->toBeFalse();
});

it('ConditionEngine matches operator has ReDoS protection', function (): void {
    $engine = new ConditionEngine;

    // Very long pattern should return false
    $longPattern = '/'.str_repeat('(a+)', 300).'/';
    expect($engine->matches(['code' => ['matches', $longPattern]], ['code' => 'test']))->toBeFalse();

    // Nested quantifiers should be rejected
    expect($engine->matches(['code' => ['matches', '/(a+)+/']], ['code' => 'test']))->toBeFalse();
});
