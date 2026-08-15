<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;
use ZeroBoiler\Events\Domain\DomainEvent;

uses(TestCase::class);

describe('WildcardMatcher edge cases', function (): void {
    it('rejects empty event with non-catchall patterns', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.placed', ''))->toBeFalse();
    });

    it('handles single-character segment events', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('*', 'x'))->toBeTrue();
    });

    it('handles events with many segments', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('**', 'a.b.c.d.e.f.g'))->toBeTrue();
    });

    it('handles double-star at the end of pattern', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order.placed.extra.deep'))->toBeTrue();
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
    });

    it('handles double-star in the middle of pattern', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('a.**.c', 'a.x.y.z.c'))->toBeTrue();
    });

    it('extracts wildcards for multi-wildcard patterns', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('*.order.*', 'user.order.created');
        expect($result)->toBe(['user', 'created']);
    });

    it('returns empty array for extraction with ** pattern', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::extractWildcards('order.**', 'order.placed');
        expect($result)->toBe([]);
    });

    it('findMatchingPatterns returns matching patterns', function (): void {
        $patterns = ['order.placed', 'order.*', 'user.created', '*'];
        $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');
        expect($result)->toContain('order.placed');
        expect($result)->toContain('order.*');
        expect($result)->toContain('*');
        expect($result)->not->toContain('user.created');
    });
});

describe('DomainEvent', function (): void {
    it('creates a new event with generated UUID and timestamp', function (): void {
        $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

        expect($event->eventType)->toBe('user.registered');
        expect($event->payload)->toBe(['email' => 'test@example.com']);
        expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('preserves eventId and occurredAt on reconstruction', function (): void {
        $original = DomainEvent::occur('order.created', ['amount' => 100]);
        $array = $original->toArray();

        // Small sleep to ensure timestamps differ
        usleep(1000);

        $restored = DomainEvent::fromArray($array);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
    });

    it('throws on reconstruction with empty eventType', function (): void {
        DomainEvent::fromArray(['eventType' => '', 'payload' => []]);
    })->throws(\InvalidArgumentException::class, 'eventType is required');

    it('handles missing eventId gracefully on reconstruction', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => ['key' => 'value'],
        ]);

        expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
    });

    it('handles invalid eventId gracefully on reconstruction', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-uuid',
            'payload' => [],
        ]);

        expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
    });

    it('handles invalid occurredAt gracefully on reconstruction', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);

        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('serializes and deserializes correctly', function (): void {
        $event = DomainEvent::occur('payment.processed', [
            'amount' => 99.99,
            'currency' => 'USD',
        ]);

        $json = json_encode($event->toArray());
        $data = json_decode($json, true);

        $restored = DomainEvent::fromArray($data);

        expect($restored->eventType)->toBe('payment.processed');
        expect($restored->payload)->toBe([
            'amount' => 99.99,
            'currency' => 'USD',
        ]);
    });
});
