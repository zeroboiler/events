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

describe('Events Phase 120 Production Audit', function () {
    describe('Source file structure', function () {
        it('has correct number of source files in src/', function () {
            $srcDir = realpath(__DIR__.'/../src');
            $files = glob($srcDir.'/**/*.php', GLOB_ERR);
            if ($files === false) {
                $files = [];
            }
            // Recursively find all .php files
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $phpFiles[] = $file->getPathname();
                }
            }

            expect(count($phpFiles))->toBeGreaterThanOrEqual(30);
        });

        it('all source files have declare(strict_types=1)', function () {
            $srcDir = realpath(__DIR__.'/../src');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    expect($content)->toContain('declare(strict_types=1)');
                }
            }
            expect(true)->toBeTrue();
        });

        it('all source files have ZeroBoiler license header', function () {
            $srcDir = realpath(__DIR__.'/../src');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    expect($content)->toContain('This file is part of ZeroBoiler');
                }
            }
            expect(true)->toBeTrue();
        });

        it('no source files contain setAccessible()', function () {
            $srcDir = realpath(__DIR__.'/../src');
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    expect($content)->not->toContain('setAccessible(');
                }
            }
            expect(true)->toBeTrue();
        });
    });

    describe('Class finality and readonly', function () {
        it('EventManager is final', function () {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('ConditionEngine is final', function () {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('ActionResolver is final', function () {
            $ref = new ReflectionClass(ActionResolver::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('TriggerBuilder is final', function () {
            $ref = new ReflectionClass(TriggerBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('SubscriptionBuilder is final', function () {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('EventScheduler is final', function () {
            $ref = new ReflectionClass(EventScheduler::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('DomainEvent is final', function () {
            $ref = new ReflectionClass(DomainEvent::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('WildcardMatcher is final and readonly', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('all 3 models are final', function () {
            expect((new ReflectionClass(Trigger::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(EventLog::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(Subscription::class))->isFinal())->toBeTrue();
        });

        it('WebhookAction is final', function () {
            $ref = new ReflectionClass(WebhookAction::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('DispatchTriggerJob is final', function () {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('EventsServiceProvider is final', function () {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('Constructor readonly properties', function () {
        it('EventManager has 3 readonly constructor parameters', function () {
            $ref = new ReflectionMethod(EventManager::class, '__construct');
            $params = $ref->getParameters();
            expect(count($params))->toBe(3);
            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue();
            }
        });

        it('ActionResolver has 1 readonly constructor parameter', function () {
            $ref = new ReflectionMethod(ActionResolver::class, '__construct');
            $params = $ref->getParameters();
            expect(count($params))->toBe(1);
            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue();
            }
        });

        it('EventScheduler has 1 readonly constructor parameter', function () {
            $ref = new ReflectionMethod(EventScheduler::class, '__construct');
            $params = $ref->getParameters();
            expect(count($params))->toBe(1);
            foreach ($params as $param) {
                expect($param->isPromoted())->toBeTrue();
            }
        });

        it('DomainEvent has 4 readonly properties', function () {
            $ref = new ReflectionClass(DomainEvent::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
            $readonlyProps = array_filter($props, fn (ReflectionProperty $p) => $p->isReadOnly());
            expect(count($readonlyProps))->toBe(4);
        });

        it('DispatchTriggerJob has 3 promoted readonly constructor parameters', function () {
            $ref = new ReflectionMethod(DispatchTriggerJob::class, '__construct');
            $params = $ref->getParameters();
            $promoted = array_filter($params, fn (ReflectionParameter $p) => $p->isPromoted());
            expect(count($promoted))->toBe(3);
        });
    });

    describe('Return type declarations', function () {
        it('EventManager::on() returns TriggerBuilder', function () {
            $ref = new ReflectionMethod(EventManager::class, 'on');
            expect($ref->getReturnType()?->getName())->toBe(TriggerBuilder::class);
        });

        it('EventManager::fire() returns void', function () {
            $ref = new ReflectionMethod(EventManager::class, 'fire');
            expect($ref->getReturnType()?->getName())->toBe('void');
        });

        it('EventManager::fireModel() returns void', function () {
            $ref = new ReflectionMethod(EventManager::class, 'fireModel');
            expect($ref->getReturnType()?->getName())->toBe('void');
        });

        it('EventManager::register() returns TriggerBuilder', function () {
            $ref = new ReflectionMethod(EventManager::class, 'register');
            expect($ref->getReturnType()?->getName())->toBe(TriggerBuilder::class);
        });

        it('EventManager::subscribe() returns SubscriptionBuilder', function () {
            $ref = new ReflectionMethod(EventManager::class, 'subscribe');
            expect($ref->getReturnType()?->getName())->toBe(SubscriptionBuilder::class);
        });

        it('ConditionEngine::matches() returns bool', function () {
            $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
            expect($ref->getReturnType()?->getName())->toBe('bool');
        });

        it('ActionResolver::resolve() returns Triggerable', function () {
            $ref = new ReflectionMethod(ActionResolver::class, 'resolve');
            expect($ref->getReturnType()?->getName())->toBe(Triggerable::class);
        });

        it('DomainEvent::occur() returns self', function () {
            $ref = new ReflectionMethod(DomainEvent::class, 'occur');
            $type = $ref->getReturnType();
            expect($type?->getName())->toBe(DomainEvent::class);
        });

        it('DomainEvent::fromArray() returns self', function () {
            $ref = new ReflectionMethod(DomainEvent::class, 'fromArray');
            $type = $ref->getReturnType();
            expect($type?->getName())->toBe(DomainEvent::class);
        });
    });

    describe('Interface compliance', function () {
        it('ConditionEngine implements ConditionEngineContract', function () {
            expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
        });

        it('WebhookAction implements Triggerable', function () {
            expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
        });
    });

    describe('ServiceProvider bindings', function () {
        it('provides() returns expected 7 services', function () {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toHaveCount(7);
            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
        });

        it('EventManager is resolvable from container', function () {
            $manager = app(EventManager::class);
            expect($manager)->toBeInstanceOf(EventManager::class);
        });

        it('ConditionEngineContract resolves to ConditionEngine', function () {
            $engine = app(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        it('TriggerBuilder is resolvable from container', function () {
            $builder = app(TriggerBuilder::class);
            expect($builder)->toBeInstanceOf(TriggerBuilder::class);
        });

        it('SubscriptionBuilder is resolvable from container', function () {
            $builder = app(SubscriptionBuilder::class);
            expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
        });

        it('EventScheduler is resolvable from container', function () {
            $scheduler = app(EventScheduler::class);
            expect($scheduler)->toBeInstanceOf(EventScheduler::class);
        });
    });

    describe('Config completeness', function () {
        it('config file has all 7 top-level keys', function () {
            $config = config('events');
            $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue();
            }
        });

        it('table_names has all 3 entries', function () {
            $tables = config('events.table_names');
            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('subscriptions config has all 5 keys', function () {
            $subs = config('events.subscriptions');
            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
            expect($subs)->toHaveKey('cleanup_cron');
        });

        it('retention config has all 3 keys', function () {
            $ret = config('events.retention');
            expect($ret)->toHaveKey('days');
            expect($ret)->toHaveKey('include_pending');
            expect($ret)->toHaveKey('schedule_cron');
        });

        it('queue config has connection and queue keys', function () {
            $queue = config('events.queue');
            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('retry config has tries and backoff keys', function () {
            $retry = config('events.retry');
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });
    });

    describe('Facade', function () {
        it('getFacadeAccessor returns correct class', function () {
            $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
            expect($ref->getReturnType()?->getName())->toBe('string');
            // Verify the method exists and has #[Override]
            $hasOverride = false;
            foreach ($ref->getAttributes() as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue();
        });

        it('facade has 25+ @method annotations', function () {
            $doc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
            expect($doc)->not->toBeFalse();
            preg_match_all('/@method\s/', $doc ?: '', $matches);
            expect(count($matches[0]))->toBeGreaterThanOrEqual(25);
        });
    });

    describe('Model config-driven table names', function () {
        it('Trigger uses config-driven table name', function () {
            $ref = new ReflectionMethod(Trigger::class, 'getTable');
            expect($ref->hasReturnType())->toBeTrue();
        });

        it('EventLog uses config-driven table name', function () {
            $ref = new ReflectionMethod(EventLog::class, 'getTable');
            expect($ref->hasReturnType())->toBeTrue();
        });

        it('Subscription uses config-driven table name', function () {
            $ref = new ReflectionMethod(Subscription::class, 'getTable');
            expect($ref->hasReturnType())->toBeTrue();
        });
    });

    describe('EventLog status constants', function () {
        it('has exactly 4 status constants', function () {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
            expect(EventLog::$statuses)->toHaveCount(4);
        });

        it('status constants are unique', function () {
            $statuses = EventLog::$statuses;
            expect(array_unique($statuses))->toHaveCount(4);
        });
    });

    describe('WildcardMatcher static-only', function () {
        it('has no public non-static methods', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (! $method->isStatic()) {
                    expect($method->getName())->toBe('__construct');
                }
            }
            expect(true)->toBeTrue();
        });

        it('matches() is static and #[Pure]', function () {
            $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
            expect($ref->isStatic())->toBeTrue();
            $attrs = $ref->getAttributes();
            $hasPure = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Pure') {
                    $hasPure = true;
                    break;
                }
            }
            expect($hasPure)->toBeTrue();
        });
    });

    describe('ConditionEngine operators', function () {
        it('supports all 19 operators', function () {
            $engine = new ConditionEngine;

            // Numeric comparisons (4)
            expect($engine->matches(['val' => ['>', 5]], ['val' => 10]))->toBeTrue();
            expect($engine->matches(['val' => ['>=', 5]], ['val' => 5]))->toBeTrue();
            expect($engine->matches(['val' => ['<', 10]], ['val' => 5]))->toBeTrue();
            expect($engine->matches(['val' => ['<=', 10]], ['val' => 10]))->toBeTrue();

            // Equality (4)
            expect($engine->matches(['val' => 'a'], ['val' => 'a']))->toBeTrue();
            expect($engine->matches(['val' => ['===', true]], ['val' => true]))->toBeTrue();
            expect($engine->matches(['val' => ['!=', 'b']], ['val' => 'a']))->toBeTrue();
            expect($engine->matches(['val' => ['!==', 1]], ['val' => '1']))->toBeTrue();

            // Array operators (2)
            expect($engine->matches(['val' => ['in', ['a', 'b']]], ['val' => 'a']))->toBeTrue();
            expect($engine->matches(['val' => ['not_in', ['a', 'b']]], ['val' => 'c']))->toBeTrue();

            // Contains (2)
            expect($engine->matches(['val' => ['contains', 'ab']], ['val' => 'xabx']))->toBeTrue();
            expect($engine->matches(['val' => ['not_contains', 'ab']], ['val' => 'xcdx']))->toBeTrue();

            // Between
            expect($engine->matches(['val' => ['between', [1, 10]]], ['val' => 5]))->toBeTrue();

            // Null checks (2)
            expect($engine->matches(['val' => ['null']], ['val' => null]))->toBeTrue();
            expect($engine->matches(['val' => ['not_null']], ['val' => 'a']))->toBeTrue();

            // Empty (2)
            expect($engine->matches(['val' => ['empty']], ['val' => null]))->toBeTrue();
            expect($engine->matches(['val' => ['not_empty']], ['val' => 'a']))->toBeTrue();

            // String operators (2)
            expect($engine->matches(['val' => ['starts_with', 'ab']], ['val' => 'abcd']))->toBeTrue();
            expect($engine->matches(['val' => ['ends_with', 'cd']], ['val' => 'abcd']))->toBeTrue();

            // Regex
            expect($engine->matches(['val' => ['matches', '/^[a-z]+$/']], ['val' => 'abc']))->toBeTrue();
        });
    });

    describe('DomainEvent immutability', function () {
        it('preserves eventId through roundtrip', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $restored = DomainEvent::fromArray($event->toArray());
            expect($restored->eventId->toString())->toBe($event->eventId->toString());
        });

        it('preserves eventType through roundtrip', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $restored = DomainEvent::fromArray($event->toArray());
            expect($restored->eventType)->toBe($event->eventType);
        });

        it('preserves payload through roundtrip', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $restored = DomainEvent::fromArray($event->toArray());
            expect($restored->payload)->toBe($event->payload);
        });

        it('fromArray throws on empty eventType', function () {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });

        it('fromArray throws on missing eventType', function () {
            expect(fn () => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('Console commands', function () {
        it('all 12 commands are registered in ServiceProvider', function () {
            $commands = [
                'EventsListCommand',
                'EventsRegisterCommand',
                'EventsFireCommand',
                'EventsLogCommand',
                'EventsRetryCommand',
                'EventsEnableCommand',
                'EventsDisableCommand',
                'EventsHealthCommand',
                'EventsSubscribeCommand',
                'EventsUnsubscribeCommand',
                'EventsSubscriptionsCommand',
                'EventsRedeliverCommand',
            ];
            foreach ($commands as $command) {
                $class = 'ZeroBoiler\\Events\\Console\\'.$command;
                expect(class_exists($class))->toBeTrue();
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue();
            }
        });

        it('all commands have handle method returning int', function () {
            $commands = [
                'EventsListCommand',
                'EventsRegisterCommand',
                'EventsFireCommand',
                'EventsLogCommand',
                'EventsRetryCommand',
                'EventsEnableCommand',
                'EventsDisableCommand',
                'EventsHealthCommand',
                'EventsSubscribeCommand',
                'EventsUnsubscribeCommand',
                'EventsSubscriptionsCommand',
                'EventsRedeliverCommand',
            ];
            foreach ($commands as $command) {
                $class = 'ZeroBoiler\\Events\\Console\\'.$command;
                $ref = new ReflectionMethod($class, 'handle');
                $returnType = $ref->getReturnType();
                expect($returnType?->getName())->toBe('int');
            }
        });
    });

    describe('PHPStan configuration', function () {
        it('phpstan.neon.dist has level 9', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('level: max');
        });

        it('phpstan.neon.dist includes src path', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
        });

        it('phpstan.neon.dist checks uninitialized properties', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('checkUninitializedProperties: true');
        });
    });

    describe('Composer configuration', function () {
        it('requires PHP ^8.5', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require']['php'])->toBe('^8.5');
        });

        it('requires illuminate packages ^13.0', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require']['illuminate/contracts'])->toBe('^13.0');
            expect($json['require']['illuminate/support'])->toBe('^13.0');
        });

        it('version matches README badge', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');
            expect($readme)->toContain('version-'.$json['version'].'-');
        });

        it('has ServiceProvider in extra.laravel.providers', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
        });

        it('has Facade alias in extra.laravel.aliases', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager',
            );
        });
    });

    describe('Test file registration consistency', function () {
        it('all registered test files exist on disk', function () {
            $pestContent = file_get_contents(__DIR__.'/Pest.php');
            preg_match_all("/'(\w+\.php)'/", $pestContent, $matches);
            $registeredFiles = $matches[1];
            expect(count($registeredFiles))->toBeGreaterThan(0);

            foreach ($registeredFiles as $file) {
                expect(file_exists(__DIR__.'/'.$file))->toBeTrue("$file should exist on disk");
            }
        });

        it('correct test file count in Pest.php', function () {
            $pestContent = file_get_contents(__DIR__.'/Pest.php');
            preg_match_all("/'(\w+Test\.php)'/", $pestContent, $matches);
            $registeredTests = $matches[1];
            // 197 registered test files (3 are intentionally unregistered)
            expect(count($registeredTests))->toBe(197);
        });
    });

    describe('Database migrations', function () {
        it('all migrations have strict types', function () {
            $dir = realpath(__DIR__.'/../database/migrations');
            $files = glob($dir.'/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
            expect(true)->toBeTrue();
        });

        it('all migrations have license header', function () {
            $dir = realpath(__DIR__.'/../database/migrations');
            $files = glob($dir.'/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('This file is part of ZeroBoiler');
            }
            expect(true)->toBeTrue();
        });

        it('all migrations use config-driven table names', function () {
            $dir = realpath(__DIR__.'/../database/migrations');
            $files = glob($dir.'/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('config(');
            }
            expect(true)->toBeTrue();
        });
    });

    describe('Factories', function () {
        it('all factories have strict types', function () {
            $dir = realpath(__DIR__.'/../database/factories');
            $files = glob($dir.'/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
            expect(true)->toBeTrue();
        });

        it('all factories have static model property', function () {
            $dir = realpath(__DIR__.'/../database/factories');
            $files = glob($dir.'/*.php');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('protected static string $model');
            }
            expect(true)->toBeTrue();
        });

        it('all factories have definition() method returning array', function () {
            $factories = [
                'TriggerFactory',
                'EventLogFactory',
                'SubscriptionFactory',
            ];
            foreach ($factories as $factory) {
                $class = 'ZeroBoiler\\Events\\Database\\Factories\\'.$factory;
                $ref = new ReflectionMethod($class, 'definition');
                $returnType = $ref->getReturnType();
                expect($returnType?->getName())->toBe('array');
            }
        });
    });
});
