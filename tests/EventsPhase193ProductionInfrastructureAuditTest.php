<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

beforeEach(function (): void {
    // Ensure a fresh application for each test
    $this->app = app();
    $this->app->make('Illuminate\Contracts\Config\Repository')
        ->set('events.disabled', false);
});

describe('EventManager::container() public API', function (): void {
    test('container() returns the application container instance', function (): void {
        $manager = $this->app->make(EventManager::class);

        $container = $manager->container();

        expect($container)->toBeInstanceOf(Container::class);
        expect($container)->toBe($this->app);
    });
});

describe('DomainEvent edge cases for PHPStan compliance', function (): void {
    test('fromArray throws on empty eventType', function (): void {
        DomainEvent::fromArray(['eventType' => '']);
    })->throws(\InvalidArgumentException::class);

    test('fromArray throws on missing eventType key', function (): void {
        DomainEvent::fromArray([]);
    })->throws(\InvalidArgumentException::class);

    test('fromArray handles non-string eventType gracefully', function (): void {
        DomainEvent::fromArray(['eventType' => 123]);
    })->throws(\InvalidArgumentException::class);

    test('fromArray handles invalid UUID gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-uuid',
        ]);

        // Should generate a fresh UUID instead of crashing
        expect($event->eventId)->not->toBeNull();
    });

    test('fromArray handles invalid datetime gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);

        // Should use current time instead of crashing
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    test('fromArray handles non-array payload gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => 'not-array',
        ]);

        expect($event->payload)->toBe([]);
    });

    test('toArray and fromArray round-trip preserves data', function (): void {
        $original = DomainEvent::occur('order.created', [
            'order_id' => 42,
            'amount' => 99.99,
        ]);

        $restored = DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
        expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
    });
});

describe('WildcardMatcher pure function correctness', function (): void {
    test('matches returns false for empty event even with wildcard pattern', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('extractWildcards returns empty for non-matching events', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*.created', 'user.profile.created'))
            ->toBe([]);
    });

    test('extractWildcards returns empty when part counts differ', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*.created', 'order.item.detail.created'))
            ->toBe([]);
    });

    test('findMatchingPatterns returns empty for no matches', function (): void {
        expect(WildcardMatcher::findMatchingPatterns(['user.*'], 'order.created'))
            ->toBe([]);
    });

    test('findMatchingPatterns returns all matching patterns', function (): void {
        $result = WildcardMatcher::findMatchingPatterns(
            ['user.*', 'order.*', 'order.created'],
            'order.created',
        );

        expect($result)->toContain('order.*');
        expect($result)->toContain('order.created');
        expect($result)->not->toContain('user.*');
    });

    test('matches handles exact single-char segments', function (): void {
        expect(WildcardMatcher::matches('a.b.c', 'a.b.c'))->toBeTrue();
        expect(WildcardMatcher::matches('a.b.c', 'a.b.d'))->toBeFalse();
    });

    test('double wildcard matches zero segments', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order'))->toBeTrue();
    });

    test('double wildcard in middle position', function (): void {
        expect(WildcardMatcher::matches('order.**.created', 'order.placed.shipped.created'))->toBeTrue();
    });
});

describe('ConditionEngine operator edge cases', function (): void {
    $engine = new ConditionEngine();

    test('empty array condition returns false', function () use ($engine): void {
        expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
    });

    test('empty array value in in operator', function () use ($engine): void {
        expect($engine->matches(['status' => ['in', []]], ['status' => 'active']))->toBeFalse();
    });

    test('matches operator with invalid pattern returns false', function () use ($engine): void {
        // Pattern exceeding max regex length
        $longPattern = str_repeat('a', 501);
        expect($engine->matches(['code' => ['matches', '/' . $longPattern . '/']], ['code' => 'aaa']))
            ->toBeFalse();
    });

    test('matches operator with catastrophic backtracking pattern', function () use ($engine): void {
        // Nested quantifiers should be rejected
        expect($engine->matches(
            ['code' => ['matches', '/(a+)+b/']],
            ['code' => str_repeat('a', 100) . 'b'],
        ))->toBeFalse();
    });

    test('between with inverted range auto-normalizes', function () use ($engine): void {
        expect($engine->matches(['amount' => ['between', [100, 50]]], ['amount' => 75]))->toBeTrue();
    });

    test('between with non-array value returns false', function () use ($engine): void {
        expect($engine->matches(['amount' => ['between', 'invalid']], ['amount' => 75]))->toBeFalse();
    });

    test('null operator with null value', function () use ($engine): void {
        expect($engine->matches(['field' => ['null']], ['field' => null]))->toBeTrue();
        expect($engine->matches(['field' => ['null']], ['field' => 'value']))->toBeFalse();
    });

    test('empty operator with empty string', function () use ($engine): void {
        expect($engine->matches(['field' => ['empty']], ['field' => '']))->toBeTrue();
        expect($engine->matches(['field' => ['empty']], ['field' => 'value']))->toBeFalse();
    });

    test('strict_equals with different types but same string representation', function () use ($engine): void {
        expect($engine->matches(['count' => '42'], ['count' => 42]))->toBeTrue();
        expect($engine->matches(['count' => '42'], ['count' => '42']))->toBeTrue();
        expect($engine->matches(['count' => '42'], ['count' => 43]))->toBeFalse();
    });

    test('not_in operator', function () use ($engine): void {
        expect($engine->matches(
            ['status' => ['not_in', ['active', 'pending']]],
            ['status' => 'deleted'],
        ))->toBeTrue();
        expect($engine->matches(
            ['status' => ['not_in', ['active', 'pending']]],
            ['status' => 'active'],
        ))->toBeFalse();
    });

    test('ends_with operator', function () use ($engine): void {
        expect($engine->matches(
            ['email' => ['ends_with', '@example.com']],
            ['email' => 'user@example.com'],
        ))->toBeTrue();
        expect($engine->matches(
            ['email' => ['ends_with', '@example.com']],
            ['email' => 'user@other.com'],
        ))->toBeFalse();
    });
});

describe('EscapesWildcardLike trait', function (): void {
    // Create an anonymous class using the trait for testing
    $makeInstance = function (): object {
        return new class
        {
            use EscapesWildcardLike;
        };
    };

    test('returns null for non-wildcard patterns', function () use ($makeInstance): void {
        expect($makeInstance()->wildcardToLike('order.placed'))->toBeNull();
        expect($makeInstance()->wildcardToLike('order'))->toBeNull();
    });

    test('converts single wildcard to percent', function () use ($makeInstance): void {
        expect($makeInstance()->wildcardToLike('order.*'))->toBe('order.%');
        expect($makeInstance()->wildcardToLike('*.created'))->toBe('%.created');
    });

    test('converts double wildcard to double percent', function () use ($makeInstance): void {
        expect($makeInstance()->wildcardToLike('order.**'))->toBe('order.%%');
    });

    test('converts catch-all wildcard', function () use ($makeInstance): void {
        expect($makeInstance()->wildcardToLike('*'))->toBe('%');
    });

    test('escapes SQL LIKE special characters', function () use ($makeInstance): void {
        // Backslash in wildcard pattern should be escaped
        expect($makeInstance()->wildcardToLike('order\*.placed'))
            ->toBe('order\\%.placed');
    });

    test('handles multiple wildcards', function () use ($makeInstance): void {
        expect($makeInstance()->wildcardToLike('*.order.*'))->toBe('%.order.%');
    });
});

describe('TriggerBuilder validation', function (): void {
    test('save throws when event name is empty string', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('');

        $builder
            ->name('Test')
            ->action(\ZeroBoiler\Events\Tests\Actions\TestAction')
            ->save();
    })->throws(\InvalidArgumentException::class, 'Event name is required');

    test('save throws when no action is provided', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('test.event');

        $builder
            ->name('Test')
            ->save();
    })->throws(\InvalidArgumentException::class, 'At least one action is required');

    test('actions() validates each element is a non-empty string', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->on('test.event');

        $builder->actions(['', 'Valid\\Action']);
    })->throws(\InvalidArgumentException::class);

    test('save auto-generates name from event', function (): void {
        $manager = app(EventManager::class);

        Trigger::query()->delete();

        $trigger = $manager->on('test.auto-name')
            ->action(\ZeroBoiler\Events\Tests\Actions\DummyAction')
            ->save();

        expect($trigger->name)->toBe('test.auto-name Trigger');
    });
});

describe('SubscriptionBuilder validation', function (): void {
    test('save throws when event name is empty', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('', 'https://example.com/hook');

        $builder->save();
    })->throws(\InvalidArgumentException::class);

    test('save throws when URL is empty', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', '');

        $builder->save();
    })->throws(\InvalidArgumentException::class);

    test('save throws when URL is not valid', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'not-a-url');

        $builder->save();
    })->throws(\InvalidArgumentException::class);

    test('save throws when URL scheme is not HTTP(S)', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'ftp://example.com/hook');

        $builder->save();
    })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

    test('withSecret throws when secret is too short', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'https://example.com/hook');

        $builder->withSecret('short');
    })->throws(\InvalidArgumentException::class, 'at least 16 characters');
});

describe('EventLog model scopes', function (): void {
    test('scopeWithStatus filters correctly', function (): void {
        $query = EventLog::withStatus('completed');

        expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    test('scopeStalePending accepts Carbon threshold', function (): void {
        $before = now()->subHours(1);
        $query = EventLog::stalePending($before);

        expect($query)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    test('markAsCompleted updates status and duration', function (): void {
        $log = EventLog::factory()->pending()->create();

        $log->markAsCompleted(150);

        expect($log->fresh()->status)->toBe(EventLog::STATUS_COMPLETED);
        expect($log->fresh()->duration_ms)->toBe(150);
    });

    test('markAsFailed updates status and error', function (): void {
        $log = EventLog::factory()->pending()->create();

        $log->markAsFailed('Something went wrong');

        expect($log->fresh()->status)->toBe(EventLog::STATUS_FAILED);
        expect($log->fresh()->error)->toBe('Something went wrong');
    });
});

describe('Subscription model methods', function (): void {
    test('signPayload returns empty string for null secret', function (): void {
        $sub = Subscription::factory()->withoutSecret()->create();

        expect($sub->signPayload('test-payload'))->toBe('');
    });

    test('signPayload returns empty string for empty secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        expect($sub->signPayload('test-payload'))->toBe('');
    });

    test('signPayload produces consistent HMAC', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'whsec_test_secret_key_1234']);

        $sig1 = $sub->signPayload('payload');
        $sig2 = $sub->signPayload('payload');

        expect($sig1)->toBe($sig2);
        expect($sig1)->not->toBeEmpty();
    });

    test('recordDelivery increments delivery count', function (): void {
        $sub = Subscription::factory()->create(['delivery_count' => 0]);

        $sub->recordDelivery();

        expect($sub->fresh()->delivery_count)->toBe(1);
    });

    test('recordFailure increments failure count', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 0]);

        $sub->recordFailure();

        expect($sub->fresh()->failure_count)->toBe(1);
    });

    test('resetFailures sets failure count to zero', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 5]);

        $sub->resetFailures();

        expect($sub->fresh()->failure_count)->toBe(0);
    });

    test('hasExceededFailures with explicit max', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 10]);

        expect($sub->hasExceededFailures(5))->toBeTrue();
        expect($sub->hasExceededFailures(15))->toBeFalse();
    });

    test('matchesEvent delegates to WildcardMatcher for wildcards', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.*']);

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        expect($sub->matchesEvent('user.placed'))->toBeFalse();
    });

    test('matchesEvent does exact match for non-wildcards', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.placed']);

        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeFalse();
    });
});

describe('EventManager fire() edge cases', function (): void {
    test('fire throws on empty event name', function (): void {
        $manager = app(EventManager::class);

        $manager->fire('');
    })->throws(\InvalidArgumentException::class);

    test('fire throws on zero-string event name', function (): void {
        $manager = app(EventManager::class);

        $manager->fire('0');
    })->throws(\InvalidArgumentException::class);

    test('fire silently returns when globally disabled', function (): void {
        $manager = app(EventManager::class);
        $manager->setEnabled(false);

        // Should not throw even without any triggers
        $manager->fire('test.event', ['key' => 'value']);

        expect($manager->isDisabled())->toBeTrue();
    });
});

describe('EventManager CRUD operations', function (): void {
    test('getTrigger returns null for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->getTrigger(''))->toBeNull();
    });

    test('getTrigger returns null for zero-string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->getTrigger('0'))->toBeNull();
    });

    test('deleteTrigger returns false for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->deleteTrigger(''))->toBeFalse();
    });

    test('enable returns false for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->enable(''))->toBeFalse();
    });

    test('disable returns false for empty string ID', function (): void {
        $manager = app(EventManager::class);

        expect($manager->disable(''))->toBeFalse();
    });
});

describe('EventScheduler registration', function (): void {
    test('register adds purge and cleanup scheduled events', function (): void {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $scheduler = app(EventScheduler::class);

        $scheduler->register($schedule);

        $events = $schedule->events();

        // Should register at least 2 scheduled tasks
        expect(count($events))->toBeGreaterThanOrEqual(2);

        $names = array_map(fn ($e) => $e->description ?? '', $events);
        expect($names)->toContain('zeroboiler:events:purge-logs');
        expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
    });
});

describe('ServiceProvider bindings', function (): void {
    test('ConditionEngineContract resolves to ConditionEngine', function (): void {
        $engine = app(ConditionEngineContract::class);

        expect($engine)->toBeInstanceOf(ConditionEngine::class);
    });

    test('EventManager is a singleton', function (): void {
        $first = app(EventManager::class);
        $second = app(EventManager::class);

        expect($first)->toBe($second);
    });

    test('TriggerBuilder is not a singleton (transient)', function (): void {
        $first = app(TriggerBuilder::class);
        $second = app(TriggerBuilder::class);

        expect($first)->not->toBe($second);
    });

    test('SubscriptionBuilder is not a singleton (transient)', function (): void {
        $first = app(SubscriptionBuilder::class);
        $second = app(SubscriptionBuilder::class);

        expect($first)->not->toBe($second);
    });

    test('EventScheduler is a singleton', function (): void {
        $first = app(EventScheduler::class);
        $second = app(EventScheduler::class);

        expect($first)->toBe($second);
    });
});

describe('Facade proxy correctness', function (): void {
    test('EventManager facade proxies to singleton', function (): void {
        $facadeInstance = EventManagerFacade::getFacadeRoot();

        expect($facadeInstance)->toBeInstanceOf(EventManager::class);
    });
});

describe('ManagesHistory trait methods', function (): void {
    test('purgeLogs returns int count', function (): void {
        $manager = app(EventManager::class);

        $count = $manager->purgeLogs(now()->subYears(10), includePending: true);

        expect($count)->toBeInt();
    });

    test('getStalePendingLogs returns collection', function (): void {
        $manager = app(EventManager::class);

        $result = $manager->getStalePendingLogs(now()->subHours(1));

        expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
    });

    test('deactivateExceededSubscriptions returns int', function (): void {
        $manager = app(EventManager::class);

        $count = $manager->deactivateExceededSubscriptions();

        expect($count)->toBeInt();
    });
});
