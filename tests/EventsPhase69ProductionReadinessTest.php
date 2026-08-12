<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngine as ConditionEngineImpl;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract as ConditionEngineContractIface;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
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

beforeEach(function (): void {
    // Fresh app is created by TestCase::setUp()
});

describe('EventManager Singleton Binding', function (): void {
    test('EventManager is registered as singleton in container', function (): void {
        $app = app();
        $manager1 = $app->make(EventManager::class);
        $manager2 = $app->make(EventManager::class);

        expect($manager1)->toBeInstanceOf(EventManager::class)
            ->and($manager2)->toBe($manager1); // Same instance (singleton)
    });

    test('ConditionEngine is registered as singleton', function (): void {
        $app = app();
        $engine1 = $app->make(ConditionEngine::class);
        $engine2 = $app->make(ConditionEngine::class);

        expect($engine1)->toBeInstanceOf(ConditionEngine::class)
            ->and($engine2)->toBe($engine1);
    });

    test('ConditionEngineContract is bound to ConditionEngine', function (): void {
        $app = app();
        $contract = $app->make(ConditionEngineContractIface::class);

        expect($contract)->toBeInstanceOf(ConditionEngine::class);
    });

    test('ActionResolver is registered as singleton', function (): void {
        $app = app();
        $resolver1 = $app->make(ActionResolver::class);
        $resolver2 = $app->make(ActionResolver::class);

        expect($resolver1)->toBeInstanceOf(ActionResolver::class)
            ->and($resolver2)->toBe($resolver1);
    });

    test('TriggerBuilder is registered as transient (not shared)', function (): void {
        $app = app();
        $builder1 = $app->make(TriggerBuilder::class);
        $builder2 = $app->make(TriggerBuilder::class);

        expect($builder1)->toBeInstanceOf(TriggerBuilder::class)
            ->and($builder2)->toBeInstanceOf(TriggerBuilder::class)
            ->and($builder1)->not->toBe($builder2); // Different instances
    });

    test('SubscriptionBuilder is registered as transient (not shared)', function (): void {
        $app = app();
        $builder1 = $app->make(SubscriptionBuilder::class);
        $builder2 = $app->make(SubscriptionBuilder::class);

        expect($builder1)->toBeInstanceOf(SubscriptionBuilder::class)
            ->and($builder2)->toBeInstanceOf(SubscriptionBuilder::class)
            ->and($builder1)->not->toBe($builder2);
    });
});

describe('WildcardMatcher Production Edge Cases', function (): void {
    test('exact match with no wildcards returns true', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    });

    test('exact match with different event returns false', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('empty pattern with empty event returns false (catch-all excludes empty)', function (): void {
        expect(WildcardMatcher::matches('', ''))->toBeFalse();
    });

    test('catch-all * matches any non-empty event', function (): void {
        expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
        expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('*', 'order.placed.extra'))->toBeTrue();
    });

    test('catch-all ** matches any non-empty event', function (): void {
        expect(WildcardMatcher::matches('**', 'x'))->toBeTrue();
        expect(WildcardMatcher::matches('**', 'a.b.c'))->toBeTrue();
    });

    test('* does NOT match empty string', function (): void {
        expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    });

    test('single-segment wildcard * matches within a dot segment only', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    });

    test('cross-segment wildcard ** matches across dot boundaries', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        expect(WildcardMatcher::matches('order.**', 'order.placed.extra.deep'))->toBeTrue();
    });

    test('multiple wildcards in pattern', function (): void {
        expect(WildcardMatcher::matches('*.*.created', 'user.profile.created'))->toBeTrue();
        expect(WildcardMatcher::matches('*.*.created', 'user.profile.updated'))->toBeFalse();
    });

    test('findMatchingPatterns returns only matching patterns', function (): void {
        $patterns = ['order.*', 'user.*', '*.created', '**'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toContain('order.*', '**')
            ->and($result)->not->toContain('user.*', '*.created');
    });

    test('extractWildcards returns empty for ** patterns', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))->toBe([]);
    });

    test('extractWildcards returns extracted segments for * patterns', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');
        expect($result)->toBe(['profile']);
    });

    test('extractWildcards returns empty when event does not match', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*', 'user.placed'))->toBe([]);
    });

    test('extractWildcards returns empty for segment count mismatch', function (): void {
        expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))->toBe([]);
    });

    test('extractWildcards returns empty for no wildcards in pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('order.placed', 'order.placed'))->toBe([]);
    });

    test('extractWildcards with multiple wildcards', function (): void {
        $result = WildcardMatcher::extractWildcards('*.*.created', 'user.admin.created');
        expect($result)->toBe(['user', 'admin']);
    });
});

describe('ConditionEngine Operator Coverage', function (): void {
    test('greater than operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['amount' => ['>', 50]], ['amount' => 100]))->toBeTrue();
        expect($engine->matches(['amount' => ['>', 50]], ['amount' => 50]))->toBeFalse();
    });

    test('greater than or equal operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['amount' => ['>=', 50]], ['amount' => 50]))->toBeTrue();
        expect($engine->matches(['amount' => ['>=', 50]], ['amount' => 49]))->toBeFalse();
    });

    test('less than operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['amount' => ['<', 50]], ['amount' => 25]))->toBeTrue();
        expect($engine->matches(['amount' => ['<', 50]], ['amount' => 50]))->toBeFalse();
    });

    test('less than or equal operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['amount' => ['<=', 50]], ['amount' => 50]))->toBeTrue();
        expect($engine->matches(['amount' => ['<=', 50]], ['amount' => 51]))->toBeFalse();
    });

    test('strict equality operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['status' => ['===', 'active']], ['status' => 'active']))->toBeTrue();
        expect($engine->matches(['status' => ['===', 'active']], ['status' => 0]))->toBeFalse();
    });

    test('strict inequality operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['status' => ['!==', 'active']], ['status' => 'pending']))->toBeTrue();
        expect($engine->matches(['status' => ['!==', 'active']], ['status' => 'active']))->toBeFalse();
    });

    test('in operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['role' => ['in', ['admin', 'moderator']]], ['role' => 'admin']))->toBeTrue();
        expect($engine->matches(['role' => ['in', ['admin', 'moderator']]], ['role' => 'user']))->toBeFalse();
    });

    test('not_in operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['role' => ['not_in', ['admin', 'moderator']]], ['role' => 'user']))->toBeTrue();
        expect($engine->matches(['role' => ['not_in', ['admin', 'moderator']]], ['role' => 'admin']))->toBeFalse();
    });

    test('contains operator for strings', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['message' => ['contains', 'error']], ['message' => 'an error occurred']))->toBeTrue();
        expect($engine->matches(['message' => ['contains', 'error']], ['message' => 'all good']))->toBeFalse();
    });

    test('contains operator for arrays', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'bug']]))->toBeTrue();
    });

    test('not_contains operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['message' => ['not_contains', 'error']], ['message' => 'all good']))->toBeTrue();
    });

    test('between operator normal range', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['amount' => ['between', [10, 50]]], ['amount' => 25]))->toBeTrue();
        expect($engine->matches(['amount' => ['between', [10, 50]]], ['amount' => 5]))->toBeFalse();
        expect($engine->matches(['amount' => ['between', [10, 50]]], ['amount' => 50]))->toBeTrue();
    });

    test('between operator inverted range auto-normalizes', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['amount' => ['between', [50, 10]]], ['amount' => 25]))->toBeTrue();
    });

    test('null operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
        expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();
    });

    test('not_null operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['email' => ['not_null']], ['email' => 'test@example.com']))->toBeTrue();
        expect($engine->matches(['email' => ['not_null']], ['email' => null]))->toBeFalse();
    });

    test('empty operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
        expect($engine->matches(['notes' => ['empty']], ['notes' => 'has text']))->toBeFalse();
    });

    test('not_empty operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'has text']))->toBeTrue();
    });

    test('starts_with operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
        expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'user@test.com']))->toBeFalse();
    });

    test('ends_with operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
        expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.org']))->toBeFalse();
    });

    test('matches (regex) operator', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
        expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABCD']))->toBeFalse();
    });

    test('matches operator rejects patterns exceeding max length', function (): void {
        $engine = new ConditionEngine();
        $longPattern = '/^[a-z]{' . 501 . '}$/';
        expect($engine->matches(['code' => ['matches', $longPattern]], ['code' => 'abc']))->toBeFalse();
    });

    test('matches operator rejects catastrophic backtracking patterns', function (): void {
        $engine = new ConditionEngine();
        // Nested quantifier pattern: (a+)+
        expect($engine->matches(['input' => ['matches', '/(a+)+/']], ['input' => 'aaaa']))->toBeFalse();
    });

    test('unknown operator returns false', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['field' => ['UNKNOWN_OP', 'value']], ['field' => 'value']))->toBeFalse();
    });

    test('empty condition array returns false', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
    });

    test('all conditions must match (AND logic)', function (): void {
        $engine = new ConditionEngine();
        $conditions = [
            'status' => 'active',
            'amount' => ['>', 10],
        ];
        expect($engine->matches($conditions, ['status' => 'active', 'amount' => 20]))->toBeTrue();
        expect($engine->matches($conditions, ['status' => 'inactive', 'amount' => 20]))->toBeFalse();
        expect($engine->matches($conditions, ['status' => 'active', 'amount' => 5]))->toBeFalse();
    });

    test('dot notation nested value access', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();
        expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'user']]))->toBeFalse();
        expect($engine->matches(['user.role' => 'admin'], []))->toBeFalse();
    });

    test('simple equality when value is not an array', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
        expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();
    });

    test('type-safe equality falls back to string comparison for scalars', function (): void {
        $engine = new ConditionEngine();
        // Different types but same string representation — compares as strings
        expect($engine->matches(['count' => '10'], ['count' => 10]))->toBeTrue();
    });

    test('type-safe equality returns false for non-scalar mixed types', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['data' => 'string'], ['data' => ['array']]))->toBeFalse();
    });

    test('missing field in payload defaults to null', function (): void {
        $engine = new ConditionEngine();
        expect($engine->matches(['missing' => ['null']], []))->toBeTrue();
        expect($engine->matches(['missing' => ['not_null']], []))->toBeFalse();
    });
});

describe('ActionResolver Error Handling', function (): void {
    test('resolves a valid Triggerable class', function (): void {
        $resolver = app()->make(ActionResolver::class);
        $handler = $resolver->resolve(DummyTriggerable::class);

        expect($handler)->toBeInstanceOf(DummyTriggerable::class);
    });

    test('throws InvalidArgumentException for non-existent class', function (): void {
        $resolver = app()->make(ActionResolver::class);

        $resolver->resolve('NonExistent\\Class\\That\\Does\\NotExist');
    })->throws(InvalidArgumentException::class, 'does not exist');

    test('throws InvalidArgumentException for class not implementing Triggerable', function (): void {
        $resolver = app()->make(ActionResolver::class);

        $resolver->resolve(stdClass::class);
    })->throws(InvalidArgumentException::class, 'must implement');
});

describe('DomainEvent Value Object', function (): void {
    test('creates event with auto-generated UUID and timestamp', function (): void {
        $event = new DomainEvent('user.registered', ['email' => 'test@example.com']);

        expect($event->eventType)->toBe('user.registered')
            ->and($event->payload)->toBe(['email' => 'test@example.com'])
            ->and($event->eventId)->not->toBeNull()
            ->and($event->occurredAt)->not->toBeNull();
    });

    test('occur factory method', function (): void {
        $event = DomainEvent::occur('order.placed', ['order_id' => 123]);

        expect($event)->toBeInstanceOf(DomainEvent::class)
            ->and($event->eventType)->toBe('order.placed');
    });

    test('serializes to array', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt'])
            ->and($data['eventType'])->toBe('test.event')
            ->and($data['payload'])->toBe(['key' => 'value']);
    });

    test('fromArray reconstructs with preserved eventId and occurredAt', function (): void {
        $original = DomainEvent::occur('test.event', ['key' => 'value']);
        $data = $original->toArray();
        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->eventType)->toBe($original->eventType)
            ->and($restored->occurredAt->format(\DateTimeInterface::ATOM))
            ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
    });

    test('fromArray throws on missing eventType', function (): void {
        DomainEvent::fromArray(['payload' => []]);
    })->throws(InvalidArgumentException::class, 'eventType is required');

    test('fromArray handles invalid UUID gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test',
            'eventId' => 'not-a-uuid',
            'occurredAt' => 'not-a-date',
        ]);

        // Should generate fresh UUID and timestamp as fallback
        expect($event->eventType)->toBe('test')
            ->and($event->eventId)->not->toBeNull()
            ->and($event->occurredAt)->not->toBeNull();
    });

    test('fromArray with empty eventType string throws', function (): void {
        DomainEvent::fromArray(['eventType' => '']);
    })->throws(InvalidArgumentException::class, 'eventType is required');
});

describe('EventLog Status Constants', function (): void {
    test('all status constants are defined', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    });

    test('statuses array contains all four statuses', function (): void {
        expect(EventLog::$statuses)->toContain(
            EventLog::STATUS_PENDING,
            EventLog::STATUS_DISPATCHED,
            EventLog::STATUS_COMPLETED,
            EventLog::STATUS_FAILED,
        );
    });
});

describe('EscapesWildcardLike Trait', function (): void {
    test('returns null when pattern has no wildcards', function (): void {
        $object = new class
        {
            use EscapesWildcardLike;

            public function test(string $pattern): ?string
            {
                return $this->wildcardToLike($pattern);
            }
        };

        expect($object->test('order.placed'))->toBeNull();
    });

    test('converts * to % and escapes SQL special chars', function (): void {
        $object = new class
        {
            use EscapesWildcardLike;

            public function test(string $pattern): ?string
            {
                return $this->wildcardToLike($pattern);
            }
        };

        expect($object->test('order.*'))->toBe('order.%');
        expect($object->test('user.%.test'))->toBe('user.\\%.test');
        expect($object->test('test_.value'))->toBe('test_.value'); // No wildcard, returns null
    });
});

describe('DispatchTriggerJob Configuration', function (): void {
    test('reads tries from config', function (): void {
        $app = app();
        $app['config']->set('events.retry.tries', 5);

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->tries)->toBe(5);
    });

    test('falls back to default tries when config is invalid', function (): void {
        $app = app();
        $app['config']->set('events.retry.tries', 'invalid');

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->tries)->toBe(3);
    });

    test('reads backoff from config as array', function (): void {
        $app = app();
        $app['config']->set('events.retry.backoff', [30, 120]);

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->backoff)->toBe([30, 120]);
    });

    test('reads backoff from config as comma-separated string', function (): void {
        $app = app();
        $app['config']->set('events.retry.backoff', '10,20,30');

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->backoff)->toBe([10, 20, 30]);
    });

    test('reads queue name from config', function (): void {
        $app = app();
        $app['config']->set('events.queue.queue', 'events');

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->queue)->toBe('events');
    });

    test('reads connection from config', function (): void {
        $app = app();
        $app['config']->set('events.queue.connection', 'redis');

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->connection)->toBe('redis');
    });

    test('connection remains null when not configured', function (): void {
        $app = app();
        $app['config']->set('events.queue.connection', null);

        $job = new DispatchTriggerJob('id', 'event', []);
        expect($job->connection)->toBeNull();
    });
});

describe('Subscription Model', function (): void {
    test('signPayload returns empty string for null secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => null]);

        expect($sub->signPayload('test payload'))->toBe('');
    });

    test('signPayload returns empty string for empty secret', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        expect($sub->signPayload('test payload'))->toBe('');
    });

    test('signPayload produces deterministic HMAC signature', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'test_secret']);
        $payload = '{"event":"test"}';

        $sig1 = $sub->signPayload($payload);
        $sig2 = $sub->signPayload($payload);

        expect($sig1)->toBe($sig2)
            ->and($sig1)->not->toBeEmpty();
    });

    test('hasExceededFailures uses config threshold', function (): void {
        $app = app();
        $app['config']->set('events.subscriptions.max_failures', 5);

        $sub = Subscription::factory()->create(['failure_count' => 5]);
        expect($sub->hasExceededFailures())->toBeTrue();

        $sub2 = Subscription::factory()->create(['failure_count' => 4]);
        expect($sub2->hasExceededFailures())->toBeFalse();
    });

    test('hasExceededFailures accepts explicit max override', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 3]);
        expect($sub->hasExceededFailures(3))->toBeTrue();
        expect($sub->hasExceededFailures(5))->toBeFalse();
    });

    test('recordDelivery increments delivery_count and sets last_fired_at', function (): void {
        $sub = Subscription::factory()->create(['delivery_count' => 0, 'last_fired_at' => null]);
        $sub->recordDelivery();
        $sub->refresh();

        expect($sub->delivery_count)->toBe(1)
            ->and($sub->last_fired_at)->not->toBeNull();
    });

    test('recordFailure increments failure_count', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 0]);
        $sub->recordFailure();
        $sub->refresh();

        expect($sub->failure_count)->toBe(1);
    });

    test('resetFailures sets failure_count to zero', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 5]);
        $sub->resetFailures();
        $sub->refresh();

        expect($sub->failure_count)->toBe(0);
    });

    test('matchesEvent exact match', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.placed']);
        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.shipped'))->toBeFalse();
    });

    test('matchesEvent wildcard delegation', function (): void {
        $sub = Subscription::factory()->create(['event' => 'order.*']);
        expect($sub->matchesEvent('order.placed'))->toBeTrue();
        expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
    });
});

describe('Trigger Model', function (): void {
    test('scopeEnabled returns only enabled triggers', function (): void {
        Trigger::factory()->enabled()->create();
        Trigger::factory()->disabled()->create();

        $enabled = Trigger::enabled()->get();
        expect($enabled->count())->toBe(1)
            ->and($enabled->first()->enabled)->toBeTrue();
    });

    test('scopeAsync returns only async triggers', function (): void {
        Trigger::factory()->async()->create();
        Trigger::factory()->sync()->create();

        $async = Trigger::async()->get();
        expect($async->count())->toBe(1)
            ->and($async->first()->async)->toBeTrue();
    });

    test('triggers have UUID primary key', function (): void {
        $trigger = Trigger::factory()->create();

        expect($trigger->id)->toBeString()
            ->and(strlen($trigger->id))->toBe(36); // UUID v4 format
    });
});

describe('Config Completeness', function (): void {
    test('config file has all required sections', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config)->toHaveKeys([
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ]);
    });

    test('table_names has triggers, event_logs, subscriptions', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    test('subscriptions config has all required keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config['subscriptions'])->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ]);
    });

    test('queue config has connection and queue keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    });

    test('retry config has tries and backoff keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
    });

    test('retention config has days and include_pending keys', function (): void {
        $config = include __DIR__.'/../config/events.php';

        expect($config['retention'])->toHaveKeys(['days', 'include_pending']);
    });
});

describe('Facade Accessor', function (): void {
    test('facade resolves to EventManager instance', function (): void {
        $resolved = EventManagerFacade::getFacadeRoot();
        expect($resolved)->toBeInstanceOf(EventManager::class);
    });

    test('facade accessor returns correct class name', function (): void {
        $reflection = new ReflectionClass(EventManagerFacade::class);
        $method = $reflection->getMethod('getFacadeAccessor');
        $result = $method->invoke(null);

        expect($result)->toBe(EventManager::class);
    });
});

describe('ServiceProvider Verification', function (): void {
    test('EventsServiceProvider publishes config', function (): void {
        $provider = new EventsServiceProvider(app());
        $publishes = $provider->publishes();

        expect($publishes)->toHaveKey('events-config');
    });

    test('EventsServiceProvider publishes migrations', function (): void {
        $provider = new EventsServiceProvider(app());
        $publishes = $provider->publishes();

        expect($publishes)->toHaveKey('events-migrations');
    });
});

// Dummy Triggerable for testing ActionResolver
final class DummyTriggerable implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void {}
}
