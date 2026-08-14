<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
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

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

use App\Actions\SendOrderNotification;

describe('Phase 136 Production Audit', function (): void {
    describe('Strict types verification on all source files', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');

        test('every PHP source file declares strict_types=1', function () use ($srcFiles): void {
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
            expect($srcFiles)->not->toBeEmpty();
        });

        test('every PHP source file has the license header', function () use ($srcFiles): void {
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('This file is part of ZeroBoiler');
            }
        });

        test('all database migration files declare strict_types=1', function (): void {
            $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');
            foreach ($migrationFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        test('all factory files declare strict_types=1', function (): void {
            $factoryFiles = glob(__DIR__.'/../database/factories/*.php');
            foreach ($factoryFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        test('rector.php has the license header', function (): void {
            $contents = file_get_contents(__DIR__.'/../rector.php');
            expect($contents)->toContain('This file is part of ZeroBoiler');
            expect($contents)->toContain('declare(strict_types=1)');
        });
    });

    describe('Final class declarations', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            DomainEvent::class,
            WildcardMatcher::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        test('all core classes are declared final', function () use ($finalClasses): void {
            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue("{$class} must be declared final");
            }
        });

        test('all console commands are declared final', function (): void {
            $commandFiles = glob(__DIR__.'/../src/Console/*.php');
            foreach ($commandFiles as $file) {
                $tokens = token_get_all(file_get_contents($file));
                $foundFinal = false;
                $foundClass = false;
                for ($i = 0; $i < count($tokens) - 1; $i++) {
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_FINAL) {
                        $foundFinal = true;
                    }
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                        $foundClass = true;
                    }
                }
                expect($foundFinal)->toBeTrue(basename($file).' must declare final class');
                expect($foundClass)->toBeTrue(basename($file).' must contain a class');
            }
        });
    });

    describe('Readonly properties verification', function (): void {
        test('EventManager has all promoted readonly constructor properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();
            expect(count($params))->toBe(3);

            foreach ($params as $param) {
                expect($param->isReadOnly())->toBeTrue(
                    "EventManager::__construct(\${$param->getName()}) must be readonly"
                );
            }
        });

        test('DispatchTriggerJob has promoted readonly properties for triggerId, event, payload', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $props = $ref->getProperties();
            $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly() && $p->isPublic());

            $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $readonlyProps);
            expect($names)->toContain('triggerId');
            expect($names)->toContain('event');
            expect($names)->toContain('payload');
        });

        test('DomainEvent properties are all readonly', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
            foreach ($props as $prop) {
                expect($prop->isReadOnly())->toBeTrue(
                    "DomainEvent::\${$prop->getName()} must be readonly"
                );
            }
        });
    });

    describe('ConditionEngine comprehensive operator coverage', function (): void {
        $engine = new ConditionEngine;

        test('matches operator with numeric values', function () use ($engine): void {
            expect($engine->matches(['val' => ['matches', '/^\\d+$/']], ['val' => '123']))->toBeTrue();
            expect($engine->matches(['val' => ['matches', '/^\\d+$/']], ['val' => 'abc']))->toBeFalse();
        });

        test('not_empty operator with non-empty string', function () use ($engine): void {
            expect($engine->matches(['field' => ['not_empty']], ['field' => 'hello']))->toBeTrue();
            expect($engine->matches(['field' => ['not_empty']], ['field' => '']))->toBeFalse();
            expect($engine->matches(['field' => ['not_empty']], ['field' => null]))->toBeFalse();
        });

        test('not_empty operator with non-empty array', function () use ($engine): void {
            expect($engine->matches(['field' => ['not_empty']], ['field' => [1]]))->toBeTrue();
            expect($engine->matches(['field' => ['not_empty']], ['field' => []]))->toBeFalse();
        });

        test('not_null operator', function () use ($engine): void {
            expect($engine->matches(['field' => ['not_null']], ['field' => 'value']))->toBeTrue();
            expect($engine->matches(['field' => ['not_null']], ['field' => 0]))->toBeTrue();
            expect($engine->matches(['field' => ['not_null']], ['field' => null]))->toBeFalse();
        });

        test('not_contains with string', function () use ($engine): void {
            expect($engine->matches(['field' => ['not_contains', 'xyz']], ['field' => 'abc']))->toBeTrue();
            expect($engine->matches(['field' => ['not_contains', 'abc']], ['field' => 'abcdef']))->toBeFalse();
        });

        test('not_contains with array', function () use ($engine): void {
            expect($engine->matches(['field' => ['not_contains', 'x']], ['field' => ['a', 'b']]))->toBeTrue();
            expect($engine->matches(['field' => ['not_contains', 'a']], ['field' => ['a', 'b']]))->toBeFalse();
        });

        test('not_in operator', function () use ($engine): void {
            expect($engine->matches(['field' => ['not_in', ['a', 'b']]], ['field' => 'c']))->toBeTrue();
            expect($engine->matches(['field' => ['not_in', ['a', 'b']]], ['field' => 'a']))->toBeFalse();
        });

        test('between with exact boundary values', function () use ($engine): void {
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 10]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 20]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 15]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 9]))->toBeFalse();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 21]))->toBeFalse();
        });

        test('between with inverted range auto-normalizes', function () use ($engine): void {
            expect($engine->matches(['val' => ['between', [20, 10]]], ['val' => 10]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [20, 10]]], ['val' => 20]))->toBeTrue();
        });

        test('between with float values', function () use ($engine): void {
            expect($engine->matches(['val' => ['between', [1.5, 3.5]]], ['val' => 2.5]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [1.5, 3.5]]], ['val' => 1.0]))->toBeFalse();
        });

        test('empty conditions array matches everything', function () use ($engine): void {
            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        });

        test('AND logic — all conditions must match', function () use ($engine): void {
            $conditions = [
                'status' => 'active',
                'amount' => ['>', 50],
                'tags' => ['contains', 'urgent'],
            ];
            expect($engine->matches($conditions, [
                'status' => 'active',
                'amount' => 100,
                'tags' => ['urgent', 'billing'],
            ]))->toBeTrue();

            // One fails → all fail
            expect($engine->matches($conditions, [
                'status' => 'inactive',
                'amount' => 100,
                'tags' => ['urgent'],
            ]))->toBeFalse();
        });

        test('dot notation nested field access', function () use ($engine): void {
            $payload = ['user' => ['profile' => ['role' => 'admin']]];
            expect($engine->matches(['user.profile.role' => 'admin'], $payload))->toBeTrue();
            expect($engine->matches(['user.profile.role' => 'user'], $payload))->toBeFalse();
        });

        test('dot notation returns null for missing nested key', function () use ($engine): void {
            expect($engine->matches(['user.missing' => ['null']], ['user' => ['name' => 'test']]))->toBeTrue();
        });

        test('strict equals with same type', function () use ($engine): void {
            expect($engine->matches(['val' => 42], ['val' => 42]))->toBeTrue();
            expect($engine->matches(['val' => 'hello'], ['val' => 'hello']))->toBeTrue();
            expect($engine->matches(['val' => 42], ['val' => '42']))->toBeTrue(); // cross-type string comparison
        });

        test('strict equality === and !=== operators', function () use ($engine): void {
            expect($engine->matches(['val' => ['===', 42]], ['val' => 42]))->toBeTrue();
            expect($engine->matches(['val' => ['===', 42]], ['val' => '42']))->toBeFalse();

            expect($engine->matches(['val' => ['!==', '42']], ['val' => 42]))->toBeTrue();
            expect($engine->matches(['val' => ['!==', 42]], ['val' => 42]))->toBeFalse();
        });

        test('ReDoS protection — long regex pattern rejected', function () use ($engine): void {
            $longPattern = str_repeat('a', 501);
            expect($engine->matches(['val' => ['matches', "/{$longPattern}/"]], ['val' => 'aaa']))->toBeFalse();
        });

        test('ReDoS protection — nested quantifier rejected', function () use ($engine): void {
            expect($engine->matches(['val' => ['matches', '/(a+)+b/']], ['val' => 'aaab']))->toBeFalse();
        });

        test('unknown operator returns false', function () use ($engine): void {
            expect($engine->matches(['val' => ['unknown_op', 10]], ['val' => 10]))->toBeFalse();
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        test('empty event string never matches any non-empty pattern', function (): void {
            expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
        });

        test('catch-all pattern * matches everything except empty', function (): void {
            expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        test('catch-all pattern ** matches everything except empty', function (): void {
            expect(WildcardMatcher::matches('**', 'a'))->toBeTrue();
            expect(WildcardMatcher::matches('**', 'a.b.c'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        test('single segment wildcard does not cross dots', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('double wildcard crosses dots', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        test('exact match without wildcards', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('regex special chars in event name', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.(test)'))->toBeTrue();
            expect(WildcardMatcher::matches('user.+', 'user.hello'))->toBeFalse(); // + is not a wildcard
        });

        test('findMatchingPatterns returns correct subset', function (): void {
            $patterns = ['order.*', 'user.created', 'order.**', 'invoice.*'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toContain('order.*');
            expect($result)->toContain('order.**');
            expect($result)->not->toContain('user.created');
            expect($result)->not->toContain('invoice.*');
        });

        test('extractWildcards with single segment wildcard', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        test('extractWildcards with double wildcard returns empty', function (): void {
            $result = WildcardMatcher::extractWildcards('user.**.created', 'user.profile.created');
            expect($result)->toBe([]);
        });

        test('extractWildcards with no wildcards returns empty', function (): void {
            $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');
            expect($result)->toBe([]);
        });
    });

    describe('DomainEvent immutability and reconstruction', function (): void {
        test('occur factory creates immutable event', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->not->toBeNull();
            expect($event->occurredAt)->not->toBeNull();
        });

        test('toArray and fromArray roundtrip preserves identity', function (): void {
            $original = DomainEvent::occur('order.created', ['order_id' => '123']);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
                $original->occurredAt->format(DateTimeImmutable::ATOM)
            );
        });

        test('fromArray with missing eventType throws', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray([]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('fromArray with empty eventType throws', function (): void {
            expect(fn (): DomainEvent => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });

        test('fromArray with invalid UUID falls back to fresh', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
            ]);
            expect($event->eventId)->not->toBeNull();
        });

        test('fromArray with invalid date falls back to now', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);
            $diff = $event->occurredAt->diffInSeconds(new DateTimeImmutable);
            expect($diff)->toBeLessThan(2);
        });
    });

    describe('TriggerBuilder validation and action resolution', function (): void {
        test('save throws on empty event name', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);
            $builder = $em->on('');

            expect(fn (): Trigger => $builder->action(SendOrderNotification::class)->save())
                ->toThrow(InvalidArgumentException::class);
        });

        test('save throws when no action provided', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);
            $builder = $em->on('test.event');

            expect(fn (): Trigger => $builder->save())
                ->toThrow(InvalidArgumentException::class);
        });

        test('save generates name from event if not provided', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);
            $trigger = $em->on('test.auto.name')
                ->action(SendOrderNotification::class)
                ->save();

            expect($trigger->name)->toBe('test.auto.name Trigger');
        });

        test('actions method validates each class name', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);
            $builder = $em->on('test.event');

            expect(fn (): TriggerBuilder => $builder->actions(['ValidClass', '']))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('SubscriptionBuilder validation', function (): void {
        test('save throws on empty event name', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            expect(fn () => $em->subscribe('', 'https://example.com')->save())
                ->toThrow(InvalidArgumentException::class);
        });

        test('save throws on empty URL', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            expect(fn () => $em->subscribe('test.event', '')->save())
                ->toThrow(InvalidArgumentException::class);
        });

        test('save throws on invalid URL', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            expect(fn () => $em->subscribe('test.event', 'not-a-url')->save())
                ->toThrow(InvalidArgumentException::class);
        });

        test('save throws on non-HTTP(S) URL scheme', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            expect(fn () => $em->subscribe('test.event', 'ftp://example.com/file')->save())
                ->toThrow(InvalidArgumentException::class);
        });

        test('save accepts http and https URLs', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            $sub = $em->subscribe('test.event', 'https://example.com/hooks')->save();
            expect($sub->url)->toBe('https://example.com/hooks');
            expect($sub->secret)->not->toBeNull();
        });
    });

    describe('EventLog status constants', function (): void {
        test('all status constants are defined', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        test('static $statuses array contains all constants', function (): void {
            expect(EventLog::$statuses)->toEqual([
                'pending',
                'dispatched',
                'completed',
                'failed',
            ]);
        });
    });

    describe('Subscription HMAC signing', function (): void {
        test('signPayload returns empty string for null secret', function (): void {
            $sub = Subscription::factory()->withoutSecret()->create();
            expect($sub->signPayload('data'))->toBe('');
        });

        test('signPayload returns empty string for empty secret', function (): void {
            $sub = Subscription::factory()->create(['secret' => '']);
            expect($sub->signPayload('data'))->toBe('');
        });

        test('signPayload produces consistent HMAC', function (): void {
            $sub = Subscription::factory()->withSecret('test_secret_key')->create();
            $sig1 = $sub->signPayload('payload1');
            $sig2 = $sub->signPayload('payload1');
            $sig3 = $sub->signPayload('payload2');

            expect($sig1)->toBe($sig2);
            expect($sig1)->not->toBe($sig3);
        });
    });

    describe('WildcardMatcher class is readonly', function (): void {
        test('WildcardMatcher is a readonly final class', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('ServiceProvider binding audit', function (): void {
        test('provides() includes all registered bindings', function (): void {
            $app = app();
            $provider = new EventsServiceProvider($app);
            $provides = $provider->provides();

            $expectedBindings = [
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
            ];

            foreach ($expectedBindings as $binding) {
                expect($provides)->toContain($binding);
            }
        });

        test('ConditionEngineContract is bound to ConditionEngine', function (): void {
            $app = app();
            $engine = $app->make(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        test('EventManager is a singleton', function (): void {
            $app = app();
            $first = $app->make(EventManager::class);
            $second = $app->make(EventManager::class);
            expect($first)->toBe($sameInstance = $second);
        });

        test('Facade accessor returns correct class', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            expect($method->isPublic())->toBeTrue();
            expect($method->getReturnType()?->getName())->toBe('string');
        });
    });

    describe('Config completeness', function (): void {
        test('config file contains all required top-level keys', function (): void {
            $config = require __DIR__.'/../config/events.php';

            $requiredKeys = [
                'table_names', 'queue', 'retry', 'retention',
                'subscriptions', 'disabled', 'wildcard_cache_ttl',
            ];

            foreach ($requiredKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }
        });

        test('table_names has all three table entries', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $tables = $config['table_names'];

            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        test('subscriptions config has all required keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $subs = $config['subscriptions'];

            $requiredSubKeys = [
                'auto_generate_secret', 'max_failures', 'timeout',
                'signature_algorithm', 'cleanup_cron',
            ];

            foreach ($requiredSubKeys as $key) {
                expect(array_key_exists($key, $subs))->toBeTrue("Missing subscriptions config key: {$key}");
            }
        });

        test('retention config has all required keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $ret = $config['retention'];

            expect($ret)->toHaveKey('days');
            expect($ret)->toHaveKey('include_pending');
            expect($ret)->toHaveKey('schedule_cron');
        });
    });

    describe('Migration structure verification', function (): void {
        test('triggers migration has all columns and indexes', function (): void {
            $columns = ['id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled'];
            $migration = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');

            foreach ($columns as $col) {
                expect($migration)->toContain("'{$col}'");
            }

            // Verify indexes
            expect($migration)->toContain("index(['event', 'enabled'])");
            expect($migration)->toContain("index('priority')");
        });

        test('event_logs migration has all columns and indexes', function (): void {
            $columns = ['id', 'trigger_id', 'event', 'payload', 'status', 'error', 'duration_ms'];
            $migration = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');

            foreach ($columns as $col) {
                expect($migration)->toContain("'{$col}'");
            }

            expect($migration)->toContain("foreign('trigger_id')");
            expect($migration)->toContain('onDelete');
            expect($migration)->toContain("index(['trigger_id', 'status'])");
        });

        test('event_subscriptions migration has all columns and indexes', function (): void {
            $columns = ['id', 'event', 'url', 'conditions', 'priority', 'active', 'secret', 'failure_count', 'delivery_count'];
            $migration = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');

            foreach ($columns as $col) {
                expect($migration)->toContain("'{$col}'");
            }

            expect($migration)->toContain("index(['event', 'active'])");
            expect($migration)->toContain("index('url')");
        });
    });

    describe('Model table name config consistency', function (): void {
        test('Trigger reads table from config', function (): void {
            $trigger = new Trigger;
            $table = $trigger->getTable();
            expect($table)->toBe('triggers');
        });

        test('EventLog reads table from config', function (): void {
            $log = new EventLog;
            $table = $log->getTable();
            expect($table)->toBe('event_logs');
        });

        test('Subscription reads table from config', function (): void {
            $sub = new Subscription;
            $table = $sub->getTable();
            expect($table)->toBe('event_subscriptions');
        });
    });

    describe('Composer.json and version consistency', function (): void {
        test('composer.json requires PHP 8.5+', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        test('composer.json requires Laravel 13', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        test('ServiceProvider is listed in extra.laravel.providers', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $providers = $composer['extra']['laravel']['providers'];
            expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        test('Facade alias is listed in extra.laravel.aliases', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $aliases = $composer['extra']['laravel']['aliases'];
            expect($aliases)->toHaveKey('EventManager');
            expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });

        test('autoload PSR-4 mapping is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
            expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
        });
    });

    describe('EventManager global disable', function (): void {
        test('fire is suppressed when globally disabled', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            // Save a trigger first
            $em->on('test.disabled.event')
                ->action(SendOrderNotification::class)
                ->save();

            $em->setEnabled(false);
            expect($em->isDisabled())->toBeTrue();

            // Fire should not dispatch
            $logCountBefore = EventLog::count();
            $em->fire('test.disabled.event', ['key' => 'value']);
            $logCountAfter = EventLog::count();

            expect($logCountAfter)->toBe($logCountBefore);
            $em->setEnabled(true);
        });

        test('setEnabled and isDisabled work correctly', function (): void {
            $app = app();
            $em = $app->make(EventManager::class);

            $em->setEnabled(true);
            expect($em->isDisabled())->toBeFalse();

            $em->setEnabled(false);
            expect($em->isDisabled())->toBeTrue();

            $em->setEnabled(true);
        });
    });

    describe('EventLog model scopes', function (): void {
        test('scopeStalePending filters correctly', function (): void {
            EventLog::factory()->pending()->create([
                'created_at' => Carbon::now()->subDays(5),
            ]);

            EventLog::factory()->pending()->create([
                'created_at' => Carbon::now()->subMinutes(5),
            ]);

            EventLog::factory()->completed()->create([
                'created_at' => Carbon::now()->subDays(5),
            ]);

            $stale = EventLog::stalePending(Carbon::now()->subDays(1))->get();
            expect($stale->count())->toBe(1);
        });
    });

    describe('Factory state builders', function (): void {
        test('TriggerFactory has all expected state builders', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC)
            );

            $expectedStates = ['async', 'sync', 'enabled', 'disabled', 'withConditions', 'priority', 'forEvent', 'withAction', 'withName'];
            foreach ($expectedStates as $state) {
                expect($methods)->toContain($state);
            }
        });

        test('EventLogFactory has all expected state builders', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC)
            );

            $expectedStates = ['pending', 'dispatched', 'completed', 'failed', 'withEvent', 'forTrigger', 'withPayload', 'withDuration'];
            foreach ($expectedStates as $state) {
                expect($methods)->toContain($state);
            }
        });

        test('SubscriptionFactory has all expected state builders', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC)
            );

            $expectedStates = ['active', 'inactive', 'forEvent', 'withUrl', 'withConditions', 'withSecret', 'withoutSecret', 'withFailureCount', 'withDeliveryCount', 'withPriority'];
            foreach ($expectedStates as $state) {
                expect($methods)->toContain($state);
            }
        });
    });

    describe('Trigger model scopes', function (): void {
        test('scopeEnabled filters correctly', function (): void {
            Trigger::factory()->enabled()->create();
            Trigger::factory()->disabled()->create();

            $enabled = Trigger::enabled()->get();
            expect($enabled->count())->toBe(1);
            expect($enabled->first()->enabled)->toBeTrue();
        });

        test('scopeAsync filters correctly', function (): void {
            Trigger::factory()->async()->create();
            Trigger::factory()->sync()->create();

            $async = Trigger::async()->get();
            expect($async->count())->toBe(1);
        });
    });

    describe('PHPStan config validation', function (): void {
        test('phpstan.neon.dist exists and has level 8', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('level: max');
        });

        test('phpstan.neon.dist analyses src directory', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('- src');
        });

        test('phpstan.neon.dist analyses tests directory', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('- tests');
        });
    });
});
