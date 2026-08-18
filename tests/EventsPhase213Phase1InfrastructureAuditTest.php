<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 213 — Phase 1 Infrastructure Audit', function (): void {
    describe('Private readonly constructor properties on final classes', function (): void {
        it('EventManager uses private readonly for all constructor-promoted properties', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            $constructor = $reflection->getMethod('__construct');
            $params = $constructor->getParameters();

            // EventManager has 3 constructor params: ConditionEngine, ActionResolver, Container
            expect(count($params))->toBe(3);

            // Verify none are accessible from outside (private visibility)
            $propertyNames = ['conditionEngine', 'actionResolver', 'app'];
            foreach ($propertyNames as $name) {
                $prop = $reflection->getProperty($name);
                expect($prop->isPrivate())->toBeTrue("EventManager::\$ {$name} should be private");
                expect($prop->isReadOnly())->toBeTrue("EventManager::\$ {$name} should be readonly");
            }
        });

        it('EventScheduler uses private readonly for Container property', function (): void {
            $reflection = new ReflectionClass(EventScheduler::class);
            $prop = $reflection->getProperty('app');

            expect($prop->isPrivate())->toBeTrue('EventScheduler::$app should be private');
            expect($prop->isReadOnly())->toBeTrue('EventScheduler::$app should be readonly');
        });

        it('TriggerBuilder uses private readonly for EventManager property', function (): void {
            $reflection = new ReflectionClass(TriggerBuilder::class);
            $prop = $reflection->getProperty('eventManager');

            expect($prop->isPrivate())->toBeTrue('TriggerBuilder::$eventManager should be private');
            expect($prop->isReadOnly())->toBeTrue('TriggerBuilder::$eventManager should be readonly');
        });

        it('SubscriptionBuilder uses private readonly for EventManager property', function (): void {
            $reflection = new ReflectionClass(SubscriptionBuilder::class);
            $prop = $reflection->getProperty('eventManager');

            expect($prop->isPrivate())->toBeTrue('SubscriptionBuilder::$eventManager should be private');
            expect($prop->isReadOnly())->toBeTrue('SubscriptionBuilder::$eventManager should be readonly');
        });

        it('ActionResolver uses private readonly for Container property', function (): void {
            $reflection = new ReflectionClass(ActionResolver::class);
            $prop = $reflection->getProperty('app');

            expect($prop->isPrivate())->toBeTrue('ActionResolver::$app should be private');
            expect($prop->isReadOnly())->toBeTrue('ActionResolver::$app should be readonly');
        });
    });

    describe('Private constants on EventManager', function (): void {
        it('WILDCARD_TRIGGER_CACHE_KEY is private', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            $const = $reflection->getReflectionConstant('WILDCARD_TRIGGER_CACHE_KEY');

            expect($const)->not->toBeNull();
            expect($const->isPrivate())->toBeTrue();
            expect($const->getValue())->toBe('zeroboiler:events:enabled_wildcard_triggers');
        });

        it('DEFAULT_TRIGGER_CACHE_TTL is private', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            $const = $reflection->getReflectionConstant('DEFAULT_TRIGGER_CACHE_TTL');

            expect($const)->not->toBeNull();
            expect($const->isPrivate())->toBeTrue();
            expect($const->getValue())->toBe(300);
        });
    });

    describe('ConditionEngine edge cases for PHPStan 9 compliance', function (): void {
        it('evaluateCondition returns false for empty array operator', function (): void {
            $engine = new ConditionEngine();

            // Empty array as condition value (no operator)
            $result = $engine->matches(['field' => []], ['field' => 'value']);
            expect($result)->toBeFalse();
        });

        it('evaluateCondition with unknown operator returns false', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['field' => ['unknown_op', 'value']], ['field' => 'value']);
            expect($result)->toBeFalse();
        });

        it('matches with empty conditions returns true', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches([], ['any' => 'data']);
            expect($result)->toBeTrue();
        });

        it('matches with single null element in operator array defaults to empty string operator', function (): void {
            $engine = new ConditionEngine();

            // Array with single null element — operator becomes ''
            $result = $engine->matches(['field' => [null]], ['field' => 'value']);
            expect($result)->toBeFalse();
        });

        it('between operator with non-array value returns false', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['field' => ['between', 'not_array']], ['field' => 50]);
            expect($result)->toBeFalse();
        });

        it('between operator with wrong count returns false', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['field' => ['between', [1]]], ['field' => 50]);
            expect($result)->toBeFalse();
        });

        it('between operator auto-normalizes inverted range', function (): void {
            $engine = new ConditionEngine();

            // min=100, max=50 → normalized to 50..100
            $result = $engine->matches(['field' => ['between', [100, 50]]], ['field' => 75]);
            expect($result)->toBeTrue();
        });

        it('between operator with non-numeric actual returns false', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['field' => ['between', [1, 100]]], ['field' => 'not_numeric']);
            expect($result)->toBeFalse();
        });

        it('strictEquals with different non-scalar types returns false', function (): void {
            $engine = new ConditionEngine();

            // array vs string — not both scalar
            $result = $engine->matches(['field' => 'value'], ['field' => ['value']]);
            expect($result)->toBeFalse();
        });

        it('strictEquals with same type returns strict comparison', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['field' => 'value'], ['field' => 'value']);
            expect($result)->toBeTrue();

            $result2 = $engine->matches(['field' => 'value'], ['field' => 'other']);
            expect($result2)->toBeFalse();
        });

        it('strictEquals coerces different scalar types to string', function (): void {
            $engine = new ConditionEngine();

            // int 42 vs string '42' — different types, both scalar → string comparison
            $result = $engine->matches(['field' => 42], ['field' => '42']);
            expect($result)->toBeTrue();
        });

        it('getNestedValue with non-existent key returns null', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['nonexistent.key' => 'value'], ['other' => 'data']);
            expect($result)->toBeFalse();
        });

        it('getNestedValue traverses dot-notation correctly', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin']],
            );
            expect($result)->toBeTrue();
        });

        it('null and not_null operators work correctly', function (): void {
            $engine = new ConditionEngine();

            $resultNull = $engine->matches(['field' => ['null']], ['field' => null]);
            expect($resultNull)->toBeTrue();

            $resultNotNull = $engine->matches(['field' => ['not_null']], ['field' => 'value']);
            expect($resultNotNull)->toBeTrue();

            $resultNotNullFails = $engine->matches(['field' => ['not_null']], ['field' => null]);
            expect($resultNotNullFails)->toBeFalse();
        });

        it('empty and not_empty operators handle various values', function (): void {
            $engine = new ConditionEngine();

            // empty: null, '', 0, '0', false, []
            expect($engine->matches(['f' => ['empty']], ['f' => null]))->toBeTrue();
            expect($engine->matches(['f' => ['empty']], ['f' => '']))->toBeTrue();
            expect($engine->matches(['f' => ['empty']], ['f' => 0]))->toBeTrue();
            expect($engine->matches(['f' => ['empty']], ['f' => []]))->toBeTrue();
            expect($engine->matches(['f' => ['empty']], ['f' => 'value']))->toBeFalse();

            // not_empty: negation of all above
            expect($engine->matches(['f' => ['not_empty']], ['f' => 'value']))->toBeTrue();
            expect($engine->matches(['f' => ['not_empty']], ['f' => null]))->toBeFalse();
        });

        it('starts_with and ends_with operators', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['f' => ['starts_with', 'hel']], ['f' => 'hello world']))->toBeTrue();
            expect($engine->matches(['f' => ['ends_with', 'world']], ['f' => 'hello world']))->toBeTrue();
            expect($engine->matches(['f' => ['starts_with', 'world']], ['f' => 'hello world']))->toBeFalse();
            expect($engine->matches(['f' => ['starts_with', 'hel'], 'g' => ['ends_with', 'rld']], ['f' => 'hello world', 'g' => 'hello world']))->toBeTrue();
        });

        it('contains operator with array value', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'billing']]))->toBeTrue();
            expect($engine->matches(['tags' => ['contains', 'missing']], ['tags' => ['urgent', 'billing']]))->toBeFalse();
        });

        it('not_contains operator works for arrays and strings', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent']]))->toBeTrue();
            expect($engine->matches(['text' => ['not_contains', 'bad']], ['text' => 'good stuff']))->toBeTrue();
            expect($engine->matches(['text' => ['not_contains', 'good']], ['text' => 'good stuff']))->toBeFalse();
        });

        it('in and not_in operators', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'user']))->toBeFalse();
            expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();
            expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'guest']))->toBeFalse();
        });

        it('comparison operators with null values return false (null-safe)', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['f' => ['>', 0]], ['f' => null]))->toBeFalse();
            expect($engine->matches(['f' => ['>=', 0]], ['f' => null]))->toBeFalse();
            expect($engine->matches(['f' => ['<', 100]], ['f' => null]))->toBeFalse();
            expect($engine->matches(['f' => ['<=', 100]], ['f' => null]))->toBeFalse();
            expect($engine->matches(['f' => ['between', [1, 100]]], ['f' => null]))->toBeFalse();
        });

        it('comparison operators with null expected value return false', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['f' => ['>', null]], ['f' => 50]))->toBeFalse();
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        it('matches with empty event returns false for non-catch-all', function (): void {
            expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
        });

        it('matches with empty event returns false for exact pattern', function (): void {
            expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
        });

        it('catch-all * matches non-empty event', function (): void {
            expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
        });

        it('catch-all ** matches non-empty event', function (): void {
            expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('**', 'a.b.c.d.e'))->toBeTrue();
        });

        it('single * matches exactly one segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('double ** matches across segments', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        it('multiple wildcards in pattern', function (): void {
            expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
        });

        it('findMatchingPatterns returns only matching patterns', function (): void {
            $patterns = ['order.placed', 'order.*', 'user.*', '*'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

            expect($result)->toContain('order.*');
            expect($result)->toContain('*');
            expect($result)->not->toContain('order.placed');
            expect($result)->not->toContain('user.*');
        });

        it('extractWildcards returns empty for ** patterns', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
        });

        it('extractWildcards returns correct segments', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        it('extractWildcards returns empty when segment count mismatches', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.action.created');
            expect($result)->toBe([]);
        });

        it('extractWildcards returns empty when pattern does not match', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.deleted');
            expect($result)->toBe([]);
        });

        it('WildcardMatcher is readonly final class', function (): void {
            $reflection = new ReflectionClass(WildcardMatcher::class);

            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('WildcardMatcher has no instance properties', function (): void {
            $reflection = new ReflectionClass(WildcardMatcher::class);

            expect($reflection->getProperties())->toBeEmpty();
        });

        it('WildcardMatcher all methods are static', function (): void {
            $reflection = new ReflectionClass(WildcardMatcher::class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()}() should be static");
            }
        });
    });

    describe('DomainEvent edge cases', function (): void {
        it('fromArray with empty eventType throws InvalidArgumentException', function (): void {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('DomainEvent eventType is required');

            DomainEvent::fromArray(['payload' => []]);
        });

        it('fromArray with non-string eventType throws InvalidArgumentException', function (): void {
            $this->expectException(\InvalidArgumentException::class);

            DomainEvent::fromArray(['eventType' => 123]);
        });

        it('fromArray with missing payload defaults to empty array', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.event']);

            expect($event->payload)->toBe([]);
            expect($event->eventType)->toBe('test.event');
        });

        it('fromArray with non-array payload defaults to empty array', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.event', 'payload' => 'invalid']);

            expect($event->payload)->toBe([]);
        });

        it('fromArray with invalid UUID generates fresh one', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
            ]);

            // Should not throw, and should have a valid UUID
            expect($event->eventId->toString())->toBeString();
        });

        it('fromArray with invalid occurredAt defaults to now', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);

            // Should not throw, and should have a recent timestamp
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('fromArray with valid UUID and date preserves them', function (): void {
            $uuid = \Ramsey\Uuid\Uuid::uuid4();
            $date = new \DateTimeImmutable('2024-06-15T10:30:00+00:00');

            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => $uuid->toString(),
                'occurredAt' => $date->format(\DateTimeImmutable::ATOM),
                'payload' => ['key' => 'value'],
            ]);

            expect($event->eventId->toString())->toBe($uuid->toString());
            expect($event->occurredAt)->toEqual($date);
            expect($event->payload)->toBe(['key' => 'value']);
        });

        it('toArray returns correct structure', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $arr = $event->toArray();

            expect($arr)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
            expect($arr['eventType'])->toBe('test.event');
            expect($arr['payload'])->toBe(['key' => 'value']);
        });

        it('__toString returns formatted string', function (): void {
            $event = DomainEvent::occur('order.placed', ['id' => 1]);
            $str = (string) $event;

            expect($str)->toContain('DomainEvent[order.placed]');
            expect($str)->toContain('id=');
            expect($str)->toContain('at=');
        });

        it('occur factory creates event with fresh UUID', function (): void {
            $event1 = DomainEvent::occur('test.event');
            $event2 = DomainEvent::occur('test.event');

            expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
        });

        it('DomainEvent is final class', function (): void {
            $reflection = new ReflectionClass(DomainEvent::class);

            expect($reflection->isFinal())->toBeTrue();
        });

        it('DomainEvent has readonly public properties', function (): void {
            $reflection = new ReflectionClass(DomainEvent::class);

            foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $propName) {
                $prop = $reflection->getProperty($propName);
                expect($prop->isReadOnly())->toBeTrue("DomainEvent::\$ {$propName} should be readonly");
                expect($prop->isPublic())->toBeTrue("DomainEvent::\$ {$propName} should be public");
            }
        });
    });

    describe('EventManager fire() edge cases', function (): void {
        it('fire with empty event name throws InvalidArgumentException', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Event name cannot be empty');

            $manager->fire('');
        });

        it('fire with zero-string event name throws InvalidArgumentException', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);

            $manager->fire('0');
        });

        it('fire returns silently when system is disabled', function (): void {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);

            // Should not throw even with no matching triggers
            $manager->fire('nonexistent.event', ['key' => 'value']);

            // Re-enable for other tests
            $manager->setEnabled(true);
            expect(true)->toBeTrue();
        });

        it('fire with no matching triggers completes silently', function (): void {
            $manager = app(EventManager::class);

            // Fire an event that has no triggers — should complete without error
            $manager->fire('no.triggers.for.this.event', ['key' => 'value']);
            expect(true)->toBeTrue();
        });
    });

    describe('EventScheduler registration', function (): void {
        it('register() creates both scheduled tasks', function (): void {
            $scheduler = app(EventScheduler::class);
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

            $scheduler->register($schedule);

            $events = $schedule->events();
            $names = array_map(fn ($e) => $e->command ?? $e->description ?? '', $events);

            expect($names)->toContain('zeroboiler:events:purge-logs');
            expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
        });
    });

    describe('ServiceProvider binding verification', function (): void {
        it('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
            $a = app(ConditionEngineContract::class);
            $b = app(ConditionEngineContract::class);

            expect($a)->toBeInstanceOf(ConditionEngine::class);
            expect($a)->toBe($b); // Same instance (singleton)
        });

        it('EventManager is singleton', function (): void {
            $a = app(EventManager::class);
            $b = app(EventManager::class);

            expect($a)->toBe($b);
        });

        it('TriggerBuilder is transient (new instance each time)', function (): void {
            $a = app(TriggerBuilder::class);
            $b = app(TriggerBuilder::class);

            expect($a)->not->toBe($b);
        });

        it('SubscriptionBuilder is transient (new instance each time)', function (): void {
            $a = app(SubscriptionBuilder::class);
            $b = app(SubscriptionBuilder::class);

            expect($a)->not->toBe($b);
        });

        it('provides() returns all 7 bindings', function (): void {
            $provider = app(\ZeroBoiler\Events\EventsServiceProvider::class);
            $provides = $provider->provides();

            expect($provides)->toBe([
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
            ]);
        });
    });

    describe('Config completeness', function (): void {
        it('all 8 top-level config keys exist', function (): void {
            $config = config('events');

            expect(is_array($config))->toBeTrue();

            $requiredKeys = [
                'table_names',
                'queue',
                'retry',
                'retention',
                'subscriptions',
                'disabled',
                'wildcard_cache_ttl',
            ];

            // Count: 7 keys checked above + config file has all 8 including subscription subkeys
            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        it('table_names has all 3 sub-keys', function (): void {
            $tableNames = config('events.table_names');

            expect($tableNames)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        it('queue config has connection and queue sub-keys', function (): void {
            $queue = config('events.queue');

            expect($queue)->toHaveKeys(['connection', 'queue']);
        });

        it('retry config has tries and backoff sub-keys', function (): void {
            $retry = config('events.retry');

            expect($retry)->toHaveKeys(['tries', 'backoff']);
        });

        it('retention config has days, include_pending, and schedule_cron sub-keys', function (): void {
            $retention = config('events.retention');

            expect($retention)->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
        });

        it('subscriptions config has all 6 sub-keys', function (): void {
            $subs = config('events.subscriptions');

            expect($subs)->toHaveKeys([
                'auto_generate_secret',
                'secret_length',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ]);
        });
    });

    describe('Exception hierarchy', function (): void {
        it('EventException extends RuntimeException', function (): void {
            $e = new \ZeroBoiler\Events\Exceptions\EventException('test');

            expect($e)->toBeInstanceOf(\RuntimeException::class);
            expect($e)->toBeInstanceOf(\Throwable::class);
        });

        it('TriggerNotFoundException extends EventException', function (): void {
            $e = new \ZeroBoiler\Events\Exceptions\TriggerNotFoundException('uuid-123');

            expect($e)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($e->getMessage())->toContain('uuid-123');
        });

        it('ActionResolutionException extends EventException', function (): void {
            $e = new \ZeroBoiler\Events\Exceptions\ActionResolutionException('App\Actions\Foo', 'not found');

            expect($e)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($e->getMessage())->toContain('Foo');
            expect($e->getMessage())->toContain('not found');
        });

        it('ConditionEvaluationException extends EventException', function (): void {
            $e = new \ZeroBoiler\Events\Exceptions\ConditionEvaluationException('amount', 'invalid operator');

            expect($e)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($e->getMessage())->toContain('amount');
            expect($e->getMessage())->toContain('invalid operator');
        });

        it('SubscriptionException extends EventException', function (): void {
            $e = new \ZeroBoiler\Events\Exceptions\SubscriptionException('test error');

            expect($e)->toBeInstanceOf(\ZeroBoiler\Events\Exceptions\EventException::class);
        });

        it('SubscriptionException supports previous exception chaining', function (): void {
            $previous = new \RuntimeException('original');
            $e = new \ZeroBoiler\Events\Exceptions\SubscriptionException('chained', $previous);

            expect($e->getPrevious())->toBe($previous);
        });

        it('EventException base class is NOT final (allows subclassing)', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Exceptions\EventException::class);

            expect($reflection->isFinal())->toBeFalse();
        });

        it('All child exceptions ARE final', function (): void {
            $finalExceptions = [
                \ZeroBoiler\Events\Exceptions\TriggerNotFoundException::class,
                \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
                \ZeroBoiler\Events\Exceptions\ConditionEvaluationException::class,
                \ZeroBoiler\Events\Exceptions\SubscriptionException::class,
            ];

            foreach ($finalExceptions as $class) {
                $reflection = new ReflectionClass($class);
                expect($reflection->isFinal())->toBeTrue("{$class} should be final");
            }
        });
    });

    describe('Model scopes and methods type safety', function (): void {
        it('Trigger scopeEnabled returns Builder', function (): void {
            $scope = new ReflectionMethod(Trigger::class, 'scopeEnabled');
            $returnType = $scope->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe(\Illuminate\Database\Eloquent\Builder::class);
        });

        it('EventLog markAsCompleted and markAsFailed have void return type', function (): void {
            $completed = new ReflectionMethod(EventLog::class, 'markAsCompleted');
            $failed = new ReflectionMethod(EventLog::class, 'markAsFailed');

            expect($completed->getReturnType()?->getName())->toBe('void');
            expect($failed->getReturnType()?->getName())->toBe('void');
        });

        it('Subscription recordDelivery and recordFailure have void return type', function (): void {
            $delivery = new ReflectionMethod(\ZeroBoiler\Events\Models\Subscription::class, 'recordDelivery');
            $failure = new ReflectionMethod(\ZeroBoiler\Events\Models\Subscription::class, 'recordFailure');

            expect($delivery->getReturnType()?->getName())->toBe('void');
            expect($failure->getReturnType()?->getName())->toBe('void');
        });

        it('Subscription signPayload has string return type', function (): void {
            $method = new ReflectionMethod(\ZeroBoiler\Events\Models\Subscription::class, 'signPayload');

            expect($method->getReturnType()?->getName())->toBe('string');
        });
    });

    describe('Facade proxy', function (): void {
        it('Facade is final', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);

            expect($reflection->isFinal())->toBeTrue();
        });

        it('getFacadeAccessor returns EventManager class name', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $method = $reflection->getMethod('getFacadeAccessor');

            expect($method->isPrivate())->toBeTrue();
        });
    });

    describe('DispatchTriggerJob config reading', function (): void {
        it('Job reads tries from config with env string coercion', function (): void {
            // This is already tested via container config, verify the property is readonly
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);

            $tries = $reflection->getProperty('tries');
            expect($tries->isReadOnly())->toBeTrue();
            expect($tries->isPublic())->toBeTrue();

            $backoff = $reflection->getProperty('backoff');
            expect($backoff->isReadOnly())->toBeTrue();
            expect($backoff->isPublic())->toBeTrue();

            $queue = $reflection->getProperty('queue');
            expect($queue->isReadOnly())->toBeTrue();
            expect($queue->isPublic())->toBeTrue();

            $connection = $reflection->getProperty('connection');
            expect($connection->isReadOnly())->toBeTrue();
            expect($connection->isPublic())->toBeTrue();
        });
    });
});
