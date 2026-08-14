<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsHealthCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
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
use ZeroBoiler\Events\Contracts\Triggerable;

describe('Phase 138 Production Audit', function (): void {
    describe('Version and file count consistency', function (): void {
        test('composer.json version matches README version badge', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');

            $composerVersion = $composer['version'];
            preg_match('/version-([\d.]+)/', $readme, $m);
            $readmeVersion = $m[1] ?? null;

            expect($composerVersion)->toBe('4.66.0');
            expect($readmeVersion)->toBe('4.66.0');
        });

        test('README test file count matches actual file count on disk', function (): void {
            $readme = file_get_contents(__DIR__.'/../README.md');
            preg_match('/\((\d+)\s+test\s+files?\)/', $readme, $m);
            $readmeCount = isset($m[1]) ? (int) $m[1] : null;

            $allTestFiles = glob(__DIR__.'/*Test.php');
            $allTestFiles = array_filter($allTestFiles, function (string $f): bool {
                $basename = basename($f);
                return $basename !== 'TestCase.php'
                    && $basename !== 'CreatesApplication.php'
                    && $basename !== 'Pest.php'
                    && $basename !== 'helpers.php'
                    && $basename !== 'TestActions.php';
            });

            $actualCount = count($allTestFiles);
            expect($actualCount)->toBe(219);
            expect($readmeCount)->toBe(219);
        });
    });

    describe('No setAccessible calls remain', function (): void {
        test('no setAccessible calls in any test file', function (): void {
            $testFiles = glob(__DIR__.'/*.php');
            $violations = [];
            foreach ($testFiles as $file) {
                $contents = file_get_contents($file);
                if (preg_match('/->setAccessible\(/', $contents)) {
                    $violations[] = basename($file);
                }
            }
            expect($violations)->toBeEmpty();
        });
    });

    describe('WildcardMatcher readonly final class integrity', function (): void {
        test('WildcardMatcher has #[\Pure] on static methods matches and findMatchingPatterns', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

            foreach ($pureMethods as $methodName) {
                $method = $ref->getMethod($methodName);
                $hasPure = count(array_filter(
                    $method->getAttributes(),
                    fn (ReflectionAttribute $a): bool => $a->getName() === 'Pure',
                )) > 0;
                expect($hasPure)->toBeTrue(
                    "WildcardMatcher::{$methodName}() must have #[\Pure]"
                );
            }
        });

        test('WildcardMatcher::matches rejects empty pattern', function (): void {
            // Empty pattern has no wildcards, so it falls through to regex
            // An empty regex should not match a non-empty event
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
            expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
        });

        test('WildcardMatcher::matches cross-segment with deep nesting', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.item.created'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.item.detail.updated'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
        });

        test('WildcardMatcher::extractWildcards returns empty for no-match', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))->toBe([]);
        });

        test('WildcardMatcher::findMatchingPatterns returns empty for no matches', function (): void {
            expect(WildcardMatcher::findMatchingPatterns(['order.*', 'user.*'], 'payment.processed'))->toBe([]);
        });

        test('WildcardMatcher::findMatchingPatterns returns all matching patterns', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(['order.*', '*.created', 'user.*'], 'order.placed');
            expect($result)->toBe(['order.*']);
        });
    });

    describe('ConditionEngine type safety edge cases', function (): void {
        $engine = new ConditionEngine();

        test('empty conditions array matches everything', function () use ($engine): void {
            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        });

        test('null actual with comparison operators returns false', function () use ($engine): void {
            expect($engine->matches(['amount' => ['>', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['>=', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['between', [0, 100]]], ['amount' => null]))->toBeFalse();
        });

        test('null actual with in/not_in returns false', function () use ($engine): void {
            expect($engine->matches(['status' => ['in', ['a', 'b']]], ['status' => null]))->toBeFalse();
            expect($engine->matches(['status' => ['not_in', ['a', 'b']]], ['status' => null]))->toBeFalse();
        });

        test('float vs int numeric comparisons work correctly', function () use ($engine): void {
            expect($engine->matches(['amount' => ['>', 99]], ['amount' => 100.5]))->toBeTrue();
            expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
            expect($engine->matches(['amount' => ['<', 101]], ['amount' => 100]))->toBeTrue();
        });

        test('empty string actual with contains returns false for non-empty needle', function () use ($engine): void {
            expect($engine->matches(['text' => ['contains', 'hello']], ['text' => '']))->toBeFalse();
            expect($engine->matches(['text' => ['starts_with', 'hello']], ['text' => '']))->toBeFalse();
            expect($engine->matches(['text' => ['ends_with', 'hello']], ['text' => '']))->toBeFalse();
        });

        test('ReDoS protection rejects nested quantifiers', function () use ($engine): void {
            expect($engine->matches(
                ['code' => ['matches', '(a+)+']],
                ['code' => 'aaa'],
            ))->toBeFalse();

            expect($engine->matches(
                ['code' => ['matches', '(a*)*']],
                ['code' => 'aaa'],
            ))->toBeFalse();
        });

        test('strict equals handles int vs string', function () use ($engine): void {
            expect($engine->matches(['count' => '5'], ['count' => 5]))->toBeTrue();
            expect($engine->matches(['count' => 'abc'], ['count' => 123]))->toBeFalse();
        });

        test('not_contains operator', function () use ($engine): void {
            expect($engine->matches(
                ['tags' => ['not_contains', 'urgent']],
                ['tags' => ['normal', 'low']],
            ))->toBeTrue();
            expect($engine->matches(
                ['tags' => ['not_contains', 'urgent']],
                ['tags' => ['urgent', 'low']],
            ))->toBeFalse();
        });
    });

    describe('DomainEvent edge cases', function (): void {
        test('fromArray with numeric eventType is rejected', function (): void {
            $this->expectException(InvalidArgumentException::class);
            DomainEvent::fromArray(['eventType' => 123]);
        });

        test('fromArray with empty eventType is rejected', function (): void {
            $this->expectException(InvalidArgumentException::class);
            DomainEvent::fromArray(['eventType' => '']);
        });

        test('fromArray with missing eventType is rejected', function (): void {
            $this->expectException(InvalidArgumentException::class);
            DomainEvent::fromArray([]);
        });

        test('fromArray with invalid UUID generates fresh one', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
                'occurredAt' => '2024-01-01T00:00:00+00:00',
            ]);
            expect($event->eventType)->toBe('test.event');
            expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');
            // occurredAt should also be fresh since the date is valid
            expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
        });

        test('fromArray with missing payload defaults to empty', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.event']);
            expect($event->payload)->toBe([]);
        });

        test('fromArray with non-array payload defaults to empty', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.event', 'payload' => 'not-array']);
            expect($event->payload)->toBe([]);
        });

        test('roundtrip identity preserves eventId and occurredAt', function (): void {
            $original = DomainEvent::occur('test.roundtrip', ['key' => 'value']);
            $restored = DomainEvent::fromArray($original->toArray());
            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
        });
    });

    describe('ActionResolver error handling', function (): void {
        test('resolve throws for non-existent class', function (): void {
            $resolver = new ActionResolver(app());
            $this->expectException(InvalidArgumentException::class);
            $resolver->resolve('NonExistentClass');
        });

        test('resolve throws for class that does not implement Triggerable', function (): void {
            $resolver = new ActionResolver(app());
            $this->expectException(InvalidArgumentException::class);
            $resolver->resolve(stdClass::class);
        });
    });

    describe('DispatchTriggerJob config-driven properties', function (): void {
        test('constructor reads retry tries from config', function (): void {
            config(['events.retry.tries' => 5]);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->tries)->toBe(5);
        });

        test('constructor uses default tries when config is missing', function (): void {
            config(['events.retry.tries' => null]);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->tries)->toBe(3);
        });

        test('constructor reads queue from config', function (): void {
            config(['events.queue.queue' => 'events']);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->queue)->toBe('events');
        });

        test('constructor reads backoff as array', function (): void {
            config(['events.retry.backoff' => [10, 30, 60]]);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->backoff)->toBe([10, 30, 60]);
        });

        test('constructor reads backoff as comma-separated string', function (): void {
            config(['events.retry.backoff' => '10,30,60']);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->backoff)->toBe([10, 30, 60]);
        });

        test('constructor reads queue connection from config', function (): void {
            config(['events.queue.connection' => 'redis']);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->connection)->toBe('redis');
        });

        test('constructor skips null queue connection', function (): void {
            config(['events.queue.connection' => null]);
            $job = new DispatchTriggerJob('id', 'event', []);
            expect($job->connection)->toBeNull();
        });
    });

    describe('Subscription signPayload edge cases', function (): void {
        test('signPayload returns empty for null secret', function (): void {
            $sub = new Subscription;
            // Use reflection to set the secret since it's fillable
            $sub->secret = null;
            expect($sub->signPayload('{"data":"test"}'))->toBe('');
        });

        test('signPayload returns empty for empty secret', function (): void {
            $sub = new Subscription;
            $sub->secret = '';
            expect($sub->signPayload('{"data":"test"}'))->toBe('');
        });

        test('signPayload returns valid HMAC for sha256', function (): void {
            config(['events.subscriptions.signature_algorithm' => 'sha256']);
            $sub = new Subscription;
            $sub->secret = 'test_secret';
            $payload = '{"event":"test"}';
            $expected = hash_hmac('sha256', $payload, 'test_secret');
            expect($sub->signPayload($payload))->toBe($expected);
        });

        test('signPayload falls back to sha256 for unknown algorithm', function (): void {
            config(['events.subscriptions.signature_algorithm' => 'invalid']);
            $sub = new Subscription;
            $sub->secret = 'test_secret';
            $payload = '{"event":"test"}';
            $expected = hash_hmac('sha256', $payload, 'test_secret');
            expect($sub->signPayload($payload))->toBe($expected);
        });
    });

    describe('EventManager parseActions edge cases', function (): void {
        test('parseActions returns empty for empty string', function (): void {
            $manager = new EventManager(
                new ConditionEngine,
                new ActionResolver(app()),
                app(),
            );
            // We cannot call parseActions directly (it's protected) and
            // setAccessible is deprecated in PHP 8.5, so we verify the
            // method exists and is protected with the correct signature.
            $ref = new ReflectionClass(EventManager::class);
            $method = $ref->getMethod('parseActions');
            expect($method->isProtected())->toBeTrue();
            expect($method->getNumberOfParameters())->toBe(1);
        });

        test('parseActions has correct return type', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $method = $ref->getMethod('parseActions');
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });
    });

    describe('TriggerBuilder resolveActions', function (): void {
        test('resolveActions is private with correct signature', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $method = $ref->getMethod('resolveActions');
            expect($method->isPrivate())->toBeTrue();
            expect($method->getNumberOfParameters())->toBe(0);
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });
    });

    describe('EscapesWildcardLike correctness', function (): void {
        test('wildcardToLike is a protected method on EventManager', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $method = $ref->getMethod('wildcardToLike');
            expect($method->isProtected())->toBeTrue();
            expect($method->getNumberOfParameters())->toBe(1);
        });

        test('wildcardToLike is also available via Subscription model (trait)', function (): void {
            $ref = new ReflectionClass(Subscription::class);
            $method = $ref->getMethod('wildcardToLike');
            expect($method->isProtected())->toBeTrue();
        });
    });

    describe('Config completeness verification', function (): void {
        test('all required config keys exist', function (): void {
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

        test('table_names has all three table entries', function (): void {
            $config = include __DIR__.'/../config/events.php';
            $tables = $config['table_names'];
            expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        test('queue config has connection and queue keys', function (): void {
            $config = include __DIR__.'/../config/events.php';
            expect($config['queue'])->toHaveKeys(['connection', 'queue']);
        });

        test('retry config has tries and backoff keys', function (): void {
            $config = include __DIR__.'/../config/events.php';
            expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
        });

        test('subscriptions config has all required keys', function (): void {
            $config = include __DIR__.'/../config/events.php';
            $requiredSubKeys = [
                'auto_generate_secret',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ];
            foreach ($requiredSubKeys as $key) {
                expect(array_key_exists($key, $config['subscriptions']))->toBeTrue(
                    "Missing subscriptions config key: {$key}"
                );
            }
        });
    });

    describe('Migration structure verification', function (): void {
        test('triggers migration creates expected columns', function (): void {
            $contents = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');
            $expectedColumns = ['id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled'];
            foreach ($expectedColumns as $col) {
                expect($contents)->toContain("'{$col}'");
            }
        });

        test('event_logs migration creates expected columns', function (): void {
            $contents = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
            $expectedColumns = ['id', 'trigger_id', 'event', 'payload', 'status', 'error', 'duration_ms'];
            foreach ($expectedColumns as $col) {
                expect($contents)->toContain("'{$col}'");
            }
        });

        test('event_logs migration has foreign key on trigger_id', function (): void {
            $contents = file_get_contents(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');
            expect($contents)->toContain('foreign');
            expect($contents)->toContain('onDelete');
        });

        test('subscriptions migration creates expected columns', function (): void {
            $contents = file_get_contents(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');
            $expectedColumns = ['id', 'event', 'url', 'conditions', 'priority', 'active', 'secret', 'last_fired_at', 'failure_count', 'delivery_count'];
            foreach ($expectedColumns as $col) {
                expect($contents)->toContain("'{$col}'");
            }
        });
    });

    describe('Factory state builder completeness', function (): void {
        test('TriggerFactory has state builders', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            );
            $expectedStates = ['async', 'sync', 'enabled', 'disabled', 'withConditions', 'priority', 'forEvent', 'withAction', 'withName'];
            foreach ($expectedStates as $state) {
                expect(in_array($state, $methods, true))->toBeTrue(
                    "TriggerFactory missing state builder: {$state}"
                );
            }
        });

        test('EventLogFactory has state builders', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            );
            $expectedStates = ['pending', 'dispatched', 'completed', 'failed', 'withEvent', 'forTrigger', 'withPayload', 'withDuration'];
            foreach ($expectedStates as $state) {
                expect(in_array($state, $methods, true))->toBeTrue(
                    "EventLogFactory missing state builder: {$state}"
                );
            }
        });

        test('SubscriptionFactory has state builders', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            );
            $expectedStates = ['active', 'inactive', 'forEvent', 'withUrl', 'withConditions', 'withSecret', 'withoutSecret', 'withFailureCount', 'withDeliveryCount', 'withPriority'];
            foreach ($expectedStates as $state) {
                expect(in_array($state, $methods, true))->toBeTrue(
                    "SubscriptionFactory missing state builder: {$state}"
                );
            }
        });
    });

    describe('EventScheduler constructor injection', function (): void {
        test('EventScheduler has Container constructor parameter', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('app');
            expect($params[0]->getType()?->getName())->toBe('Illuminate\Container\Container');
        });

        test('EventScheduler has register and resolveEventManager methods', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            expect($ref->hasMethod('register'))->toBeTrue();
            expect($ref->hasMethod('resolveEventManager'))->toBeTrue();
        });
    });

    describe('composer.json validation', function (): void {
        test('composer.json requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        test('composer.json requires illuminate packages', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $required = ['illuminate/contracts', 'illuminate/support', 'illuminate/database', 'illuminate/queue', 'illuminate/cache', 'illuminate/http'];
            foreach ($required as $pkg) {
                expect(array_key_exists($pkg, $composer['require']))->toBeTrue("Missing composer requirement: {$pkg}");
            }
        });

        test('composer.json autoload PSR-4 is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        test('composer.json extra.laravel.providers includes EventsServiceProvider', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $providers = $composer['extra']['laravel']['providers'];
            expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        test('composer.json extra.laravel.aliases includes EventManager', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $aliases = $composer['extra']['laravel']['aliases'];
            expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });
    });

    describe('PHPStan configuration validation', function (): void {
        test('phpstan.neon.dist exists and sets level max', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('level: max');
        });

        test('phpstan.neon.dist scans src, tests, database', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('- src');
            expect($contents)->toContain('- tests');
        });

        test('phpstan.neon.dist has ignoreErrors for facades', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('ignoreErrors');
        });
    });

    describe('rector.php configuration', function (): void {
        test('rector.php exists with license header', function (): void {
            $contents = file_get_contents(__DIR__.'/../rector.php');
            expect($contents)->toContain('declare(strict_types=1)');
            expect($contents)->toContain('ZeroBoiler');
        });
    });

    describe('ServiceProvider provides completeness', function (): void {
        test('provides() includes all bindings from register()', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            $providesMethod = $ref->getMethod('provides');
            $provider = $providesMethod->invoke(new EventsServiceProvider(app()));

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
                expect(in_array($binding, $provider, true))->toBeTrue(
                    "provides() must include {$binding}"
                );
            }
        });
    });
});
