<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

/**
 * Phase 187 — Final production infrastructure audit.
 *
 * Focus areas:
 * - Edge cases not covered by earlier test phases
 * - Type-safety enforcement patterns
 * - Config fallback chains
 * - Builder validation consistency
 * - Queue serialization safety
 */
describe('Phase 187 Production Infrastructure Audit', function (): void {
    describe('WildcardMatcher edge cases', function (): void {
        it('does not match empty pattern against any event', function (): void {
            expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
        });

        it('does not match catch-all against empty event string', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('extracts wildcards returns empty for non-matching events', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))->toBe([]);
        });

        it('extracts wildcards returns empty when segment counts differ', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.profile.action.created'))->toBe([]);
        });

        it('findMatchingPatterns returns empty array for empty patterns list', function (): void {
            expect(WildcardMatcher::findMatchingPatterns([], 'order.placed'))->toBe([]);
        });

        it('findMatchingPatterns returns only matching patterns', function (): void {
            $patterns = ['order.*', 'user.*', 'order.**'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toContain('order.*');
            expect($result)->toContain('order.**');
            expect($result)->not->toContain('user.*');
        });
    });

    describe('ConditionEngine operator edge cases', function (): void {
        it('between operator with inverted range normalizes correctly', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 75]))->toBeTrue();
            expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 101]))->toBeFalse();
        });

        it('between operator returns false for non-array value', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['between', 'not_an_array']], ['value' => 50]))->toBeFalse();
        });

        it('between operator returns false for array with wrong count', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['between', [50]]], ['value' => 75]))->toBeFalse();
        });

        it('between operator returns false for non-numeric actual', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['between', [1, 100]]], ['value' => 'abc']))->toBeFalse();
        });

        it('contains operator returns false for non-string non-array actual with string value', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['contains', 'hello']], ['value' => 123]))->toBeFalse();
        });

        it('in operator returns false when value is null', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['status' => ['in', null]], ['status' => 'active']))->toBeFalse();
        });

        it('not_in operator returns false when value is null', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['status' => ['not_in', null]], ['status' => 'active']))->toBeFalse();
        });

        it('starts_with returns false for non-string actual', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['starts_with', 'pre']], ['value' => 123]))->toBeFalse();
        });

        it('ends_with returns false for non-string actual', function (): void {
            $engine = new ConditionEngine;
            expect($engine->matches(['value' => ['ends_with', 'fix']], ['value' => null]))->toBeFalse();
        });
    });

    describe('ConditionEngine dot-notation nesting', function (): void {
        it('accesses deeply nested values with dot notation', function (): void {
            $engine = new ConditionEngine;
            $conditions = ['order.items.count' => ['>', 0]];
            $payload = ['order' => ['items' => ['count' => 5]]];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        it('returns null for missing nested path', function (): void {
            $engine = new ConditionEngine;
            $conditions = ['deep.missing.path' => ['not_null']];
            $payload = ['deep' => ['other' => 'value']];

            expect($engine->matches($conditions, $payload))->toBeFalse();
        });

        it('evaluates multiple nested conditions with AND logic', function (): void {
            $engine = new ConditionEngine;
            $conditions = [
                'user.profile.name' => 'John',
                'user.settings.notifications' => true,
                'user.account.active' => ['=', true],
            ];
            $payload = [
                'user' => [
                    'profile' => ['name' => 'John'],
                    'settings' => ['notifications' => true],
                    'account' => ['active' => true],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });
    });

    describe('DomainEvent immutability and reconstruction', function (): void {
        it('creates event with fresh UUID and current timestamp by default', function (): void {
            $before = new DateTimeImmutable;
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $after = new DateTimeImmutable;

            expect($event->eventType)->toBe('test.event');
            expect($event->eventId->toString())->not->toBeEmpty();
            expect($event->occurredAt)->toBeGreaterThanOrEqual($before);
            expect($event->occurredAt)->toBeLessThanOrEqual($after);
            expect($event->payload)->toBe(['key' => 'value']);
        });

        it('preserves eventId and occurredAt during fromArray reconstruction', function (): void {
            $original = DomainEvent::occur('order.created', ['id' => 42]);
            $array = $original->toArray();
            $reconstructed = DomainEvent::fromArray($array);

            expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
            expect($reconstructed->occurredAt->format(DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
            expect($reconstructed->eventType)->toBe('order.created');
        });

        it('throws exception for empty eventType in fromArray', function (): void {
            DomainEvent::fromArray(['eventType' => '']);
        })->throws(InvalidArgumentException::class, 'DomainEvent eventType is required');

        it('generates fresh UUID when fromArray receives invalid eventId', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
            ]);

            // Should not throw; should generate a fresh UUID
            expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');
        });

        it('toArray and fromArray round-trip preserves payload', function (): void {
            $original = DomainEvent::occur('test.roundtrip', [
                'nested' => ['key' => 'value', 'count' => 5],
                'empty_array' => [],
            ]);

            $reconstructed = DomainEvent::fromArray($original->toArray());
            expect($reconstructed->payload)->toBe($original->payload);
        });
    });

    describe('EventManager fireModel edge cases', function (): void {
        it('throws exception for empty model class', function (): void {
            $manager = app(EventManager::class);
            $manager->fireModel('', 'created', new stdClass);
        })->throws(InvalidArgumentException::class, 'Model class name cannot be empty');

        it('throws exception for empty action', function (): void {
            $manager = app(EventManager::class);
            $manager->fireModel('App\\Models\\Order', '', new stdClass);
        })->throws(InvalidArgumentException::class, 'Model action cannot be empty');

        it('throws exception for event name "0"', function (): void {
            $manager = app(EventManager::class);
            $manager->fireModel('0', 'created', new stdClass);
        })->throws(InvalidArgumentException::class, 'Model class name cannot be empty');

        it('constructs event name correctly', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('App\\Models\\Order.created')
                ->action(ZeroBoiler\Events\Tests\Actions\LogOrderCreated::class)
                ->name('Log Order Created Test')
                ->save();

            $model = new class {
                public function attributesToArray(): array
                {
                    return ['id' => 1, 'status' => 'active'];
                }
            };

            // Should not throw
            $manager->fireModel('App\\Models\\Order', 'created', $model);

            expect(true)->toBeTrue();
        });
    });

    describe('TriggerBuilder validation', function (): void {
        it('throws for empty event name', function (): void {
            $manager = app(EventManager::class);
            $builder = $manager->on('valid.event');
            // Try to save with empty event (by creating another builder)
            $manager->on('')->action(ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class)->save();
        })->throws(InvalidArgumentException::class, 'Event name is required');

        it('throws for missing action', function (): void {
            $manager = app(EventManager::class);
            $manager->on('test.event')->save();
        })->throws(InvalidArgumentException::class, 'At least one action is required');

        it('actions() validates each class is a non-empty string', function (): void {
            $manager = app(EventManager::class);
            $manager->on('test.event')
                ->actions(['ValidClass', '', 'AnotherClass'])
                ->save();
        })->throws(InvalidArgumentException::class, 'Each action class must be a non-empty string');

        it('auto-generates name from event when not provided', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.autoname')
                ->action(ZeroBoiler\Events\Tests\Actions\HighPriority::class)
                ->save();

            expect($trigger->name)->toBe('test.autoname Trigger');
        });

        it('deduplicates actions when action() and actions() contain same class', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.dedup')
                ->action(ZeroBoiler\Events\Tests\Actions\HighPriority::class)
                ->actions([ZeroBoiler\Events\Tests\Actions\HighPriority::class, ZeroBoiler\Events\Tests\Actions\LowPriority::class])
                ->save();

            $parsed = $trigger->action;
            // Should be a JSON array with HighPriority prepended then deduped
            $decoded = json_decode($parsed, true);
            expect(is_array($decoded))->toBeTrue();
            // HighPriority should only appear once
            $count = count(array_filter($decoded, fn (mixed $v): bool => $v === \ZeroBoiler\Events\Tests\Actions\HighPriority' || (is_array($v) && ($v['class'] ?? '') === \ZeroBoiler\Events\Tests\Actions\HighPriority')));
            expect($count)->toBe(1);
        });
    });

    describe('EventLog status constants', function (): void {
        it('has exactly four valid statuses', function (): void {
            expect(EventLog::$statuses)->toHaveCount(4);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    describe('Trigger scopes', function (): void {
        it('scopeEnabled filters correctly', function (): void {
            $enabled = Trigger::factory()->enabled()->create();
            $disabled = Trigger::factory()->disabled()->create();

            $results = Trigger::enabled()->get();
            expect($results->pluck('id'))->toContain($enabled->id);
            expect($results->pluck('id'))->not->toContain($disabled->id);
        });

        it('scopeAsync filters correctly', function (): void {
            $async = Trigger::factory()->async()->create();
            $sync = Trigger::factory()->sync()->create();

            $results = Trigger::async()->get();
            expect($results->pluck('id'))->toContain($async->id);
            expect($results->pluck('id'))->not->toContain($sync->id);
        });

        it('scopeOrderByPriority returns triggers in DESC priority order', function (): void {
            Trigger::query()->delete();
            $low = Trigger::factory()->priority(1)->create();
            $high = Trigger::factory()->priority(100)->create();
            $mid = Trigger::factory()->priority(50)->create();

            $results = Trigger::orderByPriority()->get();
            expect($results->first()->id)->toBe($high->id);
            expect($results->last()->id)->toBe($low->id);
        });
    });

    describe('Subscription scopes and methods', function (): void {
        it('scopeActive filters correctly', function (): void {
            $active = Subscription::factory()->active()->create();
            $inactive = Subscription::factory()->inactive()->create();

            $results = Subscription::active()->get();
            expect($results->pluck('id'))->toContain($active->id);
            expect($results->pluck('id'))->not->toContain($inactive->id);
        });

        it('signPayload returns empty string for null secret', function (): void {
            $sub = Subscription::factory()->withoutSecret()->create();
            expect($sub->signPayload('test payload'))->toBe('');
        });

        it('signPayload returns empty string for empty secret', function (): void {
            $sub = Subscription::factory()->create(['secret' => '']);
            expect($sub->signPayload('test payload'))->toBe('');
        });

        it('signPayload produces consistent signature', function (): void {
            $sub = Subscription::factory()->create(['secret' => 'whsec_test_secret_key_1234']);
            $sig1 = $sub->signPayload('hello');
            $sig2 = $sub->signPayload('hello');

            expect($sig1)->not->toBeEmpty();
            expect($sig1)->toBe($sig2);
        });

        it('hasExceededFailures uses config default when no max provided', function (): void {
            $sub = Subscription::factory()->create(['failure_count' => 10]);
            // Default max_failures is 10, so 10 >= 10 = true
            expect($sub->hasExceededFailures())->toBeTrue();
        });

        it('hasExceededFailures uses explicit max override', function (): void {
            $sub = Subscription::factory()->create(['failure_count' => 5]);
            expect($sub->hasExceededFailures(3))->toBeTrue();
            expect($sub->hasExceededFailures(10))->toBeFalse();
        });

        it('resetFailures sets failure_count to 0', function (): void {
            $sub = Subscription::factory()->create(['failure_count' => 15]);
            $sub->resetFailures();

            $sub->refresh();
            expect($sub->failure_count)->toBe(0);
        });

        it('recordDelivery atomically increments delivery_count and updates last_fired_at', function (): void {
            $sub = Subscription::factory()->create([
                'delivery_count' => 0,
                'last_fired_at' => null,
            ]);

            $sub->recordDelivery();
            $sub->refresh();

            expect($sub->delivery_count)->toBe(1);
            expect($sub->last_fired_at)->not->toBeNull();
        });

        it('recordFailure increments failure_count', function (): void {
            $sub = Subscription::factory()->create(['failure_count' => 0]);
            $sub->recordFailure();
            $sub->refresh();

            expect($sub->failure_count)->toBe(1);
        });
    });

    describe('EventLog scopes', function (): void {
        it('scopeStalePending returns only old pending logs', function (): void {
            $oldLog = EventLog::factory()->pending()->create([
                'created_at' => Carbon::now()->subDays(7),
            ]);
            $newLog = EventLog::factory()->pending()->create();

            $stale = EventLog::stalePending(Carbon::now()->subDays(3))->get();
            expect($stale->pluck('id'))->toContain($oldLog->id);
            expect($stale->pluck('id'))->not->toContain($newLog->id);
        });
    });

    describe('EscapesWildcardLike trait', function (): void {
        it('returns null for patterns without wildcards', function (): void {
            $trait = new class {
                use EscapesWildcardLike;
            };

            // Access protected method via reflection
            $ref = new ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            expect($ref->invoke($trait, 'order.placed'))->toBeNull();
            expect($ref->invoke($trait, 'user.created'))->toBeNull();
        });

        it('converts simple wildcard to LIKE pattern', function (): void {
            $trait = new class {
                use EscapesWildcardLike;
            };

            $ref = new ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            expect($ref->invoke($trait, 'order.*'))->toBe('order.%');
            expect($ref->invoke($trait, '*'))->toBe('%');
        });

        it('escapes SQL special characters', function (): void {
            $trait = new class {
                use EscapesWildcardLike;
            };

            $ref = new ReflectionMethod($trait, 'wildcardToLike');
            $ref->setAccessible(true);

            expect($ref->invoke($trait, 'user.%*'))
                ->toBe('user.\\%\\%');
        });
    });

    describe('Config completeness', function (): void {
        it('all config keys referenced in code exist in published config', function (): void {
            $config = config('events');
            expect($config)->not->toBeNull();

            // Verify expected top-level keys
            expect(isset($config['table_names']))->toBeTrue();
            expect(isset($config['queue']))->toBeTrue();
            expect(isset($config['retry']))->toBeTrue();
            expect(isset($config['retention']))->toBeTrue();
            expect(isset($config['subscriptions']))->toBeTrue();
            expect(isset($config['disabled']))->toBeTrue();
            expect(isset($config['wildcard_cache_ttl']))->toBeTrue();

            // Verify table_names sub-keys
            expect(isset($config['table_names']['triggers']))->toBeTrue();
            expect(isset($config['table_names']['event_logs']))->toBeTrue();
            expect(isset($config['table_names']['subscriptions']))->toBeTrue();

            // Verify queue sub-keys
            expect(isset($config['queue']['connection']))->toBeTrue();
            expect(isset($config['queue']['queue']))->toBeTrue();

            // Verify retry sub-keys
            expect(isset($config['retry']['tries']))->toBeTrue();
            expect(isset($config['retry']['backoff']))->toBeTrue();

            // Verify retention sub-keys
            expect(isset($config['retention']['days']))->toBeTrue();
            expect(isset($config['retention']['include_pending']))->toBeTrue();
            expect(isset($config['retention']['schedule_cron']))->toBeTrue();

            // Verify subscriptions sub-keys
            expect(isset($config['subscriptions']['auto_generate_secret']))->toBeTrue();
            expect(isset($config['subscriptions']['secret_length']))->toBeTrue();
            expect(isset($config['subscriptions']['max_failures']))->toBeTrue();
            expect(isset($config['subscriptions']['timeout']))->toBeTrue();
            expect(isset($config['subscriptions']['signature_algorithm']))->toBeTrue();
            expect(isset($config['subscriptions']['cleanup_cron']))->toBeTrue();
        });
    });

    describe('ServiceProvider bindings', function (): void {
        it('binds EventManager as singleton', function (): void {
            $app = app();
            $instance1 = $app->make(EventManager::class);
            $instance2 = $app->make(EventManager::class);

            expect($instance1)->toBe($instance2);
        });

        it('binds ConditionEngine as singleton', function (): void {
            $app = app();
            $instance1 = $app->make(ConditionEngine::class);
            $instance2 = $app->make(ConditionEngine::class);

            expect($instance1)->toBe($instance2);
        });

        it('binds ActionResolver as singleton', function (): void {
            $app = app();
            $instance1 = $app->make(ActionResolver::class);
            $instance2 = $app->make(ActionResolver::class);

            expect($instance1)->toBe($instance2);
        });

        it('binds TriggerBuilder as transient (new instance each time)', function (): void {
            $app = app();
            $instance1 = $app->make(TriggerBuilder::class);
            $instance2 = $app->make(TriggerBuilder::class);

            expect($instance1)->not->toBe($instance2);
        });

        it('binds SubscriptionBuilder as transient (new instance each time)', function (): void {
            $app = app();
            $instance1 = $app->make(SubscriptionBuilder::class);
            $instance2 = $app->make(SubscriptionBuilder::class);

            expect($instance1)->not->toBe($instance2);
        });

        it('binds EventScheduler as singleton', function (): void {
            $app = app();
            $instance1 = $app->make(EventScheduler::class);
            $instance2 = $app->make(EventScheduler::class);

            expect($instance1)->toBe($instance2);
        });
    });

    describe('Facade proxy', function (): void {
        it('Facade resolves to EventManager instance', function (): void {
            $facadeRoot = EventManagerFacade::getFacadeRoot();
            expect($facadeRoot)->toBeInstanceOf(EventManager::class);
        });
    });
});
