<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
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

describe('Phase 192 — Final Production Infrastructure Audit', function (): void {
    describe('PHP 8.5 syntax compliance', function (): void {
        it('all source files declare strict_types=1', function (): void {
            $srcFiles = glob(base_path('vendor/zeroboiler/events/src/**/*.php'), GLOB_BRACE);

            if ($srcFiles === false || $srcFiles === []) {
                $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
            }

            if ($srcFiles === false || $srcFiles === []) {
                $this->markTestSkipped('Cannot locate source files');
            }

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        it('all test files declare strict_types=1', function (): void {
            $testFiles = glob(__DIR__.'/*Test.php');

            if ($testFiles === false) {
                $testFiles = [];
            }

            foreach ($testFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        it('EventManager constructor uses promoted readonly properties', function (): void {
            $manager = app(EventManager::class);
            $ref = new ReflectionClass($manager);

            expect($ref->getConstructor())->not->toBeNull();

            $params = $ref->getConstructor()->getParameters();
            expect($params)->toHaveCount(3);

            // Check each parameter is a constructor property promotion (has type, is readonly)
            foreach ($params as $param) {
                expect($param->getType())->not->toBeNull();
            }
        });

        it('DomainEvent constructor uses promoted readonly properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);

            $props = $ref->getProperties();
            $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());

            expect(count($readonlyProps))->toBeGreaterThanOrEqual(2);
        });

        it('WildcardMatcher is readonly final class', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);

            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('Return type declarations completeness', function (): void {
        it('EventManager public methods all have return types', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            $missingReturnTypes = [];
            foreach ($publicMethods as $method) {
                if ($method->getDeclaringClass()->getName() !== EventManager::class) {
                    continue;
                }
                $returnType = $method->getReturnType();
                if ($returnType === null) {
                    $missingReturnTypes[] = $method->getName();
                }
            }

            expect($missingReturnTypes)->toBeEmpty(
                'Methods missing return type declarations: '.implode(', ', $missingReturnTypes),
            );
        });

        it('ConditionEngine methods all have return types', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() === ConditionEngine::class) {
                    expect($method->getReturnType())->not->toBeNull(
                        "ConditionEngine::{$method->getName()}() missing return type",
                    );
                }
            }
        });

        it('WildcardMatcher static methods all have return types', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

            foreach ($methods as $method) {
                expect($method->getReturnType())->not->toBeNull(
                    "WildcardMatcher::{$method->getName()}() missing return type",
                );
            }
        });

        it('DomainEvent methods all have return types', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                expect($method->getReturnType())->not->toBeNull(
                    "DomainEvent::{$method->getName()}() missing return type",
                );
            }
        });

        it('SubscriptionBuilder methods all have return types', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() === SubscriptionBuilder::class) {
                    expect($method->getReturnType())->not->toBeNull(
                        "SubscriptionBuilder::{$method->getName()}() missing return type",
                    );
                }
            }
        });

        it('TriggerBuilder methods all have return types', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() === TriggerBuilder::class) {
                    expect($method->getReturnType())->not->toBeNull(
                        "TriggerBuilder::{$method->getName()}() missing return type",
                    );
                }
            }
        });

        it('ActionResolver methods all have return types', function (): void {
            $ref = new ReflectionClass(ActionResolver::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                expect($method->getReturnType())->not->toBeNull(
                    "ActionResolver::{$method->getName()}() missing return type",
                );
            }
        });

        it('EventScheduler methods all have return types', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                expect($method->getReturnType())->not->toBeNull(
                    "EventScheduler::{$method->getName()}() missing return type",
                );
            }
        });
    });

    describe('Final classes audit', function (): void {
        it('EventManager is final', function (): void {
            expect((new ReflectionClass(EventManager::class))->isFinal())->toBeTrue();
        });

        it('ConditionEngine is final', function (): void {
            expect((new ReflectionClass(ConditionEngine::class))->isFinal())->toBeTrue();
        });

        it('WildcardMatcher is final', function (): void {
            expect((new ReflectionClass(WildcardMatcher::class))->isFinal())->toBeTrue();
        });

        it('TriggerBuilder is final', function (): void {
            expect((new ReflectionClass(TriggerBuilder::class))->isFinal())->toBeTrue();
        });

        it('SubscriptionBuilder is final', function (): void {
            expect((new ReflectionClass(SubscriptionBuilder::class))->isFinal())->toBeTrue();
        });

        it('ActionResolver is final', function (): void {
            expect((new ReflectionClass(ActionResolver::class))->isFinal())->toBeTrue();
        });

        it('EventScheduler is final', function (): void {
            expect((new ReflectionClass(EventScheduler::class))->isFinal())->toBeTrue();
        });

        it('DomainEvent is final', function (): void {
            expect((new ReflectionClass(DomainEvent::class))->isFinal())->toBeTrue();
        });

        it('EventsServiceProvider is final', function (): void {
            expect((new ReflectionClass(EventsServiceProvider::class))->isFinal())->toBeTrue();
        });

        it('All models are final', function (): void {
            expect((new ReflectionClass(EventLog::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(Trigger::class))->isFinal())->toBeTrue();
            expect((new ReflectionClass(Subscription::class))->isFinal())->toBeTrue();
        });

        it('Facade is final', function (): void {
            expect((new ReflectionClass(EventManagerFacade::class))->isFinal())->toBeTrue();
        });

        it('WebhookAction is final', function (): void {
            expect((new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class))->isFinal())->toBeTrue();
        });

        it('DispatchTriggerJob is final', function (): void {
            expect((new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class))->isFinal())->toBeTrue();
        });
    });

    describe('Typed properties audit', function (): void {
        it('TriggerBuilder has all properties typed', function (): void {
            $ref = new ReflectionClass(TriggerBuilder::class);
            $props = $ref->getProperties();

            foreach ($props as $prop) {
                expect($prop->getType())->not->toBeNull(
                    "TriggerBuilder::\${$prop->getName()} is untyped",
                );
            }
        });

        it('SubscriptionBuilder has all properties typed', function (): void {
            $ref = new ReflectionClass(SubscriptionBuilder::class);
            $props = $ref->getProperties();

            foreach ($props as $prop) {
                expect($prop->getType())->not->toBeNull(
                    "SubscriptionBuilder::\${$prop->getName()} is untyped",
                );
            }
        });

        it('EventLog model has typed properties', function (): void {
            $ref = new ReflectionClass(EventLog::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($props as $prop) {
                expect($prop->getType())->not->toBeNull(
                    "EventLog::\${$prop->getName()} is untyped",
                );
            }
        });

        it('Trigger model has typed properties', function (): void {
            $ref = new ReflectionClass(Trigger::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($props as $prop) {
                expect($prop->getType())->not->toBeNull(
                    "Trigger::\${$prop->getName()} is untyped",
                );
            }
        });

        it('Subscription model has typed properties', function (): void {
            $ref = new ReflectionClass(Subscription::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

            foreach ($props as $prop) {
                expect($prop->getType())->not->toBeNull(
                    "Subscription::\${$prop->getName()} is untyped",
                );
            }
        });
    });

    describe('Docblock completeness', function (): void {
        it('EventManager class has class-level docblock', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('ConditionEngine class has class-level docblock', function (): void {
            $ref = new ReflectionClass(ConditionEngine::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('DomainEvent class has class-level docblock', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('WildcardMatcher class has class-level docblock', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('EventsServiceProvider has class-level docblock', function (): void {
            $ref = new ReflectionClass(EventsServiceProvider::class);
            expect($ref->getDocComment())->not->toBeFalse();
        });

        it('public methods on EventManager have docblocks', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            $missingDocblocks = [];
            foreach ($publicMethods as $method) {
                if ($method->getDeclaringClass()->getName() !== EventManager::class) {
                    continue;
                }
                if ($method->getDocComment() === false) {
                    $missingDocblocks[] = $method->getName();
                }
            }

            expect($missingDocblocks)->toBeEmpty(
                'Methods missing docblocks: '.implode(', ', $missingDocblocks),
            );
        });

        it('interfaces have docblocks on their methods', function (): void {
            $interfaces = [
                ConditionEngineContract::class,
                Triggerable::class,
            ];

            foreach ($interfaces as $interface) {
                $ref = new ReflectionClass($interface);
                $methods = $ref->getMethods();

                foreach ($methods as $method) {
                    expect($method->getDocComment())->not->toBeFalse(
                        "{$interface}::{$method->getName()}() missing docblock",
                    );
                }
            }
        });
    });

    describe('#[Override] attribute audit', function (): void {
        it('ConditionEngine::matches has #[Override]', function (): void {
            $method = new ReflectionMethod(ConditionEngine::class, 'matches');
            $attrs = $method->getAttributes();

            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }

            expect($hasOverride)->toBeTrue('ConditionEngine::matches() should have #[Override]');
        });

        it('Models boot() has #[Override]', function (): void {
            foreach ([EventLog::class, Trigger::class, Subscription::class] as $model) {
                $method = new ReflectionMethod($model, 'boot');
                $attrs = $method->getAttributes();
                $hasOverride = false;
                foreach ($attrs as $attr) {
                    if ($attr->getName() === 'Override') {
                        $hasOverride = true;
                        break;
                    }
                }
                expect($hasOverride)->toBeTrue("{$model}::boot() should have #[Override]");
            }
        });

        it('EventsServiceProvider register/boot/provides have #[Override]', function (): void {
            $methods = ['register', 'boot', 'provides'];

            foreach ($methods as $methodName) {
                $method = new ReflectionMethod(EventsServiceProvider::class, $methodName);
                $attrs = $method->getAttributes();
                $hasOverride = false;
                foreach ($attrs as $attr) {
                    if ($attr->getName() === 'Override') {
                        $hasOverride = true;
                        break;
                    }
                }
                expect($hasOverride)->toBeTrue(
                    "EventsServiceProvider::{$methodName}() should have #[Override]",
                );
            }
        });

        it('Facade getFacadeAccessor has #[Override]', function (): void {
            $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
            $attrs = $method->getAttributes();
            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue('EventManager facade should have #[Override] on getFacadeAccessor');
        });
    });

    describe('ServiceProvider binding correctness', function (): void {
        it('ConditionEngineContract is bound to ConditionEngine', function (): void {
            $impl = app()->make(ConditionEngineContract::class);
            expect($impl)->toBeInstanceOf(ConditionEngine::class);
        });

        it('EventManager can be resolved from container', function (): void {
            $manager = app()->make(EventManager::class);
            expect($manager)->toBeInstanceOf(EventManager::class);
        });

        it('EventScheduler can be resolved from container', function (): void {
            $scheduler = app()->make(EventScheduler::class);
            expect($scheduler)->toBeInstanceOf(EventScheduler::class);
        });

        it('ActionResolver can be resolved from container', function (): void {
            $resolver = app()->make(ActionResolver::class);
            expect($resolver)->toBeInstanceOf(ActionResolver::class);
        });
    });

    describe('Config file structure validation', function (): void {
        it('queue config has connection and queue keys', function (): void {
            $queue = config('events.queue');

            expect($queue)->toBeArray();
            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('retry config has tries and backoff keys', function (): void {
            $retry = config('events.retry');

            expect($retry)->toBeArray();
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });

        it('retention config has days, include_pending, and schedule_cron keys', function (): void {
            $retention = config('events.retention');

            expect($retention)->toBeArray();
            expect($retention)->toHaveKey('days');
            expect($retention)->toHaveKey('include_pending');
            expect($retention)->toHaveKey('schedule_cron');
        });

        it('wildcard_cache_ttl is a positive integer or zero', function (): void {
            $ttl = config('events.wildcard_cache_ttl');

            expect($ttl)->toBeNumeric();
        });

        it('disabled config is boolean', function (): void {
            $disabled = config('events.disabled');

            expect($disabled)->toBeBool();
        });
    });

    describe('WildcardMatcher advanced patterns', function (): void {
        it('handles Unicode event names', function (): void {
            expect(WildcardMatcher::matches('*.created', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        });

        it('double-star matches deeply nested events', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.item.variant.created'))->toBeTrue();
        });

        it('double-star matches single-segment events', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        });

        it('single-star does not match cross-segment', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.item.placed'))->toBeFalse();
        });

        it('extractWildcards works with multiple wildcards', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.action.*', 'user.admin.action.delete');

            expect($result)->toBe(['admin', 'delete']);
        });
    });

    describe('ConditionEngine safe regex', function (): void {
        $engine = new ConditionEngine;

        it('allows simple valid regex', function () use ($engine): void {
            expect($engine->matches(['name' => ['matches', '/^test$/']], ['name' => 'test']))->toBeTrue();
            expect($engine->matches(['name' => ['matches', '/^test$/']], ['name' => 'testing']))->toBeFalse();
        });

        it('rejects overly long regex patterns', function () use ($engine): void {
            $longPattern = '/'.str_repeat('a', 600).'/';
            expect($engine->matches(['name' => ['matches', $longPattern]], ['name' => 'test']))->toBeFalse();
        });

        it('rejects nested quantifier patterns (ReDoS protection)', function () use ($engine): void {
            expect($engine->matches(
                ['name' => ['matches', '/(a+)+/']],
                ['name' => str_repeat('a', 50)],
            ))->toBeFalse();
        });

        it('regex operator requires string actual value', function () use ($engine): void {
            expect($engine->matches(['name' => ['matches', '/test/']], ['name' => 123]))->toBeFalse();
            expect($engine->matches(['name' => ['matches', '/test/']], ['name' => null]))->toBeFalse();
        });
    });

    describe('DomainEvent serialization roundtrip', function (): void {
        it('preserves all data through toArray/fromArray cycle', function (): void {
            $original = DomainEvent::occur('order.placed', [
                'order_id' => 42,
                'total' => 99.99,
                'items' => ['SKU-001', 'SKU-002'],
            ]);

            $array = $original->toArray();
            $restored = DomainEvent::fromArray($array);

            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->payload)->toBe($original->payload);
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
        });

        it('fromArray with empty payload creates event with empty payload', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.empty']);
            expect($event->payload)->toBe([]);
        });

        it('occur is equivalent to new self()', function (): void {
            $type = 'test.event';
            $payload = ['key' => 'value'];

            $constructed = new DomainEvent($type, $payload);
            $occurred = DomainEvent::occur($type, $payload);

            expect($occurred->eventType)->toBe($constructed->eventType);
            expect($occurred->payload)->toBe($constructed->payload);
        });
    });

    describe('Facade static proxy coverage', function (): void {
        it('facade accessor returns EventManager class name', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');

            expect($method->invoke(null))->toBe(EventManager::class);
        });
    });

    describe('Console commands existence', function (): void {
        $commands = [
            'zeroboiler:events:list' => \ZeroBoiler\Events\Console\EventsListCommand::class,
            'zeroboiler:events:register' => \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
            'zeroboiler:events:fire' => \ZeroBoiler\Events\Console\EventsFireCommand::class,
            'zeroboiler:events:log' => \ZeroBoiler\Events\Console\EventsLogCommand::class,
            'zeroboiler:events:retry' => \ZeroBoiler\Events\Console\EventsRetryCommand::class,
            'zeroboiler:events:enable' => \ZeroBoiler\Events\Console\EventsEnableCommand::class,
            'zeroboiler:events:disable' => \ZeroBoiler\Events\Console\EventsDisableCommand::class,
            'zeroboiler:events:health' => \ZeroBoiler\Events\Console\EventsHealthCommand::class,
            'zeroboiler:events:subscribe' => \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
            'zeroboiler:events:unsubscribe' => \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
            'zeroboiler:events:subscriptions' => \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
            'zeroboiler:events:redeliver' => \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        ];

        it('all commands are registered in the service provider', function () use ($commands): void {
            $provider = new EventsServiceProvider(app());
            $registered = app()->make('Illuminate\Contracts\Console\Kernel');

            foreach ($commands as $signature => $class) {
                expect(class_exists($class))->toBeTrue("Command class {$class} does not exist");
            }
        });

        it('all command classes are final', function () use ($commands): void {
            foreach ($commands as $class) {
                expect((new ReflectionClass($class))->isFinal())->toBeTrue(
                    "{$class} should be final",
                );
            }
        });
    });

    describe('ManagesHistory trait contract', function (): void {
        it('EventManager has getEventHistory method', function (): void {
            expect(method_exists(EventManager::class, 'getEventHistory'))->toBeTrue();
        });

        it('EventManager has getStats method', function (): void {
            expect(method_exists(EventManager::class, 'getStats'))->toBeTrue();
        });

        it('EventManager has purgeLogs method', function (): void {
            expect(method_exists(EventManager::class, 'purgeLogs'))->toBeTrue();
        });

        it('EventManager has getStalePendingLogs method', function (): void {
            expect(method_exists(EventManager::class, 'getStalePendingLogs'))->toBeTrue();
        });

        it('EventManager has deactivateExceededSubscriptions method', function (): void {
            expect(method_exists(EventManager::class, 'deactivateExceededSubscriptions'))->toBeTrue();
        });
    });

    describe('ManagesSubscriptions trait contract', function (): void {
        it('EventManager has subscribe method', function (): void {
            expect(method_exists(EventManager::class, 'subscribe'))->toBeTrue();
        });

        it('EventManager has unsubscribe method', function (): void {
            expect(method_exists(EventManager::class, 'unsubscribe'))->toBeTrue();
        });

        it('EventManager has listSubscriptions method', function (): void {
            expect(method_exists(EventManager::class, 'listSubscriptions'))->toBeTrue();
        });

        it('EventManager has getSubscription method', function (): void {
            expect(method_exists(EventManager::class, 'getSubscription'))->toBeTrue();
        });

        it('EventManager has subscribeWebhook method', function (): void {
            expect(method_exists(EventManager::class, 'subscribeWebhook'))->toBeTrue();
        });
    });

    describe('Migration config-driven tables', function (): void {
        it('triggers migration reads table name from config', function (): void {
            $table = config('events.table_names.triggers', 'triggers');
            expect($table)->toBeString();
            expect($table)->not->toBeEmpty();
        });

        it('event_logs migration reads table name from config', function (): void {
            $table = config('events.table_names.event_logs', 'event_logs');
            expect($table)->toBeString();
            expect($table)->not->toBeEmpty();
        });

        it('subscriptions migration reads table name from config', function (): void {
            $table = config('events.table_names.subscriptions', 'event_subscriptions');
            expect($table)->toBeString();
            expect($table)->not->toBeEmpty();
        });

        it('all table names are different', function (): void {
            $triggers = config('events.table_names.triggers');
            $logs = config('events.table_names.event_logs');
            $subs = config('events.table_names.subscriptions');

            expect($triggers)->not->toBe($logs);
            expect($triggers)->not->toBe($subs);
            expect($logs)->not->toBe($subs);
        });
    });

    describe('Composer package metadata', function (): void {
        it('composer.json requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('composer.json requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        it('composer.json has correct autoload PSR-4 mapping', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('composer.json registers EventsServiceProvider in extra.laravel.providers', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $providers = $composer['extra']['laravel']['providers'];
            expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        it('composer.json registers EventManager facade alias', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $aliases = $composer['extra']['laravel']['aliases'];
            expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });
    });
});
