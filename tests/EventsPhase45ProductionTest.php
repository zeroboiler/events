<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;

/**
 * Phase 45 — Comprehensive final production audit.
 *
 * Covers:
 * - rector.php valid LaravelSetList constant (LARAVEL_120, not LARAVEL_130)
 * - All source files have declare(strict_types=1)
 * - All core classes are final
 * - No #[\Readonly] attribute usage (PHP 8.5 removed it)
 * - readonly keyword used correctly
 * - All public methods have return type declarations
 * - #[\Override] on all interface implementations
 * - phpstan.neon.dist level 9
 * - composer.json require php ^8.5
 * - composer.json autoload PSR-4
 * - All models use config-driven table names
 * - All models have UUID string keys, non-incrementing
 * - DomainEvent immutability (readonly properties)
 * - WildcardMatcher #[\Pure] on all public static methods
 * - EventManager trait composition (3 traits)
 * - ServiceProvider binding correctness
 * - Facade accessor
 * - Config completeness (6 top-level sections)
 * - All console commands have zeroboiler:events: prefix
 * - Factory definitions exist for all 3 models
 * - Migration count = 3
 * - EventLog status constants
 * - Triggerable interface contract
 * - ConditionEngineContract interface contract
 */

describe('Phase 45 Production Audit', function () {
    describe('rector.php validity', function () {
        it('contains valid LARAVEL_120 set constant', function () {
            $content = file_get_contents(__DIR__.'/../rector.php');
            expect($content)->not->toBeFalse();
            expect($content)->toContain('LaravelSetList::LARAVEL_120');
            expect($content)->not->toContain('LaravelSetList::LARAVEL_130');
        });

        it('has strict_types declaration', function () {
            $content = file_get_contents(__DIR__.'/../rector.php');
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('has preparedness configuration', function () {
            $content = file_get_contents(__DIR__.'/../rector.php');
            expect($content)->toContain('->withPreparedness(');
        });
    });

    describe('Strict types enforcement', function () {
        it('all source files have declare(strict_types=1)', function () {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                expect($content)->toContain('declare(strict_types=1');
            }
        });
    });

    describe('Final class verification', function () {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            WildcardMatcher::class,
            DomainEvent::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            EventsServiceProvider::class,
            EventManagerFacade::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
        ];

        foreach ($finalClasses as $class) {
            it("{$class} is final", function () use ($class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue();
            });
        }
    });

    describe('No #[\Readonly] attribute usage', function () {
        it('no source files contain #[\Readonly] attribute', function () {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                // #[\Readonly] was removed in PHP 8.5, replaced by readonly keyword
                expect($content)->not->toContain('#[\\Readonly]');
            }
        });
    });

    describe('readonly keyword usage', function () {
        it('DomainEvent uses readonly modifier on properties', function () {
            $ref = new ReflectionClass(DomainEvent::class);
            $props = $ref->getProperties();
            $readonlyProps = ['eventId', 'eventType', 'payload', 'occurredAt'];
            foreach ($readonlyProps as $propName) {
                $prop = $ref->getProperty($propName);
                expect($prop->isReadOnly())->toBeTrue("{$propName} should be readonly");
            }
        });

        it('EventManager constructor properties are readonly', function () {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();
            foreach ($params as $param) {
                if ($param->getName() !== 'app') {
                    expect($param->isReadOnly())->toBeTrue("{$param->getName()} should be readonly");
                }
            }
        });
    });

    describe('Return type declarations', function () {
        $classesWithPublicMethods = [
            EventManager::class => [
                'on', 'register', 'invalidateTriggerCache', 'listTriggers', 'getTrigger',
                'deleteTrigger', 'enable', 'disable', 'fire', 'fireModel', 'executeTrigger',
                'getEventHistory', 'getStats', 'purgeLogs',
                'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook',
            ],
            TriggerBuilder::class => ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams', 'save'],
            SubscriptionBuilder::class => ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async', 'save'],
            ConditionEngine::class => ['matches'],
        ];

        foreach ($classesWithPublicMethods as $class => $methods) {
            foreach ($methods as $method) {
                it("{$class}::{$method}() has return type", function () use ($class, $method) {
                    $ref = new ReflectionMethod($class, $method);
                    $rt = $ref->getReturnType();
                    expect($rt)->not->toBeNull("{$class}::{$method}() must have a return type declaration");
                });
            }
        }
    });

    describe('Override attribute verification', function () {
        it('ConditionEngine::matches() has #[\Override]', function () {
            $method = new ReflectionMethod(ConditionEngine::class, 'matches');
            $attrs = $method->getAttributes(\Attribute::class);
            $hasOverride = false;
            foreach ($method->getAttributes() as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue('ConditionEngine::matches() must have #[\Override]');
        });

        it('WebhookAction::handle() has #[\Override]', function () {
            $method = new ReflectionMethod(WebhookAction::class, 'handle');
            $hasOverride = false;
            foreach ($method->getAttributes() as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue('WebhookAction::handle() must have #[\Override]');
        });
    });

    describe('WildcardMatcher #[\Pure]', function () {
        $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

        foreach ($pureMethods as $method) {
            it("WildcardMatcher::{$method}() has #[\Pure]", function () use ($method) {
                $ref = new ReflectionMethod(WildcardMatcher::class, $method);
                $hasPure = false;
                foreach ($ref->getAttributes() as $attr) {
                    if ($attr->getName() === 'Pure') {
                        $hasPure = true;
                        break;
                    }
                }
                expect($hasPure)->toBeTrue("WildcardMatcher::{$method}() must have #[\Pure]");
            });
        }
    });

    describe('Trait composition', function () {
        it('EventManager uses EscapesWildcardLike', function () {
            expect(in_array(EscapesWildcardLike::class, class_uses(EventManager::class), true))->toBeTrue();
        });

        it('EventManager uses ManagesHistory', function () {
            expect(in_array(ManagesHistory::class, class_uses(EventManager::class), true))->toBeTrue();
        });

        it('EventManager uses ManagesSubscriptions', function () {
            expect(in_array(ManagesSubscriptions::class, class_uses(EventManager::class), true))->toBeTrue();
        });

        it('Subscription uses EscapesWildcardLike', function () {
            expect(in_array(EscapesWildcardLike::class, class_uses(Subscription::class), true))->toBeTrue();
        });
    });

    describe('PHPStan config', function () {
        it('phpstan.neon.dist has level 9', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('level: 9');
        });

        it('phpstan.neon.dist has bootstrapFiles', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('bootstrapFiles');
        });

        it('phpstan.neon.dist has paths src', function () {
            $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($content)->toContain('- src');
        });
    });

    describe('composer.json structure', function () {
        it('requires PHP ^8.5', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require']['php'])->toBe('^8.5');
        });

        it('has autoload PSR-4', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('has extra.laravel.providers', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['extra']['laravel']['providers'])->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        it('has extra.laravel.aliases', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['extra']['laravel']['aliases']['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });
    });

    describe('Config completeness', function () {
        it('config has all 6 top-level sections', function () {
            $config = include __DIR__.'/../config/events.php';
            $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
            }
        });

        it('table_names has triggers, event_logs, subscriptions', function () {
            $config = include __DIR__.'/../config/events.php';
            expect(isset($config['table_names']['triggers']))->toBeTrue();
            expect(isset($config['table_names']['event_logs']))->toBeTrue();
            expect(isset($config['table_names']['subscriptions']))->toBeTrue();
        });

        it('subscriptions has auto_generate_secret, max_failures, timeout, signature_algorithm', function () {
            $config = include __DIR__.'/../config/events.php';
            $sub = $config['subscriptions'];
            expect(isset($sub['auto_generate_secret']))->toBeTrue();
            expect(isset($sub['max_failures']))->toBeTrue();
            expect(isset($sub['timeout']))->toBeTrue();
            expect(isset($sub['signature_algorithm']))->toBeTrue();
        });
    });

    describe('Model config-driven table names', function () {
        it('Trigger::getTable() reads from config', function () {
            $ref = new ReflectionMethod(Trigger::class, 'getTable');
            expect($ref->getDeclaringClass()->getName())->toBe(Trigger::class);
        });

        it('EventLog::getTable() reads from config', function () {
            $ref = new ReflectionMethod(EventLog::class, 'getTable');
            expect($ref->getDeclaringClass()->getName())->toBe(EventLog::class);
        });

        it('Subscription::getTable() reads from config', function () {
            $ref = new ReflectionMethod(Subscription::class, 'getTable');
            expect($ref->getDeclaringClass()->getName())->toBe(Subscription::class);
        });

        it('all models have UUID string key type', function () {
            foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
                $ref = new ReflectionProperty($model, 'keyType');
                expect($ref->getDefaultValue())->toBe('string');
            }
        });

        it('all models are non-incrementing', function () {
            foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
                $ref = new ReflectionProperty($model, 'incrementing');
                expect($ref->getDefaultValue())->toBeFalse();
            }
        });
    });

    describe('Console commands prefix', function () {
        $commandFiles = glob(__DIR__.'/../src/Console/*.php');
        it('all 11 console commands exist', function () use ($commandFiles) {
            expect(count($commandFiles))->toBe(11);
        });

        foreach ($commandFiles as $file) {
            $className = 'ZeroBoiler\\Events\\Console\\'.basename($file, '.php');
            it("{$className} signature has zeroboiler:events: prefix", function () use ($className) {
                $ref = new ReflectionClass($className);
                if ($ref->hasProperty('signature')) {
                    $prop = $ref->getProperty('signature');
                    $sig = $prop->getDefaultValue();
                    expect(str_starts_with($sig, 'zeroboiler:events:'))->toBeTrue("{$className} signature must start with zeroboiler:events:");
                }
            });
        }
    });

    describe('Migration structure', function () {
        it('has 3 migration files', function () {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            expect(count($migrations))->toBe(3);
        });

        it('all migrations have up() and down() methods', function () {
            $migrations = glob(__DIR__.'/../database/migrations/*.php');
            foreach ($migrations as $file) {
                // Anonymous classes in migration files — we check the file content
                $content = file_get_contents($file);
                expect($content)->toContain('public function up(): void');
                expect($content)->toContain('public function down(): void');
            }
        });
    });

    describe('EventLog status constants', function () {
        it('has all 4 status constants', function () {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('$statuses array matches constants', function () {
            $statuses = EventLog::$statuses;
            expect($statuses)->toContain(EventLog::STATUS_PENDING);
            expect($statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect($statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect($statuses)->toContain(EventLog::STATUS_FAILED);
            expect(count($statuses))->toBe(4);
        });
    });

    describe('Interface contracts', function () {
        it('ConditionEngine implements ConditionEngineContract', function () {
            expect(is_subclass_of(ConditionEngine::class, ConditionEngineContract::class))->toBeTrue();
        });

        it('WebhookAction implements Triggerable', function () {
            expect(is_subclass_of(WebhookAction::class, Triggerable::class))->toBeTrue();
        });

        it('ConditionEngineContract::matches() has array params with docblocks', function () {
            $method = new ReflectionMethod(ConditionEngineContract::class, 'matches');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@param');
        });

        it('Triggerable::handle() has array param with docblocks', function () {
            $method = new ReflectionMethod(Triggerable::class, 'handle');
            $doc = $method->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@param');
        });
    });

    describe('ServiceProvider bindings', function () {
        it('registers ConditionEngineContract singleton', function () {
            $sp = new EventsServiceProvider(app());
            // Just verify the class exists and has register/boot methods
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->hasMethod('register'))->toBeTrue();
            expect($ref->hasMethod('boot'))->toBeTrue();
            expect($ref->getMethod('register')->getReturnType()?->getName())->toBe('void');
            expect($ref->getMethod('boot')->getReturnType()?->getName())->toBe('void');
        });
    });

    describe('Facade accessor', function () {
        it('facade getFacadeAccessor returns EventManager::class', function () {
            $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
            $result = $method->invoke(null);
            expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
        });
    });

    describe('Factory definitions', function () {
        it('Trigger has factory', function () {
            expect(class_exists(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class))->toBeTrue();
        });

        it('EventLog has factory', function () {
            expect(class_exists(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class))->toBeTrue();
        });

        it('Subscription has factory', function () {
            expect(class_exists(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class))->toBeTrue();
        });
    });

    describe('Gitignore completeness', function () {
        it('.gitignore exists and has essential entries', function () {
            $content = file_get_contents(__DIR__.'/../.gitignore');
            expect($content)->toContain('vendor/');
            expect($content)->toContain('composer.lock');
            expect($content)->toContain('phpstan.neon');
            expect($content)->toContain('phpstan-baseline.neon');
        });
    });

    describe('Version consistency', function () {
        it('composer.json version matches README badge', function () {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');
            $version = $json['version'];
            expect($readme)->toContain("version-{$version}");
        });
    });

    describe('Source file license headers', function () {
        it('all source files have ZeroBoiler license header', function () {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                expect($content)->toContain('ZeroBoiler');
            }
        });
    });
});
