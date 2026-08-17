<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

// ─────────────────────────────────────────────────
// EventManager::fireModel — detailed tests
// ─────────────────────────────────────────────────

describe('EventManager fireModel', function (): void {
    test('fireModel flattens model attributes into payload', function (): void {
        Trigger::factory()->create([
            'event' => 'App\\Models\\Order.created',
            'action' => SendOrderNotification::class,
            'conditions' => ['status' => 'active'],
            'enabled' => true,
            'async' => false,
        ]);

        $order = new class
        {
            public string $id = '123';
            public string $status = 'active';
            public float $total = 99.99;

            public function attributesToArray(): array
            {
                return [
                    'id' => $this->id,
                    'status' => $this->status,
                    'total' => $this->total,
                ];
            }
        };

        EventManagerFacade::fireModel('App\\Models\\Order', 'created', $order);

        expect(EventLog::count())->toBe(1);
        $log = EventLog::first();
        $payload = $log->payload;
        expect($payload['status'])->toBe('active')
            ->and($payload['model_class'])->toBe('App\\Models\\Order')
            ->and($payload['action'])->toBe('created')
            ->and($log->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('fireModel with non-Eloquent object falls back to toArray', function (): void {
        Trigger::factory()->create([
            'event' => 'App\\Models\\Item.updated',
            'action' => LogOrderEvent::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $item = new class
        {
            public string $name = 'Widget';
            public int $qty = 5;

            public function toArray(): array
            {
                return [
                    'name' => $this->name,
                    'qty' => $this->qty,
                ];
            }
        };

        EventManagerFacade::fireModel('App\\Models\\Item', 'updated', $item);

        expect(EventLog::count())->toBe(1);
    });

    test('fireModel does not dispatch when condition fails', function (): void {
        Trigger::factory()->create([
            'event' => 'App\\Models\\Order.created',
            'action' => SendOrderNotification::class,
            'conditions' => ['total' => ['>', 500]],
            'enabled' => true,
            'async' => false,
        ]);

        $order = new class
        {
            public string $id = '456';
            public float $total = 50.0;

            public function attributesToArray(): array
            {
                return ['id' => $this->id, 'total' => $this->total];
            }
        };

        EventManagerFacade::fireModel('App\\Models\\Order', 'created', $order);

        expect(EventLog::count())->toBe(0);
    });
});

// ─────────────────────────────────────────────────
// EventManager::executeTrigger — failure handling
// ─────────────────────────────────────────────────

describe('EventManager executeTrigger failure', function (): void {
    test('executeTrigger marks log as failed and re-throws exception', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'fail.test',
            'action' => \ZeroBoiler\Events\Tests\Actions\FailingAction',
            'enabled' => true,
            'async' => false,
        ]);

        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'event' => 'fail.test',
            'status' => EventLog::STATUS_PENDING,
        ]);

        // Register a class that throws
        $app = app();
        $app->bind(\ZeroBoiler\Events\Tests\Actions\FailingAction', function () {
            return new class implements \ZeroBoiler\Events\Contracts\Triggerable
            {
                public function handle(array $payload): void
                {
                    throw new \RuntimeException('Action failed intentionally');
                }
            };
        });

        $eventManager = app(EventManager::class);

        expect(fn () => $eventManager->executeTrigger($trigger, $log))
            ->toThrow(\RuntimeException::class, 'Action failed intentionally');

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toBe('Action failed intentionally');
    });

    test('executeTrigger with multiple actions continues on success', function (): void {
        $callCount = 0;

        $app = app();

        $app->bind(\ZeroBoiler\Events\Tests\Actions\CountAction', function () use (&$callCount) {
            return new class($callCount) implements \ZeroBoiler\Events\Contracts\Triggerable
            {
                public function __construct(public int &$count) {}

                public function handle(array $payload): void
                {
                    $this->count++;
                }
            };
        });

        $trigger = Trigger::factory()->create([
            'event' => 'multi.success',
            'action' => json_encode([\ZeroBoiler\Events\Tests\Actions\CountAction', \ZeroBoiler\Events\Tests\Actions\CountAction']),
            'enabled' => true,
            'async' => false,
        ]);

        $log = EventLog::factory()->create([
            'trigger_id' => $trigger->id,
            'event' => 'multi.success',
            'status' => EventLog::STATUS_PENDING,
        ]);

        $eventManager = app(EventManager::class);
        $eventManager->executeTrigger($trigger, $log);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($callCount)->toBe(2);
    });
});

// ─────────────────────────────────────────────────
// WildcardMatcher::extractWildcards enhanced
// ─────────────────────────────────────────────────

describe('WildcardMatcher extractWildcards', function (): void {
    test('extracts single-segment wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

        expect($result)->toBe(['profile']);
    });

    test('extracts multiple single-segment wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('*.order.*', 'user.order.placed');

        expect($result)->toBe(['user', 'placed']);
    });

    test('returns empty for cross-segment wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

        expect($result)->toBe([]);
    });

    test('returns empty when segment counts differ', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.settings.created');

        expect($result)->toBe([]);
    });

    test('returns empty when pattern does not match', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.deleted', 'user.profile.created');

        expect($result)->toBe([]);
    });

    test('returns empty for catch-all pattern', function (): void {
        $result = WildcardMatcher::extractWildcards('*', 'anything');

        expect($result)->toBe([]);
    });
});

// ─────────────────────────────────────────────────
// SubscriptionBuilder auto_generate_secret config
// ─────────────────────────────────────────────────

describe('SubscriptionBuilder auto_generate_secret', function (): void {
    test('secret is auto-generated when config is true (default)', function (): void {
        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('auto.secret.test')
            ->to('https://example.com/webhook')
            ->save();

        expect($subscription->secret)->not->toBeNull()
            ->and($subscription->secret)->toMatch('/^whsec_/');
    });

    test('secret remains null when auto_generate_secret is false', function (): void {
        $app = app();
        $config = $app->make('config');
        $config->set('events.subscriptions.auto_generate_secret', false);

        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('no.auto.secret')
            ->to('https://example.com/webhook')
            ->save();

        expect($subscription->secret)->toBeNull();
    });

    test('provided secret is always used regardless of config', function (): void {
        $app = app();
        $config = $app->make('config');
        $config->set('events.subscriptions.auto_generate_secret', false);

        $builder = app(SubscriptionBuilder::class);
        $subscription = $builder
            ->on('provided.secret')
            ->to('https://example.com/webhook')
            ->withSecret('my_custom_secret')
            ->save();

        expect($subscription->secret)->toBe('my_custom_secret');
    });
});

// ─────────────────────────────────────────────────
// Trigger soft delete
// ─────────────────────────────────────────────────

describe('Trigger soft delete', function (): void {
    test('soft-deleted triggers are excluded by default queries', function (): void {
        $trigger = Trigger::factory()->create(['enabled' => true]);
        $trigger->delete();

        expect(Trigger::count())->toBe(0)
            ->and(Trigger::withTrashed()->count())->toBe(1);
    });

    test('trigger can be restored after soft delete', function (): void {
        $trigger = Trigger::factory()->create(['enabled' => true]);
        $trigger->delete();

        $trigger->restore();

        expect(Trigger::count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────
// Subscription soft delete
// ─────────────────────────────────────────────────

describe('Subscription soft delete', function (): void {
    test('soft-deleted subscriptions are excluded by default queries', function (): void {
        $sub = Subscription::factory()->create(['active' => true]);
        $sub->delete();

        expect(Subscription::count())->toBe(0)
            ->and(Subscription::withTrashed()->count())->toBe(1);
    });

    test('subscription can be restored after soft delete', function (): void {
        $sub = Subscription::factory()->create(['active' => true]);
        $sub->delete();

        $sub->restore();

        expect(Subscription::count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────
// EventManager::listSubscriptions
// ─────────────────────────────────────────────────

describe('EventManager listSubscriptions', function (): void {
    test('returns all subscriptions when no filters', function (): void {
        Subscription::factory()->count(3)->create(['active' => true]);
        Subscription::factory()->count(2)->create(['active' => false]);

        $result = EventManagerFacade::listSubscriptions();

        expect($result)->toHaveCount(5);
    });

    test('filters by event name exactly', function (): void {
        Subscription::factory()->create(['event' => 'order.placed']);
        Subscription::factory()->create(['event' => 'user.created']);

        $result = EventManagerFacade::listSubscriptions('order.placed');

        expect($result)->toHaveCount(1)
            ->and($result->first()->event)->toBe('order.placed');
    });

    test('filters by active only', function (): void {
        Subscription::factory()->create(['active' => true]);
        Subscription::factory()->create(['active' => false]);

        $result = EventManagerFacade::listSubscriptions(activeOnly: true);

        expect($result)->toHaveCount(1)
            ->and($result->first()->active)->toBeTrue();
    });

    test('filters by wildcard event pattern', function (): void {
        Subscription::factory()->create(['event' => 'order.placed']);
        Subscription::factory()->create(['event' => 'order.shipped']);
        Subscription::factory()->create(['event' => 'user.created']);

        $result = EventManagerFacade::listSubscriptions('order.*');

        expect($result)->toHaveCount(2);
    });
});

// ─────────────────────────────────────────────────
// EventManager::getEventHistory filtering
// ─────────────────────────────────────────────────

describe('EventManager getEventHistory', function (): void {
    test('returns event logs for a specific event', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->create(['trigger_id' => $trigger->id, 'event' => 'order.placed']);
        EventLog::factory()->create(['trigger_id' => $trigger->id, 'event' => 'user.created']);

        $result = EventManagerFacade::getEventHistory(event: 'order.placed');

        expect($result)->toHaveCount(1)
            ->and($result->first()->event)->toBe('order.placed');
    });

    test('returns event logs filtered by status', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->completed()->create(['trigger_id' => $trigger->id]);
        EventLog::factory()->failed()->create(['trigger_id' => $trigger->id]);

        $result = EventManagerFacade::getEventHistory(status: 'failed');

        expect($result)->toHaveCount(1)
            ->and($result->first()->status)->toBe('failed');
    });

    test('returns event logs filtered by trigger ID', function (): void {
        $trigger1 = Trigger::factory()->create();
        $trigger2 = Trigger::factory()->create();
        EventLog::factory()->create(['trigger_id' => $trigger1->id]);
        EventLog::factory()->create(['trigger_id' => $trigger2->id]);

        $result = EventManagerFacade::getEventHistory(triggerId: $trigger1->id);

        expect($result)->toHaveCount(1)
            ->and($result->first()->trigger_id)->toBe($trigger1->id);
    });

    test('respects limit parameter', function (): void {
        $trigger = Trigger::factory()->create();
        EventLog::factory()->count(5)->create(['trigger_id' => $trigger->id]);

        $result = EventManagerFacade::getEventHistory(limit: 2);

        expect($result)->toHaveCount(2);
    });
});

// ─────────────────────────────────────────────────
// ConditionEngine edge cases
// ─────────────────────────────────────────────────

describe('ConditionEngine edge cases', function (): void {
    test('evaluates === strict identity operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['count' => ['===', 42]], ['count' => 42]))
            ->toBeTrue()
            ->and($engine->matches(['count' => ['===', '42']], ['count' => 42]))
            ->toBeFalse();
    });

    test('evaluates !== strict not-identity operator', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['count' => ['!==', '42']], ['count' => 42]))
            ->toBeTrue()
            ->and($engine->matches(['count' => ['!==', 42]], ['count' => 42]))
            ->toBeFalse();
    });

    test('strictEquals compares scalars of different types as strings', function (): void {
        $engine = app(ConditionEngine::class);

        // int 42 vs string "42" — different types, compare as strings
        expect($engine->matches(['value' => 42], ['value' => '42']))
            ->toBeTrue();
    });

    test('empty array condition returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['field' => []], ['field' => 'anything']))
            ->toBeFalse();
    });

    test('returns true for empty conditions array', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], []))->toBeTrue();
    });

    test('missing field in payload returns false for equality', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['nonexistent' => 'value'], []))
            ->toBeFalse();
    });
});
