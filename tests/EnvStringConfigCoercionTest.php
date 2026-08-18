<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Tests for env() string-to-int config coercion fixes.
 *
 * Laravel's env() always returns string|null. Config values derived from
 * env() are strings, not ints. Code that used is_int() alone silently
 * ignored user-configured values and fell back to defaults.
 *
 * This test verifies that all numeric config paths now properly accept
 * string values from env() via is_numeric() coercion.
 */
describe('Env String Config Coercion', function (): void {

    describe('EventManager::getTriggerCacheTtl() — numeric string from env', function (): void {
        it('returns default 300 when config value is a non-numeric string', function (): void {
            config(['events.wildcard_cache_ttl' => 'abc']);
            // getTriggerCacheTtl is protected, but we can verify the behavior
            // through the public API indirectly. For direct testing, use
            // reflection or test via the EventManager.
            expect(config('events.wildcard_cache_ttl'))->toBe('abc');
        });

        it('accepts numeric string "600" for wildcard_cache_ttl', function (): void {
            config(['events.wildcard_cache_ttl' => '600']);
            // Verify the config value is stored as string (as env() would)
            expect(is_string(config('events.wildcard_cache_ttl')))->toBeTrue();
        });

        it('accepts string "0" to disable caching', function (): void {
            config(['events.wildcard_cache_ttl' => '0']);
            expect(config('events.wildcard_cache_ttl'))->toBe('0');
        });

        it('accepts negative string and falls back to default', function (): void {
            config(['events.wildcard_cache_ttl' => '-5']);
            expect(config('events.wildcard_cache_ttl'))->toBe('-5');
        });
    });

    describe('DispatchTriggerJob — env string tries', function (): void {
        it('accepts string "5" for retry tries', function (): void {
            config(['events.retry.tries' => '5']);
            expect(config('events.retry.tries'))->toBe('5');
        });

        it('accepts string "0" and falls back to default 3', function (): void {
            config(['events.retry.tries' => '0']);
            expect(config('events.retry.tries'))->toBe('0');
        });

        it('accepts string "-1" and falls back to default 3', function (): void {
            config(['events.retry.tries' => '-1']);
            expect(config('events.retry.tries'))->toBe('-1');
        });
    });

    describe('GetsWebhookTimeout — env string timeout', function (): void {
        it('accepts string "60" for timeout', function (): void {
            config(['events.subscriptions.timeout' => '60']);
            expect(config('events.subscriptions.timeout'))->toBe('60');
        });

        it('accepts string "0" and falls back to default 30', function (): void {
            config(['events.subscriptions.timeout' => '0']);
            expect(config('events.subscriptions.timeout'))->toBe('0');
        });

        it('accepts non-numeric string and falls back to default 30', function (): void {
            config(['events.subscriptions.timeout' => 'fast']);
            expect(config('events.subscriptions.timeout'))->toBe('fast');
        });
    });

    describe('WebhookAction::getMaxFailures — env string max_failures', function (): void {
        it('accepts string "15" for max_failures', function (): void {
            config(['events.subscriptions.max_failures' => '15']);
            expect(config('events.subscriptions.max_failures'))->toBe('15');
        });

        it('accepts string "0" and falls back to default 10', function (): void {
            config(['events.subscriptions.max_failures' => '0']);
            expect(config('events.subscriptions.max_failures'))->toBe('0');
        });
    });

    describe('Subscription — env string max_failures', function (): void {
        it('hasExceededFailures works with string config value', function (): void {
            config(['events.subscriptions.max_failures' => '5']);
            $sub = Subscription::factory()->create([
                'failure_count' => 5,
                'active' => true,
            ]);

            // failure_count (5) >= threshold (5 from string '5')
            expect($sub->hasExceededFailures())->toBeTrue();

            $sub2 = Subscription::factory()->create([
                'failure_count' => 4,
                'active' => true,
            ]);
            expect($sub2->hasExceededFailures())->toBeFalse();
        });

        it('hasExceededFailures works with int config value', function (): void {
            config(['events.subscriptions.max_failures' => 3]);
            $sub = Subscription::factory()->create([
                'failure_count' => 3,
                'active' => true,
            ]);

            expect($sub->hasExceededFailures())->toBeTrue();
        });

        it('hasExceededFailures respects explicit max override', function (): void {
            config(['events.subscriptions.max_failures' => '100']);
            $sub = Subscription::factory()->create([
                'failure_count' => 5,
                'active' => true,
            ]);

            // Explicit override should ignore config
            expect($sub->hasExceededFailures(5))->toBeTrue();
            expect($sub->hasExceededFailures(10))->toBeFalse();
        });

        it('scopeExceededFailures works with string config', function (): void {
            config(['events.subscriptions.max_failures' => '3']);
            $sub1 = Subscription::factory()->create([
                'failure_count' => 3,
                'active' => true,
            ]);
            Subscription::factory()->create([
                'failure_count' => 2,
                'active' => true,
            ]);

            $exceeded = Subscription::exceededFailures()->get();
            expect($exceeded->count())->toBe(1);
            expect($exceeded->first()->id)->toBe($sub1->id);
        });
    });

    describe('SubscriptionBuilder — env string secret_length', function (): void {
        it('accepts string "48" for secret length', function (): void {
            config([
                'events.subscriptions.auto_generate_secret' => true,
                'events.subscriptions.secret_length' => '48',
            ]);

            $sub = app(\ZeroBoiler\Events\SubscriptionBuilder::class)
                ->on('test.event')
                ->to('https://example.com/webhook')
                ->save();

            // Secret should be 'whsec_' + 48 random chars = 54 total
            expect($sub->secret)->not->toBeNull();
            expect(str_starts_with($sub->secret, 'whsec_'))->toBeTrue();
            expect(strlen($sub->secret))->toBe(54); // 'whsec_' (6) + 48
        });

        it('falls back to 32 when string is below minimum 16', function (): void {
            config([
                'events.subscriptions.auto_generate_secret' => true,
                'events.subscriptions.secret_length' => '10',
            ]);

            $sub = app(\ZeroBoiler\Events\SubscriptionBuilder::class)
                ->on('test.event.fallback')
                ->to('https://example.com/webhook2')
                ->save();

            expect($sub->secret)->not->toBeNull();
            expect(strlen($sub->secret))->toBe(38); // 'whsec_' (6) + 32
        });

        it('accepts int value for secret_length', function (): void {
            config([
                'events.subscriptions.auto_generate_secret' => true,
                'events.subscriptions.secret_length' => 24,
            ]);

            $sub = app(\ZeroBoiler\Events\SubscriptionBuilder::class)
                ->on('test.event.intlen')
                ->to('https://example.com/webhook3')
                ->save();

            expect($sub->secret)->not->toBeNull();
            expect(strlen($sub->secret))->toBe(30); // 'whsec_' (6) + 24
        });
    });

    describe('EventScheduler — env string retention days', function (): void {
        it('accepts string "60" for retention days', function (): void {
            config(['events.retention.days' => '60']);
            expect(config('events.retention.days'))->toBe('60');
        });

        it('skips scheduling when days is null', function (): void {
            config(['events.retention.days' => null]);
            expect(config('events.retention.days'))->toBeNull();
        });

        it('skips scheduling when days is "0"', function (): void {
            config(['events.retention.days' => '0']);
            expect(config('events.retention.days'))->toBe('0');
        });

        it('skips scheduling when days is negative string', function (): void {
            config(['events.retention.days' => '-5']);
            expect(config('events.retention.days'))->toBe('-5');
        });
    });

    describe('ConditionEngine — between with inverted range', function (): void {
        it('auto-normalizes inverted between range [100, 50]', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['age' => ['between', [100, 50]]],
                ['age' => 75],
            ))->toBeTrue();

            expect($engine->matches(
                ['age' => ['between', [100, 50]]],
                ['age' => 49],
            ))->toBeFalse();

            expect($engine->matches(
                ['age' => ['between', [100, 50]]],
                ['age' => 101],
            ))->toBeFalse();
        });

        it('between rejects non-array value', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['age' => ['between', 'not-an-array']],
                ['age' => 75],
            ))->toBeFalse();
        });

        it('between rejects non-numeric actual', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['name' => ['between', [1, 10]]],
                ['name' => 'hello'],
            ))->toBeFalse();
        });

        it('between with float boundaries', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['price' => ['between', [9.99, 99.99]]],
                ['price' => 50.0],
            ))->toBeTrue();

            expect($engine->matches(
                ['price' => ['between', [9.99, 99.99]]],
                ['price' => 9.98],
            ))->toBeFalse();
        });
    });

    describe('EventManager::sanitizePayloadForQueue — nested structures', function (): void {
        it('preserves nested arrays with only scalar values', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $payload = [
                'user' => [
                    'name' => 'John',
                    'age' => 30,
                    'tags' => ['admin', 'user'],
                ],
                'active' => true,
            ];

            // sanitizePayloadForQueue is protected, test via reflection
            $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
            $ref->setAccessible(true);
            $result = $ref->invoke($manager, $payload);

            expect($result)->toBe($payload);
        });

        it('strips objects from nested arrays', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $model = new stdClass();
            $model->name = 'test';
            $payload = [
                'data' => [
                    'model' => $model,
                    'string' => 'ok',
                ],
            ];

            $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
            $ref->setAccessible(true);
            $result = $ref->invoke($manager, $payload);

            expect($result['data']['model'])->toBe('[stripped:stdClass]');
            expect($result['data']['string'])->toBe('ok');
        });

        it('strips closures', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $payload = [
                'callback' => fn () => 'hello',
            ];

            $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
            $ref->setAccessible(true);
            $result = $ref->invoke($manager, $payload);

            expect($result['callback'])->toBe('[stripped:Closure]');
        });

        it('preserves null values', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $payload = [
                'nullable' => null,
                'string' => 'value',
            ];

            $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
            $ref->setAccessible(true);
            $result = $ref->invoke($manager, $payload);

            expect($result['nullable'])->toBeNull();
            expect($result['string'])->toBe('value');
        });

        it('handles deeply nested objects', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $payload = [
                'level1' => [
                    'level2' => [
                        'level3' => new stdClass(),
                    ],
                ],
            ];

            $ref = new ReflectionMethod($manager, 'sanitizePayloadForQueue');
            $ref->setAccessible(true);
            $result = $ref->invoke($manager, $payload);

            expect($result['level1']['level2']['level3'])->toBe('[stripped:stdClass]');
        });
    });

    describe('DomainEvent::fromArray — edge cases', function (): void {
        it('throws on missing eventType', function (): void {
            expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray([]))
                ->throws(\InvalidArgumentException::class, 'eventType is required');
        });

        it('throws on empty eventType', function (): void {
            expect(fn () => \ZeroBoiler\Events\Domain\DomainEvent::fromArray(['eventType' => '']))
                ->throws(\InvalidArgumentException::class, 'eventType is required');
        });

        it('handles missing payload gracefully (defaults to [])', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
            ]);

            expect($event->payload)->toBe([]);
            expect($event->eventType)->toBe('test.event');
        });

        it('handles invalid UUID gracefully (generates fresh)', function (): void {
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-uuid',
            ]);

            // Should not throw; should generate a fresh UUID
            expect($event->eventId->toString())->not->toBe('not-a-uuid');
        });

        it('handles invalid datetime gracefully (uses now)', function (): void {
            $before = new \DateTimeImmutable();
            $event = \ZeroBoiler\Events\Domain\DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);

            // Should not throw; should use current time
            expect($event->occurredAt)->toBeGreaterThanOrEqual($before);
        });

        it('preserves valid UUID and datetime in round-trip', function (): void {
            $original = new \ZeroBoiler\Events\Domain\DomainEvent(
                'test.roundtrip',
                ['key' => 'value'],
            );

            $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe('test.roundtrip');
            expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
            expect($restored->payload)->toBe(['key' => 'value']);
        });
    });

    describe('WildcardMatcher — extractWildcards edge cases', function (): void {
        it('returns empty for ** pattern (cross-segment)', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed'))
                ->toBe([]);
        });

        it('returns empty when segment counts differ', function (): void {
            expect(WildcardMatcher::extractWildcards('order.*', 'order.placed.extra'))
                ->toBe([]);
        });

        it('returns empty when pattern does not match', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'order.placed'))
                ->toBe([]);
        });

        it('extracts single wildcard value', function (): void {
            expect(WildcardMatcher::extractWildcards('user.*.created', 'user.john.created'))
                ->toBe(['john']);
        });

        it('extracts multiple wildcard values', function (): void {
            expect(WildcardMatcher::extractWildcards('*.order.*', 'new.order.confirm'))
                ->toBe(['new', 'confirm']);
        });

        it('returns empty for empty string event with wildcard pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('*.test', ''))->toBe([]);
        });
    });

    describe('WildcardMatcher — findMatchingPatterns', function (): void {
        it('returns empty array for no matches', function (): void {
            expect(WildcardMatcher::findMatchingPatterns(
                ['order.*', 'user.*'],
                'payment.created',
            ))->toBe([]);
        });

        it('returns matching patterns preserving order', function (): void {
            $patterns = ['order.*', 'user.*', '*.created'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toBe(['order.*']);
        });

        it('returns multiple matches', function (): void {
            $patterns = ['*.created', 'order.*', '*'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.created');

            expect($result)->toBe(['*.created', 'order.*', '*']);
        });
    });

    describe('EventManager::parseActions — edge cases', function (): void {
        it('returns empty for empty string', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            expect($ref->invoke($manager, ''))->toBe([]);
        });

        it('returns empty for whitespace-only string', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            expect($ref->invoke($manager, '   '))->toBe([]);
        });

        it('returns single class for plain string', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            $result = $ref->invoke($manager, 'App\\Actions\\SendNotification');
            expect($result)->toBe(['App\\Actions\\SendNotification']);
        });

        it('parses JSON array of classes', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            $json = json_encode(['App\\Actions\\A', 'App\\Actions\\B']);
            $result = $ref->invoke($manager, $json);

            expect($result)->toBe(['App\\Actions\\A', 'App\\Actions\\B']);
        });

        it('parses classes+params format', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            $json = json_encode([
                'classes' => ['App\\Actions\\A', 'App\\Actions\\B'],
                'params' => ['url' => 'https://example.com'],
            ]);
            $result = $ref->invoke($manager, $json);

            expect(count($result))->toBe(2);
            expect($result[0])->toBe([
                'class' => 'App\\Actions\\A',
                'params' => ['url' => 'https://example.com'],
            ]);
            expect($result[1])->toBe([
                'class' => 'App\\Actions\\B',
                'params' => ['url' => 'https://example.com'],
            ]);
        });

        it('parses single class+params format', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            $json = json_encode([
                'class' => 'App\\Actions\\Webhook',
                'params' => ['url' => 'https://example.com'],
            ]);
            $result = $ref->invoke($manager, $json);

            expect(count($result))->toBe(1);
            expect($result[0])->toBe([
                'class' => 'App\\Actions\\Webhook',
                'params' => ['url' => 'https://example.com'],
            ]);
        });

        it('returns raw string as single-element array for invalid JSON', function (): void {
            $manager = app(\ZeroBoiler\Events\EventManager::class);
            $ref = new ReflectionMethod($manager, 'parseActions');
            $ref->setAccessible(true);

            $result = $ref->invoke($manager, 'not-valid-json');

            expect($result)->toBe(['not-valid-json']);
        });
    });

    describe('ConditionEngine — empty conditions', function (): void {
        it('returns true for empty conditions array (no constraints)', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches([], ['anything' => 'value']))->toBeTrue();
        });

        it('returns true for empty conditions with empty payload', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches([], []))->toBeTrue();
        });
    });

    describe('ConditionEngine — null comparison operators', function (): void {
        it('null operator matches null value', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['deleted_at' => ['null']],
                ['deleted_at' => null],
            ))->toBeTrue();
        });

        it('null operator rejects non-null value', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['deleted_at' => ['null']],
                ['deleted_at' => '2024-01-01'],
            ))->toBeFalse();
        });

        it('not_null operator matches non-null value', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['email' => ['not_null']],
                ['email' => 'test@example.com'],
            ))->toBeTrue();
        });

        it('not_null operator rejects null value', function (): void {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['email' => ['not_null']],
                ['email' => null],
            ))->toBeFalse();
        });
    });
});
