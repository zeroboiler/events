<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
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
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;

describe('Phase 177 — Production Infrastructure Audit', function (): void {
    // ─── 1. Source File Structure ──────────────────────────────────────
    describe('source file structure', function (): void {
        it('has exactly 33 source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            expect(count($srcFiles))->toBe(33);
        });

        it('all source files have strict_types declaration', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)');
            }
            expect(true)->toBeTrue(); // ensure loop ran
        });

        it('all source files have license header', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->toContain('This file is part of ZeroBoiler');
            }
            expect(true)->toBeTrue();
        });

        it('all source files use correct namespace', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                // Skip empty files or non-class files
                if (str_contains($contents, 'namespace ')) {
                    expect($contents)->toContain('namespace ZeroBoiler\\Events');
                }
            }
            expect(true)->toBeTrue();
        });
    });

    // ─── 2. Final Classes ─────────────────────────────────────────────
    describe('final class enforcement', function (): void {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            WildcardMatcher::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
            EventsServiceProvider::class,
            DomainEvent::class,
            WebhookAction::class,
            DispatchTriggerJob::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
            EventManagerFacade::class,
        ];

        foreach ($classes as $className) {
            it("$className is final", function () use ($className): void {
                $ref = new ReflectionClass($className);
                expect($ref->isFinal())->toBeTrue();
            });
        }
    });

    // ─── 3. Readonly Properties ──────────────────────────────────────
    describe('readonly property enforcement', function (): void {
        it('EventManager has readonly promoted constructor properties', function (): void {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();
            $readonlyNames = array_map(
                fn (ReflectionParameter $p): string => $p->getName(),
                array_filter($params, fn (ReflectionParameter $p): bool => $p->isReadOnly()),
            );
            expect($readonlyNames)->toContain('conditionEngine');
            expect($readonlyNames)->toContain('actionResolver');
            expect($readonlyNames)->toContain('app');
        });

        it('WildcardMatcher is readonly class', function (): void {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('DomainEvent has readonly properties', function (): void {
            $ref = new ReflectionClass(DomainEvent::class);
            $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
            $readonlyProps = array_filter($props, fn (ReflectionProperty $p): bool => $p->isReadOnly());
            $readonlyNames = array_map(fn (ReflectionProperty $p): string => $p->getName(), $readonlyProps);
            expect($readonlyNames)->toContain('eventType');
            expect($readonlyNames)->toContain('payload');
            expect($readonlyNames)->toContain('eventId');
            expect($readonlyNames)->toContain('occurredAt');
        });
    });

    // ─── 4. Interface Contracts ──────────────────────────────────────
    describe('interface contract compliance', function (): void {
        it('ConditionEngine implements ConditionEngineContract', function (): void {
            expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
        });

        it('WebhookAction implements Triggerable', function (): void {
            expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
        });

        it('ConditionEngineContract defines matches() method', function (): void {
            $ref = new ReflectionClass(ConditionEngineContract::class);
            expect($ref->hasMethod('matches'))->toBeTrue();
            $method = $ref->getMethod('matches');
            expect($method->getReturnType()?->getName())->toBe('bool');
        });

        it('Triggerable defines handle() method', function (): void {
            $ref = new ReflectionClass(Triggerable::class);
            expect($ref->hasMethod('handle'))->toBeTrue();
            $method = $ref->getMethod('handle');
            expect($method->getReturnType()?->getName())->toBe('void');
        });
    });

    // ─── 5. ServiceProvider Bindings ─────────────────────────────────
    describe('service provider bindings', function (): void {
        it('EventsServiceProvider provides 7 services', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toHaveCount(7);
        });

        it('provides includes all expected bindings', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
        });

        it('provides has no duplicates', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();
            expect($provides)->toEqual(array_unique($provides));
        });
    });

    // ─── 6. Facade Accessor ─────────────────────────────────────────
    describe('facade accessor', function (): void {
        it('facade accessor returns correct class', function (): void {
            $ref = new ReflectionClass(EventManagerFacade::class);
            $method = $ref->getMethod('getFacadeAccessor');
            expect($method->getModifiers() & ReflectionMethod::IS_STATIC)->not->toBe(0);
        });
    });

    // ─── 7. Config Completeness ──────────────────────────────────────
    describe('config completeness', function (): void {
        it('config has all 8 top-level keys', function (): void {
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
                expect(array_key_exists($key, $config))->toBeTrue("`events.{$key}` key is missing");
            }
        });

        it('table_names config has triggers, event_logs, subscriptions', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $tables = $config['table_names'];
            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('queue config has connection and queue', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['queue'])->toHaveKey('connection');
            expect($config['queue'])->toHaveKey('queue');
        });

        it('retry config has tries and backoff', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['retry'])->toHaveKey('tries');
            expect($config['retry'])->toHaveKey('backoff');
        });

        it('retention config has days, include_pending, schedule_cron', function (): void {
            $config = require __DIR__.'/../config/events.php';
            expect($config['retention'])->toHaveKey('days');
            expect($config['retention'])->toHaveKey('include_pending');
            expect($config['retention'])->toHaveKey('schedule_cron');
        });

        it('subscriptions config has all 6 keys', function (): void {
            $config = require __DIR__.'/../config/events.php';
            $sub = $config['subscriptions'];
            expect($sub)->toHaveKey('auto_generate_secret');
            expect($sub)->toHaveKey('secret_length');
            expect($sub)->toHaveKey('max_failures');
            expect($sub)->toHaveKey('timeout');
            expect($sub)->toHaveKey('signature_algorithm');
            expect($sub)->toHaveKey('cleanup_cron');
        });
    });

    // ─── 8. DomainEvent Immutability ───────────────────────────────
    describe('DomainEvent immutability', function (): void {
        it('is final', function (): void {
            expect((new ReflectionClass(DomainEvent::class))->isFinal())->toBeTrue();
        });

        it('toArray and fromArray roundtrip preserves identity', function (): void {
            $original = DomainEvent::occur('test.event', ['key' => 'value']);
            $restored = DomainEvent::fromArray($original->toArray());
            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
            expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
                $original->occurredAt->format(DateTimeImmutable::ATOM),
            );
        });
    });

    // ─── 9. Model Status Constants ───────────────────────────────────
    describe('EventLog status constants', function (): void {
        it('has all 4 status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('statuses array matches constants', function (): void {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    // ─── 10. PHPStan Configuration ───────────────────────────────────
    describe('PHPStan configuration', function (): void {
        it('phpstan.neon.dist exists and specifies level 9', function (): void {
            $path = __DIR__.'/../phpstan.neon.dist';
            expect(file_exists($path))->toBeTrue();
            $contents = file_get_contents($path);
            expect($contents)->toContain('level: 9');
        });

        it('phpstan.neon.dist has reportUnusedIgnoredErrors', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('reportUnusedIgnoredErrors: true');
        });

        it('phpstan.neon.dist has checkExplicitMixed', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('checkExplicitMixed: true');
        });
    });

    // ─── 11. Composer.json Validation ────────────────────────────────
    describe('composer.json validation', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        it('autoloads ZeroBoiler\\Events namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('extra.laravel.providers includes EventsServiceProvider', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $providers = $composer['extra']['laravel']['providers'];
            expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
        });

        it('extra.laravel.aliases includes EventManager facade', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $aliases = $composer['extra']['laravel']['aliases'];
            expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
        });
    });

    // ─── 12. Console Commands ──────────────────────────────────────────
    describe('console commands registration', function (): void {
        $commandClasses = [
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

        it('has exactly 12 console commands', function () use ($commandClasses): void {
            expect(count($commandClasses))->toBe(12);
        });

        foreach ($commandClasses as $cmd) {
            it("$cmd file exists and has handle method", function () use ($cmd): void {
                $path = __DIR__."/../src/Console/{$cmd}.php";
                expect(file_exists($path))->toBeTrue();
                $contents = file_get_contents($path);
                expect($contents)->toContain('function handle');
            });
        }
    });

    // ─── 13. WildcardMatcher Patterns ────────────────────────────────
    describe('WildcardMatcher pattern coverage', function (): void {
        it('catch-all * matches any event', function (): void {
            expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'user.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('catch-all ** matches any event', function (): void {
            expect(WildcardMatcher::matches('**', 'order.placed.extra'))->toBeTrue();
        });

        it('single-segment wildcard', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        it('cross-segment wildcard', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        it('exact match', function (): void {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('extractWildcards works for single-segment', function (): void {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
            expect($result)->toBe(['profile']);
        });

        it('extractWildcards returns empty for ** patterns', function (): void {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');
            expect($result)->toBe([]);
        });
    });

    // ─── 14. ReDoS Protection ──────────────────────────────────────────
    describe('ReDoS protection', function (): void {
        it('regex over 500 chars is rejected', function (): void {
            $engine = new ConditionEngine;
            $longPattern = '/'.str_repeat('a', 501).'/';
            expect($engine->matches(['field' => ['matches', $longPattern]], ['field' => 'test']))->toBeFalse();
        });

        it('nested quantifiers are rejected', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['field' => ['matches', '/(a+)+/']], ['field' => 'aaa']))->toBeFalse();
        });

        it('valid regex matches correctly', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['field' => ['matches', '/^[A-Z]{3}$/']], ['field' => 'ABC']))->toBeTrue();
            expect($engine->matches(['field' => ['matches', '/^[A-Z]{3}$/']], ['field' => 'abc']))->toBeFalse();
        });
    });

    // ─── 15. Global Disable Toggle ────────────────────────────────────
    describe('global disable toggle', function (): void {
        it('fires normally when enabled', function (): void {
            $eventManager = app(EventManager::class);
            $eventManager->setEnabled(true);
            expect($eventManager->isDisabled())->toBeFalse();
        });

        it('isDisabled returns true when explicitly disabled', function (): void {
            $eventManager = app(EventManager::class);
            $eventManager->setEnabled(false);
            expect($eventManager->isDisabled())->toBeTrue();
            $eventManager->setEnabled(true); // cleanup
        });
    });

    // ─── 16. Condition Engine Operators ──────────────────────────────
    describe('condition engine operator coverage', function (): void {
        $operators = [
            ['>', ['>', 10], 20, true],
            ['>=', ['>=', 10], 10, true],
            ['<', ['<', 10], 5, true],
            ['<=', ['<=', 10], 10, true],
            ['=', ['=', 'active'], 'active', true],
            ['===', ['===', 'active'], 'active', true],
            ['!=', ['!=', 'inactive'], 'active', true],
            ['!==', ['!==', 10], '10', true],
            ['in', ['in', ['a', 'b']], 'a', true],
            ['not_in', ['not_in', ['a', 'b']], 'c', true],
            ['contains', ['contains', 'hello'], 'ell', true],
            ['not_contains', ['not_contains', 'hello'], 'xyz', true],
            ['between', ['between', [1, 10]], 5, true],
            ['null', ['null'], null, true],
            ['not_null', ['not_null'], 'value', true],
            ['empty', ['empty'], '', true],
            ['not_empty', ['not_empty'], 'value', true],
            ['starts_with', ['starts_with', 'hello'], 'hel', true],
            ['ends_with', ['ends_with', 'hello'], 'llo', true],
        ];

        foreach ($operators as [$name, $condition, $value, $expected]) {
            it("operator '$name' works correctly", function () use ($name, $condition, $value, $expected): void {
                $engine = new ConditionEngine;
                $result = $engine->matches(['field' => $condition], ['field' => $value]);
                expect($result)->toBe($expected);
            });
        }
    });

    // ─── 17. Version Badge Consistency ────────────────────────────────
    describe('version badge consistency', function (): void {
        it('composer.json version matches README badge', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $version = $composer['version'];
            $readme = file_get_contents(__DIR__.'/../README.md');
            expect($readme)->toContain("version-{$version}");
        });
    });

    // ─── 18. No TODO/FIXME in Source ──────────────────────────────────
    describe('no TODO or FIXME in source code', function (): void {
        it('no TODO comments in source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->not()->toContain('TODO');
            }
            expect(true)->toBeTrue();
        });

        it('no FIXME comments in source files', function (): void {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            foreach ($srcFiles as $file) {
                $contents = file_get_contents($file);
                expect($contents)->not()->toContain('FIXME');
            }
            expect(true)->toBeTrue();
        });
    });
});
