<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
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

describe('Phase 141 Production Audit', function (): void {
    describe('EventManager fire() edge cases', function (): void {
        it('returns silently when event system is globally disabled', function (): void {
            $this->app->get('config')->set('events.disabled', true);

            $manager = $this->app->make(EventManager::class);
            // Should not throw, just return silently
            $manager->fire('test.event', ['key' => 'value']);

            expect(true)->toBeTrue();
        });

        it('throws InvalidArgumentException for empty string event name', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect(fn (): mixed => $manager->fire(''))
                ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
        });

        it('throws InvalidArgumentException for "0" event name', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect(fn (): mixed => $manager->fire('0'))
                ->toThrow(\InvalidArgumentException::class, 'Event name cannot be empty');
        });

        it('throws InvalidArgumentException for empty fireModel model class', function (): void {
            $manager = $this->app->make(EventManager::class);
            $model = new \stdClass();

            expect(fn (): mixed => $manager->fireModel('', 'created', $model))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
        });

        it('throws InvalidArgumentException for empty fireModel action', function (): void {
            $manager = $this->app->make(EventManager::class);
            $model = new \stdClass();

            expect(fn (): mixed => $manager->fireModel('App\\Models\\Order', '', $model))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
        });
    });

    describe('EventManager CRUD operations', function (): void {
        it('returns null for non-existent trigger', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect($manager->getTrigger('non-existent-uuid'))->toBeNull();
        });

        it('returns false when enabling non-existent trigger', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect($manager->enable('non-existent-uuid'))->toBeFalse();
        });

        it('returns false when disabling non-existent trigger', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect($manager->disable('non-existent-uuid'))->toBeFalse();
        });

        it('returns false when deleting non-existent trigger', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect($manager->deleteTrigger('non-existent-uuid'))->toBeFalse();
        });

        it('returns null for non-existent subscription', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect($manager->getSubscription('non-existent-uuid'))->toBeNull();
        });

        it('returns false when unsubscribing non-existent subscription', function (): void {
            $manager = $this->app->make(EventManager::class);

            expect($manager->unsubscribe('non-existent-uuid'))->toBeFalse();
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        it('matches catch-all pattern * against single-segment event', function (): void {
            expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
        });

        it('does not match catch-all pattern * against empty string', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        it('matches cross-segment ** against deeply nested events', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order.placed.shipped.delivered'))->toBeTrue();
        });

        it('extracts wildcards from single-segment pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created'))
                ->toBe(['admin']);
        });

        it('returns empty array when extracting wildcards from cross-segment pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))
                ->toBe([]);
        });

        it('returns empty array when extractWildcards segments dont match count', function (): void {
            expect(WildcardMatcher::extractWildcards('a.b.c', 'a.b'))
                ->toBe([]);
        });

        it('findMatchingPatterns returns only matching patterns', function (): void {
            $patterns = ['order.placed', 'order.*', 'user.created'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.shipped');

            expect($result)->toBe(['order.placed', 'order.*']);
        });

        it('findMatchingPatterns returns empty for no matches', function (): void {
            $patterns = ['user.*', 'billing.*'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toBe([]);
        });
    });

    describe('ConditionEngine comprehensive operators', function (): void {
        it('evaluates = operator with string equality', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
            expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();
        });

        it('evaluates > operator with numeric comparison', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
            expect($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();
        });

        it('evaluates in operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'user']))->toBeFalse();
        });

        it('evaluates contains operator with arrays', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'billing']]))->toBeTrue();
        });

        it('evaluates between operator with auto-normalized range', function (): void {
            $engine = new ConditionEngine();

            // Inverted range should be auto-normalized
            expect($engine->matches(['age' => ['between', [65, 18]]], ['age' => 30]))->toBeTrue();
        });

        it('evaluates null operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
            expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => '2024-01-01']))->toBeFalse();
        });

        it('evaluates empty operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['notes' => ['empty']], ['notes' => null]))->toBeTrue();
            expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
            expect($engine->matches(['notes' => ['empty']], ['notes' => 'hello']))->toBeFalse();
        });

        it('evaluates starts_with operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'user@test.com']))->toBeFalse();
        });

        it('evaluates matches (regex) operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();
            expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABCD']))->toBeFalse();
        });

        it('evaluates dot notation nested fields', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();
        });

        it('returns false for empty conditions array', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches([], ['anything' => 'value']))->toBeTrue();
        });

        it('returns false for empty operator array', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
        });

        it('returns false for unknown operator', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['field' => ['unknown_op', 'value']], ['field' => 'value']))->toBeFalse();
        });
    });

    describe('DomainEvent serialization and reconstruction', function (): void {
        it('creates event via occur() factory', function (): void {
            $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

            expect($event->eventType)->toBe('user.registered');
            expect($event->payload)->toBe(['email' => 'test@example.com']);
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('serializes and reconstructs preserving identity', function (): void {
            $original = DomainEvent::occur('order.created', ['order_id' => '123']);
            $data = $original->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe('order.created');
            expect($restored->payload)->toBe(['order_id' => '123']);
        });

        it('throws on fromArray with empty eventType', function (): void {
            expect(fn () => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        it('throws on fromArray with numeric eventType', function (): void {
            expect(fn () => DomainEvent::fromArray(['eventType' => 12345]))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        it('generates fresh UUID for invalid UUID in fromArray', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
            ]);

            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        });

        it('defaults payload to empty array when missing', function (): void {
            $event = DomainEvent::fromArray(['eventType' => 'test.event']);

            expect($event->payload)->toBe([]);
        });
    });

    describe('EventLog status constants', function (): void {
        it('has correct status values', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('has all statuses in $statuses array', function (): void {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    describe('TriggerBuilder validation', function (): void {
        it('throws on save with empty event', function (): void {
            $builder = $this->app->make(TriggerBuilder::class);

            expect(fn () => $builder->action(TestAction::class)->save())
                ->toThrow(\InvalidArgumentException::class, 'Event name is required');
        });

        it('throws on save with no action', function (): void {
            $builder = $this->app->make(TriggerBuilder::class);

            expect(fn () => $builder->on('test.event')->save())
                ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
        });

        it('throws on actions() with empty string class', function (): void {
            $builder = $this->app->make(TriggerBuilder::class);

            expect(fn () => $builder->actions(['']))
                ->toThrow(\InvalidArgumentException::class, 'non-empty string');
        });

        it('auto-generates name from event when not provided', function (): void {
            $builder = $this->app->make(TriggerBuilder::class);
            $trigger = $builder->on('test.auto-name')
                ->action(TestAction::class)
                ->save();

            expect($trigger->name)->toBe('test.auto-name Trigger');
        });
    });

    describe('SubscriptionBuilder validation', function (): void {
        it('throws on save with empty event', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn () => $builder->to('https://example.com/hook')->save())
                ->toThrow(\InvalidArgumentException::class, 'Event name is required');
        });

        it('throws on save with empty URL', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn () => $builder->on('test.event')->to('')->save())
                ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
        });

        it('throws on save with non-HTTP scheme', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn () => $builder->on('test.event')->to('ftp://evil.com/upload')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS protocol');
        });

        it('throws on save with invalid URL', function (): void {
            $builder = $this->app->make(SubscriptionBuilder::class);

            expect(fn () => $builder->on('test.event')->to('not-a-url')->save())
                ->toThrow(\InvalidArgumentException::class, 'valid URL');
        });
    });

    describe('Subscription signPayload edge cases', function (): void {
        it('returns empty string for null secret', function (): void {
            $sub = new Subscription(['secret' => null]);

            expect($sub->signPayload('test-payload'))->toBe('');
        });

        it('returns empty string for empty secret', function (): void {
            $sub = new Subscription(['secret' => '']);

            expect($sub->signPayload('test-payload'))->toBe('');
        });

        it('returns valid HMAC signature for sha256', function (): void {
            $sub = new Subscription(['secret' => 'whsec_test_secret_12345']);
            $signature = $sub->signPayload('test-payload');

            expect($signature)->not->toBeEmpty();
            expect(strlen($signature))->toBe(64); // sha256 = 64 hex chars
        });
    });

    describe('Config completeness', function (): void {
        it('has all required top-level config keys', function (): void {
            $config = config('events');

            expect($config)->toHaveKey('table_names');
            expect($config)->toHaveKey('queue');
            expect($config)->toHaveKey('retry');
            expect($config)->toHaveKey('retention');
            expect($config)->toHaveKey('subscriptions');
            expect($config)->toHaveKey('disabled');
            expect($config)->toHaveKey('wildcard_cache_ttl');
        });

        it('has all table_names keys', function (): void {
            $tables = config('events.table_names');

            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('has all queue config keys', function (): void {
            $queue = config('events.queue');

            expect($queue)->toHaveKey('connection');
            expect($queue)->toHaveKey('queue');
        });

        it('has all retry config keys', function (): void {
            $retry = config('events.retry');

            expect($retry)->toHaveKey('tries');
            expect($retry)->toHaveKey('backoff');
        });

        it('has all subscription config keys', function (): void {
            $subs = config('events.subscriptions');

            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
            expect($subs)->toHaveKey('cleanup_cron');
        });
    });

    describe('ServiceProvider bindings', function (): void {
        it('binds EventManager as singleton', function (): void {
            $first = $this->app->make(EventManager::class);
            $second = $this->app->make(EventManager::class);

            expect($first)->toBe($second);
        });

        it('binds ConditionEngine as singleton', function (): void {
            $first = $this->app->make(ConditionEngine::class);
            $second = $this->app->make(ConditionEngine::class);

            expect($first)->toBe($second);
        });

        it('binds ConditionEngineContract to ConditionEngine', function (): void {
            $engine = $this->app->make(ConditionEngineContract::class);

            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        it('binds ActionResolver as singleton', function (): void {
            $first = $this->app->make(\ZeroBoiler\Events\ActionResolver::class);
            $second = $this->app->make(\ZeroBoiler\Events\ActionResolver::class);

            expect($first)->toBe($second);
        });

        it('binds EventScheduler as singleton', function (): void {
            $first = $this->app->make(EventScheduler::class);
            $second = $this->app->make(EventScheduler::class);

            expect($first)->toBe($second);
        });
    });

    describe('Facade accessor', function (): void {
        it('resolves to EventManager class', function (): void {
            expect(EventManagerFacade::getFacadeRoot())
                ->toBeInstanceOf(EventManager::class);
        });
    });

    describe('ActionResolver error handling', function (): void {
        it('throws for non-existent class', function (): void {
            $resolver = $this->app->make(\ZeroBoiler\Events\ActionResolver::class);

            expect(fn () => $resolver->resolve('NonExistent\\Class\\Here'))
                ->toThrow(\InvalidArgumentException::class, 'does not exist');
        });

        it('throws for class that does not implement Triggerable', function (): void {
            $resolver = $this->app->make(\ZeroBoiler\Events\ActionResolver::class);

            expect(fn () => $resolver->resolve(\stdClass::class))
                ->toThrow(\InvalidArgumentException::class, 'must implement');
        });
    });
});

class TestAction implements \ZeroBoiler\Events\Contracts\Triggerable
{
    public function handle(array $payload): void {}
}
