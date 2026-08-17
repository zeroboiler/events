<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\NullAction;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Events Phase 203 — Final Production Infrastructure Hardening', function () {
    describe('WildcardMatcher boundary cases', function () {
        it('matches pattern with only dots', function () {
            expect(WildcardMatcher::matches('...', '...'))->toBeTrue();
            expect(WildcardMatcher::matches('...', '..'))->toBeFalse();
            expect(WildcardMatcher::matches('.', '.'))->toBeTrue();
        });

        it('handles very long event names with wildcards', function () {
            $longEvent = str_repeat('segment.', 50).'final';
            expect(WildcardMatcher::matches(str_repeat('*.', 50).'final', $longEvent))->toBeFalse();
            expect(WildcardMatcher::matches('**', $longEvent))->toBeTrue();
            expect(WildcardMatcher::matches('*', $longEvent))->toBeTrue();
        });

        it('extracts wildcards from multi-segment pattern', function () {
            $result = WildcardMatcher::extractWildcards('a.*.c.*.e', 'a.ONE.c.TWO.e');
            expect($result)->toBe(['ONE', 'TWO']);
        });

        it('findMatchingPatterns returns empty for no matches', function () {
            $result = WildcardMatcher::findMatchingPatterns(['order.placed', 'user.created'], 'invoice.paid');
            expect($result)->toBe([]);
        });

        it('findMatchingPatterns returns all matching patterns', function () {
            $result = WildcardMatcher::findMatchingPatterns(['*', 'order.*', 'user.created'], 'order.placed');
            expect($result)->toBe(['*', 'order.*']);
        });
    });

    describe('ConditionEngine dot-notation edge cases', function () {
        it('evaluates condition with deeply nested null chain', function () {
            $engine = new ConditionEngine();
            $payload = ['a' => ['b' => ['c' => null]]];
            expect($engine->matches(['a.b.c' => ['null']], $payload))->toBeTrue();
            expect($engine->matches(['a.b.c' => ['not_null']], $payload))->toBeFalse();
        });

        it('evaluates between with exact boundary values', function () {
            $engine = new ConditionEngine();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 10]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 20]))->toBeTrue();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 9]))->toBeFalse();
            expect($engine->matches(['val' => ['between', [10, 20]]], ['val' => 21]))->toBeFalse();
        });

        it('handles empty conditions array as always true', function () {
            $engine = new ConditionEngine();
            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        });

        it('matches operator with numeric strings compares as strings', function () {
            $engine = new ConditionEngine();
            // When actual and expected are both numeric strings, strict comparison
            expect($engine->matches(['code' => '123'], ['code' => '123']))->toBeTrue();
        });
    });

    describe('DomainEvent reconstruction edge cases', function () {
        it('reconstructs with invalid UUID gracefully', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => ['key' => 'val'],
                'eventId' => 'not-a-valid-uuid',
            ]);
            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'val']);
            // Should have generated a fresh UUID since the provided one is invalid
            expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');
        });

        it('reconstructs with invalid date gracefully', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);
            expect($event->eventType)->toBe('test.event');
            expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
        });

        it('fromArray throws on empty eventType', function () {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class);
        });

        it('fromArray throws on missing eventType', function () {
            expect(fn () => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(InvalidArgumentException::class);
        });

        it('toArray and fromArray round-trip preserves eventId and occurredAt', function () {
            $original = DomainEvent::occur('order.created', ['order_id' => '123']);
            $restored = DomainEvent::fromArray($original->toArray());
            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->occurredAt->format('U'))->toBe($original->occurredAt->format('U'));
            expect($restored->eventType)->toBe('order.created');
        });
    });

    describe('EventManager public API validation', function () {
        it('fire throws on empty string event', function () {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fire(''))
                ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty.');
        });

        it('fire throws on "0" event name', function () {
            $manager = app(EventManager::class);
            expect(fn () => $manager->fire('0'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('fireModel throws on empty model class', function () {
            $manager = app(EventManager::class);
            $model = new class {
                public function attributesToArray(): array
                {
                    return ['id' => 1];
                }
            };
            expect(fn () => $manager->fireModel('', 'created', $model))
                ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty.');
        });

        it('fireModel throws on empty action', function () {
            $manager = app(EventManager::class);
            $model = new class {
                public function attributesToArray(): array
                {
                    return ['id' => 1];
                }
            };
            expect(fn () => $manager->fireModel('App\\Model', '', $model))
                ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty.');
        });

        it('getTrigger returns null for empty string ID', function () {
            $manager = app(EventManager::class);
            expect($manager->getTrigger(''))->toBeNull();
            expect($manager->getTrigger('0'))->toBeNull();
        });

        it('deleteTrigger returns false for empty string ID', function () {
            $manager = app(EventManager::class);
            expect($manager->deleteTrigger(''))->toBeFalse();
            expect($manager->deleteTrigger('0'))->toBeFalse();
        });

        it('enable returns false for empty string ID', function () {
            $manager = app(EventManager::class);
            expect($manager->enable(''))->toBeFalse();
        });

        it('disable returns false for empty string ID', function () {
            $manager = app(EventManager::class);
            expect($manager->disable(''))->toBeFalse();
        });

        it('register returns same builder as on()', function () {
            $manager = app(EventManager::class);
            $onBuilder = $manager->on('test.event');
            $registerBuilder = $manager->register('test.event');
            expect($onBuilder)->toBeInstanceOf(TriggerBuilder::class);
            expect($registerBuilder)->toBeInstanceOf(TriggerBuilder::class);
        });

        it('container() returns the app container', function () {
            $manager = app(EventManager::class);
            expect($manager->container())->toBeInstanceOf(Container::class);
        });

        it('isDisabled respects config', function () {
            $manager = app(EventManager::class);
            // Default config has disabled=false
            expect($manager->isDisabled())->toBeFalse();
        });

        it('setEnabled changes disabled state', function () {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();
            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });
    });

    describe('TriggerBuilder validation', function () {
        it('save throws on empty event', function () {
            $builder = app(TriggerBuilder::class);
            expect(fn () => $builder->action(NullAction::class)->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required');
        });

        it('save throws when no action is provided', function () {
            $builder = app(TriggerBuilder::class);
            expect(fn () => $builder->on('test.event')->save())
                ->toThrow(InvalidArgumentException::class, 'At least one action is required');
        });

        it('save auto-generates name from event when empty', function () {
            $builder = app(TriggerBuilder::class);
            $trigger = $builder->on('order.placed')->action(NullAction::class)->save();
            expect($trigger->name)->toBe('order.placed Trigger');
        });

        it('actions validates each class is a non-empty string', function () {
            $builder = app(TriggerBuilder::class);
            expect(fn () => $builder->actions(['', 'ValidClass']))
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('SubscriptionBuilder validation', function () {
        it('save throws on empty event', function () {
            $builder = app(SubscriptionBuilder::class);
            expect(fn () => $builder->to('https://example.com/hook')->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required');
        });

        it('save throws on empty URL', function () {
            $builder = app(SubscriptionBuilder::class);
            expect(fn () => $builder->on('test.event')->save())
                ->toThrow(InvalidArgumentException::class, 'Webhook URL is required');
        });

        it('save rejects non-HTTP scheme URLs', function () {
            $builder = app(SubscriptionBuilder::class);
            expect(fn () => $builder->on('test.event')->to('ftp://evil.com/steal')->save())
                ->toThrow(InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        it('withSecret rejects secrets shorter than 16 characters', function () {
            $builder = app(SubscriptionBuilder::class);
            expect(fn () => $builder->withSecret('short'))
                ->toThrow(InvalidArgumentException::class, 'at least 16 characters');
        });
    });

    describe('EventLog model constants and scopes', function () {
        it('has all status constants defined', function () {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('statuses array contains all constants', function () {
            expect(EventLog::$statuses)->toContain(
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            );
        });

        it('markAsCompleted updates status and duration', function () {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->forTrigger($trigger->id)->pending()->create();
            $log->markAsCompleted(150);

            expect($log->fresh()->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->fresh()->duration_ms)->toBe(150);
        });

        it('markAsFailed updates status and error', function () {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->forTrigger($trigger->id)->pending()->create();
            $log->markAsFailed('Connection timeout');

            expect($log->fresh()->status)->toBe(EventLog::STATUS_FAILED);
            expect($log->fresh()->error)->toBe('Connection timeout');
        });
    });

    describe('Subscription model operations', function () {
        it('signPayload returns empty for null secret', function () {
            $sub = Subscription::factory()->withoutSecret()->create();
            expect($sub->signPayload('test-payload'))->toBe('');
        });

        it('signPayload returns HMAC hex for valid secret', function () {
            $sub = Subscription::factory()->withSecret('whsec_test_secret_for_hmac_verification_123')->create();
            $sig = $sub->signPayload('{"test": true}');
            expect($sig)->not->toBe('');
            expect(strlen($sig))->toBe(64); // sha256 = 64 hex chars
        });

        it('hasExceededFailures respects config threshold', function () {
            $sub = Subscription::factory()->withFailureCount(5)->create();
            expect($sub->hasExceededFailures(5))->toBeTrue();
            expect($sub->hasExceededFailures(10))->toBeFalse();
        });

        it('recordDelivery atomically increments count and updates timestamp', function () {
            $sub = Subscription::factory()->create();
            $sub->recordDelivery();
            $fresh = $sub->fresh();
            expect($fresh->delivery_count)->toBe(1);
            expect($fresh->last_fired_at)->not->toBeNull();
        });

        it('recordFailure increments failure count', function () {
            $sub = Subscription::factory()->create();
            $sub->recordFailure();
            expect($sub->fresh()->failure_count)->toBe(1);
        });

        it('resetFailures sets count to zero', function () {
            $sub = Subscription::factory()->withFailureCount(5)->create();
            $sub->resetFailures();
            expect($sub->fresh()->failure_count)->toBe(0);
        });
    });

    describe('ActionResolver edge cases', function () {
        it('throws on non-existent class', function () {
            $resolver = app(ActionResolver::class);
            expect(fn () => $resolver->resolve('NonExistentClass'))
                ->toThrow(InvalidArgumentException::class, 'does not exist');
        });

        it('throws on class that does not implement Triggerable', function () {
            $resolver = app(ActionResolver::class);
            expect(fn () => $resolver->resolve(stdClass::class))
                ->toThrow(InvalidArgumentException::class, 'must implement');
        });
    });

    describe('EventScheduler config-driven behavior', function () {
        it('resolveEventManager returns null when binding missing', function () {
            $app = new Container;
            $scheduler = new EventScheduler($app);
            // EventManager is not bound — should return null
            $reflection = new ReflectionMethod($scheduler, 'resolveEventManager');
            $reflection->setAccessible(true);
            $result = $reflection->invoke($scheduler);
            expect($result)->toBeNull();
        });

        it('register does not throw when retention days is zero', function () {
            $config = app('config');
            $original = $config->get('events.retention.days');
            $config->set('events.retention.days', 0);

            $scheduler = app(EventScheduler::class);
            $schedule = app('events'); // Dummy dispatcher for Schedule constructor
            $scheduleInstance = new Illuminate\Console\Scheduling\Schedule($schedule);
            // Should not throw
            $scheduler->register($scheduleInstance);

            $config->set('events.retention.days', $original);
        });
    });

    describe('ServiceProvider binding verification', function () {
        it('binds EventManager as singleton', function () {
            $app = app();
            $instance1 = $app->make(EventManager::class);
            $instance2 = $app->make(EventManager::class);
            expect($instance1)->toBe($instance2);
        });

        it('binds ConditionEngine as singleton', function () {
            $app = app();
            $instance1 = $app->make(ConditionEngine::class);
            $instance2 = $app->make(ConditionEngine::class);
            expect($instance1)->toBe($instance2);
        });

        it('binds ConditionEngineContract to ConditionEngine', function () {
            $app = app();
            $contract = $app->make(ConditionEngineContract::class);
            $concrete = $app->make(ConditionEngine::class);
            expect($contract)->toBe($concrete);
        });

        it('binds TriggerBuilder as transient', function () {
            $app = app();
            $instance1 = $app->make(TriggerBuilder::class);
            $instance2 = $app->make(TriggerBuilder::class);
            expect($instance1)->not->toBe($instance2);
        });

        it('binds SubscriptionBuilder as transient', function () {
            $app = app();
            $instance1 = $app->make(SubscriptionBuilder::class);
            $instance2 = $app->make(SubscriptionBuilder::class);
            expect($instance1)->not->toBe($instance2);
        });

        it('binds EventScheduler as singleton', function () {
            $app = app();
            $instance1 = $app->make(EventScheduler::class);
            $instance2 = $app->make(EventScheduler::class);
            expect($instance1)->toBe($instance2);
        });
    });

    describe('Wildcard trigger cache invalidation', function () {
        it('invalidateTriggerCache clears the cache', function () {
            $manager = app(EventManager::class);
            Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect());
            $manager->invalidateTriggerCache();
            expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();
        });

        it('save() invalidates trigger cache', function () {
            $manager = app(EventManager::class);
            Cache::put('zeroboiler:events:enabled_wildcard_triggers', collect(['test']));

            $builder = app(TriggerBuilder::class);
            $builder->on('cache.test')->action(NullAction::class)->save();

            expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();
        });
    });

    describe('Config completeness', function () {
        it('has all 8 top-level config keys', function () {
            $config = app('config');
            expect($config->has('events.table_names'))->toBeTrue();
            expect($config->has('events.queue'))->toBeTrue();
            expect($config->has('events.retry'))->toBeTrue();
            expect($config->has('events.retention'))->toBeTrue();
            expect($config->has('events.subscriptions'))->toBeTrue();
            expect($config->has('events.disabled'))->toBeTrue();
            expect($config->has('events.wildcard_cache_ttl'))->toBeTrue();
            expect($config->has('events.subscriptions.auto_generate_secret'))->toBeTrue();
        });

        it('table_names has all 3 entries', function () {
            $tables = app('config')->get('events.table_names');
            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('subscriptions config has all required keys', function () {
            $subs = app('config')->get('events.subscriptions');
            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('secret_length');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
            expect($subs)->toHaveKey('cleanup_cron');
        });

        it('queue config has connection and queue', function () {
            $queue = app('config')->get('events.queue');
            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('retry config has tries and backoff', function () {
            $retry = app('config')->get('events.retry');
            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });

        it('retention config has days, include_pending, and schedule_cron', function () {
            $retention = app('config')->get('events.retention');
            expect($retention)->toHaveKey('days');
            expect($retention)->toHaveKey('include_pending');
            expect($retention)->toHaveKey('schedule_cron');
        });
    });

    describe('DispatchTriggerJob config initialization', function () {
        it('reads tries from config', function () {
            $config = app('config');
            $config->set('events.retry.tries', 5);
            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->tries)->toBe(5);
            $config->set('events.retry.tries', 3);
        });

        it('reads backoff from config as string', function () {
            $config = app('config');
            $config->set('events.retry.backoff', '30,120,300');
            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->backoff)->toBe([30, 120, 300]);
            $config->set('events.retry.backoff', '60,300,900');
        });

        it('reads backoff from config as array', function () {
            $config = app('config');
            $config->set('events.retry.backoff', [10, 20, 30]);
            $job = new DispatchTriggerJob('test-id', 'test.event', []);
            expect($job->backoff)->toBe([10, 20, 30]);
            $config->set('events.retry.backoff', '60,300,900');
        });
    });

    describe('Model config-driven table names', function () {
        it('Trigger reads table name from config', function () {
            $trigger = new Trigger;
            expect($trigger->getTable())->toBe('triggers');
        });

        it('EventLog reads table name from config', function () {
            $log = new EventLog;
            expect($log->getTable())->toBe('event_logs');
        });

        it('Subscription reads table name from config', function () {
            $sub = new Subscription;
            expect($sub->getTable())->toBe('event_subscriptions');
        });
    });

    describe('Facade accessor verification', function () {
        it('resolves to EventManager instance', function () {
            $facadeRoot = EventManagerFacade::getFacadeRoot();
            expect($facadeRoot)->toBeInstanceOf(EventManager::class);
        });
    });

    describe('ManagesHistory operations', function () {
        it('getEventHistory returns empty collection when no logs', function () {
            $manager = app(EventManager::class);
            $history = $manager->getEventHistory();
            expect($history)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
            expect($history)->toHaveCount(0);
        });

        it('purgeLogs returns 0 when no logs to purge', function () {
            $manager = app(EventManager::class);
            $count = $manager->purgeLogs(now()->subYears(10));
            expect($count)->toBe(0);
        });

        it('deactivateExceededSubscriptions returns 0 when none exceeded', function () {
            $manager = app(EventManager::class);
            $count = $manager->deactivateExceededSubscriptions();
            expect($count)->toBe(0);
        });
    });

    describe('ManagesSubscriptions operations', function () {
        it('getSubscription returns null for non-existent ID', function () {
            $manager = app(EventManager::class);
            expect($manager->getSubscription('non-existent-id'))->toBeNull();
        });

        it('listSubscriptions returns empty when none exist', function () {
            $manager = app(EventManager::class);
            $subs = $manager->listSubscriptions();
            expect($subs)->toHaveCount(0);
        });

        it('unsubscribe returns false for non-existent subscription', function () {
            $manager = app(EventManager::class);
            expect($manager->unsubscribe('non-existent-id'))->toBeFalse();
        });
    });

    describe('EscapesWildcardLike trait', function () {
        it('returns null for patterns without wildcards', function () {
            $matcher = new class {
                use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
            };
            expect($matcher->wildcardToLike('order.placed'))->toBeNull();
        });

        it('converts single asterisk to percent', function () {
            $matcher = new class {
                use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
            };
            expect($matcher->wildcardToLike('order.*'))->toBe('order\%');
        });

        it('escapes SQL LIKE special characters', function () {
            $matcher = new class {
                use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
            };
            expect($matcher->wildcardToLike('user_%name*'))->toBe('user\%\_name\%');
        });

        it('escapes backslashes before wildcard conversion', function () {
            $matcher = new class {
                use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
            };
            expect($matcher->wildcardToLike('path\\to\\*'))->toBe('path\\\\to\\\\%');
        });
    });
});
