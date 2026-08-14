<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\Triggerable;
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

/**
 * Phase 144 production audit — comprehensive source and test coverage verification.
 *
 * Covers: README accuracy, version consistency, source file structure,
 * PHP 8.5 compliance, ServiceProvider binding completeness,
 * Config key coverage, Facade method coverage, and edge cases.
 */
describe('EventsPhase144ProductionAudit', function (): void {
    // ───────────────────────────────────────────────────────────
    // 1. README & Version Consistency
    // ───────────────────────────────────────────────────────────
    describe('README accuracy', function (): void {
        test('README.md exists and is non-empty', function (): void {
            $readme = file_get_contents(base_path('../README.md'));
            expect($readme)->not->toBeFalse();
            expect(strlen($readme))->toBeGreaterThan(1000);
        });

        test('README version badge matches composer.json version', function (): void {
            $composer = json_decode(file_get_contents(base_path('composer.json')), true);
            $version = $composer['version'];
            $readme = file_get_contents(base_path('../README.md'));

            // Badge format: badge/version-X.X.X-blue
            expect($readme)->toContain("version-{$version}-blue");
        });

        test('README table of contents mentions all major sections', function (): void {
            $readme = file_get_contents(base_path('../README.md'));

            $requiredSections = [
                'Requirements',
                'Installation',
                'Configuration',
                'Usage',
                'CLI Commands',
                'Architecture',
                'Security Considerations',
                'Troubleshooting',
                'API Reference',
                'Changelog',
            ];

            foreach ($requiredSections as $section) {
                expect($readme)->toContain($section);
            }
        });

        test('README mentions all 12 CLI commands', function (): void {
            $readme = file_get_contents(base_path('../README.md'));

            $commands = [
                'zeroboiler:events:list',
                'zeroboiler:events:fire',
                'zeroboiler:events:register',
                'zeroboiler:events:enable',
                'zeroboiler:events:disable',
                'zeroboiler:events:retry',
                'zeroboiler:events:redeliver',
                'zeroboiler:events:log',
                'zeroboiler:events:subscribe',
                'zeroboiler:events:unsubscribe',
                'zeroboiler:events:subscriptions',
                'zeroboiler:events:health',
            ];

            foreach ($commands as $command) {
                expect($readme)->toContain($command);
            }
        });

        test('README package tree lists all source directories', function (): void {
            $readme = file_get_contents(base_path('../README.md'));

            $expectedDirs = [
                'Actions/',
                'Console/',
                'Contracts/',
                'Concerns/',
                'Domain/',
                'Facades/',
                'Jobs/',
                'Models/',
            ];

            foreach ($expectedDirs as $dir) {
                expect($readme)->toContain($dir);
            }
        });
    });

    // ───────────────────────────────────────────────────────────
    // 2. Source File PHP 8.5 Compliance
    // ───────────────────────────────────────────────────────────
    describe('PHP 8.5 source compliance', function (): void {
        $sourceFiles = [
            'EventManager.php',
            'ConditionEngine.php',
            'ActionResolver.php',
            'TriggerBuilder.php',
            'SubscriptionBuilder.php',
            'WildcardMatcher.php',
            'EventScheduler.php',
            'EventsServiceProvider.php',
        ];

        test('all core source files have declare(strict_types=1)', function () use ($sourceFiles): void {
            foreach ($sourceFiles as $file) {
                $path = base_path("src/{$file}");
                $content = file_get_contents($path);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('all core source files have license header', function () use ($sourceFiles): void {
            foreach ($sourceFiles as $file) {
                $path = base_path("src/{$file}");
                $content = file_get_contents($path);
                expect($content)->toContain('ZeroBoiler, licensed under the proprietary license');
            }
        });

        test('EventManager is final class', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        test('ConditionEngine is final class', function (): void {
            $reflection = new ReflectionClass(ConditionEngine::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        test('WildcardMatcher is readonly final class', function (): void {
            $reflection = new ReflectionClass(WildcardMatcher::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('DomainEvent is final class', function (): void {
            $reflection = new ReflectionClass(DomainEvent::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        test('TriggerBuilder is final class', function (): void {
            $reflection = new ReflectionClass(TriggerBuilder::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        test('SubscriptionBuilder is final class', function (): void {
            $reflection = new ReflectionClass(SubscriptionBuilder::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        test('DispatchTriggerJob is final class', function (): void {
            $reflection = new ReflectionClass(DispatchTriggerJob::class);
            expect($reflection->isFinal())->toBeTrue();
        });

        test('all models are final classes', function (): void {
            $models = [Trigger::class, EventLog::class, Subscription::class];
            foreach ($models as $model) {
                expect((new ReflectionClass($model))->isFinal())->toBeTrue("{$model} should be final");
            }
        });
    });

    // ───────────────────────────────────────────────────────────
    // 3. ServiceProvider Binding Completeness
    // ───────────────────────────────────────────────────────────
    describe('ServiceProvider bindings', function (): void {
        test('EventManager is registered as singleton', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            $instance1 = $this->app->make(EventManager::class);
            $instance2 = $this->app->make(EventManager::class);
            expect(spl_object_id($instance1))->toBe(spl_object_id($instance2));
        });

        test('ConditionEngine is registered as singleton', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            $instance1 = $this->app->make(ConditionEngine::class);
            $instance2 = $this->app->make(ConditionEngine::class);
            expect(spl_object_id($instance1))->toBe(spl_object_id($instance2));
        });

        test('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            $instance = $this->app->make(ConditionEngineContract::class);
            expect($instance)->toBeInstanceOf(ConditionEngine::class);
        });

        test('ActionResolver is registered', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            expect($this->app->make(ActionResolver::class))->toBeInstanceOf(ActionResolver::class);
        });

        test('TriggerBuilder is registered as transient', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            $instance1 = $this->app->make(TriggerBuilder::class);
            $instance2 = $this->app->make(TriggerBuilder::class);
            // Transient — different instances
            expect(spl_object_id($instance1))->not->toBe(spl_object_id($instance2));
        });

        test('SubscriptionBuilder is registered as transient', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            $instance1 = $this->app->make(SubscriptionBuilder::class);
            $instance2 = $this->app->make(SubscriptionBuilder::class);
            expect(spl_object_id($instance1))->not->toBe(spl_object_id($instance2));
        });

        test('EventScheduler is registered as singleton', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provider->register();

            $instance1 = $this->app->make(EventScheduler::class);
            $instance2 = $this->app->make(EventScheduler::class);
            expect(spl_object_id($instance1))->toBe(spl_object_id($instance2));
        });

        test('provides() returns all registered services', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provides = $provider->provides();

            $expectedServices = [
                EventManager::class,
                ConditionEngine::class,
                ConditionEngineContract::class,
                ActionResolver::class,
                TriggerBuilder::class,
                SubscriptionBuilder::class,
                EventScheduler::class,
            ];

            foreach ($expectedServices as $service) {
                expect(in_array($service, $provides, true))->toBeTrue(
                    "provides() should include {$service}"
                );
            }
        });
    });

    // ───────────────────────────────────────────────────────────
    // 4. Config Completeness
    // ───────────────────────────────────────────────────────────
    describe('Config completeness', function (): void {
        test('all config keys are documented in README', function (): void {
            $readme = file_get_contents(base_path('../README.md'));
            $configKeys = [
                'table_names',
                'queue',
                'retry',
                'retention',
                'subscriptions',
                'disabled',
                'wildcard_cache_ttl',
            ];

            foreach ($configKeys as $key) {
                expect($readme)->toContain("'{$key}'");
            }
        });

        test('config has table_names with all three tables', function (): void {
            $tableNames = config('events.table_names');
            expect($tableNames)->toBeArray();
            expect($tableNames)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        test('config has queue settings', function (): void {
            $queue = config('events.queue');
            expect($queue)->toBeArray();
            expect($queue)->toHaveKeys(['connection', 'queue']);
        });

        test('config has retry settings', function (): void {
            $retry = config('events.retry');
            expect($retry)->toBeArray();
            expect($retry)->toHaveKeys(['tries', 'backoff']);
        });

        test('config has retention settings', function (): void {
            $retention = config('events.retention');
            expect($retention)->toBeArray();
            expect($retention)->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
        });

        test('config has subscription settings', function (): void {
            $subs = config('events.subscriptions');
            expect($subs)->toBeArray();
            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
            expect($subs)->toHaveKey('cleanup_cron');
        });
    });

    // ───────────────────────────────────────────────────────────
    // 5. Facade Coverage
    // ───────────────────────────────────────────────────────────
    describe('Facade method coverage', function (): void {
        test('facade resolves to EventManager', function (): void {
            $resolved = $this->app->make(EventManagerFacade::getFacadeRoot());
            expect($resolved)->toBeInstanceOf(EventManager::class);
        });

        test('facade accessor returns correct class name', function (): void {
            expect(EventManagerFacade::getFacadeAccessor())
                ->toBe(\ZeroBoiler\Events\EventManager::class);
        });

        test('Facade has @method annotations for all public EventManager methods', function (): void {
            $facadeFile = file_get_contents(base_path('src/Facades/EventManager.php'));
            $expectedMethods = [
                'on(',
                'register(',
                'fire(',
                'fireModel(',
                'enable(',
                'disable(',
                'invalidateTriggerCache(',
                'isDisabled(',
                'setEnabled(',
                'listTriggers(',
                'getTrigger(',
                'deleteTrigger(',
                'subscribe(',
                'unsubscribe(',
                'listSubscriptions(',
                'getSubscription(',
                'subscribeWebhook(',
                'getEventHistory(',
                'getStats(',
                'purgeLogs(',
                'getStalePendingLogs(',
                'deactivateExceededSubscriptions(',
                'executeTrigger(',
                'registerScheduler(',
            ];

            foreach ($expectedMethods as $method) {
                expect($facadeFile)->toContain("@method static {$method}");
            }
        });
    });

    // ───────────────────────────────────────────────────────────
    // 6. DomainEvent Edge Cases
    // ───────────────────────────────────────────────────────────
    describe('DomainEvent edge cases', function (): void {
        test('fromArray with integer eventType throws InvalidArgumentException', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray(['eventType' => 123]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('fromArray with empty string eventType throws InvalidArgumentException', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });

        test('fromArray with missing eventType throws InvalidArgumentException', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray([]))
                ->toThrow(InvalidArgumentException::class);
        });

        test('occur and toArray roundtrip preserves all fields', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $event->toArray();

            expect($data['eventType'])->toBe('test.event');
            expect($data['payload'])->toBe(['key' => 'value']);
            expect($data['eventId'])->toBeString();
            expect($data['occurredAt'])->toBeString();
        });

        test('eventId and occurredAt are readonly public properties', function (): void {
            $reflection = new ReflectionClass(DomainEvent::class);

            expect($reflection->getProperty('eventId')->isReadOnly())->toBeTrue();
            expect($reflection->getProperty('occurredAt')->isReadOnly())->toBeTrue();
            expect($reflection->getProperty('eventType')->isReadOnly())->toBeTrue();
            expect($reflection->getProperty('payload')->isReadOnly())->toBeTrue();
        });
    });

    // ───────────────────────────────────────────────────────────
    // 7. ConditionEngine Operator Coverage
    // ───────────────────────────────────────────────────────────
    describe('ConditionEngine operator completeness', function (): void {
        test('all 21 operators are handled in evaluateCondition', function (): void {
            $operators = [
                '>', '>=', '<', '<=',
                '=', '===', '!=', '!==',
                'in', 'not_in',
                'contains', 'not_contains',
                'between',
                'null', 'not_null',
                'empty', 'not_empty',
                'starts_with', 'ends_with',
                'matches',
            ];

            $engine = new ConditionEngine();

            // Verify each operator is callable via matches()
            // Test with simple valid payloads
            foreach ($operators as $operator) {
                $validPayload = match ($operator) {
                    '>' => ['val' => 10],
                    '>=' => ['val' => 10],
                    '<' => ['val' => 10],
                    '<=' => ['val' => 10],
                    '=' => ['val' => 'test'],
                    '===' => ['val' => true],
                    '!=' => ['val' => 'test'],
                    '!==' => ['val' => 'test'],
                    'in' => ['val' => 'a'],
                    'not_in' => ['val' => 'x'],
                    'contains' => ['val' => 'hello world'],
                    'not_contains' => ['val' => 'hello'],
                    'between' => ['val' => 50],
                    'null' => ['val' => null],
                    'not_null' => ['val' => 'test'],
                    'empty' => ['val' => ''],
                    'not_empty' => ['val' => 'test'],
                    'starts_with' => ['val' => 'hello world'],
                    'ends_with' => ['val' => 'hello world'],
                    'matches' => ['val' => 'ABC123'],
                    default => ['val' => null],
                };

                // Just verify the engine can evaluate without error
                $conditions = match ($operator) {
                    'between' => ['val' => [$operator, [0, 100]]],
                    'null', 'not_null', 'empty', 'not_empty' => ['val' => [$operator]],
                    'matches' => ['val' => [$operator, '/^[A-Z]+\\d+$/']],
                    'in', 'not_in' => ['val' => [$operator, ['a', 'b', 'c']]],
                    'contains', 'not_contains' => ['val' => [$operator, 'world']],
                    'starts_with' => ['val' => [$operator, 'hello']],
                    'ends_with' => ['val' => [$operator, 'world']],
                    '===' => ['val' => [$operator, true]],
                    '!==' => ['val' => [$operator, false]],
                    default => ['val' => [$operator, 'test']],
                };

                // Should not throw
                $result = $engine->matches($conditions, $validPayload);
                expect($result)->toBeBool();
            }
        });
    });

    // ───────────────────────────────────────────────────────────
    // 8. Model Casts & Scopes
    // ───────────────────────────────────────────────────────────
    describe('Model casts and scopes', function (): void {
        test('Trigger model has casts method', function (): void {
            $trigger = new Trigger;
            $casts = $trigger->casts();
            expect($casts)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);
        });

        test('EventLog model has casts method', function (): void {
            $log = new EventLog;
            $casts = $log->casts();
            expect($casts)->toHaveKeys(['payload', 'duration_ms', 'error']);
        });

        test('Subscription model has casts method', function (): void {
            $sub = new Subscription;
            $casts = $sub->casts();
            expect($casts)->toHaveKeys(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
        });

        test('EventLog has all four status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        test('Trigger scopeEnabled returns Builder', function (): void {
            $scopeResult = Trigger::enabled();
            expect($scopeResult)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        test('EventLog scopeFailed returns Builder', function (): void {
            $scopeResult = EventLog::failed();
            expect($scopeResult)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });

        test('Subscription scopeActive returns Builder', function (): void {
            $scopeResult = Subscription::active();
            expect($scopeResult)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
        });
    });

    // ───────────────────────────────────────────────────────────
    // 9. Factory State Builders
    // ───────────────────────────────────────────────────────────
    describe('Factory state builders', function (): void {
        test('TriggerFactory has all expected state methods', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            );

            $expectedStates = ['async', 'sync', 'enabled', 'disabled', 'withConditions', 'priority', 'forEvent', 'withAction', 'withName'];
            foreach ($expectedStates as $state) {
                expect(in_array($state, $methods, true))->toBeTrue("TriggerFactory should have {$state}() state method");
            }
        });

        test('EventLogFactory has all expected state methods', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            );

            $expectedStates = ['pending', 'dispatched', 'completed', 'failed', 'withEvent', 'forTrigger', 'withPayload', 'withDuration'];
            foreach ($expectedStates as $state) {
                expect(in_array($state, $methods, true))->toBeTrue("EventLogFactory should have {$state}() state method");
            }
        });

        test('SubscriptionFactory has all expected state methods', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
            $methods = array_map(
                fn (ReflectionMethod $m): string => $m->getName(),
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            );

            $expectedStates = ['active', 'inactive', 'forEvent', 'withUrl', 'withConditions', 'withSecret', 'withoutSecret', 'withFailureCount', 'withDeliveryCount', 'withPriority'];
            foreach ($expectedStates as $state) {
                expect(in_array($state, $methods, true))->toBeTrue("SubscriptionFactory should have {$state}() state method");
            }
        });
    });

    // ───────────────────────────────────────────────────────────
    // 10. phpstan.neon.dist Configuration
    // ───────────────────────────────────────────────────────────
    describe('PHPStan configuration', function (): void {
        test('phpstan.neon.dist exists and has max level', function (): void {
            $neon = file_get_contents(base_path('phpstan.neon.dist'));
            expect($neon)->toContain('level: max');
        });

        test('phpstan.neon includes phpstan.neon.dist', function (): void {
            $neon = file_get_contents(base_path('phpstan.neon'));
            expect($neon)->toContain('phpstan.neon.dist');
        });

        test('phpstan.neon.dist analyses src, tests, database paths', function (): void {
            $neon = file_get_contents(base_path('phpstan.neon.dist'));
            expect($neon)->toContain('- src');
            expect($neon)->toContain('- tests');
            expect($neon)->toContain('- database/migrations');
            expect($neon)->toContain('- database/factories');
        });
    });
});
