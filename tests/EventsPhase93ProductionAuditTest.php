<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable as TriggerableContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 93 — Production Readiness Audit', function () {
    describe('EventsHealthCommand Consistency', function () {
        test('EventsHealthCommand uses Carbon::now() instead of global now() helper', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
            $file = $ref->getFileName();
            $contents = file_get_contents($file);

            // Should NOT use bare now() global helper
            expect($contents)->not->toContain('now()->');
            // Should use Carbon::now()
            expect($contents)->toContain('Carbon::now()');
        });

        test('EventsHealthCommand uses Config facade consistently (no nested config() helper)', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
            $file = $ref->getFileName();
            $contents = file_get_contents($file);

            // Should use Config::get() not nested config() helper
            expect($contents)->not->toContain("config('queue.default'");
        });

        test('EventsHealthCommand imports Carbon class', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
            $file = $ref->getFileName();
            $contents = file_get_contents($file);

            expect($contents)->toContain('use Illuminate\\Support\\Carbon');
        });
    });

    describe('EventManager parseActions Edge Cases', function () {
        test('parseActions handles classes+params JSON format with empty classes array', function () {
            $ref = new ReflectionMethod(EventManager::class, 'parseActions');

            $manager = app(EventManager::class);
            $result = $ref->invoke($manager, json_encode(['classes' => [], 'params' => ['url' => 'https://example.com']]));

            expect($result)->toBe([]);
        });

        test('parseActions handles single class JSON with params', function () {
            $ref = new ReflectionMethod(EventManager::class, 'parseActions');

            $manager = app(EventManager::class);
            $result = $ref->invoke($manager, json_encode([
                'class' => 'App\\Actions\\WebhookAction',
                'params' => ['url' => 'https://example.com'],
            ], JSON_THROW_ON_ERROR));

            expect($result)->toBeArray();
            expect(count($result))->toBe(1);
            expect($result[0])->toBeArray();
            expect($result[0]['class'])->toBe('App\\Actions\\WebhookAction');
        });

        test('parseActions handles sequential list of class+params objects', function () {
            $ref = new ReflectionMethod(EventManager::class, 'parseActions');

            $manager = app(EventManager::class);
            $input = json_encode([
                ['class' => 'App\\Actions\\ActionOne', 'params' => ['key' => 'val1']],
                ['class' => 'App\\Actions\\ActionTwo', 'params' => ['key' => 'val2']],
            ], JSON_THROW_ON_ERROR);

            $result = $ref->invoke($manager, $input);

            expect($result)->toBeArray();
            expect(count($result))->toBe(2);
            expect($result[0]['class'])->toBe('App\\Actions\\ActionOne');
            expect($result[1]['class'])->toBe('App\\Actions\\ActionTwo');
        });

        test('parseActions handles whitespace-only action string', function () {
            $ref = new ReflectionMethod(EventManager::class, 'parseActions');

            $manager = app(EventManager::class);
            $result = $ref->invoke($manager, '   ');

            expect($result)->toBe([]);
        });
    });

    describe('PHPStan Config Completeness', function () {
        test('phpstan.neon.dist suppresses app() function call', function () {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

            expect($contents)->toContain('now|database_path|app');
        });

        test('phpstan.neon.dist has level 9', function () {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

            expect($contents)->toContain('level: 9');
        });

        test('phpstan.neon.dist checks all PHPStan 9 strict options', function () {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

            expect($contents)->toContain('checkMissingIterableValueType: true');
            expect($contents)->toContain('checkGenericClassInNonGenericObjectType: true');
            expect($contents)->toContain('checkUninitializedProperties: true');
            expect($contents)->toContain('checkFunctionNameCase: true');
            expect($contents)->toContain('checkClassLikeNameCase: true');
            expect($contents)->toContain('checkPropertyHookNameCase: true');
            expect($contents)->toContain('checkEnumCaseValueNameCase: true');
        });
    });

    describe('WildcardMatcher Comprehensive Edge Cases', function () {
        test('matches returns false for empty event with exact pattern', function () {
            expect(WildcardMatcher::matches('order.placed', ''))->toBeFalse();
        });

        test('matches returns false for empty pattern with non-empty event', function () {
            expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
        });

        test('findMatchingPatterns returns empty for empty patterns array', function () {
            expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
        });

        test('extractWildcards returns empty for exact match (no wildcards)', function () {
            expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
        });

        test('extractWildcards returns correct values for multiple wildcards', function () {
            $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');
            expect($result)->toBe(['user', 'created']);
        });
    });

    describe('ConditionEngine Comprehensive Operators', function () {
        test('not_contains operator works correctly', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent', 'important']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'urgent']], ['tags' => ['urgent', 'important']]))->toBeFalse();
        });

        test('not_empty operator works correctly', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'some text']))->toBeTrue();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => []]))->toBeFalse();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => null]))->toBeFalse();
        });

        test('between operator auto-normalizes inverted ranges', function () {
            $engine = app(ConditionEngineContract::class);

            // [100, 50] should be treated as [50, 100]
            expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 75]))->toBeTrue();
        });

        test('nested dot notation returns null for missing deep key', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['user.profile.avatar' => ['null']], ['user' => []]))->toBeTrue();
            expect($engine->matches(['user.profile.avatar' => ['not_null']], ['user' => []]))->toBeFalse();
        });

        test('regex matches operator returns false for malformed pattern', function () {
            $engine = app(ConditionEngineContract::class);

            // Nested quantifier pattern should be rejected
            expect($engine->matches(['code' => ['matches', '/(a+)+/']], ['code' => 'aaa']))->toBeFalse();
        });
    });

    describe('DomainEvent Edge Cases', function () {
        test('fromArray with missing eventType throws exception', function () {
            expect(fn () => DomainEvent::fromArray(['payload' => ['key' => 'val']]))
                ->toThrow(InvalidArgumentException::class, 'eventType is required');
        });

        test('fromArray with empty eventType throws exception', function () {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });

        test('fromArray with invalid UUID generates fresh UUID', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => [],
                'eventId' => 'not-a-uuid',
                'occurredAt' => '2024-01-01T00:00:00+00:00',
            ]);

            expect($event->eventType)->toBe('test.event');
            expect($event->eventId)->not->toBeNull();
            // UUID should be a fresh one since the input was invalid
            expect($event->eventId->toString())->not->toBe('not-a-uuid');
        });

        test('fromArray with invalid date uses current time', function () {
            $before = new DateTimeImmutable();
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => [],
                'eventId' => null,
                'occurredAt' => 'not-a-date',
            ]);
            $after = new DateTimeImmutable();

            expect($event->occurredAt)->toBeGreaterThanOrEqual($before);
            expect($event->occurredAt)->toBeLessThanOrEqual($after);
        });

        test('occur factory method creates event with fresh UUID and current time', function () {
            $before = new DateTimeImmutable();
            $event = DomainEvent::occur('test.factory', ['key' => 'value']);
            $after = new DateTimeImmutable();

            expect($event->eventType)->toBe('test.factory');
            expect($event->payload)->toBe(['key' => 'value']);
            expect($event->eventId)->not->toBeNull();
            expect($event->occurredAt)->toBeGreaterThanOrEqual($before);
            expect($event->occurredAt)->toBeLessThanOrEqual($after);
        });
    });

    describe('TriggerBuilder Action Resolution', function () {
        test('save() with action() and actions() merges without duplicates', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.merge.dedup')
                ->name('Merge Dedup Test')
                ->action('App\\Actions\\FirstAction')
                ->actions(['App\\Actions\\FirstAction', 'App\\Actions\\SecondAction'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBe(['App\\Actions\\FirstAction', 'App\\Actions\\SecondAction']);
        });

        test('save() with actionParams and multiple actions generates correct JSON', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.multi.params')
                ->name('Multi Params Test')
                ->actions(['App\\Actions\\ActionOne', 'App\\Actions\\ActionTwo'])
                ->actionParams(['webhook_url' => 'https://example.com'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toHaveKey('classes');
            expect($decoded['classes'])->toBe(['App\\Actions\\ActionOne', 'App\\Actions\\ActionTwo']);
            expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com']);
        });

        test('save() with actionParams and single action generates correct JSON', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.single.params')
                ->name('Single Params Test')
                ->action('App\\Actions\\WebhookAction')
                ->actionParams(['url' => 'https://example.com/hooks'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toHaveKey('class');
            expect($decoded['class'])->toBe('App\\Actions\\WebhookAction');
            expect($decoded['params'])->toBe(['url' => 'https://example.com/hooks']);
        });
    });

    describe('EventScheduler Config-Driven Behavior', function () {
        test('register() creates both scheduled tasks', function () {
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $scheduler = app(EventScheduler::class);
            $scheduler->register($schedule);

            $events = $schedule->events();
            $names = array_map(fn ($e) => $e->command ?? $e->description ?? '', $events);

            expect($names)->toContain('zeroboiler:events:purge-logs');
            expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
        });

        test('register() skips log purge when retention days is null', function () {
            $app = app();
            $config = $app->get('config');
            $config->set('events.retention.days', null);

            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $scheduler = app(EventScheduler::class);
            $scheduler->register($schedule);

            $events = $schedule->events();
            $names = array_map(fn ($e) => $e->command ?? $e->description ?? '', $events);

            // Purge should be skipped, cleanup should still exist
            expect($names)->not->toContain('zeroboiler:events:purge-logs');
            expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');

            // Restore
            $config->set('events.retention.days', 30);
        });

        test('register() skips log purge when retention days is zero', function () {
            $app = app();
            $config = $app->get('config');
            $config->set('events.retention.days', 0);

            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $scheduler = app(EventScheduler::class);
            $scheduler->register($schedule);

            $events = $schedule->events();
            $names = array_map(fn ($e) => $e->command ?? $e->description ?? '', $events);

            expect($names)->not->toContain('zeroboiler:events:purge-logs');

            // Restore
            $config->set('events.retention.days', 30);
        });
    });

    describe('ServiceProvider Register/Boot Completeness', function () {
        test('EventManager is registered as singleton', function () {
            $app = app();
            $instance1 = $app->make(EventManager::class);
            $instance2 = $app->make(EventManager::class);

            expect($instance1)->toBe($instance2);
        });

        test('TriggerBuilder is transient (new instance per resolution)', function () {
            $app = app();
            $instance1 = $app->make(TriggerBuilder::class);
            $instance2 = $app->make(TriggerBuilder::class);

            expect($instance1)->not->toBe($instance2);
        });

        test('SubscriptionBuilder is transient (new instance per resolution)', function () {
            $app = app();
            $instance1 = $app->make(SubscriptionBuilder::class);
            $instance2 = $app->make(SubscriptionBuilder::class);

            expect($instance1)->not->toBe($instance2);
        });

        test('ConditionEngineContract resolves to ConditionEngine', function () {
            $instance = app(ConditionEngineContract::class);

            expect($instance)->toBeInstanceOf(ConditionEngine::class);
        });

        test('EventScheduler is registered as singleton', function () {
            $app = app();
            $instance1 = $app->make(EventScheduler::class);
            $instance2 = $app->make(EventScheduler::class);

            expect($instance1)->toBe($instance2);
        });

        test('provides() lists all registered services', function () {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
            $provides = $provider->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
        });
    });

    describe('Model Table Name Config-Driven', function () {
        test('Trigger table name reads from config', function () {
            $trigger = new Trigger;
            expect($trigger->getTable())->toBe('triggers');
        });

        test('EventLog table name reads from config', function () {
            $log = new EventLog;
            expect($log->getTable())->toBe('event_logs');
        });

        test('Subscription table name reads from config', function () {
            $sub = new \ZeroBoiler\Events\Models\Subscription;
            expect($sub->getTable())->toBe('event_subscriptions');
        });

        test('table names respect custom config override', function () {
            $config = app('config');
            $original = $config->get('events.table_names.triggers');

            $config->set('events.table_names.triggers', 'custom_triggers');
            $trigger = new Trigger;
            expect($trigger->getTable())->toBe('custom_triggers');

            // Restore
            $config->set('events.table_names.triggers', $original);
        });
    });

    describe('Factory State Coverage', function () {
        test('TriggerFactory has all expected state methods', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
            $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

            expect($methods)->toContain('async');
            expect($methods)->toContain('sync');
            expect($methods)->toContain('enabled');
            expect($methods)->toContain('disabled');
            expect($methods)->toContain('withConditions');
            expect($methods)->toContain('priority');
            expect($methods)->toContain('forEvent');
            expect($methods)->toContain('withAction');
            expect($methods)->toContain('withName');
        });

        test('EventLogFactory has all expected state methods', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
            $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

            expect($methods)->toContain('pending');
            expect($methods)->toContain('dispatched');
            expect($methods)->toContain('completed');
            expect($methods)->toContain('failed');
            expect($methods)->toContain('withEvent');
            expect($methods)->toContain('forTrigger');
            expect($methods)->toContain('withPayload');
            expect($methods)->toContain('withDuration');
        });

        test('SubscriptionFactory has all expected state methods', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
            $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

            expect($methods)->toContain('active');
            expect($methods)->toContain('inactive');
            expect($methods)->toContain('forEvent');
            expect($methods)->toContain('withUrl');
            expect($methods)->toContain('withConditions');
            expect($methods)->toContain('withSecret');
            expect($methods)->toContain('withoutSecret');
            expect($methods)->toContain('withFailureCount');
            expect($methods)->toContain('withDeliveryCount');
            expect($methods)->toContain('withPriority');
        });
    });

    describe('All Console Commands Final and Strict Types', function () {
        test('all console command classes are final', function () {
            $commands = [
                \ZeroBoiler\Events\Console\EventsFireCommand::class,
                \ZeroBoiler\Events\Console\EventsListCommand::class,
                \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
                \ZeroBoiler\Events\Console\EventsEnableCommand::class,
                \ZeroBoiler\Events\Console\EventsDisableCommand::class,
                \ZeroBoiler\Events\Console\EventsRetryCommand::class,
                \ZeroBoiler\Events\Console\EventsLogCommand::class,
                \ZeroBoiler\Events\Console\EventsHealthCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
                \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            ];

            foreach ($commands as $command) {
                $ref = new ReflectionClass($command);
                expect($ref->isFinal())->toBeTrue("{$command} must be final");
            }
        });
    });
});

/**
 * Noop action for Phase 93 tests.
 */
class Phase93NoopAction implements TriggerableContract
{
    #[\Override]
    public function handle(array $payload): void
    {
        // No-op for testing
    }
}
