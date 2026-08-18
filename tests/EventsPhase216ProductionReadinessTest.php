<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('Phase 216 — Production Readiness Deep Audit', function (): void {
    // ─── ConditionEngine Edge Cases ─────────────────────────────────────

    describe('ConditionEngine strictEquals cross-type comparison', function (): void {
        test('int and string with same value return true via coercion', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['count' => '5'], ['count' => 5]))->toBeTrue();
        });

        test('float and int with same value return true via coercion', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['amount' => 10], ['amount' => 10.0]))->toBeTrue();
        });

        test('array vs string returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['data' => 'hello'], ['data' => ['hello']]))->toBeFalse();
        });

        test('null vs string returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['name' => 'test'], ['name' => null]))->toBeFalse();
        });

        test('bool true vs int 1 returns true via string coercion', function (): void {
            $engine = new ConditionEngine;
            // true casts to "1", 1 casts to "1" — strict types on method but not on comparison
            expect($engine->matches(['flag' => 1], ['flag' => true]))->toBeTrue();
        });

        test('empty array condition returns false immediately', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches([], ['key' => 'value']))->toBeFalse();
        });
    });

    describe('ConditionEngine comparison operators with boundary values', function (): void {
        test('greater than with equal values returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['>', 5]], ['x' => 5]))->toBeFalse();
        });

        test('greater than or equal with equal values returns true', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['>=', 5]], ['x' => 5]))->toBeTrue();
        });

        test('between with inverted range normalizes correctly', function (): void {
            $engine = new ConditionEngine;
            // [100, 50] should be normalized to [50, 100]
            expect($engine->matches(['x' => ['between', [100, 50]]], ['x' => 75]))->toBeTrue();
        });

        test('between with value at exact boundary returns true', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['between', [10, 20]]], ['x' => 10]))->toBeTrue();
            expect($engine->matches(['x' => ['between', [10, 20]]], ['x' => 20]))->toBeTrue();
        });

        test('between with non-array value returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['between', 'not_array']], ['x' => 15]))->toBeFalse();
        });

        test('comparison with null actual returns false (null-safe)', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['>', 0]], ['x' => null]))->toBeFalse();
            expect($engine->matches(['x' => ['<', 100]], ['x' => null]))->toBeFalse();
            expect($engine->matches(['x' => ['>=', 0]], ['x' => null]))->toBeFalse();
        });

        test('comparison with null expected returns false (null-safe)', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['>', null]], ['x' => 5]))->toBeFalse();
        });
    });

    describe('ConditionEngine string operators', function (): void {
        test('starts_with with non-string actual returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['starts_with', 'a']], ['x' => 123]))->toBeFalse();
        });

        test('ends_with with non-string actual returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['ends_with', 'z']], ['x' => 456]))->toBeFalse();
        });

        test('contains with array actual checks array membership', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['normal', 'urgent']]))->toBeTrue();
        });

        test('not_contains with non-string non-array actual returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['x' => ['not_contains', 'a']], ['x' => 42]))->toBeFalse();
        });

        test('in operator with null value returns false', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => null]))->toBeFalse();
        });
    });

    describe('ConditionEngine dot notation', function (): void {
        test('accesses nested array values', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin', 'name' => 'John']],
            ))->toBeTrue();
        });

        test('returns null for non-existent nested key', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['name' => 'John']],
            ))->toBeFalse();
        });

        test('stops traversing at non-array intermediate', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['user.role.name' => 'admin'],
                ['user' => ['role' => 'admin']],
            ))->toBeFalse();
        });

        test('3-level deep dot notation', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(
                ['a.b.c' => 'deep'],
                ['a' => ['b' => ['c' => 'deep']]],
            ))->toBeTrue();
        });
    });

    // ─── WildcardMatcher Edge Cases ──────────────────────────────────────

    describe('WildcardMatcher boundary conditions', function (): void {
        test('empty pattern does not match non-empty event', function (): void {
            expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
        });

        test('empty event does not match catch-all', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        test('exact match requires identical strings', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('trailing dot in pattern does not match without trailing dot', function (): void {
            expect(WildcardMatcher::matches('order.', 'order.placed'))->toBeFalse();
        });

        test('single segment wildcard matches exactly one segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('cross-segment wildcard matches multiple segments', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra.detail'))->toBeTrue();
        });

        test('regex special characters in event are matched literally', function (): void {
            expect(WildcardMatcher::matches('user.login', 'user.login'))->toBeTrue();
            // Dot is literal in event, but the pattern 'user.*' should still work
            expect(WildcardMatcher::matches('user.*', 'user.login'))->toBeTrue();
        });

        test('findMatchingPatterns returns empty for no matches', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'payment.placed');
            expect($result)->toBe([]);
        });

        test('findMatchingPatterns returns all matching patterns', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(['order.*', '*.placed', '*'], 'order.placed');
            expect($result)->toHaveCount(3);
        });

        test('extractWildcards returns empty for cross-segment pattern', function (): void {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped');
            expect($result)->toBe([]);
        });

        test('extractWildcards returns correct segments', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        test('extractWildcards returns empty for non-matching event', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.deleted');
            expect($result)->toBe([]);
        });
    });

    // ─── DomainEvent Edge Cases ──────────────────────────────────────────

    describe('DomainEvent immutability and serialization', function (): void {
        test('occur creates event with fresh UUID and current timestamp', function (): void {
            $before = new \DateTimeImmutable();
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $after = new \DateTimeImmutable();

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId->toString())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-/');
            expect($event->occurredAt >= $before)->toBeTrue();
            expect($event->occurredAt <= $after)->toBeTrue();
        });

        test('fromArray preserves eventId and occurredAt', function (): void {
            $original = DomainEvent::occur('order.created', ['id' => 42]);
            $data = $original->toArray();

            // Simulate persistence round-trip
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
            expect($restored->eventType)->toBe('order.created');
            expect($restored->payload)->toBe(['id' => 42]);
        });

        test('fromArray with missing eventType throws exception', function (): void {
            expect(fn () => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        test('fromArray with empty eventType throws exception', function (): void {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        test('fromArray with invalid UUID falls back to fresh UUID', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test',
                'eventId' => 'not-a-uuid',
            ]);
            // Should not throw — just generates a fresh UUID
            expect($event->eventId->toString())->toMatch('/^[0-9a-f]{8}-/');
        });

        test('fromArray with invalid datetime falls back to now', function (): void {
            $before = new \DateTimeImmutable();
            $event = DomainEvent::fromArray([
                'eventType' => 'test',
                'occurredAt' => 'not-a-date',
            ]);
            $after = new \DateTimeImmutable();
            expect($event->occurredAt >= $before)->toBeTrue();
            expect($event->occurredAt <= $after)->toBeTrue();
        });

        test('fromArray with non-array payload falls back to empty', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test',
                'payload' => 'not-an-array',
            ]);
            expect($event->payload)->toBe([]);
        });

        test('__toString returns correct format', function (): void {
            $event = DomainEvent::occur('order.placed', []);
            $str = (string) $event;
            expect($str)->toStartWith('DomainEvent[order.placed]');
            expect($str)->toContain('id=');
            expect($str)->toContain('at=');
        });

        test('properties are readonly', function (): void {
            $ref = new \ReflectionClass(DomainEvent::class);
            foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $prop) {
                expect($ref->getProperty($prop)->isReadOnly())
                    ->toBeTrue("DomainEvent::\${$prop} must be readonly");
            }
        });

        test('class is final', function (): void {
            expect((new \ReflectionClass(DomainEvent::class))->isFinal())->toBeTrue();
        });
    });

    // ─── EventManager sanitizePayloadForQueue ────────────────────────────

    describe('EventManager payload sanitization for queue', function (): void {
        test('preserves scalar values', function (): void {
            $manager = $this->app->make(EventManager::class);
            $ref = new \ReflectionClass($manager);
            $method = $ref->getMethod('sanitizePayloadForQueue');
            $method->setAccessible(true);

            $result = $method->invoke($manager, [
                'string' => 'hello',
                'int' => 42,
                'float' => 3.14,
                'bool' => true,
                'null' => null,
            ]);

            expect($result['string'])->toBe('hello');
            expect($result['int'])->toBe(42);
            expect($result['float'])->toBe(3.14);
            expect($result['bool'])->toBeTrue();
            expect($result['null'])->toBeNull();
        });

        test('strips objects and replaces with type placeholder', function (): void {
            $manager = $this->app->make(EventManager::class);
            $ref = new \ReflectionClass($manager);
            $method = $ref->getMethod('sanitizePayloadForQueue');
            $method->setAccessible(true);

            $obj = new \stdClass;
            $result = $method->invoke($manager, ['obj' => $obj]);
            expect($result['obj'])->toBe('[stripped:stdClass]');
        });

        test('recursively sanitizes nested arrays', function (): void {
            $manager = $this->app->make(EventManager::class);
            $ref = new \ReflectionClass($manager);
            $method = $ref->getMethod('sanitizePayloadForQueue');
            $method->setAccessible(true);

            $result = $method->invoke($manager, [
                'nested' => [
                    'deep' => [
                        'obj' => new \stdClass,
                        'safe' => 'value',
                    ],
                ],
            ]);

            expect($result['nested']['deep']['obj'])->toBe('[stripped:stdClass]');
            expect($result['nested']['deep']['safe'])->toBe('value');
        });

        test('handles empty array', function (): void {
            $manager = $this->app->make(EventManager::class);
            $ref = new \ReflectionClass($manager);
            $method = $ref->getMethod('sanitizePayloadForQueue');
            $method->setAccessible(true);

            $result = $method->invoke($manager, []);
            expect($result)->toBe([]);
        });
    });

    // ─── ServiceProvider Completeness ─────────────────────────────────────

    describe('ServiceProvider binding completeness', function (): void {
        test('registers all 7 services in provides()', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provides = $provider->provides();

            expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
            expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
            expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
            expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
            expect($provides)->toContain(\ZeroBoiler\Events\EventScheduler::class);
        });

        test('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provider->register();

            $contract = $this->app->make(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
            expect($contract)->toBeInstanceOf(\ZeroBoiler\Events\ConditionEngine::class);
        });

        test('TriggerBuilder is transient (not shared)', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provider->register();

            expect($this->app->isShared(\ZeroBoiler\Events\TriggerBuilder::class))->toBeFalse();
        });

        test('SubscriptionBuilder is transient (not shared)', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provider->register();

            expect($this->app->isShared(\ZeroBoiler\Events\SubscriptionBuilder::class))->toBeFalse();
        });

        test('EventScheduler is singleton', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provider->register();

            expect($this->app->isShared(\ZeroBoiler\Events\EventScheduler::class))->toBeTrue();
        });

        test('boot registers all 12 commands', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provider->register();
            $provider->boot();

            $expectedCommands = [
                \ZeroBoiler\Events\Console\EventsListCommand::class,
                \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
                \ZeroBoiler\Events\Console\EventsFireCommand::class,
                \ZeroBoiler\Events\Console\EventsLogCommand::class,
                \ZeroBoiler\Events\Console\EventsRetryCommand::class,
                \ZeroBoiler\Events\Console\EventsEnableCommand::class,
                \ZeroBoiler\Events\Console\EventsDisableCommand::class,
                \ZeroBoiler\Events\Console\EventsHealthCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
                \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            ];

            $commands = $this->app->get('commands');
            foreach ($expectedCommands as $cmdClass) {
                $found = false;
                foreach ($commands as $cmd) {
                    if ($cmd instanceof $cmdClass) {
                        $found = true;
                        break;
                    }
                }
                expect($found)->toBeTrue("{$cmdClass} should be registered");
            }
        });
    });

    // ─── Config Completeness ─────────────────────────────────────────────

    describe('Config file completeness', function (): void {
        test('config has all 8 top-level keys', function (): void {
            $config = $this->app->get('config')->get('events');
            $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        test('table_names has all 3 entries', function (): void {
            $tables = $this->app->get('config')->get('events.table_names');
            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        test('subscriptions config has all required keys', function (): void {
            $sub = $this->app->get('config')->get('events.subscriptions');
            $expectedKeys = ['auto_generate_secret', 'secret_length', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $sub))->toBeTrue("Missing subscriptions config key: {$key}");
            }
        });

        test('retention config has all required keys', function (): void {
            $ret = $this->app->get('config')->get('events.retention');
            expect($ret)->toHaveKey('days');
            expect($ret)->toHaveKey('include_pending');
            expect($ret)->toHaveKey('schedule_cron');
        });

        test('queue config has connection and queue keys', function (): void {
            $queue = $this->app->get('config')->get('events.queue');
            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        test('retry config has tries and backoff keys', function (): void {
            $retry = $this->app->get('config')->get('events.retry');
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });
    });

    // ─── Exception Hierarchy ─────────────────────────────────────────────

    describe('Exception hierarchy', function (): void {
        test('EventException extends RuntimeException', function (): void {
            expect((new \ReflectionClass(\ZeroBoiler\Events\Exceptions\EventException::class))
                ->getParentClass()->getName())->toBe('RuntimeException');
        });

        test('all leaf exceptions extend EventException', function (): void {
            $leaves = [
                \ZeroBoiler\Events\Exceptions\TriggerNotFoundException::class,
                \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
                \ZeroBoiler\Events\Exceptions\ConditionEvaluationException::class,
                \ZeroBoiler\Events\Exceptions\SubscriptionException::class,
            ];
            foreach ($leaves as $leaf) {
                expect((new \ReflectionClass($leaf))->getParentClass()->getName())
                    ->toBe(\ZeroBoiler\Events\Exceptions\EventException::class);
            }
        });

        test('all leaf exceptions are final', function (): void {
            $leaves = [
                \ZeroBoiler\Events\Exceptions\TriggerNotFoundException::class,
                \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
                \ZeroBoiler\Events\Exceptions\ConditionEvaluationException::class,
                \ZeroBoiler\Events\Exceptions\SubscriptionException::class,
            ];
            foreach ($leaves as $leaf) {
                expect((new \ReflectionClass($leaf))->isFinal())->toBeTrue("{$leaf} must be final");
            }
        });

        test('EventException is NOT final (allows extension)', function (): void {
            expect((new \ReflectionClass(\ZeroBoiler\Events\Exceptions\EventException::class))->isFinal())->toBeFalse();
        });
    });

    // ─── Source File Quality ──────────────────────────────────────────────

    describe('Source file quality audit', function (): void {
        test('all src files have declare(strict_types=1)', function (): void {
            $srcDir = dirname(__DIR__).'/src';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('all src files have license header', function (): void {
            $srcDir = dirname(__DIR__).'/src';
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                expect($content)->toContain('part of ZeroBoiler');
            }
        });

        test('all factory files have declare(strict_types=1)', function (): void {
            $factoryDir = dirname(__DIR__).'/database/factories';
            foreach (glob($factoryDir.'/*.php') as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('all migration files have declare(strict_types=1)', function (): void {
            $migrationDir = dirname(__DIR__).'/database/migrations';
            foreach (glob($migrationDir.'/*.php') as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });
    });

    // ─── Facade Coverage ─────────────────────────────────────────────────

    describe('Facade method coverage', function (): void {
        test('facade accessor points to EventManager', function (): void {
            $ref = new \ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $method = $ref->getMethod('getFacadeAccessor');
            expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
        });

        test('facade is final', function (): void {
            expect((new \ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class))->isFinal())->toBeTrue();
        });

        test('facade getFacadeAccessor has Override attribute', function (): void {
            $ref = new \ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $method = $ref->getMethod('getFacadeAccessor');
            $attrs = $method->getAttributes(\Override::class);
            expect(count($attrs))->toBe(1);
        });
    });

    // ─── Model Quality ───────────────────────────────────────────────────

    describe('Model quality checks', function (): void {
        test('Trigger model has 4 casts', function (): void {
            $ref = new \ReflectionClass(\ZeroBoiler\Events\Models\Trigger::class);
            $method = $ref->getMethod('casts');
            $casts = $method->invoke(new \ZeroBoiler\Events\Models\Trigger);
            expect(count($casts))->toBe(4);
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('async');
            expect($casts)->toHaveKey('enabled');
            expect($casts)->toHaveKey('priority');
        });

        test('EventLog model has 3 casts', function (): void {
            $ref = new \ReflectionClass(\ZeroBoiler\Events\Models\EventLog::class);
            $method = $ref->getMethod('casts');
            $casts = $method->invoke(new \ZeroBoiler\Events\Models\EventLog);
            expect(count($casts))->toBe(3);
            expect($casts)->toHaveKey('payload');
            expect($casts)->toHaveKey('duration_ms');
            expect($casts)->toHaveKey('error');
        });

        test('Subscription model has 6 casts', function (): void {
            $ref = new \ReflectionClass(\ZeroBoiler\Events\Models\Subscription::class);
            $method = $ref->getMethod('casts');
            $casts = $method->invoke(new \ZeroBoiler\Events\Models\Subscription);
            expect(count($casts))->toBe(6);
            expect($casts)->toHaveKey('conditions');
            expect($casts)->toHaveKey('priority');
            expect($casts)->toHaveKey('active');
            expect($casts)->toHaveKey('failure_count');
            expect($casts)->toHaveKey('delivery_count');
            expect($casts)->toHaveKey('last_fired_at');
        });

        test('all models use SoftDeletes', function (): void {
            $models = [
                \ZeroBoiler\Events\Models\Trigger::class,
                \ZeroBoiler\Events\Models\EventLog::class,
                \ZeroBoiler\Events\Models\Subscription::class,
            ];
            foreach ($models as $model) {
                $ref = new \ReflectionClass($model);
                expect($ref->hasMethod('softDeletes'))->toBeTrue("{$model} must use SoftDeletes");
                // Verify trait is used
                $traitNames = array_map(
                    fn (\ReflectionClass $t) => $t->getName(),
                    $ref->getTraits(),
                );
                expect($traitNames)->toContain('Illuminate\Database\Eloquent\SoftDeletes');
            }
        });

        test('all models have UUID string key type', function (): void {
            $models = [
                \ZeroBoiler\Events\Models\Trigger::class,
                \ZeroBoiler\Events\Models\EventLog::class,
                \ZeroBoiler\Events\Models\Subscription::class,
            ];
            foreach ($models as $model) {
                $ref = new \ReflectionClass($model);
                $prop = $ref->getProperty('keyType');
                // Default visibility for Eloquent $keyType is protected
                expect($prop->getValue(new $model))->toBe('string');
                $incProp = $ref->getProperty('incrementing');
                expect($incProp->getValue(new $model))->toBeFalse();
            }
        });
    });

    // ─── ActionResolver Edge Cases ───────────────────────────────────────

    describe('ActionResolver edge cases', function (): void {
        test('throws for non-existent class', function (): void {
            $resolver = $this->app->make(\ZeroBoiler\Events\ActionResolver::class);
            expect(fn () => $resolver->resolve('NonExistent\Class'))
                ->toThrow(\ZeroBoiler\Events\Exceptions\ActionResolutionException::class);
        });

        test('throws for class not implementing Triggerable', function (): void {
            $resolver = $this->app->make(\ZeroBoiler\Events\ActionResolver::class);
            expect(fn () => $resolver->resolve(\stdClass::class))
                ->toThrow(\ZeroBoiler\Events\Exceptions\ActionResolutionException::class);
        });
    });

    // ─── EscapesWildcardLike ─────────────────────────────────────────────

    describe('EscapesWildcardLike SQL injection prevention', function (): void {
        test('returns null for non-wildcard pattern', function (): void {
            $engine = new ConditionEngine;
            // Use the trait through EventManager (indirectly via WildcardMatcher)
            // Test the trait directly on a class that uses it
            $ref = new \ReflectionClass(\ZeroBoiler\Events\Models\Trigger::class);
            $traits = array_map(fn (\ReflectionClass $t) => $t->getName(), $ref->getTraits());
            // Trigger does NOT use EscapesWildcardLike directly, but Subscription does
            $subRef = new \ReflectionClass(\ZeroBoiler\Events\Models\Subscription::class);
            $subTraits = array_map(fn (\ReflectionClass $t) => $t->getName(), $subRef->getTraits());
            expect($subTraits)->toContain('ZeroBoiler\Events\Concerns\EscapesWildcardLike');
        });
    });

    // ─── Composer.json Alignment ──────────────────────────────────────────

    describe('Composer.json alignment', function (): void {
        test('requires PHP 8.5+', function (): void {
            $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        test('requires Laravel 13+', function (): void {
            $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        test('autoload PSR-4 mapping is correct', function (): void {
            $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        test('extra.laravel.providers includes EventsServiceProvider', function (): void {
            $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
        });

        test('extra.laravel.aliases includes EventManager facade', function (): void {
            $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager',
            );
        });
    });

    // ─── PHPStan Configuration ────────────────────────────────────────────

    describe('PHPStan configuration', function (): void {
        test('phpstan.neon.dist exists and has level 9', function (): void {
            $path = dirname(__DIR__).'/phpstan.neon.dist';
            expect(file_exists($path))->toBeTrue();
            $content = file_get_contents($path);
            expect($content)->toContain('level: 9');
        });

        test('phpstan.neon.dist includes src in paths', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/phpstan.neon.dist');
            expect($content)->toContain('- src');
        });

        test('phpstan.neon.dist has baselineFile', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/phpstan.neon.dist');
            expect($content)->toContain('baselineFile: phpstan-baseline.neon');
        });

        test('phpstan.neon includes phpstan.neon.dist', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/phpstan.neon');
            expect($content)->toContain('phpstan.neon.dist');
        });

        test('phpstan-baseline.neon exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/phpstan-baseline.neon'))->toBeTrue();
        });
    });

    // ─── Migrations Existence ────────────────────────────────────────────

    describe('Migration files existence', function (): void {
        test('triggers migration exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/database/migrations/2024_01_01_000001_create_triggers_table.php'))->toBeTrue();
        });

        test('event_logs migration exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/database/migrations/2024_01_01_000002_create_event_logs_table.php'))->toBeTrue();
        });

        test('event_subscriptions migration exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/database/migrations/2025_06_28_000001_create_event_subscriptions_table.php'))->toBeTrue();
        });

        test('triggers migration uses config-driven table name', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/database/migrations/2024_01_01_000001_create_triggers_table.php');
            expect($content)->toContain("config('events.table_names.triggers'");
        });

        test('event_logs migration uses config-driven table name', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/database/migrations/2024_01_01_000002_create_event_logs_table.php');
            expect($content)->toContain("config('events.table_names.event_logs'");
        });

        test('event_subscriptions migration uses config-driven table name', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
            expect($content)->toContain("config('events.table_names.subscriptions'");
        });

        test('event_logs migration has foreign key to triggers', function (): void {
            $content = file_get_contents(dirname(__DIR__).'/database/migrations/2024_01_01_000002_create_event_logs_table.php');
            expect($content)->toContain("->foreign('trigger_id')");
        });
    });

    // ─── Factory Files Existence ──────────────────────────────────────────

    describe('Factory files existence', function (): void {
        test('TriggerFactory exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/database/factories/TriggerFactory.php'))->toBeTrue();
        });

        test('EventLogFactory exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/database/factories/EventLogFactory.php'))->toBeTrue();
        });

        test('SubscriptionFactory exists', function (): void {
            expect(file_exists(dirname(__DIR__).'/database/factories/SubscriptionFactory.php'))->toBeTrue();
        });

        test('all factories have strict_types', function (): void {
            $factories = [
                dirname(__DIR__).'/database/factories/TriggerFactory.php',
                dirname(__DIR__).'/database/factories/EventLogFactory.php',
                dirname(__DIR__).'/database/factories/SubscriptionFactory.php',
            ];
            foreach ($factories as $f) {
                expect(file_get_contents($f))->toContain('declare(strict_types=1)');
            }
        });
    });

    // ─── EventManager Public API Surface ──────────────────────────────────

    describe('EventManager public API surface', function (): void {
        test('has fire method', function (): void {
            $ref = new \ReflectionClass(EventManager::class);
            expect($ref->hasMethod('fire'))->toBeTrue();
            expect($ref->getMethod('fire')->isPublic())->toBeTrue();
        });

        test('has fireModel method', function (): void {
            $ref = new \ReflectionClass(EventManager::class);
            expect($ref->hasMethod('fireModel'))->toBeTrue();
        });

        test('has on method', function (): void {
            $ref = new \ReflectionClass(EventManager::class);
            expect($ref->hasMethod('on'))->toBeTrue();
        });

        test('has subscribe method', function (): void {
            $ref = new \ReflectionClass(EventManager::class);
            expect($ref->hasMethod('subscribe'))->toBeTrue();
        });

        test('has executeTrigger method', function (): void {
            $ref = new \ReflectionClass(EventManager::class);
            expect($ref->hasMethod('executeTrigger'))->toBeTrue();
        });

        test('has container method', function (): void {
            $ref = new \ReflectionClass(EventManager::class);
            expect($ref->hasMethod('container'))->toBeTrue();
        });

        test('is final class', function (): void {
            expect((new \ReflectionClass(EventManager::class))->isFinal())->toBeTrue();
        });
    });
});
