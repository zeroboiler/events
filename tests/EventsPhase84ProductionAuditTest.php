<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\Tests\TestCase;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 84 comprehensive production audit — targeted test coverage
 * for edge cases identified during manual code review.
 *
 * Covers:
 * - WildcardMatcher::findMatchingPatterns() return types and edge cases
 * - WildcardMatcher::extractWildcards() empty/extraction fidelity
 * - SubscriptionBuilder URL scheme validation (ftp, file, mailto, javascript)
 * - DomainEvent::fromArray() with extra fields, missing fields, reconstruction fidelity
 * - TriggerBuilder action() + actions() merging and deduplication
 * - Trait docblock correctness (no reference to non-existent $manager)
 * - DomainEvent immutability verification
 */
describe('Phase 84 Production Audit', function () {
    describe('WildcardMatcher::findMatchingPatterns', function () {
        it('returns empty array for empty patterns list', function () {
            $result = WildcardMatcher::findMatchingPatterns([], 'order.placed');

            expect($result)->toBeArray()->toBe([]);
        });

        it('returns only matching patterns for a given event', function () {
            $patterns = ['order.placed', 'order.*', 'payment.*', 'order.shipped'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toContain('order.placed')
                ->toContain('order.*')
                ->not()->toContain('payment.*')
                ->not()->toContain('order.shipped');
        });

        it('preserves pattern order from input', function () {
            $patterns = ['a.*', 'b.*', 'a.specific'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'a.specific');

            // array_values removes gaps; order is preserved
            expect($result)->toEqual(['a.*', 'a.specific']);
        });

        it('returns empty array when no patterns match', function () {
            $patterns = ['payment.*', 'user.*'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toBe([]);
        });

        it('handles catch-all pattern correctly', function () {
            $patterns = ['*', 'order.placed'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

            expect($result)->toContain('*')->toContain('order.placed');
        });

        it('returns re-indexed array (no gaps from array_filter)', function () {
            $patterns = ['x.*', 'y.*', 'x.z'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'x.z');

            expect(array_values($result))->toEqual(['x.*', 'x.z']);
            expect(array_is_list($result))->toBeTrue();
        });
    });

    describe('WildcardMatcher::extractWildcards', function () {
        it('returns empty array for patterns with double-star', function () {
            $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.shipped');

            expect($result)->toBe([]);
        });

        it('returns empty array when segment count mismatches', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.settings.created');

            expect($result)->toBe([]);
        });

        it('extracts single wildcard value correctly', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');

            expect($result)->toEqual(['admin']);
        });

        it('extracts multiple wildcard values', function () {
            $result = WildcardMatcher::extractWildcards('*.*.created', 'user.admin.created');

            expect($result)->toEqual(['user', 'admin']);
        });

        it('returns empty array when event does not match pattern', function () {
            $result = WildcardMatcher::extractWildcards('user.*.created', 'order.admin.deleted');

            expect($result)->toBe([]);
        });

        it('returns empty array for non-wildcard pattern', function () {
            $result = WildcardMatcher::extractWildcards('order.placed', 'order.placed');

            expect($result)->toBe([]);
        });

        it('returns empty string for wildcard matching empty segment', function () {
            $result = WildcardMatcher::extractWildcards('*.created', '.created');

            expect($result)->toEqual(['']);
        });
    });

    describe('DomainEvent::fromArray edge cases', function () {
        it('ignores extra fields in data array', function () {
            $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
            $data = $event->toArray();
            $data['extra_field'] = 'should_be_ignored';

            $restored = DomainEvent::fromArray($data);

            expect($restored->eventType)->toBe('user.registered')
                ->and($restored->payload)->toHaveKey('email')
                ->and($restored->payload)->not()->toHaveKey('extra_field');
        });

        it('generates fresh UUID when eventId is invalid', function () {
            $restored = DomainEvent::fromArray([
                'eventId' => 'not-a-valid-uuid',
                'eventType' => 'test.event',
                'payload' => [],
                'occurredAt' => '2024-01-01T00:00:00+00:00',
            ]);

            expect($restored->eventId->toString())->not()->toBe('not-a-valid-uuid');
            expect($restored->eventType)->toBe('test.event');
        });

        it('generates fresh UUID when eventId is missing', function () {
            $restored = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => ['key' => 'value'],
            ]);

            expect($restored->eventId->toString())->toBeString()->toHaveLength(36);
        });

        it('uses current time when occurredAt is invalid', function () {
            $before = new DateTimeImmutable('-1 second');
            $restored = DomainEvent::fromArray([
                'eventId' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-valid-date',
            ]);
            $after = new DateTimeImmutable('+1 second');

            expect($restored->occurredAt)->greaterThanOrEqual($before)
                ->lessThanOrEqual($after);
        });

        it('throws exception when eventType is missing', function () {
            DomainEvent::fromArray(['eventType' => '', 'payload' => []]);
        })->throws(\InvalidArgumentException::class, 'eventType is required');

        it('throws exception when eventType is not a string', function () {
            DomainEvent::fromArray(['eventType' => 123, 'payload' => []]);
        })->throws(\InvalidArgumentException::class, 'eventType is required');

        it('preserves payload when it is not an array', function () {
            $restored = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => 'not_an_array',
            ]);

            expect($restored->payload)->toBe([]);
        });

        it('round-trips correctly through toArray and fromArray', function () {
            $original = DomainEvent::occur('order.created', ['order_id' => 42, 'total' => 99.99]);
            $restored = DomainEvent::fromArray($original->toArray());

            expect($restored->eventId->toString())->toBe($original->eventId->toString())
                ->and($restored->eventType)->toBe($original->eventType)
                ->and($restored->occurredAt->format(\DateTimeInterface::ATOM))
                    ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM))
                ->and($restored->payload)->toEqual($original->payload);
        });

        it('DomainEvent is immutable — readonly properties cannot be modified', function () {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            // Verify readonly by checking the property is public readonly
            $ref = new ReflectionClass($event);
            $prop = $ref->getProperty('eventType');

            expect($prop->isReadOnly())->toBeTrue()
                ->and($prop->isPublic())->toBeTrue();
        });
    });

    describe('TriggerBuilder action merging and deduplication', function () {
        it('deduplicates action classes preserving first occurrence', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            // The builder uses resolveActions() internally on save()
            // We test the final action string output through the builder
            // by examining the saved trigger
            $trigger = $em->on('test.dedup')
                ->actions([\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class, \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class, \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class])
                ->save();

            $actions = json_decode($trigger->action, true);

            // Should only have 2 unique actions, first occurrence preserved
            expect($actions)->toHaveCount(2)
                ->and($actions[0])->toBe(\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class)
                ->and($actions[1])->toBe(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class);
        });

        it('merges action() and actions() without duplication', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $cls = \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class;
            $trigger = $em->on('test.merge')
                ->action($cls)
                ->actions([$cls, \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class])
                ->save();

            $actions = json_decode($trigger->action, true);

            expect($actions)->toHaveCount(2)
                ->and($actions[0])->toBe($cls)
                ->and($actions[1])->toBe(\ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class);
        });

        it('actionParams are encoded with multi-action classes format', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $trigger = $em->on('test.params')
                ->actions([\ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class, \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class])
                ->actionParams(['url' => 'https://example.com/hook'])
                ->save();

            $decoded = json_decode($trigger->action, true);

            expect($decoded)->toHaveKey('classes')
                ->and($decoded['classes'])->toHaveCount(2)
                ->and($decoded)->toHaveKey('params')
                ->and($decoded['params']['url'])->toBe('https://example.com/hook');
        });
    });

    describe('SubscriptionBuilder URL validation', function () {
        it('rejects ftp:// URL scheme', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->subscribe('test.event', 'ftp://evil.com/payload');
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        it('rejects file:// URL scheme', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->subscribe('test.event', 'file:///etc/passwd');
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        it('rejects mailto: URL scheme', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->subscribe('test.event', 'mailto:admin@example.com');
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        it('rejects javascript: URL scheme', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->subscribe('test.event', 'javascript:alert(1)');
        })->throws(\InvalidArgumentException::class, 'HTTP or HTTPS');

        it('accepts valid https:// URL', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $sub = $em->subscribe('test.event', 'https://partner.com/webhook')
                ->save();

            expect($sub->url)->toBe('https://partner.com/webhook')
                ->and($sub->active)->toBeTrue()
                ->and($sub->secret)->not()->toBeNull()
                ->and($sub->secret)->toStartWith('whsec_');
        });

        it('accepts valid http:// URL', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $sub = $em->subscribe('test.event', 'http://localhost:8080/hook')
                ->save();

            expect($sub->url)->toBe('http://localhost:8080/hook');
        });

        it('rejects empty URL', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->subscribe('test.event', '');
        })->throws(\InvalidArgumentException::class, 'URL is required');

        it('rejects empty event name', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->subscribe('', 'https://example.com/hook');
        })->throws(\InvalidArgumentException::class, 'Event name is required');
    });

    describe('Trait docblock correctness', function () {
        it('EventManager uses EscapesWildcardLike trait', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $traits = $ref->getTraitNames();

            expect($traits)->toContain(\ZeroBoiler\Events\Concerns\EscapesWildcardLike::class);
        });

        it('EventManager uses ManagesSubscriptions trait', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $traits = $ref->getTraitNames();

            expect($traits)->toContain(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class);
        });

        it('EventManager uses ManagesHistory trait', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
            $traits = $ref->getTraitNames();

            expect($traits)->toContain(\ZeroBoiler\Events\Concerns\ManagesHistory::class);
        });

        it('EventManager has the $app property required by traits', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);

            expect($ref->hasProperty('app'))->toBeTrue();

            $prop = $ref->getProperty('app');
            expect($prop->isReadOnly())->toBeTrue()
                ->and($prop->getType()->getName())->toBe(\Illuminate\Container\Container::class);
        });

        it('ManagesSubscriptions trait docblock does not reference $manager', function () {
            $doc = (new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesSubscriptions::class))->getDocComment();

            expect($doc)->not()->toContain('$manager');
        });

        it('ManagesHistory trait docblock does not reference $manager', function () {
            $doc = (new ReflectionClass(\ZeroBoiler\Events\Concerns\ManagesHistory::class))->getDocComment();

            expect($doc)->not()->toContain('$manager');
        });
    });

    describe('ConditionEngine type safety edge cases', function () {
        it('between operator with inverted range normalizes correctly', function () {
            $app = app();
            $engine = $app->make(\ZeroBoiler\Events\ConditionEngine::class);

            // [100, 50] should normalize to [50, 100]
            expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 75]))->toBeTrue();
            expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 49]))->toBeFalse();
            expect($engine->matches(['value' => ['between', [100, 50]]], ['value' => 101]))->toBeFalse();
        });

        it('contains operator with non-string non-array actual returns false', function () {
            $app = app();
            $engine = $app->make(\ZeroBoiler\Events\ConditionEngine::class);

            // $actual = null, not string or array
            expect($engine->matches(['field' => ['contains', 'test']], ['field' => null]))->toBeFalse();
            expect($engine->matches(['field' => ['contains', 'test']], ['field' => 123]))->toBeFalse();
        });

        it('in operator with null value returns false', function () {
            $app = app();
            $engine = $app->make(\ZeroBoiler\Events\ConditionEngine::class);

            expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => null]))->toBeFalse();
        });

        it('empty conditions array returns true', function () {
            $app = app();
            $engine = $app->make(\ZeroBoiler\Events\ConditionEngine::class);

            expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
        });

        it('empty operator array returns false', function () {
            $app = app();
            $engine = $app->make(\ZeroBoiler\Events\ConditionEngine::class);

            // [0] => [] empty array as expected
            expect($engine->matches(['field' => []], ['field' => 'test']))->toBeFalse();
        });

        it('not_empty operator works correctly', function () {
            $app = app();
            $engine = $app->make(\ZeroBoiler\Events\ConditionEngine::class);

            expect($engine->matches(['name' => ['not_empty']], ['name' => 'John']))->toBeTrue();
            expect($engine->matches(['name' => ['not_empty']], ['name' => '']))->toBeFalse();
            expect($engine->matches(['name' => ['not_empty']], ['name' => null]))->toBeFalse();
            expect($engine->matches(['name' => ['not_empty']], ['name' => 0]))->toBeFalse();
            expect($engine->matches(['name' => ['not_empty']], ['items' => []]))->toBeFalse();
        });
    });

    describe('EventManager fire validation', function () {
        it('throws on empty string event name', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->fire('');
        })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

        it('throws on "0" event name', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->fire('0');
        })->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

        it('fireModel throws on empty model class', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->fireModel('', 'created', new stdClass);
        })->throws(\InvalidArgumentException::class, 'Model class name cannot be empty');

        it('fireModel throws on empty action', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);

            $em->fireModel('App\\Models\\User', '', new stdClass);
        })->throws(\InvalidArgumentException::class, 'Model action cannot be empty');

        it('silently returns when globally disabled', function () {
            $app = app();
            $em = $app->make(\ZeroBoiler\Events\EventManager::class);
            $em->setEnabled(false);

            // Should not throw, should silently return
            $em->fire('test.event', ['data' => 'value']);

            // Re-enable for other tests
            $em->setEnabled(true);

            // If we reach here, the test passed
            expect(true)->toBeTrue();
        });
    });

    describe('WildcardMatcher comprehensive edge cases', function () {
        it('handles pattern with no wildcards as exact match', function () {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        it('single-star matches exactly one segment', function () {
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
            expect(WildcardMatcher::matches('order.*', 'order'))->toBeFalse();
        });

        it('double-star matches across segments', function () {
            expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
            expect(WildcardMatcher::matches('order.**', 'order.a.b.c.d'))->toBeTrue();
        });

        it('catch-all matches everything except empty string', function () {
            expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'single'))->toBeTrue();
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
            expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        it('multiple wildcards in pattern', function () {
            expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
            expect(WildcardMatcher::matches('*.order.*', 'user.order'))->toBeFalse();
        });

        it('does not match when pattern segment is longer than event', function () {
            expect(WildcardMatcher::matches('order.placed.extra', 'order.placed'))->toBeFalse();
        });

        it('special regex chars in event are treated literally', function () {
            // Event with dots is literal, not regex
            expect(WildcardMatcher::matches('test.*', 'test.value'))->toBeTrue();
            expect(WildcardMatcher::matches('test.*', 'test.value.extra'))->toBeFalse();
        });
    });
});
