<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
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

// Load test action classes
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Config::set('events.disabled', false);
    Config::set('events.wildcard_cache_ttl', 300);
    Config::set('events.subscriptions.max_failures', 10);
    Config::set('events.subscriptions.timeout', 30);
    Config::set('events.subscriptions.auto_generate_secret', true);
    Config::set('events.retry.tries', 3);
    Config::set('events.retry.backoff', '60,300,900');
    Config::set('events.queue.queue', 'default');
    Config::set('events.queue.connection', null);

    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
    Cache::forget('zeroboiler:events:enabled_wildcard_triggers');
});

describe('EventManager Fire & Dispatch', function (): void {
    it('fires event and dispatches matching sync trigger with condition evaluation', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => \App\Actions\SendOrderNotification::class,
            'conditions' => ['amount' => ['>', 50]],
            'enabled' => true,
            'async' => false,
            'priority' => 10,
        ]);

        EventManagerFacade::fire('order.placed', ['order_id' => 1, 'amount' => 100]);

        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->event)->toBe('order.placed');
    });

    it('does not dispatch trigger when conditions do not match', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => \App\Actions\SendOrderNotification::class,
            'conditions' => ['amount' => ['>', 500]],
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed', ['order_id' => 1, 'amount' => 100]);

        expect(EventLog::count())->toBe(0);
    });

    it('dispatches wildcard triggers for matching events', function (): void {
        Trigger::factory()->create([
            'event' => 'order.*',
            'action' => \App\Actions\LogOrderEvent::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed', ['test' => true]);
        expect(EventLog::count())->toBe(1);

        EventManagerFacade::fire('order.shipped', ['test' => true]);
        expect(EventLog::count())->toBe(2);

        EventManagerFacade::fire('user.created', ['test' => true]);
        expect(EventLog::count())->toBe(2); // unchanged
    });

    it('deduplicates triggers matched by both exact and wildcard queries', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('order.placed', ['test' => true]);

        // Should only be called once, not twice (exact + wildcard duplicate)
        expect(EventLog::count())->toBe(1);
    });

    it('respects priority ordering when dispatching multiple triggers', function (): void {
        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => \App\Actions\LowPriority::class,
            'enabled' => true,
            'async' => false,
            'priority' => 1,
        ]);

        Trigger::factory()->create([
            'event' => 'order.placed',
            'action' => \App\Actions\HighPriority::class,
            'enabled' => true,
            'async' => false,
            'priority' => 100,
        ]);

        EventManagerFacade::fire('order.placed', ['test' => true]);

        $logs = EventLog::orderBy('id')->get();
        expect($logs)->toHaveCount(2)
            ->and($logs[0]->event)->toBe('order.placed')
            ->and($logs[1]->event)->toBe('order.placed');
    });

    it('creates event log entry for sync dispatch with duration', function (): void {
        Trigger::factory()->create([
            'event' => 'test.event',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('test.event', ['data' => 'value']);

        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->event)->toBe('test.event')
            ->and($log->duration_ms)->not->toBeNull()
            ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
    });

    it('marks event log as failed when action throws', function (): void {
        Trigger::factory()->create([
            'event' => 'test.fail',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // We need a failing action — create a minimal class inline
        $failingClass = 'FailingAction_'.uniqid();
        eval("namespace App\\Actions; final class {$failingClass} implements \\ZeroBoiler\\Events\\Contracts\\Triggerable { public function handle(array \$p): void { throw new \\RuntimeException('deliberate'); } }");

        Trigger::query()->delete();
        Trigger::factory()->create([
            'event' => 'test.fail',
            'action' => "\\App\\Actions\\{$failingClass}",
            'enabled' => true,
            'async' => false,
        ]);

        expect(fn () => EventManagerFacade::fire('test.fail', []))
            ->toThrow(\RuntimeException::class, 'deliberate');

        $log = EventLog::first();
        expect($log)->not->toBeNull()
            ->and($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toBe('deliberate');
    });

    it('silently returns when event system is globally disabled', function (): void {
        Trigger::factory()->create([
            'event' => 'test.event',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        Config::set('events.disabled', true);

        EventManagerFacade::fire('test.event', ['data' => 'value']);

        expect(EventLog::count())->toBe(0);
    });
});

describe('Cache Management', function (): void {
    it('invalidates trigger cache on enable/disable/delete', function (): void {
        $trigger = Trigger::factory()->create([
            'event' => 'cache.test',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        // Populate the cache by firing
        EventManagerFacade::fire('cache.test', ['data' => 'value']);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

        // Disable should invalidate
        EventManagerFacade::disable($trigger->id);
        expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();

        // Enable should work
        EventManagerFacade::enable($trigger->id);
        $trigger->refresh();
        expect($trigger->enabled)->toBeTrue();

        // Delete should invalidate
        EventManagerFacade::deleteTrigger($trigger->id);
        expect(Trigger::find($trigger->id))->toBeNull();
    });
});

describe('Trigger CRUD', function (): void {
    it('list, get, delete triggers', function (): void {
        $trigger1 = Trigger::factory()->create([
            'event' => 'crud.test',
            'action' => \App\Actions\SendOrderNotification::class,
            'priority' => 10,
        ]);

        $trigger2 = Trigger::factory()->create([
            'event' => 'crud.test',
            'action' => \App\Actions\LogOrderEvent::class,
            'priority' => 5,
        ]);

        $triggers = EventManagerFacade::listTriggers('crud.test');
        expect($triggers)->toHaveCount(2);

        $found = EventManagerFacade::getTrigger($trigger1->id);
        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($trigger1->id);

        $result = EventManagerFacade::deleteTrigger($trigger1->id);
        expect($result)->toBeTrue();
        expect(EventManagerFacade::getTrigger($trigger1->id))->toBeNull();

        $result = EventManagerFacade::deleteTrigger('non-existent-id');
        expect($result)->toBeFalse();
    });
});

describe('Subscription Lifecycle', function (): void {
    it('create, list, unsubscribe', function (): void {
        $sub = EventManagerFacade::subscribe('order.placed', 'https://example.com/webhook')
            ->withSecret('whsec_test123')
            ->priority(5)
            ->save();

        expect($sub)->not->toBeNull()
            ->and($sub->event)->toBe('order.placed')
            ->and($sub->url)->toBe('https://example.com/webhook')
            ->and($sub->secret)->toBe('whsec_test123')
            ->and($sub->active)->toBeTrue();

        $subs = EventManagerFacade::listSubscriptions('order.placed');
        expect($subs)->not->toBeEmpty();

        $found = EventManagerFacade::getSubscription($sub->id);
        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($sub->id);

        $result = EventManagerFacade::unsubscribe($sub->id);
        expect($result)->toBeTrue();
        expect(Subscription::find($sub->id))->toBeNull();
    });

    it('auto-generates secret when none provided', function (): void {
        Config::set('events.subscriptions.auto_generate_secret', true);

        $sub = EventManagerFacade::subscribe('auto.secret', 'https://example.com/hook')
            ->save();

        expect($sub->secret)->not->toBeNull()
            ->and($sub->secret)->toMatch('/^whsec_/');
    });

    it('rejects non-HTTP URLs', function (): void {
        expect(fn () => EventManagerFacade::subscribe('test.event', 'ftp://evil.com/upload')
            ->save())->toThrow(\InvalidArgumentException::class);

        expect(fn () => EventManagerFacade::subscribe('test.event', 'file:///etc/passwd')
            ->save())->toThrow(\InvalidArgumentException::class);
    });
});

describe('Event History & Stats', function (): void {
    it('history and stats work correctly', function (): void {
        Trigger::factory()->create([
            'event' => 'stats.test',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('stats.test', ['data' => 'value']);
        EventManagerFacade::fire('stats.test', ['data' => 'value2']);

        $history = EventManagerFacade::getEventHistory('stats.test');
        expect($history)->toHaveCount(2);

        $stats = EventManagerFacade::getStats();
        expect($stats)
            ->toHaveKey('total_logs')
            ->and($stats['total_logs'])->toBe(2)
            ->and($stats['total_triggers'])->toBe(1)
            ->and($stats['active_triggers'])->toBe(1)
            ->and($stats['completed'])->toBe(2)
            ->and($stats['failed'])->toBe(0);
    });

    it('purge logs removes old completed logs', function (): void {
        Trigger::factory()->create([
            'event' => 'purge.test',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManagerFacade::fire('purge.test', ['data' => 'value']);

        $deleted = EventManagerFacade::purgeLogs(now()->addDay(), includePending: false);
        expect($deleted)->toBe(1);
        expect(EventLog::count())->toBe(0);
    });
});

describe('Validation', function (): void {
    it('fire throws on empty event name', function (): void {
        expect(fn () => EventManagerFacade::fire(''))
            ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
    });

    it('trigger builder rejects empty event name', function (): void {
        expect(fn () => EventManagerFacade::on('')
            ->action(\App\Actions\SendOrderNotification::class)
            ->save())->toThrow(\InvalidArgumentException::class, 'Event name is required');
    });

    it('trigger builder rejects when no action is provided', function (): void {
        expect(fn () => EventManagerFacade::on('test.event')
            ->save())->toThrow(\InvalidArgumentException::class, 'At least one action is required');
    });
});

describe('WildcardMatcher', function (): void {
    it('matches cross-segment wildcard patterns', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
        expect(WildcardMatcher::matches('order.**', 'user.placed'))->toBeFalse();
    });

    it('findMatchingPatterns returns matching patterns', function (): void {
        $patterns = ['order.*', 'user.*', 'order.placed'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toContain('order.*')
            ->toContain('order.placed')
            ->not->toContain('user.*');
    });

    it('extractWildcards returns wildcard values', function (): void {
        expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created'))
            ->toBe(['profile']);

        expect(WildcardMatcher::extractWildcards('order.*', 'order.placed'))
            ->toBe(['placed']);

        // Cross-segment returns empty
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))
            ->toBe([]);
    });
});

describe('ConditionEngine Operators', function (): void {
    it('evaluates all 19 operators', function (): void {
        $engine = app(ConditionEngine::class);

        // Simple equality
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
        expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

        // Comparison
        expect($engine->matches(['amount' => ['>', 50]], ['amount' => 100]))->toBeTrue();
        expect($engine->matches(['amount' => ['<', 50]], ['amount' => 25]))->toBeTrue();
        expect($engine->matches(['amount' => ['>=', 50]], ['amount' => 50]))->toBeTrue();
        expect($engine->matches(['amount' => ['<=', 50]], ['amount' => 50]))->toBeTrue();

        // Array
        expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
        expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'user']))->toBeTrue();

        // String
        expect($engine->matches(['name' => ['contains', 'John']], ['name' => 'John Doe']))->toBeTrue();
        expect($engine->matches(['name' => ['not_contains', 'spam']], ['name' => 'valid']))->toBeTrue();
        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
        expect($engine->matches(['email' => ['ends_with', '@test.com']], ['email' => 'admin@test.com']))->toBeTrue();
        expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

        // Null
        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
        expect($engine->matches(['name' => ['not_null']], ['name' => 'test']))->toBeTrue();
        expect($engine->matches(['items' => ['empty']], ['items' => []]))->toBeTrue();
        expect($engine->matches(['name' => ['not_empty']], ['name' => 'test']))->toBeTrue();

        // Between
        expect($engine->matches(['amount' => ['between', [10, 100]]], ['amount' => 50]))->toBeTrue();

        // Strict
        expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
        expect($engine->matches(['flag' => ['!==', false]], ['flag' => true]))->toBeTrue();

        // Dot notation
        expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();

        // Unknown operator
        expect($engine->matches(['field' => ['unknown_op', 'value']], ['field' => 'value']))->toBeFalse();

        // AND logic
        expect($engine->matches(
            ['status' => 'active', 'amount' => ['>', 50]],
            ['status' => 'active', 'amount' => 100],
        ))->toBeTrue();

        expect($engine->matches(
            ['status' => 'active', 'amount' => ['>', 50]],
            ['status' => 'inactive', 'amount' => 100],
        ))->toBeFalse();
    });
});

describe('DomainEvent', function (): void {
    it('roundtrip preserves identity', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('user.created', [
            'email' => 'test@example.com',
        ]);

        $data = $event->toArray();
        $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($event->eventId->toString())
            ->and($restored->eventType)->toBe('user.created')
            ->and($restored->payload)->toBe(['email' => 'test@example.com'])
            ->and($restored->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($event->occurredAt->format(\DateTimeImmutable::ATOM));
    });

    it('rejects empty eventType in fromArray', function (): void {
        expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]))
            ->toThrow(\InvalidArgumentException::class, 'eventType is required');
    });
});

describe('DispatchTriggerJob', function (): void {
    it('reads config for tries and backoff', function (): void {
        Config::set('events.retry.tries', 5);
        Config::set('events.retry.backoff', '10,30,60,120,300');
        Config::set('events.queue.queue', 'events');
        Config::set('events.queue.connection', 'redis');

        $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'value']);

        expect($job->tries)->toBe(5)
            ->and($job->backoff)->toBe([10, 30, 60, 120, 300])
            ->and($job->queue)->toBe('events')
            ->and($job->connection)->toBe('redis');
    });
});

describe('EscapesWildcardLike', function (): void {
    it('converts wildcard patterns correctly', function (): void {
        $matcher = new class {
            use EscapesWildcardLike;
        };

        expect($matcher->wildcardToLike('order.placed'))->toBeNull();
        expect($matcher->wildcardToLike('order.*'))->toBe('order.%');
        expect($matcher->wildcardToLike('order.**'))->toBe('order.%');
        expect($matcher->wildcardToLike('test.%_pattern*'))->toBe('test.\\\\%\\_pattern%');
    });
});

describe('ServiceProvider Bindings', function (): void {
    it('registers correct singleton and transient bindings', function (): void {
        $app = app();

        $em1 = $app->make(EventManager::class);
        $em2 = $app->make(EventManager::class);
        expect($em1)->toBe($em2);

        $ce1 = $app->make(ConditionEngine::class);
        $ce2 = $app->make(ConditionEngine::class);
        expect($ce1)->toBe($ce2);

        $contract = $app->make(ConditionEngineContract::class);
        expect($contract)->toBeInstanceOf(ConditionEngine::class);

        $tb1 = $app->make(TriggerBuilder::class);
        $tb2 = $app->make(TriggerBuilder::class);
        expect($tb1)->not->toBe($tb2);

        $sb1 = $app->make(SubscriptionBuilder::class);
        $sb2 = $app->make(SubscriptionBuilder::class);
        expect($sb1)->not->toBe($sb2);

        $ar1 = $app->make(ActionResolver::class);
        $ar2 = $app->make(ActionResolver::class);
        expect($ar1)->toBe($ar2);
    });
});
