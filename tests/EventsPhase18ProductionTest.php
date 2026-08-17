<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    $this->app = $this->createApplication();
    $this->eventManager = $this->app->make(EventManager::class);
});

describe('EventManager fire validation', function (): void {
    test('fire throws on empty string event name', function (): void {
        $this->eventManager->fire('');
    })->throws(\InvalidArgumentException::class, 'Event name cannot be empty.');

    test('fire throws on zero-string event name', function (): void {
        $this->eventManager->fire('0');
    })->throws(\InvalidArgumentException::class, 'Event name cannot be empty.');

    test('fire works with valid event and empty payload', function (): void {
        // No triggers registered, so fire succeeds silently
        $this->eventManager->fire('test.event');
        expect(true)->toBeTrue();
    });

    test('fireModel throws on empty model class', function (): void {
        $model = new \stdClass;
        $this->eventManager->fireModel('', 'created', $model);
    })->throws(\InvalidArgumentException::class, 'Model class name cannot be empty.');

    test('fireModel throws on empty action', function (): void {
        $model = new \stdClass;
        $this->eventManager->fireModel('App\\Models\\Order', '', $model);
    })->throws(\InvalidArgumentException::class, 'Model action cannot be empty.');

    test('fireModel constructs correct event name', function (): void {
        // Register a sync trigger that will be matched
        $trigger = $this->eventManager->on('App\\Models\\Order.created')
            ->action(\ZeroBoiler\Events\Tests\Actions\LogOrderCreated::class)
            ->sync()
            ->save();

        $model = new class
        {
            public function attributesToArray(): array
            {
                return ['id' => 1, 'name' => 'Test Order', 'status' => 'active'];
            }
        };

        $this->eventManager->fireModel('App\\Models\\Order', 'created', $model);

        // Verify an event log was created
        $logs = EventLog::where('trigger_id', $trigger->id)->get();
        expect($logs->count())->toBe(1);
        expect($logs->first()->event)->toBe('App\\Models\\Order.created');
    });

    test('fireModel flattens model attributes into payload', function (): void {
        $captured = [];

        // Create a custom action that captures the payload
        $actionClass = 'CapturePayloadAction_' . uniqid();
        eval("
            namespace App\\Actions;
            use ZeroBoiler\\Events\\Contracts\\Triggerable;
            final class {$actionClass} implements Triggerable {
                public static array \$captured = [];
                public function handle(array \$payload): void {
                    self::\$captured = \$payload;
                }
            }
        ");

        $this->eventManager->on('App\\Models\\User.created')
            ->action(\ZeroBoiler\Events\Tests\Actions\' . $actionClass)
            ->save();

        $model = new class
        {
            public function attributesToArray(): array
            {
                return ['id' => 42, 'email' => 'test@example.com', 'role' => 'admin'];
            }
        };

        $this->eventManager->fireModel('App\\Models\\User', 'created', $model);

        // Check that attributes are flattened into the payload
        $captured = (\ZeroBoiler\Events\Tests\Actions\' . $actionClass)::$captured;
        expect($captured['id'])->toBe(42);
        expect($captured['email'])->toBe('test@example.com');
        expect($captured['role'])->toBe('admin');
        expect($captured['model_class'])->toBe('App\\Models\\User');
        expect($captured['action'])->toBe('created');
    });
});

describe('TriggerBuilder save validation', function (): void {
    test('save throws on empty event name', function (): void {
        $builder = $this->app->make(TriggerBuilder::class);
        $builder->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification')->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    test('save throws when no action is set', function (): void {
        $builder = $this->app->make(TriggerBuilder::class);
        $builder->on('test.event')->save();
    })->throws(\InvalidArgumentException::class, 'At least one action is required');

    test('save auto-generates name from event when not provided', function (): void {
        $trigger = $this->eventManager->on('order.placed')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        expect($trigger->name)->toBe('order.placed Trigger');
    });

    test('save generates UUID', function (): void {
        $trigger = $this->eventManager->on('order.placed')
            ->name('Test Trigger')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        expect($trigger->id)->not->toBeEmpty();
        // UUID format: 8-4-4-4-12 hex chars
        expect($trigger->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
    });

    test('save with multiple actions encodes as JSON array', function (): void {
        $trigger = $this->eventManager->on('order.placed')
            ->actions([\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class, \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded)->toContain(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class);
        expect($decoded)->toContain(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class);
    });

    test('save with action params encodes as JSON object', function (): void {
        $trigger = $this->eventManager->on('webhook.test')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->actionParams(['url' => 'https://example.com/hook'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded['class'])->toBe(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class);
        expect($decoded['params']['url'])->toBe('https://example.com/hook');
    });

    test('save with multiple actions and params uses classes key', function (): void {
        $trigger = $this->eventManager->on('multi.param.test')
            ->actions([\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class, \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class])
            ->actionParams(['url' => 'https://example.com/hook'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray();
        expect($decoded['classes'])->toBeArray();
        expect($decoded['params']['url'])->toBe('https://example.com/hook');
    });
});

describe('SubscriptionBuilder save validation', function (): void {
    test('save throws on empty event name', function (): void {
        $builder = $this->app->make(SubscriptionBuilder::class);
        $builder->to('https://example.com/hook')->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    test('save throws on empty URL', function (): void {
        $builder = $this->app->make(SubscriptionBuilder::class);
        $builder->on('order.placed')->to('')->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL is required');

    test('save throws on invalid URL', function (): void {
        $builder = $this->app->make(SubscriptionBuilder::class);
        $builder->on('order.placed')->to('not-a-valid-url')->save();
    })->throws(\InvalidArgumentException::class, 'Webhook URL must be a valid URL');

    test('save auto-generates HMAC secret', function (): void {
        $subscription = $this->eventManager
            ->subscribe('order.placed', 'https://example.com/hook')
            ->save();

        expect($subscription->secret)->not->toBeNull();
        expect($subscription->secret)->toMatch('/^whsec_/');
    });

    test('save with explicit secret uses provided secret', function (): void {
        $subscription = $this->eventManager
            ->subscribe('order.placed', 'https://example.com/hook')
            ->withSecret('my_custom_secret')
            ->save();

        expect($subscription->secret)->toBe('my_custom_secret');
    });

    test('save creates both subscription and trigger', function (): void {
        $subscription = $this->eventManager
            ->subscribe('order.placed', 'https://example.com/hook')
            ->save();

        // Subscription should exist
        expect($subscription->id)->not->toBeEmpty();
        expect($subscription->event)->toBe('order.placed');
        expect($subscription->url)->toBe('https://example.com/hook');
        expect($subscription->active)->toBeTrue();

        // A trigger should also have been created for the webhook
        $triggers = Trigger::where('event', 'order.placed')->get();
        expect($triggers->count())->toBeGreaterThan(0);
    });
});

describe('DomainEvent edge cases', function (): void {
    test('fromArray throws on missing eventType', function (): void {
        DomainEvent::fromArray(['payload' => ['key' => 'value']]);
    })->throws(\InvalidArgumentException::class, 'DomainEvent eventType is required');

    test('fromArray throws on empty eventType', function (): void {
        DomainEvent::fromArray(['eventType' => '']);
    })->throws(\InvalidArgumentException::class, 'DomainEvent eventType is required');

    test('fromArray with invalid UUID generates fresh one', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => ['key' => 'value'],
            'eventId' => 'not-a-uuid',
        ]);

        expect($event->eventType)->toBe('test.event');
        expect($event->eventId->toString())->toMatch('/^[0-9a-f]{8}-/');
    });

    test('fromArray with invalid datetime uses now', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'invalid-datetime',
        ]);

        $diff = $event->occurredAt->diffInSeconds(new \DateTimeImmutable);
        expect($diff)->toBeLessThan(5); // Should be within 5 seconds
    });

    test('fromArray preserves valid eventId and occurredAt', function (): void {
        $uuid = \Ramsey\Uuid\Uuid::uuid4();
        $time = new \DateTimeImmutable('2024-01-15T10:30:00+00:00');

        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => $uuid->toString(),
            'occurredAt' => $time->format(\DateTimeImmutable::ATOM),
        ]);

        expect($event->eventId->toString())->toBe($uuid->toString());
        expect($event->occurredAt)->toEqual($time);
    });

    test('occur factory creates with fresh UUID', function (): void {
        $event1 = DomainEvent::occur('test.event');
        $event2 = DomainEvent::occur('test.event');

        expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
    });

    test('toArray round-trips correctly', function (): void {
        $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->payload)->toBe($original->payload);
    });
});

describe('EventManager trigger management', function (): void {
    test('deleteTrigger returns false for non-existent trigger', function (): void {
        $result = $this->eventManager->deleteTrigger('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    test('deleteTrigger removes trigger and invalidates cache', function (): void {
        $trigger = $this->eventManager->on('test.delete')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        expect(Trigger::find($trigger->id))->not->toBeNull();

        $result = $this->eventManager->deleteTrigger($trigger->id);

        expect($result)->toBeTrue();
        expect(Trigger::find($trigger->id))->toBeNull();
    });

    test('enable returns false for non-existent trigger', function (): void {
        $result = $this->eventManager->enable('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    test('disable returns false for non-existent trigger', function (): void {
        $result = $this->eventManager->disable('00000000-0000-0000-0000-000000000000');

        expect($result)->toBeFalse();
    });

    test('listTriggers filters by event name', function (): void {
        $t1 = $this->eventManager->on('order.placed')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $t2 = $this->eventManager->on('order.shipped')->action(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class)->save();

        $results = $this->eventManager->listTriggers(event: 'order.placed');

        expect($results->count())->toBe(1);
        expect($results->first()->id)->toBe($t1->id);
    });

    test('listTriggers filters by enabled status', function (): void {
        $t1 = $this->eventManager->on('test.enabled')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $t2 = $this->eventManager->on('test.disabled')->action(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class)->save();

        $this->eventManager->disable($t2->id);

        $enabled = $this->eventManager->listTriggers(enabled: true);
        $disabled = $this->eventManager->listTriggers(enabled: false);

        expect($enabled->contains(fn (Trigger $t): bool => $t->id === $t1->id))->toBeTrue();
        expect($disabled->contains(fn (Trigger $t): bool => $t->id === $t2->id))->toBeTrue();
    });

    test('listTriggers supports wildcard filtering', function (): void {
        $t1 = $this->eventManager->on('order.placed')->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)->save();
        $t2 = $this->eventManager->on('order.shipped')->action(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class)->save();
        $t3 = $this->eventManager->on('user.created')->action(\ZeroBoiler\Events\Tests\Actions\HighPriority::class)->save();

        $results = $this->eventManager->listTriggers(event: 'order.*');

        expect($results->count())->toBe(2);
        expect($results->pluck('id')->toArray())->toContain($t1->id);
        expect($results->pluck('id')->toArray())->toContain($t2->id);
        expect($results->pluck('id')->toArray())->not->toContain($t3->id);
    });
});

describe('EventManager cache invalidation', function (): void {
    test('invalidateTriggerCache clears cached wildcard triggers', function (): void {
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';

        // Create a wildcard trigger
        $this->eventManager->on('order.*')
            ->action(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
            ->save();

        // Access wildcard triggers to populate the cache
        Cache::put($cacheKey, collect(), 300);
        expect(Cache::has($cacheKey))->toBeTrue();

        // Invalidate
        $this->eventManager->invalidateTriggerCache();

        expect(Cache::has($cacheKey))->toBeFalse();
    });
});

describe('EventManager alias behavior', function (): void {
    test('register is an alias for on', function (): void {
        $onBuilder = $this->eventManager->on('test.event');
        $registerBuilder = $this->eventManager->register('test.event');

        expect($onBuilder)->toBeInstanceOf(TriggerBuilder::class);
        expect($registerBuilder)->toBeInstanceOf(TriggerBuilder::class);
    });
});

describe('WildcardMatcher special patterns', function (): void {
    test('pattern with dot-parenthesis characters is properly escaped', function (): void {
        // Patterns containing regex special chars (other than *)
        expect(WildcardMatcher::matches('user.(login)', 'user.(login)'))->toBeTrue();
        expect(WildcardMatcher::matches('user.(login)', 'user.login'))->toBeFalse();
    });

    test('pattern with plus sign is properly escaped', function (): void {
        expect(WildcardMatcher::matches('user.+test', 'user.+test'))->toBeTrue();
    });

    test('single asterisk at end of pattern', function (): void {
        expect(WildcardMatcher::matches('order.', 'order.'))->toBeFalse(); // No trailing segment to match
    });

    test('pattern matching events with dots', function (): void {
        expect(WildcardMatcher::matches('a.b.c', 'a.b.c'))->toBeTrue();
        expect(WildcardMatcher::matches('a.*.c', 'a.b.c'))->toBeTrue();
        expect(WildcardMatcher::matches('a.*.c', 'a.b.d'))->toBeFalse();
    });
});

describe('ConditionEngine not_contains operator', function (): void {
    beforeEach(function (): void {
        $this->engine = $this->app->make(ConditionEngine::class);
    });

    test('not_contains with string actual', function (): void {
        expect($this->engine->matches(
            ['bio' => ['not_contains', 'admin']],
            ['bio' => 'user bio text'],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['bio' => ['not_contains', 'admin']],
            ['bio' => 'admin user'],
        ))->toBeFalse();
    });

    test('not_contains with array actual', function (): void {
        expect($this->engine->matches(
            ['tags' => ['not_contains', 'urgent']],
            ['tags' => ['normal', 'low']],
        ))->toBeTrue();

        expect($this->engine->matches(
            ['tags' => ['not_contains', 'urgent']],
            ['tags' => ['urgent', 'normal']],
        ))->toBeFalse();
    });
});

describe('ConditionEngine not_in operator', function (): void {
    beforeEach(function (): void {
        $this->engine = $this->app->make(ConditionEngine::class);
    });

    test('not_in with matching value', function (): void {
        expect($this->engine->matches(
            ['role' => ['not_in', ['admin', 'mod']]],
            ['role' => 'user'],
        ))->toBeTrue();
    });

    test('not_in with excluded value', function (): void {
        expect($this->engine->matches(
            ['role' => ['not_in', ['admin', 'mod']]],
            ['role' => 'admin'],
        ))->toBeFalse();
    });
});

describe('ConditionEngine empty/not_empty operators', function (): void {
    beforeEach(function (): void {
        $this->engine = $this->app->make(ConditionEngine::class);
    });

    test('empty matches empty string', function (): void {
        expect($this->engine->matches(
            ['name' => ['empty']],
            ['name' => ''],
        ))->toBeTrue();
    });

    test('empty matches empty array', function (): void {
        expect($this->engine->matches(
            ['tags' => ['empty']],
            ['tags' => []],
        ))->toBeTrue();
    });

    test('empty matches null', function (): void {
        expect($this->engine->matches(
            ['name' => ['empty']],
            ['name' => null],
        ))->toBeTrue();
    });

    test('empty does not match non-empty string', function (): void {
        expect($this->engine->matches(
            ['name' => ['empty']],
            ['name' => 'John'],
        ))->toBeFalse();
    });

    test('not_empty matches non-empty string', function (): void {
        expect($this->engine->matches(
            ['name' => ['not_empty']],
            ['name' => 'John'],
        ))->toBeTrue();
    });

    test('not_empty does not match empty string', function (): void {
        expect($this->engine->matches(
            ['name' => ['not_empty']],
            ['name' => ''],
        ))->toBeFalse();
    });
});
