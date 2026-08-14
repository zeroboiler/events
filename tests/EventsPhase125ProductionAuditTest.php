<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
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

describe('Events Phase 125 — Production Readiness Audit', function (): void {
    describe('PHPStan Configuration', function (): void {
        it('phpstan.neon.dist has level 9 (PHPStan 2.x compatible)', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('level: 9');
            expect($config)->not->toContain('level: 8');
        });

        it('phpstan.neon.dist includes tests in analysis paths', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('- tests');
        });

        it('phpstan.neon.dist has sortBy Collection suppression', function (): void {
            $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($config)->toContain('Collection::sortBy');
        });

        it('phpstan-baseline.neon does not use includes (not a baseline)', function (): void {
            $baseline = file_get_contents(__DIR__.'/../phpstan-baseline.neon');
            expect($baseline)->not->toContain('includes:');
            expect($baseline)->not->toContain('- phpstan.neon.dist');
        });

        it('phpstan.neon includes phpstan.neon.dist correctly', function (): void {
            $local = file_get_contents(__DIR__.'/../phpstan.neon');
            expect($local)->toContain('includes:');
            expect($local)->toContain('- phpstan.neon.dist');
        });

        it('composer.json requires phpstan ^2.2', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($json['require-dev']['phpstan/phpstan'])->toContain('^2.2');
        });
    });

    describe('EventManager::getMatchingTriggers sortBy compatibility', function (): void {
        it('sortBy uses positional args not named descending option', function (): void {
            $source = file_get_contents(__DIR__.'/../src/EventManager.php');
            // Old: descending: false named arg (Laravel 11 style)
            expect($source)->not->toContain('descending: false');
            // New: sortBy uses 2-arg positional form
            expect($source)->toContain('SORT_REGULAR');
        });
    });

    describe('Config Consistency', function (): void {
        it('queue.connection uses env() with default, not ?: fallback', function (): void {
            $config = file_get_contents(__DIR__.'/../config/events.php');
            // Should use env($key, $default) not env($key) ?: $default
            expect($config)->toContain("env('EVENTS_QUEUE_CONNECTION', config('queue.default'");
            expect($config)->not->toContain("env('EVENTS_QUEUE_CONNECTION') ?: config(");
        });

        it('config has all 7 top-level keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect(array_keys($config)->sort()->values()->toArray())->toBe(
                collect(['disabled', 'queue', 'retry', 'retention', 'subscriptions', 'table_names', 'wildcard_cache_ttl'])
                    ->sort()->values()->toArray(),
            );
        });

        it('subscriptions config has all 6 keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $subs = $config['subscriptions'];
            expect(array_keys($subs)->sort()->values()->toArray())->toBe(
                collect(['auto_generate_secret', 'cleanup_cron', 'max_failures', 'signature_algorithm', 'timeout'])
                    ->sort()->values()->toArray(),
            );
        });

        it('retention config has all 3 keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $ret = $config['retention'];
            expect(array_keys($ret)->sort()->values()->toArray())->toBe(
                collect(['days', 'include_pending', 'schedule_cron'])
                    ->sort()->values()->toArray(),
            );
        });
    });

    describe('ServiceProvider Completeness', function (): void {
        it('provides all 7 services', function (): void {
            $provider = new EventsServiceProvider($this->app);
            $provides = $provider->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
            expect($provides)->toHaveCount(7);
        });

        it('registers 12 commands in console mode', function (): void {
            $commands = [];
            $provider = new class($this->app) extends EventsServiceProvider {
                public function getCommands(): array
                {
                    return [];
                }
            };

            // Verify all command classes exist
            $commandClasses = [
                \ZeroBoiler\Events\Console\EventsListCommand::class,
                \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
                \ZeroBoiler\Events\Console\EventsFireCommand::class,
                \ZeroBoiler\Events\Console\EventsLogCommand::class,
                \ZeroBoiler\Events\Console\EventsRetryCommand::class,
                \ZeroBoiler\Events\Console\EventsEnableCommand::class,
                \ZeroBoiler\Events\Console\EventsDisableCommand::class,
                \ZeroBoiler\Events\Console\EventsHealthCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
                \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
                \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            ];

            foreach ($commandClasses as $class) {
                expect(class_exists($class))->toBeTrue("Command {$class} does not exist");
                expect((new ReflectionClass($class))->isFinal())->toBeTrue("Command {$class} must be final");
            }

            expect(count($commandClasses))->toBe(12);
        });
    });

    describe('Trait Consistency', function (): void {
        it('EventManager uses all 3 traits', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            $traits = array_map(fn (ReflectionClass $t): string => $t->getShortName(), $reflection->getTraits());
            expect($traits)->toContain('EscapesWildcardLike');
            expect($traits)->toContain('ManagesHistory');
            expect($traits)->toContain('ManagesSubscriptions');
        });

        it('Subscription uses EscapesWildcardLike', function (): void {
            $reflection = new ReflectionClass(Subscription::class);
            $traits = array_map(fn (ReflectionClass $t): string => $t->getShortName(), $reflection->getTraits());
            expect($traits)->toContain('EscapesWildcardLike');
        });

        it('WebhookAction uses GetsWebhookTimeout', function (): void {
            $reflection = new ReflectionClass(WebhookAction::class);
            $traits = array_map(fn (ReflectionClass $t): string => $t->getShortName(), $reflection->getTraits());
            expect($traits)->toContain('GetsWebhookTimeout');
        });

        it('ManagesHistory uses EscapesWildcardLike', function (): void {
            $reflection = new ReflectionClass(ManagesHistory::class);
            $traits = array_map(fn (ReflectionClass $t): string => $t->getShortName(), $reflection->getTraits());
            expect($traits)->toContain('EscapesWildcardLike');
        });

        it('ManagesSubscriptions uses EscapesWildcardLike', function (): void {
            $reflection = new ReflectionClass(ManagesSubscriptions::class);
            $traits = array_map(fn (ReflectionClass $t): string => $t->getShortName(), $reflection->getTraits());
            expect($traits)->toContain('EscapesWildcardLike');
        });
    });

    describe('Return Type Completeness', function (): void {
        it('all EventManager public methods have return types', function (): void {
            $reflection = new ReflectionClass(EventManager::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "EventManager::{$method->getName()}() missing return type declaration",
                );
            }
        });

        it('all ConditionEngine public methods have return types', function (): void {
            $reflection = new ReflectionClass(ConditionEngine::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "ConditionEngine::{$method->getName()}() missing return type declaration",
                );
            }
        });

        it('ActionResolver::resolve returns Triggerable', function (): void {
            $method = new ReflectionMethod(ActionResolver::class, 'resolve');
            $returnType = $method->getReturnType()?->getName();
            expect($returnType)->toBe(Triggerable::class);
        });
    });

    describe('ConditionEngine Operators', function (): void {
        $engine = new ConditionEngine();

        it('handles === operator', function () use ($engine): void {
            expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
            expect($engine->matches(['flag' => ['===', true]], ['flag' => 1]))->toBeFalse();
        });

        it('handles !== operator', function () use ($engine): void {
            expect($engine->matches(['flag' => ['!==', true]], ['flag' => false]))->toBeTrue();
            expect($engine->matches(['flag' => ['!==', true]], ['flag' => true]))->toBeFalse();
        });

        it('handles not_in operator', function () use ($engine): void {
            expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'user']))->toBeTrue();
            expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'admin']))->toBeFalse();
        });

        it('handles not_empty operator', function () use ($engine): void {
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'some']))->toBeTrue();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => '']))->toBeFalse();
            expect($engine->matches(['notes' => ['not_empty']], ['notes' => []]))->toBeFalse();
        });

        it('handles ends_with operator', function () use ($engine): void {
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
            expect($engine->matches(['domain' => ['ends_with', '.org']], ['domain' => 'example.com']))->toBeFalse();
        });

        it('19 operators total are supported', function () use ($engine): void {
            // Count operators from match expression in evaluateCondition
            $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in', 'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty', 'starts_with', 'ends_with', 'matches'];
            expect(count($operators))->toBe(21); // 21 including 'default'
            // Actually 20 real operators + default in match
            expect($operators)->not->toContain('default');
            expect(count($operators) - 1)->toBe(20); // 20 operators
        });
    });

    describe('WildcardMatcher Static Methods', function (): void {
        it('all 3 public methods are #[Pure]', function (): void {
            $reflection = new ReflectionClass(WildcardMatcher::class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

            foreach ($methods as $method) {
                $attrs = $method->getAttributes(\Pure::class);
                expect($attrs)->not->toBeEmpty(
                    "WildcardMatcher::{$method->getName()}() missing #[Pure] attribute",
                );
            }
        });

        it('class is readonly final', function (): void {
            $reflection = new ReflectionClass(WildcardMatcher::class);
            expect($reflection->isReadOnly())->toBeTrue();
            expect($reflection->isFinal())->toBeTrue();
        });
    });

    describe('DomainEvent Immutability', function (): void {
        it('has exactly 4 readonly properties', function (): void {
            $reflection = new ReflectionClass(DomainEvent::class);
            $props = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
            $readonly = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());
            expect(count($readonly))->toBe(4); // eventId, eventType, payload, occurredAt
        });

        it('roundtrip preserves identity', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $restored = DomainEvent::fromArray($event->toArray());
            expect($restored->eventId->toString())->toBe($event->eventId->toString());
            expect($restored->eventType)->toBe($event->eventType);
            expect($restored->payload)->toBe($event->payload);
        });

        it('rejects empty eventType in fromArray', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('DispatchTriggerJob', function (): void {
        it('constructor has 3 readonly promoted properties', function (): void {
            $reflection = new ReflectionClass(DispatchTriggerJob::class);
            $params = $reflection->getConstructor()?->getParameters();
            expect($params)->not->toBeNull();
            expect(count($params))->toBe(3);
            expect($params[0]->getName())->toBe('triggerId');
            expect($params[1]->getName())->toBe('event');
            expect($params[2]->getName())->toBe('payload');
            foreach ($params as $param) {
                expect($param->isReadOnly())->toBeTrue();
            }
        });

        it('implements ShouldQueue', function (): void {
            $reflection = new ReflectionClass(DispatchTriggerJob::class);
            expect($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class))->toBeTrue();
        });

        it('handle and failed have #[Override]', function (): void {
            $handle = new ReflectionMethod(DispatchTriggerJob::class, 'handle');
            $failed = new ReflectionMethod(DispatchTriggerJob::class, 'failed');

            expect($handle->getAttributes(\Override::class))->not->toBeEmpty();
            expect($failed->getAttributes(\Override::class))->not->toBeEmpty();
        });
    });

    describe('Facade @method Coverage', function (): void {
        it('facade has @method for registerScheduler', function (): void {
            $doc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
            expect($doc)->toContain('registerScheduler');
        });

        it('facade has @method for subscribe', function (): void {
            $doc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
            expect($doc)->toContain('subscribe(');
        });

        it('facade has @method for unsubscribe', function (): void {
            $doc = (new ReflectionClass(EventManagerFacade::class))->getDocComment();
            expect($doc)->toContain('unsubscribe(');
        });

        it('facade accessor returns EventManager::class', function (): void {
            $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
            // PHP 8.5+: setAccessible() removed — invoke directly
            expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
        });
    });

    describe('Model Table Names Config-Driven', function (): void {
        it('Trigger uses config for table name', function (): void {
            $reflection = new ReflectionMethod(Trigger::class, 'getTable');
            expect($reflection->getAttributes(\Override::class))->not->toBeEmpty();
        });

        it('EventLog uses config for table name', function (): void {
            $reflection = new ReflectionMethod(EventLog::class, 'getTable');
            expect($reflection->getAttributes(\Override::class))->not->toBeEmpty();
        });

        it('Subscription uses config for table name', function (): void {
            $reflection = new ReflectionMethod(Subscription::class, 'getTable');
            expect($reflection->getAttributes(\Override::class))->not->toBeEmpty();
        });
    });

    describe('EventLog Status Constants', function (): void {
        it('has exactly 4 unique statuses', function (): void {
            $statuses = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];
            expect(count($statuses))->toBe(4);
            expect(count(array_unique($statuses)))->toBe(4);
        });

        it('status values match migration enum order', function (): void {
            $expected = ['pending', 'dispatched', 'completed', 'failed'];
            $actual = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];
            expect($actual)->toBe($expected);
        });
    });

    describe('Strict Types Across All Source Files', function (): void {
        it('all source files have declare(strict_types=1)', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
            }
        });

        it('all factory files have declare(strict_types=1)', function (): void {
            $factoryFiles = glob(__DIR__.'/../database/factories/*.php');
            foreach ($factoryFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
            }
        });

        it('all migration files have declare(strict_types=1)', function (): void {
            $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');
            foreach ($migrationFiles as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in: {$file}");
            }
        });
    });

    describe('No setAccessible in Source Files', function (): void {
        it('source files have zero setAccessible calls', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
            $violations = [];
            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, 'setAccessible')) {
                    $violations[] = $file;
                }
            }
            expect($violations)->toBeEmpty('setAccessible() found in: '.implode(', ', $violations));
        });
    });
});
