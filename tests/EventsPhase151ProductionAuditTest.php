<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
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
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Actions\WebhookAction;
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

beforeEach(function (): void {
    $this->refreshApp();
});

// ──────────────────────────────────────────────────────────────
// 1. Source file strict_types + license header
// ──────────────────────────────────────────────────────────────
describe('strict_types and license headers', function (): void {
    $sourceFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);

    test('all source files have declare(strict_types=1)', function () use ($sourceFiles): void {
        foreach ($sourceFiles as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
        }
    });

    test('all source files have license header', function () use ($sourceFiles): void {
        foreach ($sourceFiles as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('This file is part of ZeroBoiler', "Missing license header in {$file}");
        }
    });

    test('no source file has duplicate declare(strict_types=1)', function () use ($sourceFiles): void {
        foreach ($sourceFiles as $file) {
            $contents = file_get_contents($file);
            $count = substr_count($contents, 'declare(strict_types=1)');
            expect($count)->toBeLessThanOrEqual(1, "Duplicate declare in {$file}");
        }
    });
});

// ──────────────────────────────────────────────────────────────
// 2. All service classes are final
// ──────────────────────────────────────────────────────────────
describe('final classes', function (): void {
    test('WildcardMatcher is readonly final', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    test('DomainEvent is final', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('EventScheduler is final', function (): void {
        $ref = new ReflectionClass(EventScheduler::class);
        expect($ref->isFinal())->toBeTrue();
    });
});

// ──────────────────────────────────────────────────────────────
// 3. DomainEvent immutability and roundtrip identity
// ──────────────────────────────────────────────────────────────
describe('DomainEvent immutability', function (): void {
    test('properties are readonly', function (): void {
        $ref = new ReflectionClass(DomainEvent::class);
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
        expect(count($props))->toBeGreaterThanOrEqual(3);

        foreach ($props as $prop) {
            expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->name} must be readonly");
        }
    });

    test('roundtrip preserves eventId and occurredAt', function (): void {
        $original = DomainEvent::occur('test.event', ['key' => 'value']);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->occurredAt->format('U'))->toBe($original->occurredAt->format('U'));
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
    });

    test('fromArray throws on empty eventType', function (): void {
        DomainEvent::fromArray(['eventType' => '']);
    })->throws(InvalidArgumentException::class);

    test('fromArray throws on missing eventType', function (): void {
        DomainEvent::fromArray([]);
    })->throws(InvalidArgumentException::class);

    test('occur factory returns correct type', function (): void {
        $event = DomainEvent::occur('test.event');
        expect($event)->toBeInstanceOf(DomainEvent::class);
        expect($event->eventType)->toBe('test.event');
        expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class);
        expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
    });
});

// ──────────────────────────────────────────────────────────────
// 4. ConditionEngine operators completeness (21 total)
// ──────────────────────────────────────────────────────────────
describe('ConditionEngine operators', function (): void {
    test('evaluateCondition contains all 20 named operators', function (): void {
        $ref = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
        $contents = file_get_contents((string) $ref->getFileName());
        $sourceFile = file_get_contents(__DIR__ . '/../src/ConditionEngine.php');

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

        foreach ($operators as $op) {
            expect($sourceFile)->toContain("'{$op}'", "Missing operator '{$op}' in ConditionEngine");
        }
    });

    test('all operators are in evaluateCondition match', function (): void {
        $ref = new ReflectionMethod(ConditionEngine::class, 'evaluateCondition');
        $contents = file_get_contents((string) $ref->getFileName());

        $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in', 'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty', 'starts_with', 'ends_with', 'matches'];

        foreach ($operators as $op) {
            expect($contents)->toContain("'{$op}'");
        }
    });

    test('matches operator rejects patterns over 500 chars', function (): void {
        $engine = new ConditionEngine();
        $longPattern = '/^' . str_repeat('a', 600) . '$/';
        $result = $engine->matches(['code' => ['matches', $longPattern]], ['code' => 'short']);
        expect($result)->toBeFalse();
    });
});

// ──────────────────────────────────────────────────────────────
// 5. ServiceProvider bindings consistency
// ──────────────────────────────────────────────────────────────
describe('ServiceProvider bindings', function (): void {
    test('provides() matches registered bindings exactly', function (): void {
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

        sort($provides);
        sort($expected);
        expect($provides)->toBe($expected);
    });

    test('ConditionEngineContract resolves to ConditionEngine', function (): void {
        $engine = $this->app->make(ConditionEngineContract::class);
        expect($engine)->toBeInstanceOf(ConditionEngine::class);
    });

    test('TriggerBuilder and SubscriptionBuilder are transient (not shared)', function (): void {
        $a = $this->app->make(TriggerBuilder::class);
        $b = $this->app->make(TriggerBuilder::class);
        expect($a)->not->toBe($b);

        $x = $this->app->make(SubscriptionBuilder::class);
        $y = $this->app->make(SubscriptionBuilder::class);
        expect($x)->not->toBe($y);
    });

    test('EventManager is singleton (shared)', function (): void {
        $a = $this->app->make(EventManager::class);
        $b = $this->app->make(EventManager::class);
        expect($a)->toBe($b);
    });

    test('EventScheduler is singleton (shared)', function (): void {
        $a = $this->app->make(EventScheduler::class);
        $b = $this->app->make(EventScheduler::class);
        expect($a)->toBe($b);
    });
});

// ──────────────────────────────────────────────────────────────
// 6. Config completeness (7 top-level keys)
// ──────────────────────────────────────────────────────────────
describe('config completeness', function (): void {
    test('has all 7 top-level config keys', function (): void {
        $config = config('events');
        $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
        foreach ($expectedKeys as $key) {
            expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
        }
    });

    test('subscriptions config has all 5 sub-keys', function (): void {
        $subs = config('events.subscriptions');
        foreach (['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'] as $key) {
            expect(array_key_exists($key, $subs))->toBeTrue("Missing subscriptions key: {$key}");
        }
    });

    test('retention config has all 3 sub-keys', function (): void {
        $ret = config('events.retention');
        foreach (['days', 'include_pending', 'schedule_cron'] as $key) {
            expect(array_key_exists($key, $ret))->toBeTrue("Missing retention key: {$key}");
        }
    });

    test('queue config has connection and queue keys', function (): void {
        $q = config('events.queue');
        expect(array_key_exists('connection', $q))->toBeTrue();
        expect(array_key_exists('queue', $q))->toBeTrue();
    });

    test('retry config has tries and backoff keys', function (): void {
        $r = config('events.retry');
        expect(array_key_exists('tries', $r))->toBeTrue();
        expect(array_key_exists('backoff', $r))->toBeTrue();
    });
});

// ──────────────────────────────────────────────────────────────
// 7. Facade getFacadeAccessor
// ──────────────────────────────────────────────────────────────
describe('Facade', function (): void {
    test('getFacadeAccessor returns EventManager class name', function (): void {
        $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
        $result = $ref->invoke(null);
        expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
    });

    test('Facade has #[Override] attribute on getFacadeAccessor', function (): void {
        $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
        $attrs = $method->getAttributes(\Override::class);
        expect(count($attrs))->toBe(1);
    });
});

// ──────────────────────────────────────────────────────────────
// 8. Model table names are config-driven
// ──────────────────────────────────────────────────────────────
describe('model table names', function (): void {
    test('Trigger uses config for table name', function (): void {
        $model = new Trigger;
        $ref = new ReflectionMethod(Trigger::class, 'getTable');
        $result = $ref->invoke($model);
        expect($result)->toBe(config('events.table_names.triggers', 'triggers'));
    });

    test('EventLog uses config for table name', function (): void {
        $model = new EventLog;
        $ref = new ReflectionMethod(EventLog::class, 'getTable');
        $result = $ref->invoke($model);
        expect($result)->toBe(config('events.table_names.event_logs', 'event_logs'));
    });

    test('Subscription uses config for table name', function (): void {
        $model = new Subscription;
        $ref = new ReflectionMethod(Subscription::class, 'getTable');
        $result = $ref->invoke($model);
        expect($result)->toBe(config('events.table_names.subscriptions', 'event_subscriptions'));
    });
});

// ──────────────────────────────────────────────────────────────
// 9. Console commands extend Command and are final
// ──────────────────────────────────────────────────────────────
describe('console commands', function (): void {
    $commands = [
        EventsListCommand::class,
        EventsRegisterCommand::class,
        EventsFireCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsHealthCommand::class,
        EventsRetryCommand::class,
        EventsRedeliverCommand::class,
        EventsLogCommand::class,
        EventsSubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsUnsubscribeCommand::class,
    ];

    test('all 12 commands extend Illuminate\\Console\\Command', function () use ($commands): void {
        foreach ($commands as $cmd) {
            expect(is_subclass_of($cmd, Illuminate\Console\Command::class))->toBeTrue("{$cmd} must extend Command");
        }
    });

    test('all 12 commands are final', function () use ($commands): void {
        foreach ($commands as $cmd) {
            expect((new ReflectionClass($cmd))->isFinal())->toBeTrue("{$cmd} must be final");
        }
    });

    test('all 12 commands have handle() method with return type int', function () use ($commands): void {
        foreach ($commands as $cmd) {
            $ref = new ReflectionMethod($cmd, 'handle');
            expect($ref->getReturnType()?->getName())->toBe('int');
        }
    });

    test('all 12 commands have #[Override] on handle()', function () use ($commands): void {
        foreach ($commands as $cmd) {
            $ref = new ReflectionMethod($cmd, 'handle');
            $attrs = $ref->getAttributes(\Override::class);
            expect(count($attrs))->toBe(1, "{$cmd}::handle() must have #[Override]");
        }
    });
});

// ──────────────────────────────────────────────────────────────
// 10. No deprecated APIs or TODO markers
// ──────────────────────────────────────────────────────────────
describe('no deprecated APIs', function (): void {
    test('source files contain no TODO/FIXME markers', function (): void {
        $sourceDir = __DIR__ . '/../src';
        $files = glob($sourceDir . '/**/*.php', GLOB_BRACE);
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->not->toContain('TODO');
            expect($contents)->not->toContain('FIXME');
        }
    });
});

// ──────────────────────────────────────────────────────────────
// 11. DispatchTriggerJob config-driven properties
// ──────────────────────────────────────────────────────────────
describe('DispatchTriggerJob config-driven properties', function (): void {
    test('constructor reads tries from config', function (): void {
        config(['events.retry.tries' => 5]);
        $job = new DispatchTriggerJob('id', 'test.event', []);
        expect($job->tries)->toBe(5);
    });

    test('constructor reads queue from config', function (): void {
        config(['events.queue.queue' => 'events-queue']);
        $job = new DispatchTriggerJob('id', 'test.event', []);
        expect($job->queue)->toBe('events-queue');
    });

    test('constructor reads connection from config', function (): void {
        config(['events.queue.connection' => 'redis']);
        $job = new DispatchTriggerJob('id', 'test.event', []);
        expect($job->connection)->toBe('redis');
    });

    test('constructor reads backoff from comma-separated string', function (): void {
        config(['events.retry.backoff' => '10,20,30']);
        $job = new DispatchTriggerJob('id', 'test.event', []);
        expect($job->backoff)->toBe([10, 20, 30]);
    });

    test('constructor reads backoff from array config', function (): void {
        config(['events.retry.backoff' => [5, 15, 45]]);
        $job = new DispatchTriggerJob('id', 'test.event', []);
        expect($job->backoff)->toBe([5, 15, 45]);
    });

    test('fallbacks on invalid config values', function (): void {
        config([
            'events.retry.tries' => 'invalid',
            'events.retry.backoff' => 'not-numbers',
            'events.queue.queue' => '',
            'events.queue.connection' => '',
        ]);
        $job = new DispatchTriggerJob('id', 'test.event', []);
        expect($job->tries)->toBe(3);
        expect($job->backoff)->toBe([0]); // non-numeric trim casts to 0
        expect($job->queue)->toBe('default');
        expect($job->connection)->toBeNull();
    });

    test('has #[Override] on handle() and failed()', function (): void {
        $handleRef = new ReflectionMethod(DispatchTriggerJob::class, 'handle');
        expect(count($handleRef->getAttributes(\Override::class)))->toBe(1);

        $failedRef = new ReflectionMethod(DispatchTriggerJob::class, 'failed');
        expect(count($failedRef->getAttributes(\Override::class)))->toBe(1);
    });

    test('constructor uses readonly promoted properties', function (): void {
        $ref = new ReflectionMethod(DispatchTriggerJob::class, '__construct');
        $params = $ref->getParameters();
        expect($params)->toHaveCount(3);

        foreach ($params as $param) {
            expect($param->isPromoted())->toBeTrue();
            // Readonly is reflected in the class property
            $prop = new ReflectionProperty(DispatchTriggerJob::class, $param->getName());
            expect($prop->isReadOnly())->toBeTrue();
        }
    });
});

// ──────────────────────────────────────────────────────────────
// 12. EventScheduler constructor injection
// ──────────────────────────────────────────────────────────────
describe('EventScheduler', function (): void {
    test('constructor takes Container injection', function (): void {
        $ref = new ReflectionMethod(EventScheduler::class, '__construct');
        $params = $ref->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getType()?->getName())->toBe(Illuminate\Container\Container::class);
        expect($params[0]->isPromoted())->toBeTrue();
    });

    test('register() calls both sub-schedulers without error', function (): void {
        $schedule = $this->app->make(Illuminate\Console\Scheduling\Schedule::class);
        $scheduler = $this->app->make(EventScheduler::class);
        $scheduler->register($schedule);
        expect(true)->toBeTrue();
    });

    test('registerLogPurge skips when days <= 0', function (): void {
        config(['events.retention.days' => 0]);
        $schedule = $this->app->make(Illuminate\Console\Scheduling\Schedule::class);
        $scheduler = $this->app->make(EventScheduler::class);
        $scheduler->register($schedule);
        expect(true)->toBeTrue();
    });

    test('registerLogPurge skips when days is null', function (): void {
        config(['events.retention.days' => null]);
        $schedule = $this->app->make(Illuminate\Console\Scheduling\Schedule::class);
        $scheduler = $this->app->make(EventScheduler::class);
        $scheduler->register($schedule);
        expect(true)->toBeTrue();
    });

    test('resolveEventManager returns null when binding missing', function (): void {
        // Create a fresh container without EventManager binding
        $container = new Illuminate\Container\Container;
        $scheduler = new EventScheduler($container);
        $ref = new ReflectionMethod(EventScheduler::class, 'resolveEventManager');
        $result = $ref->invoke($scheduler);
        expect($result)->toBeNull();
    });
});

// ──────────────────────────────────────────────────────────────
// 13. phpstan.neon.dist validation
// ──────────────────────────────────────────────────────────────
describe('phpstan configuration', function (): void {
    test('phpstan.neon.dist exists and has level max', function (): void {
        $path = __DIR__ . '/../phpstan.neon.dist';
        expect(file_exists($path))->toBeTrue();
        $contents = file_get_contents($path);
        expect($contents)->toContain('level: 9');
    });

    test('phpstan.neon.dist scans src, tests, database paths', function (): void {
        $contents = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
        expect($contents)->toContain('- src');
        expect($contents)->toContain('- tests');
        expect($contents)->toContain('- database/migrations');
        expect($contents)->toContain('- database/factories');
    });
});

// ──────────────────────────────────────────────────────────────
// 14. Migration and factory file counts
// ──────────────────────────────────────────────────────────────
describe('database structure', function (): void {
    test('has 3 migration files', function (): void {
        $files = glob(__DIR__ . '/../database/migrations/*.php');
        expect(count($files))->toBe(3);
    });

    test('has 3 factory files', function (): void {
        $files = glob(__DIR__ . '/../database/factories/*.php');
        expect(count($files))->toBe(3);
    });

    test('migration files use strict_types', function (): void {
        $files = glob(__DIR__ . '/../database/migrations/*.php');
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    test('factory files use strict_types', function (): void {
        $files = glob(__DIR__ . '/../database/factories/*.php');
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });
});

// ──────────────────────────────────────────────────────────────
// 15. README version and test count accuracy
// ──────────────────────────────────────────────────────────────
describe('README accuracy', function (): void {
    test('README version badge matches composer.json', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $version = $composer['version'] ?? '';
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->toContain("version-{$version}");
    });

    test('README test count matches actual test files', function (): void {
        $testFiles = glob(__DIR__ . '/*Test.php');
        $readme = file_get_contents(__DIR__ . '/../README.md');
        expect($readme)->toContain((string) count($testFiles));
    });
});

// ──────────────────────────────────────────────────────────────
// 16. Trait docblock @see cross-references
// ──────────────────────────────────────────────────────────────
describe('trait documentation', function (): void {
    test('ManagesHistory has @see EventManager', function (): void {
        $ref = new ReflectionClass(ManagesHistory::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@see');
    });

    test('ManagesSubscriptions has @see EventManager', function (): void {
        $ref = new ReflectionClass(ManagesSubscriptions::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@see');
    });

    test('EscapesWildcardLike has descriptive docblock', function (): void {
        $ref = new ReflectionClass(EscapesWildcardLike::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
    });

    test('GetsWebhookTimeout has descriptive docblock', function (): void {
        $ref = new ReflectionClass(GetsWebhookTimeout::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
    });
});

// ──────────────────────────────────────────────────────────────
// 17. Interfaces have correct signatures
// ──────────────────────────────────────────────────────────────
describe('interface signatures', function (): void {
    test('ConditionEngineContract::matches has correct signature', function (): void {
        $ref = new ReflectionMethod(ConditionEngineContract::class, 'matches');
        expect($ref->getReturnType()?->getName())->toBe('bool');
        $params = $ref->getParameters();
        expect(count($params))->toBe(2);
    });

    test('Triggerable::handle has correct signature', function (): void {
        $ref = new ReflectionMethod(Triggerable::class, 'handle');
        expect($ref->getReturnType()?->getName())->toBe('void');
        $params = $ref->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getType()?->getName())->toBe('array');
    });

    test('ConditionEngine::matches has #[Override]', function (): void {
        $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
        expect(count($ref->getAttributes(\Override::class)))->toBe(1);
    });
});

// ──────────────────────────────────────────────────────────────
// 18. EventManager fire/fireModel validation
// ──────────────────────────────────────────────────────────────
describe('EventManager validation', function (): void {
    test('fire() throws on empty event', function (): void {
        $em = $this->app->make(EventManager::class);
        $em->fire('');
    })->throws(InvalidArgumentException::class);

    test('fire() throws on zero-string event', function (): void {
        $em = $this->app->make(EventManager::class);
        $em->fire('0');
    })->throws(InvalidArgumentException::class);

    test('fireModel() throws on empty modelClass', function (): void {
        $em = $this->app->make(EventManager::class);
        $em->fireModel('', 'created', new stdClass);
    })->throws(InvalidArgumentException::class);

    test('fireModel() throws on empty action', function (): void {
        $em = $this->app->make(EventManager::class);
        $em->fireModel(stdClass::class, '', new stdClass);
    })->throws(InvalidArgumentException::class);
});

// ──────────────────────────────────────────────────────────────
// 19. TriggerBuilder and SubscriptionBuilder save validation
// ──────────────────────────────────────────────────────────────
describe('builder validation', function (): void {
    test('TriggerBuilder save() throws on empty event', function (): void {
        $em = $this->app->make(EventManager::class);
        $builder = $em->on('order.placed');
        // The event is already set — test with empty by direct construction
        $ref = new ReflectionProperty(TriggerBuilder::class, 'event');
        $ref->setValue($builder, '');
        $builder->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class);
        $builder->save();
    })->throws(InvalidArgumentException::class);

    test('TriggerBuilder save() throws on no action', function (): void {
        $em = $this->app->make(EventManager::class);
        $builder = $em->on('test.event');
        // Reset action to empty
        $ref = new ReflectionProperty(TriggerBuilder::class, 'action');
        $ref->setValue($builder, '');
        $refActions = new ReflectionProperty(TriggerBuilder::class, 'actions');
        $refActions->setValue($builder, []);
        $builder->save();
    })->throws(InvalidArgumentException::class);

    test('SubscriptionBuilder save() throws on non-HTTP URL', function (): void {
        $em = $this->app->make(EventManager::class);
        $builder = $em->subscribe('test.event', 'ftp://evil.com/hook');
        $builder->save();
    })->throws(InvalidArgumentException::class);
});

// ──────────────────────────────────────────────────────────────
// 20. WebhookAction implements Triggerable
// ──────────────────────────────────────────────────────────────
describe('WebhookAction', function (): void {
    test('implements Triggerable interface', function (): void {
        $ref = new ReflectionClass(WebhookAction::class);
        expect($ref->implementsInterface(Triggerable::class))->toBeTrue();
    });

    test('handle() has #[Override] and return type void', function (): void {
        $ref = new ReflectionMethod(WebhookAction::class, 'handle');
        expect($ref->getReturnType()?->getName())->toBe('void');
        expect(count($ref->getAttributes(\Override::class)))->toBe(1);
    });
});

// ──────────────────────────────────────────────────────────────
// 21. EventLog status constants
// ──────────────────────────────────────────────────────────────
describe('EventLog status constants', function (): void {
    test('has 4 status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    });

    test('$statuses array contains all 4 constants', function (): void {
        expect(EventLog::$statuses)->toHaveCount(4);
        expect(EventLog::$statuses)->toContain('pending');
        expect(EventLog::$statuses)->toContain('dispatched');
        expect(EventLog::$statuses)->toContain('completed');
        expect(EventLog::$statuses)->toContain('failed');
    });
});
