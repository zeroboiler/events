<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Collection;

// Load test action classes

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

describe('Full event lifecycle integration', function (): void {
    test('register → fire → execute → log → history → stats pipeline', function (): void {
        // 1. Register a trigger
        $trigger = EventManagerFacade::on('order.placed')
            ->name('Integration Test Trigger')
            ->action(SendOrderNotification::class)
            ->priority(10)
            ->save();

        expect($trigger)->toBeInstanceOf(Trigger::class)
            ->and($trigger->enabled)->toBeTrue()
            ->and($trigger->event)->toBe('order.placed');

        // 2. Fire the event
        EventManagerFacade::fire('order.placed', ['order_id' => 42, 'total' => 99.99]);

        // 3. Verify event log was created
        $logs = EventLog::where('trigger_id', $trigger->id)->get();
        expect($logs)->toHaveCount(1);
        $log = $logs->first();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->event)->toBe('order.placed')
            ->and($log->payload)->toBe(['order_id' => 42, 'total' => 99.99])
            ->and($log->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);

        // 4. Query history
        $history = EventManagerFacade::getEventHistory(event: 'order.placed');
        expect($history)->toHaveCount(1);

        // 5. Check stats
        $stats = EventManagerFacade::getStats();
        expect($stats['total_logs'])->toBe(1)
            ->and($stats['completed'])->toBe(1)
            ->and($stats['failed'])->toBe(0)
            ->and($stats['success_rate'])->toBe(100.0)
            ->and($stats['top_events'])->toHaveCount(1);
    });

    test('register with conditions → fire with non-matching payload → no log', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->when(['total' => ['>', 500]])
            ->save();

        EventManagerFacade::fire('order.placed', ['total' => 50]);

        expect(EventLog::count())->toBe(0);
    });

    test('register with conditions → fire with matching payload → log created', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->when(['total' => ['>', 500]])
            ->save();

        EventManagerFacade::fire('order.placed', ['total' => 600]);

        expect(EventLog::count())->toBe(1);
    });

    test('wildcard trigger fires for matching events', function (): void {
        EventManagerFacade::on('order.*')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::fire('order.placed', []);
        EventManagerFacade::fire('order.shipped', []);

        expect(EventLog::count())->toBe(2);
    });

    test('wildcard trigger does not fire for non-matching events', function (): void {
        EventManagerFacade::on('order.*')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::fire('user.created', []);

        expect(EventLog::count())->toBe(0);
    });

    test('multiple triggers for same event fire in priority order', function (): void {
        $triggerHigh = EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->priority(100)
            ->save();

        $triggerLow = EventManagerFacade::on('order.placed')
            ->action(LogOrderEvent::class)
            ->priority(1)
            ->save();

        EventManagerFacade::fire('order.placed', []);

        $logs = EventLog::where('event', 'order.placed')->get();
        expect($logs)->toHaveCount(2);
        // Higher priority trigger should fire first
        expect($logs->first()->trigger_id)->toBe($triggerHigh->id);
    });

    test('disabled trigger does not fire', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::fire('order.placed', ['count' => 1]);
        expect(EventLog::count())->toBe(1);

        // Disable the trigger
        $trigger = Trigger::where('event', 'order.placed')->first();
        EventManagerFacade::disable($trigger->id);

        EventManagerFacade::fire('order.placed', ['count' => 2]);
        expect(EventLog::count())->toBe(1); // No new log
    });

    test('delete trigger removes it and prevents firing', function (): void {
        $trigger = EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(1);

        EventManagerFacade::deleteTrigger($trigger->id);

        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(1); // No new log
    });
});

describe('Global disable integration', function (): void {
    test('global disable prevents all events from firing', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::on('user.created')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::setEnabled(false);

        EventManagerFacade::fire('order.placed', []);
        EventManagerFacade::fire('user.created', []);

        expect(EventLog::count())->toBe(0);
        expect(EventManagerFacade::isDisabled())->toBeTrue();
    });

    test('re-enabling allows events to fire again', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::setEnabled(false);
        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(0);

        EventManagerFacade::setEnabled(true);
        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(1);
    });
});

describe('Cache invalidation integration', function (): void {
    test('cache is invalidated on trigger create', function (): void {
        EventManagerFacade::on('order.*')
            ->action(SendOrderNotification::class)
            ->save();

        // This should find the wildcard trigger
        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(1);
    });

    test('cache is invalidated on enable/disable', function (): void {
        $trigger = EventManagerFacade::on('order.*')
            ->action(SendOrderNotification::class)
            ->save();

        // Fire should work
        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(1);

        // Disable and re-enable
        EventManagerFacade::disable($trigger->id);
        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(1);

        EventManagerFacade::enable($trigger->id);
        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(2);
    });

    test('cache is invalidated on delete', function (): void {
        $triggerA = EventManagerFacade::on('order.*')
            ->action(SendOrderNotification::class)
            ->save();

        $triggerB = EventManagerFacade::on('order.placed')
            ->action(LogOrderEvent::class)
            ->save();

        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(2);

        EventManagerFacade::deleteTrigger($triggerA->id);

        EventManagerFacade::fire('order.placed', []);
        expect(EventLog::count())->toBe(3); // Only triggerB should fire
    });
});

describe('fireModel integration', function (): void {
    test('fireModel constructs correct event name and flattens attributes', function (): void {
        EventManagerFacade::on('App\Models.Order.created')
            ->action(SendOrderNotification::class)
            ->when(['status' => 'active'])
            ->save();

        $model = new class {
            public function attributesToArray(): array
            {
                return [
                    'id' => 1,
                    'status' => 'active',
                    'total' => 100,
                ];
            }
        };

        EventManagerFacade::fireModel('App\Models\Order', 'created', $model);

        expect(EventLog::count())->toBe(1);

        $log = EventLog::first();
        $payload = $log->payload;
        expect($payload['id'])->toBe(1)
            ->and($payload['status'])->toBe('active')
            ->and($payload['total'])->toBe(100)
            ->and($payload['model_class'])->toBe('App\Models\Order')
            ->and($payload['action'])->toBe('created')
            ->and($payload['model'])->toBeInstanceOf($model::class);
    });

    test('fireModel rejects empty model class', function (): void {
        expect(fn (): mixed => EventManagerFacade::fireModel('', 'created', new stdClass))
            ->toThrow(InvalidArgumentException::class);
    });

    test('fireModel rejects empty action', function (): void {
        expect(fn (): mixed => EventManagerFacade::fireModel('App\Models\Order', '', new stdClass))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('DomainEvent integration', function (): void {
    test('domain event roundtrip preserves identity', function (): void {
        $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

        $data = $event->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($event->eventId->toString())
            ->and($restored->eventType)->toBe($event->eventType)
            ->and($restored->payload)->toBe($event->payload)
            ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
    });

    test('domain event properties are readonly', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);

        expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class)
            ->and($event->eventType)->toBe('test.event')
            ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
    });
});

describe('Subscription integration', function (): void {
    test('subscribe creates subscription and internal trigger', function (): void {
        $sub = EventManagerFacade::subscribe('order.placed', 'https://example.com/webhook')
            ->save();

        expect($sub)->toBeInstanceOf(Subscription::class)
            ->and($sub->event)->toBe('order.placed')
            ->and($sub->url)->toBe('https://example.com/webhook')
            ->and($sub->active)->toBeTrue()
            ->and($sub->secret)->toStartWith('whsec_');

        // An internal trigger should have been created
        $triggers = Trigger::where('event', 'order.placed')->get();
        expect($triggers)->not->toBeEmpty();
    });

    test('unsubscribe removes subscription', function (): void {
        $sub = EventManagerFacade::subscribe('order.placed', 'https://example.com/webhook')
            ->save();

        expect(EventManagerFacade::unsubscribe($sub->id))->toBeTrue();
        expect(Subscription::find($sub->id))->toBeNull();
    });

    test('list subscriptions filters correctly', function (): void {
        EventManagerFacade::subscribe('order.placed', 'https://example.com/order')
            ->save();
        EventManagerFacade::subscribe('user.created', 'https://example.com/user')
            ->save();

        $subs = EventManagerFacade::listSubscriptions('order.*');
        expect($subs)->toHaveCount(1)
            ->and($subs->first()->event)->toBe('order.placed');

        $active = EventManagerFacade::listSubscriptions(activeOnly: true);
        expect($active)->toHaveCount(2);
    });

    test('subscribe rejects non-HTTP scheme', function (): void {
        expect(function (): void {
            EventManagerFacade::subscribe('order.placed', 'ftp://example.com/webhook')
                ->save();
        })->toThrow(InvalidArgumentException::class);
    });
});

describe('Event history and purge', function (): void {
    test('purgeLogs removes old completed logs', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        // Create some logs
        EventManagerFacade::fire('order.placed', []);
        EventManagerFacade::fire('order.placed', []);

        expect(EventLog::count())->toBe(2);

        // Purge all logs older than now + 1 day
        $deleted = EventManagerFacade::purgeLogs(
            before: Illuminate\Support\Carbon::now()->addDay(),
            includePending: true,
        );

        expect($deleted)->toBe(2)
            ->and(EventLog::count())->toBe(0);
    });

    test('purgeLogs skips logs when before is in the past', function (): void {
        EventManagerFacade::on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        EventManagerFacade::fire('order.placed', []);

        $deleted = EventManagerFacade::purgeLogs(
            before: Illuminate\Support\Carbon::now()->subDays(30),
        );

        expect($deleted)->toBe(0)
            ->and(EventLog::count())->toBe(1);
    });
});

describe('ServiceProvider contract binding', function (): void {
    test('ConditionEngineContract resolves to ConditionEngine', function (): void {
        $engine = app(ConditionEngineContract::class);
        expect($engine)->toBeInstanceOf(ConditionEngine::class);
    });

    test('TriggerBuilder is transient (fresh instance each time)', function (): void {
        $a = app(\ZeroBoiler\Events\TriggerBuilder::class);
        $b = app(\ZeroBoiler\Events\TriggerBuilder::class);
        expect($a)->not->toBe($b);
    });

    test('EventManager is singleton', function (): void {
        $a = app(EventManager::class);
        $b = app(EventManager::class);
        expect($a)->toBe($b);
    });

    test('EventScheduler is singleton', function (): void {
        $a = app(\ZeroBoiler\Events\EventScheduler::class);
        $b = app(\ZeroBoiler\Events\EventScheduler::class);
        expect($a)->toBe($b);
    });
});
