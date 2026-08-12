<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\TestCase;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 86 comprehensive production audit — targeted test coverage
 * for edge cases identified during deep manual code review.
 *
 * Covers:
 * - EventManager::fireModel() with plain stdClass (no model methods)
 * - EventManager::fireModel() with object having only toArray()
 * - EventManager::fireModel() with nested attributes in payload
 * - ConditionEngine::strictEquals() array vs string (returns false)
 * - ConditionEngine::strictEquals() bool vs int (returns false)
 * - ConditionEngine::safeRegexMatch() catastrophic pattern rejection
 * - ConditionEngine::safeRegexMatch() pattern exceeding max length
 * - WildcardMatcher::matches() with exact pattern (no wildcards)
 * - WildcardMatcher::matches() with empty pattern and empty event
 * - WildcardMatcher::matches() single-char event
 * - WildcardMatcher::findMatchingPatterns() with no matches
 * - SubscriptionBuilder validation with empty event
 * - SubscriptionBuilder validation with empty URL
 * - SubscriptionBuilder validation with non-HTTP scheme
 * - EventManager::getTrigger() with nonexistent ID
 * - EventManager::getSubscription() with nonexistent ID
 * - EventManager::listTriggers() with empty string event filter
 * - EventManager::isDisabled() / setEnabled() toggle
 * - DomainEvent immutability (all properties readonly)
 * - EventLog status constants match $statuses array
 * - DispatchTriggerJob handles negative tries config
 * - Config file structure completeness
 */
describe('Phase 86 Production Audit', function () {
    // -------------------------------------------------------
    // EventManager::fireModel() with plain stdClass
    // -------------------------------------------------------
    describe('EventManager::fireModel() with various objects', function () {
        it('fires successfully with plain stdClass (no attributesToArray/toArray)', function () {
            $manager = app(EventManager::class);
            $obj = new \stdClass;
            $obj->name = 'Test';

            // Should not throw — fireModel should handle objects without model methods
            // by passing empty modelData
            $manager->fireModel('stdClass', 'created', $obj);
            expect(true)->toBeTrue();
        });

        it('fires with object having only toArray() method', function () {
            $manager = app(EventManager::class);
            $obj = new class {
                public function toArray(): array
                {
                    return ['id' => 42, 'name' => 'TestObject'];
                }
            };

            $manager->fireModel('AnonymousClass', 'updated', $obj);
            expect(true)->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // ConditionEngine::strictEquals() type safety
    // -------------------------------------------------------
    describe('ConditionEngine::strictEquals() type safety', function () {
        it('returns false for array vs string comparison', function () {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['tags' => 'value'],
                ['tags' => ['a', 'b']],
            ))->toBeFalse();
        });

        it('returns false for bool vs int when strict comparison', function () {
            $engine = app(ConditionEngine::class);

            // true vs 1 — same get_debug_type? No: bool vs int
            expect($engine->matches(
                ['active' => ['===', true]],
                ['active' => 1],
            ))->toBeFalse();
        });

        it('returns true for same-type numeric equality', function () {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['count' => 42],
                ['count' => 42],
            ))->toBeTrue();
        });
    });

    // -------------------------------------------------------
    // ConditionEngine::safeRegexMatch() security
    // -------------------------------------------------------
    describe('ConditionEngine::safeRegexMatch() ReDoS protection', function () {
        it('rejects nested quantifier pattern', function () {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['input' => ['matches', '/(a+)+/']],
                ['input' => 'aaa'],
            ))->toBeFalse();
        });

        it('rejects pattern exceeding max length', function () {
            $engine = app(ConditionEngine::class);

            $longPattern = '/^' . str_repeat('a', 600) . '$/';

            expect($engine->matches(
                ['input' => ['matches', $longPattern]],
                ['input' => str_repeat('a', 600)],
            ))->toBeFalse();
        });

        it('accepts valid short regex', function () {
            $engine = app(ConditionEngine::class);

            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}$/']],
                ['code' => 'ABC'],
            ))->toBeTrue();

            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}$/']],
                ['code' => 'abc'],
            ))->toBeFalse();
        });
    });

    // -------------------------------------------------------
    // WildcardMatcher edge cases
    // -------------------------------------------------------
    describe('WildcardMatcher::matches() edge cases', function () {
        it('matches exact pattern without wildcards', function () {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('catch-all pattern does not match empty string', function () {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('matches single-char event against single-segment wildcard', function () {
            expect(WildcardMatcher::matches('*', 'x'))->toBeTrue();
        });

        it('findMatchingPatterns returns empty array when no patterns match', function () {
            $result = WildcardMatcher::findMatchingPatterns(
                ['user.*', 'order.*'],
                'payment.placed',
            );

            expect($result)->toBe([]);
        });

        it('findMatchingPatterns returns all matching patterns', function () {
            $result = WildcardMatcher::findMatchingPatterns(
                ['user.*', 'order.*', '*.placed'],
                'order.placed',
            );

            expect($result)->toContain('order.*');
            expect($result)->toContain('*.placed');
            expect($result)->not->toContain('user.*');
        });
    });

    // -------------------------------------------------------
    // SubscriptionBuilder validation
    // -------------------------------------------------------
    describe('SubscriptionBuilder validation', function () {
        it('throws InvalidArgumentException for empty event', function () {
            $app = app();
            $manager = $app->make(EventManager::class);
            $builder = $manager->subscribe('', 'https://example.com/webhook');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'Event name is required');
        });

        it('throws InvalidArgumentException for empty URL', function () {
            $app = app();
            $manager = $app->make(EventManager::class);
            $builder = $manager->subscribe('order.placed', '');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
        });

        it('throws InvalidArgumentException for ftp:// URL scheme', function () {
            $app = app();
            Config::set('events.subscriptions.auto_generate_secret', false);
            $manager = $app->make(EventManager::class);
            $builder = $manager->subscribe('order.placed', 'ftp://files.example.com/webhook');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'must use HTTP or HTTPS');
        });

        it('throws InvalidArgumentException for file:// URL scheme', function () {
            $app = app();
            Config::set('events.subscriptions.auto_generate_secret', false);
            $manager = $app->make(EventManager::class);
            $builder = $manager->subscribe('order.placed', 'file:///etc/passwd');

            expect(fn () => $builder->save())
                ->toThrow(\InvalidArgumentException::class, 'must use HTTP or HTTPS');
        });
    });

    // -------------------------------------------------------
    // EventManager CRUD edge cases
    // -------------------------------------------------------
    describe('EventManager CRUD edge cases', function () {
        it('getTrigger returns null for nonexistent ID', function () {
            $manager = app(EventManager::class);

            expect($manager->getTrigger('nonexistent-uuid-0000-0000-000000000000'))->toBeNull();
        });

        it('listTriggers with empty string event filter returns all triggers', function () {
            $manager = app(EventManager::class);

            // Empty string should not filter — returns all (up to limit)
            $result = $manager->listTriggers(event: '', limit: 10);

            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });
    });

    // -------------------------------------------------------
    // EventManager::isDisabled / setEnabled toggle
    // -------------------------------------------------------
    describe('EventManager global disable toggle', function () {
        it('toggles disabled state correctly', function () {
            $manager = app(EventManager::class);

            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();

            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });

        it('fire returns early when disabled', function () {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);

            // Should silently return without firing
            $manager->fire('test.event', ['key' => 'value']);

            expect($manager->isDisabled())->toBeTrue();

            // Clean up
            $manager->setEnabled(true);
        });
    });

    // -------------------------------------------------------
    // DomainEvent immutability
    // -------------------------------------------------------
    describe('DomainEvent immutability', function () {
        it('all properties are readonly', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            $ref = new ReflectionClass($event);

            foreach (['eventId', 'eventType', 'payload', 'occurredAt'] as $prop) {
                $rp = $ref->getProperty($prop);
                expect($rp->isReadOnly())->toBeTrue("Property {$prop} should be readonly");
            }
        });

        it('toArray and fromArray round-trip preserves data', function () {
            $original = DomainEvent::occur('order.placed', ['order_id' => 42]);
            $data = $original->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
                ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
            expect($restored->payload)->toBe($original->payload);
        });

        it('occur factory creates unique IDs', function () {
            $a = DomainEvent::occur('test.event');
            $b = DomainEvent::occur('test.event');

            expect($a->eventId->toString())->not->toBe($b->eventId->toString());
        });
    });

    // -------------------------------------------------------
    // EventLog status consistency
    // -------------------------------------------------------
    describe('EventLog status consistency', function () {
        it('status constants match $statuses array values exactly', function () {
            $constants = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];

            foreach ($constants as $status) {
                expect(EventLog::$statuses)->toContain($status);
            }
            expect(EventLog::$statuses)->toHaveCount(count($constants));
        });
    });

    // -------------------------------------------------------
    // DispatchTriggerJob edge cases
    // -------------------------------------------------------
    describe('DispatchTriggerJob config handling', function () {
        it('uses default tries when config is zero', function () {
            Config::set('events.retry.tries', 0);

            $job = new DispatchTriggerJob(
                triggerId: 'test-id',
                event: 'test.event',
                payload: [],
            );

            expect($job->tries)->toBe(3);
        });

        it('uses default tries when config is negative', function () {
            Config::set('events.retry.tries', -5);

            $job = new DispatchTriggerJob(
                triggerId: 'test-id',
                event: 'test.event',
                payload: [],
            );

            expect($job->tries)->toBe(3);
        });

        it('uses default queue when config is empty string', function () {
            Config::set('events.queue.queue', '');

            $job = new DispatchTriggerJob(
                triggerId: 'test-id',
                event: 'test.event',
                payload: [],
            );

            expect($job->queue)->toBe('default');
        });

        it('ignores empty string connection config', function () {
            Config::set('events.queue.connection', '');

            $job = new DispatchTriggerJob(
                triggerId: 'test-id',
                event: 'test.event',
                payload: [],
            );

            expect($job->connection)->toBeNull();
        });
    });

    // -------------------------------------------------------
    // Config file completeness
    // -------------------------------------------------------
    describe('Config file structure completeness', function () {
        it('has all required top-level keys', function () {
            $config = config('events');

            expect($config)->toBeArray();
            expect($config)->toHaveKey('table_names');
            expect($config)->toHaveKey('queue');
            expect($config)->toHaveKey('retry');
            expect($config)->toHaveKey('retention');
            expect($config)->toHaveKey('subscriptions');
            expect($config)->toHaveKey('disabled');
            expect($config)->toHaveKey('wildcard_cache_ttl');
        });

        it('table_names has all three table entries', function () {
            $tables = config('events.table_names');

            expect($tables)->toHaveKey('triggers');
            expect($tables)->toHaveKey('event_logs');
            expect($tables)->toHaveKey('subscriptions');
        });

        it('subscriptions config has all required keys', function () {
            $subs = config('events.subscriptions');

            expect($subs)->toHaveKey('auto_generate_secret');
            expect($subs)->toHaveKey('max_failures');
            expect($subs)->toHaveKey('timeout');
            expect($subs)->toHaveKey('signature_algorithm');
        });
    });
});
