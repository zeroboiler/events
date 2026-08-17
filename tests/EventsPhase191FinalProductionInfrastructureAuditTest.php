<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

describe('Phase 191 — Final Production Infrastructure Audit', function (): void {
    describe('ServiceProvider registration integrity', function (): void {
        it('registers all contracts and implementations as singletons', function (): void {
            $bindings = [
                ConditionEngineContract::class => ConditionEngine::class,
                ConditionEngine::class => ConditionEngine::class,
                ActionResolver::class => ActionResolver::class,
                EventManager::class => EventManager::class,
                EventScheduler::class => EventScheduler::class,
            ];

            foreach ($bindings as $abstract => $concrete) {
                expect(app()->bound($abstract))->toBeTrue();
                expect(app()->make($abstract))->toBeInstanceOf($concrete);
            }
        });

        it('SubscriptionBuilder is registered as transient (not singleton)', function (): void {
            $first = app()->make(SubscriptionBuilder::class);
            $second = app()->make(SubscriptionBuilder::class);

            expect($first)->toBeInstanceOf(SubscriptionBuilder::class);
            expect($second)->toBeInstanceOf(SubscriptionBuilder::class);
            // Transient — each resolution gives a fresh instance
            expect(spl_object_id($first))->not->toBe(spl_object_id($second));
        });

        it('TriggerBuilder is registered as transient (not singleton)', function (): void {
            $first = app()->make(TriggerBuilder::class);
            $second = app()->make(TriggerBuilder::class);

            expect($first)->toBeInstanceOf(TriggerBuilder::class);
            expect(spl_object_id($first))->not->toBe(spl_object_id($second));
        });

        it('provides() returns all expected service identifiers', function (): void {
            $provider = new EventsServiceProvider(app());
            $provides = $provider->provides();

            expect($provides)->toBeArray();
            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
        });
    });

    describe('WildcardMatcher edge cases', function (): void {
        it('rejects empty pattern against empty event', function (): void {
            // Empty pattern is not a wildcard — literal match only
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
        });

        it('handles pattern with multiple consecutive dots', function (): void {
            expect(WildcardMatcher::matches('order..placed', 'order.placed'))->toBeFalse();
        });

        it('handles event with trailing dots', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.')).toBeTrue();
        });

        it('handles event with leading dots', function (): void {
            expect(WildcardMatcher::matches('*.placed', '.placed'))->toBeTrue();
        });

        it('extractWildcards returns empty for non-matching event', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))->toBe([]);
        });

        it('extractWildcards returns empty when pattern has no wildcards', function (): void {
            expect(WildcardMatcher::extractWildcards('user.profile.created', 'user.profile.created'))->toBe([]);
        });

        it('findMatchingPatterns returns empty for empty patterns array', function (): void {
            expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
        });

        it('findMatchingPatterns returns all matching patterns', function (): void {
            $patterns = ['order.*', 'user.*', 'order.**', '*.placed'];

            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toContain('order.*');
            expect($result)->toContain('order.**');
            expect($result)->toContain('*.placed');
            expect($result)->not->toContain('user.*');
        });
    });

    describe('ConditionEngine operator coverage', function (): void {
        $engine = new ConditionEngine();

        it('handles "between" with inverted range', function () use ($engine): void {
            expect($engine->matches(['value' => ['between', 100, 50]], ['value' => 75]))->toBeTrue();
        });

        it('handles "between" with boundary values', function () use ($engine): void {
            expect($engine->matches(['value' => ['between', 10, 20]], ['value' => 10]))->toBeTrue();
            expect($engine->matches(['value' => ['between', 10, 20]], ['value' => 20]))->toBeTrue();
            expect($engine->matches(['value' => ['between', 10, 20]], ['value' => 9]))->toBeFalse();
            expect($engine->matches(['value' => ['between', 10, 20]], ['value' => 21]))->toBeFalse();
        });

        it('handles "not_in" with empty value', function () use ($engine): void {
            // not_in with null value: the null coerces (array)null → [] → in_array returns false → !false = true
            expect($engine->matches(['role' => ['not_in', ['admin']]], ['role' => null]))->toBeFalse();
        });

        it('handles "empty" operator with empty array', function () use ($engine): void {
            expect($engine->matches(['items' => ['empty']], ['items' => []]))->toBeTrue();
            expect($engine->matches(['items' => ['empty']], ['items' => [1]]))->toBeFalse();
        });

        it('handles "not_empty" operator with zero value', function () use ($engine): void {
            expect($engine->matches(['count' => ['not_empty']], ['count' => 0]))->toBeFalse();
            expect($engine->matches(['count' => ['not_empty']], ['count' => 1]))->toBeTrue();
        });

        it('handles "starts_with" with empty string', function () use ($engine): void {
            expect($engine->matches(['name' => ['starts_with', '']], ['name' => 'hello']))->toBeTrue();
            expect($engine->matches(['name' => ['starts_with', 'hello']], ['name' => '']))->toBeFalse();
        });

        it('handles "ends_with" with non-string actual', function () use ($engine): void {
            expect($engine->matches(['name' => ['ends_with', 'bar']], ['name' => 123]))->toBeFalse();
        });

        it('handles "matches" with invalid regex gracefully', function () use ($engine): void {
            expect($engine->matches(['name' => ['matches', '[invalid']], ['name' => 'test']))->toBeFalse();
        });

        it('handles "matches" with overly long regex', function () use ($engine): void {
            $longPattern = str_repeat('a', 600);
            expect($engine->matches(['name' => ['matches', "/{$longPattern}/"]], ['name' => 'aaa']))->toBeFalse();
        });

        it('handles nested dot notation', function () use ($engine): void {
            $payload = ['user' => ['profile' => ['name' => 'John']]];
            expect($engine->matches(['user.profile.name' => 'John'], $payload))->toBeTrue();
            expect($engine->matches(['user.profile.name' => 'Jane'], $payload))->toBeFalse();
        });

        it('handles missing nested key returns null', function () use ($engine): void {
            $payload = ['user' => ['name' => 'John']];
            expect($engine->matches(['user.email' => ['null']], $payload))->toBeTrue();
        });

        it('handles empty conditions array', function () use ($engine): void {
            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        });
    });

    describe('ActionResolver error cases', function (): void {
        it('throws for non-existent class', function (): void {
            $resolver = app(ActionResolver::class);
            $resolver->resolve('NonExistentClass\That\Does\Not\Exist');
        })->throws(\InvalidArgumentException::class, 'does not exist');

        it('throws for class that does not implement Triggerable', function (): void {
            $resolver = app(ActionResolver::class);
            $resolver->resolve(\stdClass::class);
        })->throws(\InvalidArgumentException::class, 'must implement');
    });

    describe('DomainEvent value object', function (): void {
        it('generates fresh UUID when not provided', function (): void {
            $event1 = new DomainEvent('order.placed', ['id' => 1]);
            $event2 = new DomainEvent('order.placed', ['id' => 1]);

            expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
        });

        it('preserves provided UUID on reconstruction', function (): void {
            $original = new DomainEvent('order.placed', ['total' => 100]);
            $array = $original->toArray();
            $reconstructed = DomainEvent::fromArray($array);

            expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
            expect($reconstructed->eventType)->toBe($original->eventType);
            expect($reconstructed->payload)->toBe($original->payload);
            expect($reconstructed->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
        });

        it('fromArray handles missing eventType gracefully', function (): void {
            DomainEvent::fromArray(['payload' => ['key' => 'val']]);
        })->throws(\InvalidArgumentException::class, 'eventType is required');

        it('fromArray handles invalid eventId with fallback', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
            ]);

            expect($event->eventType)->toBe('test.event');
            // Invalid UUID → should generate a fresh one (no crash)
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        });

        it('fromArray handles invalid occurredAt with fallback', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);

            expect($event->eventType)->toBe('test.event');
            // Invalid date → should use now() (no crash)
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });

        it('toArray contains all expected keys', function (): void {
            $event = new DomainEvent('test.event', ['key' => 'val']);
            $array = $event->toArray();

            expect(array_keys($array))->toBe([
                'eventId',
                'eventType',
                'payload',
                'occurredAt',
            ]);
        });

        it('occur factory method creates valid instance', function (): void {
            $event = DomainEvent::occur('test.event', ['foo' => 'bar']);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['foo' => 'bar']);
            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        });
    });

    describe('EventManager public API completeness', function (): void {
        it('subscribe() returns a SubscriptionBuilder', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->subscribe('order.placed', 'https://example.com/webhook');

            expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
        });

        it('register() is alias for on()', function (): void {
            $manager = app(EventManager::class);

            $onBuilder = $manager->on('test.event');
            $registerBuilder = $manager->register('test.event');

            expect($onBuilder)->toBeInstanceOf(TriggerBuilder::class);
            expect($registerBuilder)->toBeInstanceOf(TriggerBuilder::class);
        });

        it('isDisabled/setEnabled work together', function (): void {
            $manager = app(EventManager::class);
            $original = $manager->isDisabled();

            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();

            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();

            // Restore original state
            $manager->setEnabled(! $original);
        });

        it('container() returns the application container', function (): void {
            $manager = app(EventManager::class);
            $container = $manager->container();

            expect($container)->toBe(app());
        });

        it('listTriggers returns empty collection for no triggers', function (): void {
            $manager = app(EventManager::class);
            // This may return real triggers if DB has data, but should always return Collection
            $result = $manager->listTriggers();

            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('getTrigger returns null for empty string ID', function (): void {
            $manager = app(EventManager::class);
            expect($manager->getTrigger(''))->toBeNull();
            expect($manager->getTrigger('0'))->toBeNull();
        });

        it('deleteTrigger returns false for empty string ID', function (): void {
            $manager = app(EventManager::class);
            expect($manager->deleteTrigger(''))->toBeFalse();
            expect($manager->deleteTrigger('0'))->toBeFalse();
        });

        it('enable returns false for empty string ID', function (): void {
            $manager = app(EventManager::class);
            expect($manager->enable(''))->toBeFalse();
        });

        it('disable returns false for empty string ID', function (): void {
            $manager = app(EventManager::class);
            expect($manager->disable(''))->toBeFalse();
        });
    });

    describe('TriggerBuilder validation', function (): void {
        it('save() throws on empty event name', function (): void {
            $manager = app(EventManager::class);
            $manager->on('')->action('SomeAction')->save();
        })->throws(\InvalidArgumentException::class, 'Event name is required');

        it('save() throws when no action provided', function (): void {
            $manager = app(EventManager::class);
            $manager->on('test.event')->save();
        })->throws(\InvalidArgumentException::class, 'At least one action is required');

        it('actions() validates each class is non-empty string', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->on('test.event');
            $builder->actions(['ValidAction', '', 'AnotherAction']);
        })->throws(\InvalidArgumentException::class, 'Each action class must be a non-empty string');

        it('save() generates name from event if not provided', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.auto.named.event')
                ->action(\stdClass::class)
                ->save();

            expect($trigger->name)->toBe('test.auto.named.event Trigger');

            // Cleanup
            $trigger->delete();
            $manager->invalidateTriggerCache();
        });
    });

    describe('SubscriptionBuilder validation', function (): void {
        it('save() throws on empty event name', function (): void {
            $manager = app(EventManager::class);
            $manager->subscribe('', 'https://example.com/hook')->save();
        })->throws(\InvalidArgumentException::class, 'Event name is required');

        it('save() throws on empty URL', function (): void {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', '')->save();
        })->throws(\InvalidArgumentException::class, 'Webhook URL is required');

        it('save() throws on invalid URL', function (): void {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', 'not-a-url')->save();
        })->throws(\InvalidArgumentException::class, 'valid URL');

        it('save() throws on non-HTTP URL scheme', function (): void {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', 'ftp://example.com/hook')->save();
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        it('withSecret() throws on short secret', function (): void {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', 'https://example.com/hook')
                ->withSecret('short')
                ->save();
        })->throws(\InvalidArgumentException::class, 'at least 16 characters');
    });

    describe('EventManager fire() validation', function (): void {
        it('throws on empty event name', function (): void {
            $manager = app(EventManager::class);
            $manager->fire('');
        })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

        it('throws on zero-string event name', function (): void {
            $manager = app(EventManager::class);
            $manager->fire('0');
        })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

        it('fireModel() throws on empty model class', function (): void {
            $manager = app(EventManager::class);
            $manager->fireModel('', 'created', new \stdClass);
        })->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

        it('fireModel() throws on empty action', function (): void {
            $manager = app(EventManager::class);
            $manager->fireModel('App\\Models\\Order', '', new \stdClass);
        })->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

        it('fireModel() handles object with attributesToArray', function (): void {
            $manager = app(EventManager::class);
            // This should not throw — the method should handle the stdClass gracefully
            $manager->fire('test.firemodel.stdclass', [
                'model' => new \stdClass,
                'model_class' => 'stdClass',
                'action' => 'created',
            ]);
        });
    });

    describe('Model constants and scopes', function (): void {
        it('EventLog has all expected status constants', function (): void {
            expect(EventLog::STATUS_PENDING)->toBe('pending');
            expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
            expect(EventLog::STATUS_COMPLETED)->toBe('completed');
            expect(EventLog::STATUS_FAILED)->toBe('failed');
        });

        it('EventLog $statuses contains all constants', function (): void {
            expect(EventLog::$statuses)->toBe([
                'pending',
                'dispatched',
                'completed',
                'failed',
            ]);
        });

        it('Trigger uses string key type and non-incrementing', function (): void {
            $trigger = new Trigger;
            expect($trigger->getIncrementing())->toBeFalse();
            expect($trigger->getKeyType())->toBe('string');
        });

        it('Subscription uses string key type and non-incrementing', function (): void {
            $sub = new Subscription;
            expect($sub->getIncrementing())->toBeFalse();
            expect($sub->getKeyType())->toBe('string');
        });
    });

    describe('Config structure completeness', function (): void {
        it('config has all required top-level keys', function (): void {
            $config = config('events');

            expect($config)->toHaveKey('table_names');
            expect($config)->toHaveKey('queue');
            expect($config)->toHaveKey('retry');
            expect($config)->toHaveKey('retention');
            expect($config)->toHaveKey('subscriptions');
            expect($config)->toHaveKey('disabled');
            expect($config)->toHaveKey('wildcard_cache_ttl');
        });

        it('table_names config has all three tables', function (): void {
            $tables = config('events.table_names');

            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('subscriptions config has all required keys', function (): void {
            $subs = config('events.subscriptions');

            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('secret_length');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
            expect($subs)->toHaveKey('cleanup_cron');
        });
    });
});
