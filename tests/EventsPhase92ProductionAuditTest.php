<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 92 — Production Readiness Final Audit', function () {
    describe('Class Hierarchy and Design', function () {
        test('EventManager is final', function () {
            expect((new ReflectionClass(EventManager::class))->isFinal())->toBeTrue();
        });

        test('ConditionEngine is final', function () {
            expect((new ReflectionClass(ConditionEngine::class))->isFinal())->toBeTrue();
        });

        test('WildcardMatcher is final and readonly', function () {
            $ref = new ReflectionClass(WildcardMatcher::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        test('TriggerBuilder is final', function () {
            expect((new ReflectionClass(TriggerBuilder::class))->isFinal())->toBeTrue();
        });

        test('DomainEvent is final', function () {
            expect((new ReflectionClass(DomainEvent::class))->isFinal())->toBeTrue();
        });

        test('ConditionEngine implements ConditionEngineContract', function () {
            $engine = app(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        test('Triggerable interface requires handle(array): void', function () {
            $ref = new ReflectionClass(Triggerable::class);
            $method = $ref->getMethod('handle');
            expect($method->hasReturnType())->toBeTrue();
            expect((string) $method->getReturnType())->toBe('void');

            $params = $method->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('payload');
        });
    });

    describe('EventManager Constructor DI', function () {
        test('constructor accepts ConditionEngine, ActionResolver, Container', function () {
            $ref = new ReflectionClass(EventManager::class);
            $ctor = $ref->getConstructor();
            $params = $ctor->getParameters();

            expect(count($params))->toBe(3);
            expect($params[0]->getName())->toBe('conditionEngine');
            expect($params[1]->getName())->toBe('actionResolver');
            expect($params[2]->getName())->toBe('app');

            // All constructor params are promoted readonly
            foreach ($params as $p) {
                expect($p->isPromoted())->toBeTrue();
                expect($p->isReadOnly())->toBeTrue();
            }
        });
    });

    describe('Event Lifecycle Full Integration', function () {
        test('fire() dispatches matching trigger synchronously and creates EventLog', function () {
            $manager = app(EventManager::class);

            // Register a trigger
            $trigger = $manager->on('test.lifecycle')
                ->name('Lifecycle Test Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            expect($trigger)->toBeInstanceOf(Trigger::class);
            expect($trigger->enabled)->toBeTrue();

            // Fire the event
            $manager->fire('test.lifecycle', ['key' => 'value']);

            // Verify EventLog was created
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(1);

            $log = $logs->first();
            expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->event)->toBe('test.lifecycle');
            expect($log->payload)->toBe(['key' => 'value']);
            expect($log->duration_ms)->toBeGreaterThanOrEqual(0);
        });

        test('fire() with non-matching conditions skips trigger', function () {
            $manager = app(EventManager::class);

            $manager->on('test.conditional.skip')
                ->name('Conditional Skip Trigger')
                ->action(Phase92NoopAction::class)
                ->when(['priority' => ['>', 100]])
                ->save();

            $manager->fire('test.conditional.skip', ['priority' => 5]);

            // No EventLog should be created since condition fails
            $logs = EventLog::where('event', 'test.conditional.skip')->get();
            expect($logs->count())->toBe(0);
        });

        test('fire() with matching conditions dispatches trigger', function () {
            $manager = app(EventManager::class);

            $manager->on('test.conditional.match')
                ->name('Conditional Match Trigger')
                ->action(Phase92NoopAction::class)
                ->when(['priority' => ['>', 100]])
                ->save();

            $manager->fire('test.conditional.match', ['priority' => 200]);

            $logs = EventLog::where('event', 'test.conditional.match')->get();
            expect($logs->count())->toBe(1);
            expect($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED);
        });
    });

    describe('Wildcard Matching Integration', function () {
        test('fire() with wildcard trigger matches multiple events', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.wildcard.*')
                ->name('Wildcard Integration Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            // Fire two different events matching the pattern
            $manager->fire('test.wildcard.created', ['action' => 'create']);
            $manager->fire('test.wildcard.updated', ['action' => 'update']);

            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(2);
        });

        test('fire() does not match non-matching wildcard', function () {
            $manager = app(EventManager::class);

            $manager->on('test.narrow.specific')
                ->name('Non-Wildcard Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            $manager->fire('test.narrow.other');

            $logs = EventLog::where('event', 'test.narrow.other')->get();
            expect($logs->count())->toBe(0);
        });

        test('wildcard cache invalidation works', function () {
            $manager = app(EventManager::class);

            // Register a wildcard trigger
            $trigger = $manager->on('test.cache.invalid.*')
                ->name('Cache Invalidation Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            // Fire and verify match
            $manager->fire('test.cache.invalid.event');
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(1);

            // Disable trigger — should invalidate cache
            $manager->disable($trigger->id);

            // Fire again — disabled triggers shouldn't match
            $manager->fire('test.cache.invalid.event2');
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            // Still 1 log from before disable
            expect($logs->count())->toBe(1);
        });
    });

    describe('Action Resolution Error Handling', function () {
        test('fire() throws on non-existent action class', function () {
            $manager = app(EventManager::class);

            $manager->on('test.bad.action')
                ->name('Bad Action Trigger')
                ->action('NonExistent\Action\Class')
                ->save();

            $manager->fire('test.bad.action');
        })->throws(InvalidArgumentException::class, 'Triggerable class');

        test('fire() throws on class that does not implement Triggerable', function () {
            $manager = app(EventManager::class);

            $manager->on('test.non.triggerable')
                ->name('Non-Triggerable Trigger')
                ->action(\stdClass::class)
                ->save();

            $manager->fire('test.non.triggerable');
        })->throws(InvalidArgumentException::class, 'must implement');
    });

    describe('Global Disable Integration', function () {
        test('fire() silently returns when globally disabled', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.disabled.global')
                ->name('Global Disable Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            // Disable globally
            $manager->setEnabled(false);

            $manager->fire('test.disabled.global', ['should' => 'not_fire']);

            // No log should be created
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(0);

            // Re-enable
            $manager->setEnabled(true);

            // Now fire again
            $manager->fire('test.disabled.global', ['should' => 'fire']);
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(1);
        });
    });

    describe('Event History and Stats Integration', function () {
        test('getEventHistory returns filtered logs', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.history.event')
                ->name('History Test Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            $manager->fire('test.history.event', ['idx' => 1]);
            $manager->fire('test.history.event', ['idx' => 2]);

            $history = $manager->getEventHistory(event: 'test.history.event');
            expect($history->count())->toBe(2);

            // Test with status filter
            $completed = $manager->getEventHistory(
                event: 'test.history.event',
                status: EventLog::STATUS_COMPLETED,
            );
            expect($completed->count())->toBe(2);
        });

        test('getStats returns correct structure', function () {
            $manager = app(EventManager::class);

            $manager->on('test.stats.event')
                ->name('Stats Test Trigger')
                ->action(Phase92NoopAction::class)
                ->save();

            $manager->fire('test.stats.event');

            $stats = $manager->getStats();

            expect($stats)->toHaveKey('total_logs');
            expect($stats)->toHaveKey('total_triggers');
            expect($stats)->toHaveKey('active_triggers');
            expect($stats)->toHaveKey('completed');
            expect($stats)->toHaveKey('failed');
            expect($stats)->toHaveKey('pending');
            expect($stats)->toHaveKey('dispatched');
            expect($stats)->toHaveKey('success_rate');
            expect($stats)->toHaveKey('failure_rate');
            expect($stats)->toHaveKey('avg_duration_ms');
            expect($stats)->toHaveKey('top_events');
            expect($stats)->toHaveKey('top_failed_events');

            expect($stats['total_logs'])->toBeInt();
            expect($stats['total_triggers'])->toBeInt();
            expect(is_array($stats['top_events']))->toBeTrue();
            expect(is_array($stats['top_failed_events']))->toBeTrue();
        });
    });

    describe('fireModel Integration', function () {
        test('fireModel() flattens model attributes into payload', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('App\\Models\\TestModel.created')
                ->name('Fire Model Trigger')
                ->action(Phase92NoopAction::class)
                ->when(['status' => 'active'])
                ->save();

            $model = new class extends \Illuminate\Database\Eloquent\Model {
                protected $attributes = [
                    'status' => 'active',
                    'name' => 'Test',
                ];

                public function attributesToArray(): array
                {
                    return $this->attributes;
                }
            };

            $manager->fireModel('App\\Models\\TestModel', 'created', $model);

            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(1);
        });
    });

    describe('Config Consistency', function () {
        test('all config keys referenced in source exist in config file', function () {
            $config = config('events');
            expect($config)->not->toBeNull();

            // Verify top-level keys
            expect(isset($config['table_names']))->toBeTrue();
            expect(isset($config['queue']))->toBeTrue();
            expect(isset($config['retry']))->toBeTrue();
            expect(isset($config['retention']))->toBeTrue();
            expect(isset($config['subscriptions']))->toBeTrue();
            expect(isset($config['disabled']))->toBeTrue();
            expect(isset($config['wildcard_cache_ttl']))->toBeTrue();

            // Verify table_names sub-keys
            expect(isset($config['table_names']['triggers']))->toBeTrue();
            expect(isset($config['table_names']['event_logs']))->toBeTrue();
            expect(isset($config['table_names']['subscriptions']))->toBeTrue();

            // Verify subscription config keys
            expect(isset($config['subscriptions']['max_failures']))->toBeTrue();
            expect(isset($config['subscriptions']['timeout']))->toBeTrue();
            expect(isset($config['subscriptions']['signature_algorithm']))->toBeTrue();
            expect(isset($config['subscriptions']['auto_generate_secret']))->toBeTrue();
            expect(isset($config['subscriptions']['cleanup_cron']))->toBeTrue();

            // Verify retention config keys
            expect(isset($config['retention']['days']))->toBeTrue();
            expect(isset($config['retention']['include_pending']))->toBeTrue();
            expect(isset($config['retention']['schedule_cron']))->toBeTrue();

            // Verify queue config keys
            expect(isset($config['queue']['connection']))->toBeTrue();
            expect(isset($config['queue']['queue']))->toBeTrue();

            // Verify retry config keys
            expect(isset($config['retry']['tries']))->toBeTrue();
            expect(isset($config['retry']['backoff']))->toBeTrue();
        });
    });

    describe('Strict Types Compliance', function () {
        test('all source files have declare(strict_types=1)', function () {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $contents = file_get_contents($file->getPathname());
                    if (! str_contains($contents, 'declare(strict_types=1)')) {
                        $violations[] = $file->getPathname();
                    }
                }
            }

            expect($violations)->toBeEmpty();
        });
    });

    describe('DomainEvent Immutability', function () {
        test('DomainEvent properties are readonly', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            $ref = new ReflectionClass($event);
            $props = $ref->getProperties();

            foreach ($props as $prop) {
                expect($prop->isReadOnly())->toBeTrue();
            }
        });

        test('DomainEvent roundtrip preserves eventId and occurredAt', function () {
            $original = DomainEvent::occur('test.replay', ['data' => 42]);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
            expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
        });
    });

    describe('EventManager Public API Completeness', function () {
        test('EventManager has all required public methods', function () {
            $ref = new ReflectionClass(EventManager::class);
            $publicMethods = array_map(
                fn (ReflectionMethod $m) => $m->getName(),
                $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            );

            $required = [
                'on', 'register', 'fire', 'fireModel',
                'enable', 'disable', 'deleteTrigger', 'getTrigger',
                'invalidateTriggerCache', 'isDisabled', 'setEnabled',
                'listTriggers', 'subscribe', 'unsubscribe',
                'listSubscriptions', 'getSubscription', 'subscribeWebhook',
                'getEventHistory', 'getStats', 'purgeLogs',
                'getStalePendingLogs', 'deactivateExceededSubscriptions',
                'executeTrigger', 'registerScheduler',
            ];

            foreach ($required as $method) {
                expect(in_array($method, $publicMethods, true))->toBeTrue("Missing method: {$method}");
            }
        });

        test('all public EventManager methods have return type declarations', function () {
            $ref = new ReflectionClass(EventManager::class);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }
                expect($method->hasReturnType())->toBeTrue(
                    "Method {$method->getName()} missing return type declaration",
                );
            }
        });
    });
});

/**
 * Noop action for Phase 92 production audit tests.
 */
class Phase92NoopAction implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // No-op for testing
    }
}
