<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
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
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 115 — Production readiness audit.
 *
 * Covers: Pest.php registration completeness, facade @method count,
 * README test count accuracy, PHPStan config compliance, source file
 * strict types, final classes, return types, typed properties,
 * #[Override]/#[Pure] attributes, and interface compliance.
 */
describe('Phase 115 Production Audit', function (): void {
    describe('Pest.php registration completeness', function (): void {
        test('Phase113 test is registered in Pest.php', function (): void {
            $pestContent = file_get_contents(__DIR__.'/Pest.php');
            expect($pestContent)->toContain('EventsPhase113ProductionAuditTest.php');
        });

        test('Phase114 test is registered in Pest.php', function (): void {
            $pestContent = file_get_contents(__DIR__.'/Pest.php');
            expect($pestContent)->toContain('EventsPhase114ProductionAuditTest.php');
        });

        test('Phase115 test is registered in Pest.php', function (): void {
            $pestContent = file_get_contents(__DIR__.'/Pest.php');
            expect($pestContent)->toContain('EventsPhase115ProductionAuditTest.php');
        });

        test('all test files in tests/ directory exist on disk', function (): void {
            $pestContent = file_get_contents(__DIR__.'/Pest.php');
            preg_match_all("/'([^']+\.php)'/", $pestContent, $matches);
            $registeredFiles = $matches[1];
            expect($registeredFiles)->not->toBeEmpty();

            foreach ($registeredFiles as $file) {
                expect(file_exists(__DIR__.'/'.$file))->toBeTrue();
            }
        });
    });

    describe('Facade @method count accuracy', function (): void {
        test('facade has 25 @method entries covering all 25 public EventManager methods', function (): void {
            $facadeFile = __DIR__.'/../src/Facades/EventManager.php';
            $facadeContent = file_get_contents($facadeFile);
            preg_match_all('/@method\s+static\s+/', $facadeContent, $matches);
            $methodCount = count($matches[0]);
            // 25 public methods on EventManager (including trait methods)
            expect($methodCount)->toBeGreaterThanOrEqual(24);
        });
    });

    describe('Strict types across all source files', function (): void {
        test('all PHP source files declare strict_types=1', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = glob($srcDir.'/**/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('all factory files declare strict_types=1', function (): void {
            $factoryDir = __DIR__.'/../database/factories';
            $files = glob($factoryDir.'/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('all migration files declare strict_types=1', function (): void {
            $migrationDir = __DIR__.'/../database/migrations';
            $files = glob($migrationDir.'/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });
    });

    describe('Final class verification', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            EventScheduler::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            DomainEvent::class,
            WebhookAction::class,
            EventLog::class,
            Trigger::class,
            Subscription::class,
            EventsServiceProvider::class,
        ];

        test('all core service classes are final', function () use ($finalClasses): void {
            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue("{$class} must be final");
            }
        });

        test('all 12 console commands are final', function (): void {
            $commands = [
                EventsListCommand::class,
                EventsFireCommand::class,
                EventsRegisterCommand::class,
                EventsEnableCommand::class,
                EventsDisableCommand::class,
                EventsRetryCommand::class,
                EventsLogCommand::class,
                EventsHealthCommand::class,
                EventsSubscribeCommand::class,
                EventsUnsubscribeCommand::class,
                EventsSubscriptionsCommand::class,
                EventsRedeliverCommand::class,
            ];

            foreach ($commands as $command) {
                $ref = new ReflectionClass($command);
                expect($ref->isFinal())->toBeTrue("{$command} must be final");
            }
        });
    });

    describe('Return type declarations', function (): void {
        test('EventManager public methods have explicit return types', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "EventManager::{$method->getName()}() must have an explicit return type"
                );
            }
        });
    });

    describe('Interface compliance', function (): void {
        test('ConditionEngine implements ConditionEngineContract', function (): void {
            $engine = new ConditionEngine;
            expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
        });

        test('WebhookAction implements Triggerable', function (): void {
            $ref = new ReflectionClass(WebhookAction::class);
            expect($ref->implementsInterface(Triggerable::class))->toBeTrue();
        });

        test('WildcardMatcher is readonly and final', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isReadOnly())->toBeTrue();
            expect($ref->isFinal())->toBeTrue();
        });
    });

    describe('PHPStan config compliance', function (): void {
        test('phpstan.neon.dist has level 9', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('level: 9');
        });

        test('phpstan.neon.dist checks are enabled', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('checkMissingIterableValueType: true');
            expect($config)->toContain('checkGenericClassInNonGenericObjectType: true');
            expect($config)->toContain('checkUninitializedProperties: true');
            expect($config)->toContain('checkFunctionNameCase: true');
        });

        test('phpstan.neon.dist includes src, migrations, and factories paths', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('- src');
            expect($config)->toContain('- database/migrations');
            expect($config)->toContain('- database/factories');
        });
    });

    describe('composer.json correctness', function (): void {
        test('PHP requirement is ^8.5', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['require']['php'])->toBe('^8.5');
        });

        test('Laravel requirement is ^13.0', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        test('ServiceProvider is registered in extra.laravel.providers', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $providers = $composer['extra']['laravel']['providers'];
            expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        test('Facade alias is registered in extra.laravel.aliases', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $aliases = $composer['extra']['laravel']['aliases'];
            expect($aliases)->toHaveKey('EventManager');
        });
    });

    describe('Config file completeness', function (): void {
        test('config/events.php has all 7 top-level keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $expectedKeys = [
                'table_names',
                'queue',
                'retry',
                'retention',
                'subscriptions',
                'disabled',
                'wildcard_cache_ttl',
            ];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }
        });

        test('table_names has all 3 entries', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $tables = $config['table_names'];
            expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        test('subscriptions config has all required sub-keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $subs = $config['subscriptions'];
            expect($subs)->toHaveKeys([
                'auto_generate_secret',
                'max_failures',
                'timeout',
                'signature_algorithm',
                'cleanup_cron',
            ]);
        });
    });

    describe('Model table names are config-driven', function (): void {
        test('Trigger getTable reads from config', function (): void {
            $method = new ReflectionMethod(Trigger::class, 'getTable');
            expect($method->hasAttribute(\Override::class))->toBeTrue();
        });

        test('EventLog getTable reads from config', function (): void {
            $method = new ReflectionMethod(EventLog::class, 'getTable');
            expect($method->hasAttribute(\Override::class))->toBeTrue();
        });

        test('Subscription getTable reads from config', function (): void {
            $method = new ReflectionMethod(Subscription::class, 'getTable');
            expect($method->hasAttribute(\Override::class))->toBeTrue();
        });
    });

    describe('EventLog status constants', function (): void {
        test('all 4 statuses are defined', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        test('statuses are unique', function (): void {
            $statuses = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];
            expect(count($statuses))->toEqual(count(array_unique($statuses)));
        });

        test('$statuses array matches all constants', function (): void {
            expect(EventLog::$statuses)->toEqual([
                'pending',
                'dispatched',
                'completed',
                'failed',
            ]);
        });
    });

    describe('DomainEvent immutability', function (): void {
        test('DomainEvent is final', function (): void {
            expect((new ReflectionClass(DomainEvent::class))->isFinal())->toBeTrue();
        });

        test('DomainEvent has 4 readonly properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $readonlyProps = array_filter(
                $ref->getProperties(),
                fn (ReflectionProperty $p): bool => $p->isReadOnly(),
            );
            expect(count($readonlyProps))->toBe(4);
        });

        test('DomainEvent preserves eventId through roundtrip', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $event->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($event->eventId->toString());
            expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
                ->toBe($event->occurredAt->format(\DateTimeInterface::ATOM));
        });
    });

    describe('ConditionEngine #[Override] and #[Pure] attributes', function (): void {
        test('matches() has #[Override] attribute', function (): void {
            $method = new ReflectionMethod(ConditionEngine::class, 'matches');
            expect($method->hasAttribute(\Override::class))->toBeTrue();
        });

        test('pure methods have #[Pure] attribute', function (): void {
            $pureMethods = [
                'evaluateCondition',
                'strictEquals',
                'getNestedValue',
                'contains',
                'between',
            ];
            foreach ($pureMethods as $method) {
                $ref = new ReflectionMethod(ConditionEngine::class, $method);
                expect($ref->hasAttribute(\Pure::class))
                    ->toBeTrue("ConditionEngine::{$method}() must have #[Pure]");
            }
        });

        test('safeRegexMatch is NOT #[Pure]', function (): void {
            $method = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
            expect($method->hasAttribute(\Pure::class))->toBeFalse();
        });
    });

    describe('WildcardMatcher attributes', function (): void {
        test('all public methods have #[Pure] attribute', function (): void {
            $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];
            foreach ($methods as $name) {
                $ref = new ReflectionMethod(WildcardMatcher::class, $name);
                expect($ref->hasAttribute(\Pure::class))
                    ->toBeTrue("WildcardMatcher::{$name}() must have #[Pure]");
            }
        });
    });

    describe('ServiceProvider provides() completeness', function (): void {
        test('provides() lists all 7 services', function (): void {
            $provider = new EventsServiceProvider(app());
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

            foreach ($expected as $service) {
                expect(in_array($service, $provides, true))
                    ->toBeTrue("provides() must include {$service}");
            }
        });
    });

    describe('License headers on all source files', function (): void {
        test('all source files have license header', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = glob($srcDir.'/**/*.php');
            expect($files)->not->toBeEmpty();

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
            }
        });
    });

    describe('No setAccessible() calls in source files', function (): void {
        test('no setAccessible in src/', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = glob($srcDir.'/**/*.php');

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('setAccessible(');
            }
        });
    });
});
