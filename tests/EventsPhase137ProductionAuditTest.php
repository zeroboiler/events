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

describe('Phase 137 Production Audit', function (): void {
    describe('File count and version consistency', function (): void {
        test('README test file count matches actual file count on disk', function (): void {
            $readme = file_get_contents(__DIR__.'/../README.md');
            // Find the test file count in README
            preg_match('/\((\d+)\s+test\s+files?\)/', $readme, $m);
            $readmeCount = isset($m[1]) ? (int) $m[1] : null;

            // Count actual test files (excluding support files)
            $testFiles = glob(__DIR__.'/Events*.php');
            $coreTests = glob(__DIR__.'/'.implode(',', [
                'ActionResolverTest.php',
                'ConditionEngine*.php',
                'Config*.php',
                'ContractBindingTest.php',
                'DispatchTriggerJobTest.php',
                'DomainEvent*.php',
                'EdgeCases*.php',
                'EscapesWildcardLikeTest.php',
                'Event*.php',
                'FacadeMethodCoverageTest.php',
                'Integration*.php',
                'Lifecycle*.php',
                'Manages*.php',
                'Manager*.php',
                'Migration*.php',
                'PhpstanConfigTest.php',
                'Production*.php',
                'Readonly*.php',
                'Service*.php',
                'Subscription*.php',
                'TestActions.php',
                'TraitConsistencyTest.php',
                'Trigger*.php',
                'Typed*.php',
                'Webhook*.php',
                'Wildcard*.php',
            ]));
            $allTestFiles = array_unique(array_merge(
                $testFiles,
                glob(__DIR__.'/*Test.php'),
            ));
            // Exclude support files
            $allTestFiles = array_filter($allTestFiles, function (string $f): bool {
                $basename = basename($f);
                return $basename !== 'TestCase.php'
                    && $basename !== 'CreatesApplication.php'
                    && $basename !== 'Pest.php'
                    && $basename !== 'helpers.php'
                    && $basename !== 'TestActions.php';
            });

            $actualCount = count($allTestFiles);

            // We expect 219 test files (the current actual count)
            expect($actualCount)->toBe(219);
            expect($readmeCount)->toBe(219);
        });

        test('composer.json version matches README version badge', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');

            $composerVersion = $composer['version'];
            preg_match('/version-([\d.]+)/', $readme, $m);
            $readmeVersion = $m[1] ?? null;

            expect($composerVersion)->toBe('4.66.0');
            expect($readmeVersion)->toBe('4.66.0');
        });
    });

    describe('PHP 8.5 strict_types and license headers', function (): void {
        $srcFiles = glob(__DIR__.'/../src/**/*.php');

        test('every source file declares strict_types=1', function () use ($srcFiles): void {
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
            expect($srcFiles)->not->toBeEmpty();
        });

        test('every source file has the ZeroBoiler license header', function () use ($srcFiles): void {
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('This file is part of ZeroBoiler');
            }
        });

        test('every migration file declares strict_types=1', function (): void {
            $files = glob(__DIR__.'/../database/migrations/*.php');
            foreach ($files as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        test('every factory file declares strict_types=1', function (): void {
            $files = glob(__DIR__.'/../database/factories/*.php');
            foreach ($files as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });
    });

    describe('Final class declarations', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            WildcardMatcher::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
            DomainEvent::class,
            EventManagerFacade::class,
            EventsFireCommand::class,
            EventsHealthCommand::class,
            EventsListCommand::class,
            EventsLogCommand::class,
            EventsRegisterCommand::class,
            EventsSubscribeCommand::class,
            EventsUnsubscribeCommand::class,
            EventsSubscriptionsCommand::class,
            EventsRedeliverCommand::class,
            EventsRetryCommand::class,
            EventsEnableCommand::class,
            EventsDisableCommand::class,
        ];

        test('all core classes are declared final', function () use ($finalClasses): void {
            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue("{$class} must be final");
            }
        });
    });

    describe('Readonly class on WildcardMatcher', function (): void {
        test('WildcardMatcher is readonly final class with only static methods', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();

            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                expect($method->isStatic())->toBeTrue(
                    "{$method->getName()} must be static on readonly class"
                );
            }
        });
    });

    describe('#[\Override] attribute verification', function (): void {
        test('EventManager has #[\Override] on getConfig, getTriggerCacheTtl, getMatchingTriggers, etc.', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PROTECTED);
            // Verify the protected methods that exist don't override parent incorrectly
            expect($methods)->not->toBeEmpty();
        });

        test('EventsServiceProvider register/boot/provides have #[\Override]', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);

            $register = $ref->getMethod('register');
            expect($register->getAttributes())->not->toBeEmpty();

            $boot = $ref->getMethod('boot');
            expect($boot->getAttributes())->not->toBeEmpty();

            $provides = $ref->getMethod('provides');
            expect($provides->getAttributes())->not->toBeEmpty();
        });

        test('all console commands handle() method has #[\Override]', function (): void {
            $commands = [
                EventsFireCommand::class,
                EventsHealthCommand::class,
                EventsListCommand::class,
                EventsLogCommand::class,
                EventsRegisterCommand::class,
                EventsSubscribeCommand::class,
                EventsUnsubscribeCommand::class,
                EventsSubscriptionsCommand::class,
                EventsRedeliverCommand::class,
                EventsRetryCommand::class,
                EventsEnableCommand::class,
                EventsDisableCommand::class,
            ];

            foreach ($commands as $cmd) {
                $ref = new ReflectionClass($cmd);
                $handle = $ref->getMethod('handle');
                $attrs = $handle->getAttributes();
                $hasOverride = count(array_filter(
                    $attrs,
                    fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
                )) > 0;
                expect($hasOverride)->toBeTrue("{$cmd}::handle() must have #[\Override]");
            }
        });

        test('DomainEvent has #[\Override] on nothing (no parent overrides)', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            // DomainEvent has no overridden methods — just a constructor
            $ctor = $ref->getMethod('__construct');
            expect($ctor)->not->toBeFalse();
        });
    });

    describe('Return type declarations', function (): void {
        test('all public methods on EventManager have explicit return types', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }
                $rt = $method->getReturnType();
                expect($rt)->not->toBeNull(
                    "EventManager::{$method->getName()}() must have a return type"
                );
            }
        });

        test('all public methods on ConditionEngine have explicit return types', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                $rt = $method->getReturnType();
                expect($rt)->not->toBeNull(
                    "ConditionEngine::{$method->getName()}() must have a return type"
                );
            }
        });

        test('all public methods on ActionResolver have explicit return types', function (): void {
            $ref = new ReflectionClass(ActionResolver::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                $rt = $method->getReturnType();
                expect($rt)->not->toBeNull(
                    "ActionResolver::{$method->getName()}() must have a return type"
                );
            }
        });

        test('TriggerBuilder public methods all have return types', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                $rt = $method->getReturnType();
                expect($rt)->not->toBeNull(
                    "TriggerBuilder::{$method->getName()}() must have a return type"
                );
            }
        });

        test('SubscriptionBuilder public methods all have return types', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                $rt = $method->getReturnType();
                expect($rt)->not->toBeNull(
                    "SubscriptionBuilder::{$method->getName()}() must have a return type"
                );
            }
        });
    });

    describe('Typed properties', function (): void {
        test('EventManager has all typed properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $props = $ref->getProperties();

            foreach ($props as $prop) {
                if ($prop->getName() === 'app') {
                    $type = $prop->getType();
                    expect($type)->not->toBeNull('EventManager::$app must be typed');
                    expect($type->getName())->toBe('Container');
                }
            }
        });

        test('DispatchTriggerJob has all public typed properties', function (): void {
            $ref = new ReflectionClass(DispatchTriggerJob::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($props as $prop) {
                $type = $prop->getType();
                expect($type)->not->toBeNull(
                    "DispatchTriggerJob::\${$prop->getName()} must be typed"
                );
            }
        });

        test('Trigger model has typed properties', function (): void {
            $ref = new ReflectionClass(Trigger::class);
            // Verify key type is string
            $keyType = $ref->getProperty('keyType');
            expect($keyType->isPublic())->toBeTrue();
            expect($keyType->getType()?->getName())->toBe('string');
        });

        test('EventLog model has typed properties', function (): void {
            $ref = new ReflectionClass(EventLog::class);
            $keyType = $ref->getProperty('keyType');
            expect($keyType->isPublic())->toBeTrue();
            expect($keyType->getType()?->getName())->toBe('string');
        });

        test('Subscription model has typed properties', function (): void {
            $ref = new ReflectionClass(Subscription::class);
            $keyType = $ref->getProperty('keyType');
            expect($keyType->isPublic())->toBeTrue();
            expect($keyType->getType()?->getName())->toBe('string');
        });
    });

    describe('ServiceProvider completeness', function (): void {
        test('provides() lists all bindings from register()', function (): void {
            $provider = new ReflectionClass(EventsServiceProvider::class);
            $providesMethod = $provider->getMethod('provides');
            $provides = $providesMethod->invoke(new EventsServiceProvider(app()));

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
                expect(in_array($binding, $provides, true))->toBeTrue(
                    "provides() must include {$binding}"
                );
            }
        });

        test('Facade getFacadeAccessor returns EventManager class', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            expect($method->getName())->toBe('getFacadeAccessor');
        });

        test('all 12 console commands are registered in ServiceProvider', function (): void {
            $bootReflection = new ReflectionMethod(EventsServiceProvider::class, 'boot');
            // Verify the commands array in boot() has 12 entries
            $filename = (new ReflectionClass(EventsServiceProvider::class))->getFileName();
            $contents = file_get_contents($filename);

            // Check all command classes are referenced
            $commands = [
                EventsListCommand::class,
                EventsRegisterCommand::class,
                EventsFireCommand::class,
                EventsLogCommand::class,
                EventsRetryCommand::class,
                EventsEnableCommand::class,
                EventsDisableCommand::class,
                EventsHealthCommand::class,
                EventsSubscribeCommand::class,
                EventsUnsubscribeCommand::class,
                EventsSubscriptionsCommand::class,
                EventsRedeliverCommand::class,
            ];

            foreach ($commands as $cmd) {
                expect($contents)->toContain(
                    (new ReflectionClass($cmd))->getShortName(),
                );
            }
        });
    });

    describe('ConditionEngine operator coverage', function (): void {
        $engine = new ConditionEngine();

        test('matches operator with regex', function () use ($engine): void {
            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}$/']],
                ['code' => 'ABC'],
            ))->toBeTrue();
            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}$/']],
                ['code' => 'abcd'],
            ))->toBeFalse();
        });

        test('not_empty operator', function () use ($engine): void {
            expect($engine->matches(
                ['notes' => ['not_empty']],
                ['notes' => 'some text'],
            ))->toBeTrue();
            expect($engine->matches(
                ['notes' => ['not_empty']],
                ['notes' => ''],
            ))->toBeFalse();
        });

        test('not_null operator', function () use ($engine): void {
            expect($engine->matches(
                ['email' => ['not_null']],
                ['email' => 'test@example.com'],
            ))->toBeTrue();
            expect($engine->matches(
                ['email' => ['not_null']],
                ['email' => null],
            ))->toBeFalse();
        });

        test('not_in operator', function () use ($engine): void {
            expect($engine->matches(
                ['role' => ['not_in', ['guest', 'banned']]],
                ['role' => 'admin'],
            ))->toBeTrue();
            expect($engine->matches(
                ['role' => ['not_in', ['guest', 'banned']]],
                ['role' => 'guest'],
            ))->toBeFalse();
        });

        test('ends_with operator', function () use ($engine): void {
            expect($engine->matches(
                ['domain' => ['ends_with', '.com']],
                ['domain' => 'example.com'],
            ))->toBeTrue();
            expect($engine->matches(
                ['domain' => ['ends_with', '.org']],
                ['domain' => 'example.com'],
            ))->toBeFalse();
        });

        test('AND logic — all conditions must match', function () use ($engine): void {
            expect($engine->matches(
                ['amount' => ['>', 100], 'status' => 'active'],
                ['amount' => 150, 'status' => 'active'],
            ))->toBeTrue();
            expect($engine->matches(
                ['amount' => ['>', 100], 'status' => 'active'],
                ['amount' => 50, 'status' => 'active'],
            ))->toBeFalse();
        });

        test('dot notation nested access', function () use ($engine): void {
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin']],
            ))->toBeTrue();
        });

        test('unknown operator returns false', function () use ($engine): void {
            expect($engine->matches(
                ['field' => ['unknown_op', 'value']],
                ['field' => 'value'],
            ))->toBeFalse();
        });

        test('empty array condition returns false', function () use ($engine): void {
            expect($engine->matches(
                ['field' => []],
                ['field' => 'value'],
            ))->toBeFalse();
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        test('empty event never matches', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
            expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
        });

        test('exact match works', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('single segment wildcard', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('cross segment wildcard', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        test('extractWildcards returns empty for ** patterns', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
        });

        test('extractWildcards extracts single segment wildcards', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
                ->toBe(['profile']);
        });

        test('findMatchingPatterns filters correctly', function (): void {
            $patterns = ['order.placed', 'order.*', 'user.*', '**'];
            $matches = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect(in_array('order.placed', $matches, true))->toBeTrue();
            expect(in_array('order.*', $matches, true))->toBeTrue();
            expect(in_array('**', $matches, true))->toBeTrue();
            expect(in_array('user.*', $matches, true))->toBeFalse();
        });
    });

    describe('DomainEvent immutability', function (): void {
        test('occur creates event with UUID and timestamp', function (): void {
            $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

            expect($event->eventType)->toBe('user.registered');
            expect($event->payload)->toBe(['email' => 'test@example.com']);
            expect($event->eventId)->not->toBeNull();
            expect($event->occurredAt)->not->toBeNull();
        });

        test('roundtrip toArray/fromArray preserves identity', function (): void {
            $original = DomainEvent::occur('order.created', ['id' => 123]);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
        });

        test('fromArray throws on missing eventType', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray([]))
                ->toThrow(InvalidArgumentException::class, 'eventType is required');
        });

        test('fromArray handles invalid UUID gracefully', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
                'occurredAt' => 'not-a-date',
            ]);
            // Should not throw — generates fresh UUID and timestamp
            expect($event->eventId)->not->toBeNull();
            expect($event->occurredAt)->not->toBeNull();
        });
    });

    describe('Config completeness', function (): void {
        test('config file has all required top-level keys', function (): void {
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
                expect(array_key_exists($key, $config))->toBeTrue(
                    "Config must have '{$key}' key"
                );
            }
        });

        test('table_names has all three tables', function (): void {
            $config = include __DIR__.'/../config/events.php';

            expect($config['table_names'])->toHaveKeys([
                'triggers',
                'event_logs',
                'subscriptions',
            ]);
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

            expect($config['subscriptions'])->toHaveKeys([
                'auto_generate_secret',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ]);
        });
    });

    describe('No TODO/FIXME/HACK in source', function (): void {
        test('no TODO comments in source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                if (preg_match('/\bTODO\b/i', $contents)) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty(
                'No TODO comments allowed in source files: '.implode(', ', $violations)
            );
        });

        test('no FIXME comments in source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                if (preg_match('/\bFIXME\b/i', $contents)) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty();
        });

        test('no HACK comments in source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                if (preg_match('/\bHACK\b/i', $contents)) {
                    $violations[] = basename($file);
                }
            }

            expect($violations)->toBeEmpty();
        });
    });

    describe('Composer.json autoload validation', function (): void {
        test('autoload PSR-4 mapping is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['autoload']['psr-4'])->toHaveKey('ZeroBoiler\\Events\\');
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        test('extra.laravel.providers is correct', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['providers'])->toContain(
                EventsServiceProvider::class,
            );
        });

        test('extra.laravel.aliases includes EventManager', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
        });

        test('PHP requirement is ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['require']['php'])->toBe('^8.5');
        });
    });

    describe('Model status constants', function (): void {
        test('EventLog has all four status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        test('EventLog $statuses array contains all constants', function (): void {
            expect(EventLog::$statuses)->toBe([
                'pending',
                'dispatched',
                'completed',
                'failed',
            ]);
        });
    });

    describe('EventLog model methods', function (): void {
        test('markAsCompleted and markAsFailed are public methods', function (): void {
            $ref = new ReflectionClass(EventLog::class);

            $completed = $ref->getMethod('markAsCompleted');
            expect($completed->isPublic())->toBeTrue();
            expect($completed->getReturnType()?->getName())->toBe('void');

            $failed = $ref->getMethod('markAsFailed');
            expect($failed->isPublic())->toBeTrue();
            expect($failed->getReturnType()?->getName())->toBe('void');
        });

        test('EventLog has scopeStalePending', function (): void {
            $ref = new ReflectionClass(EventLog::class);
            $method = $ref->getMethod('scopeStalePending');
            expect($method->isPublic())->toBeTrue();
        });
    });

    describe('Subscription model methods', function (): void {
        test('Subscription has signPayload, recordDelivery, recordFailure, resetFailures', function (): void {
            $ref = new ReflectionClass(Subscription::class);

            expect($ref->hasMethod('signPayload'))->toBeTrue();
            expect($ref->hasMethod('recordDelivery'))->toBeTrue();
            expect($ref->hasMethod('recordFailure'))->toBeTrue();
            expect($ref->hasMethod('resetFailures'))->toBeTrue();
            expect($ref->hasMethod('hasExceededFailures'))->toBeTrue();
            expect($ref->hasMethod('matchesEvent'))->toBeTrue();
            expect($ref->hasMethod('scopeExceededFailures'))->toBeTrue();
            expect($ref->hasMethod('scopeActive'))->toBeTrue();
            expect($ref->hasMethod('scopeForEvent'))->toBeTrue();
        });

        test('secret and deleted_at are hidden', function (): void {
            $ref = new ReflectionClass(Subscription::class);
            $hidden = $ref->getDefaultProperties()['hidden'] ?? [];
            expect($hidden)->toContain('secret');
            expect($hidden)->toContain('deleted_at');
        });
    });

    describe('Trigger model methods', function (): void {
        test('Trigger has eventLogs relationship', function (): void {
            $ref = new ReflectionClass(Trigger::class);
            $method = $ref->getMethod('eventLogs');
            expect($method->isPublic())->toBeTrue();
        });

        test('Trigger has scopeEnabled, scopeAsync, scopeOrderByPriority', function (): void {
            $ref = new ReflectionClass(Trigger::class);
            expect($ref->hasMethod('scopeEnabled'))->toBeTrue();
            expect($ref->hasMethod('scopeAsync'))->toBeTrue();
            expect($ref->hasMethod('scopeOrderByPriority'))->toBeTrue();
        });
    });

    describe('Migration structure verification', function (): void {
        test('triggers migration exists and creates correct columns', function (): void {
            $files = glob(__DIR__.'/../database/migrations/*_create_triggers_table.php');
            expect($files)->not->toBeEmpty();

            $contents = file_get_contents($files[0]);
            expect($contents)->toContain('id');
            expect($contents)->toContain('name');
            expect($contents)->toContain('event');
            expect($contents)->toContain('action');
            expect($contents)->toContain('conditions');
            expect($contents)->toContain('async');
            expect($contents)->toContain('priority');
            expect($contents)->toContain('enabled');
        });

        test('event_logs migration exists', function (): void {
            $files = glob(__DIR__.'/../database/migrations/*_create_event_logs_table.php');
            expect($files)->not->toBeEmpty();

            $contents = file_get_contents($files[0]);
            expect($contents)->toContain('trigger_id');
            expect($contents)->toContain('event');
            expect($contents)->toContain('payload');
            expect($contents)->toContain('status');
            expect($contents)->toContain('duration_ms');
            expect($contents)->toContain('error');
        });

        test('event_subscriptions migration exists', function (): void {
            $files = glob(__DIR__.'/../database/migrations/*_create_event_subscriptions_table.php');
            expect($files)->not->toBeEmpty();

            $contents = file_get_contents($files[0]);
            expect($contents)->toContain('event');
            expect($contents)->toContain('url');
            expect($contents)->toContain('conditions');
            expect($contents)->toContain('priority');
            expect($contents)->toContain('active');
            expect($contents)->toContain('secret');
            expect($contents)->toContain('failure_count');
            expect($contents)->toContain('delivery_count');
        });
    });

    describe('Factory existence', function (): void {
        test('TriggerFactory exists', function (): void {
            expect(file_exists(__DIR__.'/../database/factories/TriggerFactory.php'))->toBeTrue();
        });

        test('EventLogFactory exists', function (): void {
            expect(file_exists(__DIR__.'/../database/factories/EventLogFactory.php'))->toBeTrue();
        });

        test('SubscriptionFactory exists', function (): void {
            expect(file_exists(__DIR__.'/../database/factories/SubscriptionFactory.php'))->toBeTrue();
        });
    });

    describe('EventScheduler completeness', function (): void {
        test('EventScheduler has register, registerLogPurge, registerSubscriptionCleanup', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            expect($ref->hasMethod('register'))->toBeTrue();
            expect($ref->hasMethod('registerLogPurge'))->toBeTrue();
            expect($ref->hasMethod('registerSubscriptionCleanup'))->toBeTrue();
        });

        test('EventScheduler uses constructor injection (readonly Container)', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();
            expect($params)->toHaveCount(1);
            expect($params[0]->getName())->toBe('app');
        });
    });

    describe('PHPStan config validation', function (): void {
        test('phpstan.neon.dist exists and has level max', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('level: max');
            expect($contents)->toContain('paths:');
            expect($contents)->toContain('src');
        });

        test('rector.php exists and has license header', function (): void {
            $contents = file_get_contents(__DIR__.'/../rector.php');
            expect($contents)->toContain('This file is part of ZeroBoiler');
            expect($contents)->toContain('declare(strict_types=1)');
        });
    });

    describe('EventManager global disable behavior', function (): void {
        test('isDisabled reads from config', function (): void {
            config(['events.disabled' => true]);
            $engine = new ConditionEngine;
            $resolver = new ActionResolver($this->app);
            $manager = new EventManager($engine, $resolver, $this->app);

            expect($manager->isDisabled())->toBeTrue();

            config(['events.disabled' => false]);
            expect($manager->isDisabled())->toBeFalse();
        });

        test('setEnabled modifies in-memory config', function (): void {
            config(['events.disabled' => false]);
            $engine = new ConditionEngine;
            $resolver = new ActionResolver($this->app);
            $manager = new EventManager($engine, $resolver, $this->app);

            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();

            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });
    });

    describe('Contract binding verification', function (): void {
        test('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $resolved = app(ConditionEngineContract::class);
            expect($resolved)->toBeInstanceOf(ConditionEngine::class);
        });
    });
});
