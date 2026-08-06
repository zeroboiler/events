<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('Facade proxy', function (): void {
    test('facade proxies on() to EventManager', function (): void {
        $builder = EventManager::on('test.event');
        expect($builder)->toBeInstanceOf(TriggerBuilder::class);
    });

    test('facade proxies register() to EventManager', function (): void {
        $builder = EventManager::register('test.event');
        expect($builder)->toBeInstanceOf(TriggerBuilder::class);
    });

    test('facade proxies fire() to EventManager', function (): void {
        Trigger::factory()->create([
            'event' => 'facade.test',
            'action' => \App\Actions\SendOrderNotification::class,
            'enabled' => true,
            'async' => false,
        ]);

        EventManager::fire('facade.test', []);
        expect(EventLog::count())->toBe(1);
    });

    test('facade proxies fireModel() to EventManager', function (): void {
        Trigger::factory()->create([
            'event' => 'stdClass.updated',
            'action' => \App\Actions\LogOrderCreated::class,
            'enabled' => true,
            'async' => false,
        ]);

        $model = new stdClass;
        $model->id = 1;
        EventManager::fireModel(stdClass::class, 'updated', $model);
        expect(EventLog::count())->toBe(1);
    });

    test('facade proxies invalidateTriggerCache() to EventManager', function (): void {
        EventManager::invalidateTriggerCache();
        // No exception means proxy works
        expect(true)->toBeTrue();
    });
});

describe('Cache invalidation', function (): void {
    test('invalidateTriggerCache clears wildcard cache', function (): void {
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';

        // Put something in the cache
        Cache::put($cacheKey, collect(['dummy']), 300);

        EventManager::invalidateTriggerCache();

        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('enable invalidates cache on success', function (): void {
        $trigger = Trigger::factory()->create(['enabled' => false]);
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
        Cache::put($cacheKey, collect(['dummy']), 300);

        EventManager::enable($trigger->id);

        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('enable does not invalidate cache when trigger not found', function (): void {
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
        Cache::put($cacheKey, collect(['dummy']), 300);

        $result = EventManager::enable('nonexistent-id');

        expect($result)->toBeFalse()
            ->and(Cache::get($cacheKey))->not->toBeNull();
    });

    test('disable invalidates cache on success', function (): void {
        $trigger = Trigger::factory()->create(['enabled' => true]);
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
        Cache::put($cacheKey, collect(['dummy']), 300);

        EventManager::disable($trigger->id);

        expect(Cache::get($cacheKey))->toBeNull();
    });

    test('disable does not invalidate cache when trigger not found', function (): void {
        $cacheKey = 'zeroboiler:events:enabled_wildcard_triggers';
        Cache::put($cacheKey, collect(['dummy']), 300);

        $result = EventManager::disable('nonexistent-id');

        expect($result)->toBeFalse()
            ->and(Cache::get($cacheKey))->not->toBeNull();
    });
});

describe('ActionResolver', function (): void {
    test('resolves valid Triggerable class from container', function (): void {
        $resolver = app(ActionResolver::class);
        $handler = $resolver->resolve(\App\Actions\SendOrderNotification::class);

        expect($handler)->toBeInstanceOf(\App\Actions\SendOrderNotification::class);
    });

    test('throws on non-existent class', function (): void {
        $resolver = app(ActionResolver::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $resolver->resolve('NonExistent\\ActionClass');
    });

    test('throws on class that does not implement Triggerable', function (): void {
        $resolver = app(ActionResolver::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        $resolver->resolve(stdClass::class);
    });
});

describe('ConditionEngine edge cases', function (): void {
    test('empty conditions array returns true', function (): void {
        $engine = app(ConditionEngine::class);
        expect($engine->matches([], ['key' => 'value']))->toBeTrue();
    });

    test('operator array with only one element falls to default', function (): void {
        $engine = app(ConditionEngine::class);
        // Array with one element — operator is the only value, no comparison target
        // The match default branch calls strictEquals
        $result = $engine->matches(['field' => ['only_op']], ['field' => ['only_op']]);
        expect($result)->toBeTrue();
    });

    test('between with non-array value returns false', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['amount' => ['between', 'not_array']], ['amount' => 50]);
        expect($result)->toBeFalse();
    });

    test('between with three elements returns false (requires exactly 2)', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['amount' => ['between', [10, 50, 100]]], ['amount' => 30]);
        expect($result)->toBeFalse();
    });

    test('in with null value returns false', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['status' => ['in', null]], ['status' => 'active']);
        expect($result)->toBeFalse();
    });

    test('not_in with null value returns false', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['status' => ['not_in', null]], ['status' => 'active']);
        expect($result)->toBeFalse();
    });

    test('comparison operators with null actual value return false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => ['>', 0]], ['amount' => null]))->toBeFalse();
        expect($engine->matches(['amount' => ['>=', 0]], ['amount' => null]))->toBeFalse();
        expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
        expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
    });

    test('string operators with non-string actual return false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['name' => ['starts_with', 'A']], ['name' => 123]))->toBeFalse();
        expect($engine->matches(['name' => ['ends_with', 'z']], ['name' => null]))->toBeFalse();
    });

    test('matches operator with non-string actual returns false', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['code' => ['matches', '/^A/']], ['code' => 123]);
        expect($result)->toBeFalse();
    });

    test('contains with non-array non-string returns false', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['data' => ['contains', 'foo']], ['data' => 123]);
        expect($result)->toBeFalse();
    });

    test('nested dot-notation with intermediate null returns null', function (): void {
        $engine = app(ConditionEngine::class);
        $result = $engine->matches(['user.profile.name' => 'John'], ['user' => null]);
        expect($result)->toBeFalse();
    });
});

describe('WildcardMatcher edge cases', function (): void {
    test('empty pattern matches nothing except empty event', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeTrue();
        expect(WildcardMatcher::matches('', 'a'))->toBeFalse();
    });

    test('empty event matches nothing except empty pattern', function (): void {
        expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    });

    test('pattern without wildcards is exact match', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.placed', 'order shipped'))->toBeFalse();
    });

    test('multiple single wildcards', function (): void {
        expect(WildcardMatcher::matches('*.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('*.*.*', 'a.b.c'))->toBeTrue();
        expect(WildcardMatcher::matches('*.*', 'a.b.c'))->toBeFalse();
    });

    test('regex special characters in event are matched literally', function (): void {
        expect(WildcardMatcher::matches('order.(created)', 'order.(created)'))->toBeTrue();
    });

    test('findMatchingPatterns returns empty for no patterns', function (): void {
        expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
    });

    test('findMatchingPatterns returns all matching patterns', function (): void {
        $patterns = ['order.*', 'user.*', '*.placed', '*.*'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toContain('order.*')
            ->toContain('*.placed')
            ->toContain('*.*')
            ->not->toContain('user.*');
    });

    test('extractWildcards returns empty for non-matching event', function (): void {
        expect(WildcardMatcher::extractWildcards('user.*.created', 'order.profile.created'))->toBe([]);
    });
});

describe('DomainEvent', function (): void {
    test('occur creates event with UUID and timestamp', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);

        expect($event->eventType)->toBe('test.event')
            ->and($event->payload)->toBe(['key' => 'value'])
            ->and($event->eventId)->not->toBeNull()
            ->and($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    test('toArray and fromArray roundtrip preserves data', function (): void {
        $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);
        $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());

        expect($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->eventType)->toBe($original->eventType)
            ->and($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe(
                $original->occurredAt->format(\DateTimeImmutable::ATOM)
            );
    });

    test('fromArray with empty array creates event with defaults', function (): void {
        $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]);

        expect($event->eventType)->toBe('')
            ->and($event->payload)->toBe([])
            ->and($event->eventId)->not->toBeNull();
    });
});

describe('ServiceProvider bindings', function (): void {
    test('ConditionEngine implements ConditionEngineContract', function (): void {
        $engine = app(ConditionEngine::class);
        expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
    });

    test('ConditionEngine is singleton', function (): void {
        $first = app(ConditionEngine::class);
        $second = app(ConditionEngine::class);
        expect($first)->toBe($second);
    });

    test('ActionResolver is singleton', function (): void {
        $first = app(ActionResolver::class);
        $second = app(ActionResolver::class);
        expect($first)->toBe($second);
    });

    test('EventManager is singleton', function (): void {
        $first = app(\ZeroBoiler\Events\EventManager::class);
        $second = app(\ZeroBoiler\Events\EventManager::class);
        expect($first)->toBe($second);
    });

    test('SubscriptionBuilder is transient', function (): void {
        $first = app(SubscriptionBuilder::class);
        $second = app(SubscriptionBuilder::class);
        expect($first)->not->toBe($second);
    });

    test('config is merged from package', function (): void {
        $config = app('config');
        expect($config->get('events.table_names.triggers'))->toBe('triggers')
            ->and($config->get('events.wildcard_cache_ttl'))->toBe(300)
            ->and($config->get('events.subscriptions.max_failures'))->toBe(10);
    });
});
