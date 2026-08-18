<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEvent::fromArray edge cases', function (): void {
    it('generates a fresh UUID when eventId is an invalid string', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-valid-uuid',
        ]);

        // Should not throw — silently generates a new UUID
        expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');

        // Should be a valid UUID v4
        expect(Uuid::isValid($event->eventId->toString()))->toBeTrue();
    });

    it('generates a fresh timestamp when occurredAt is an invalid string', function (): void {
        $before = new \DateTimeImmutable();

        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-valid-datetime',
        ]);

        // Should not throw — silently defaults to now()
        expect($event->occurredAt)->not->toBeNull();
        $after = new \DateTimeImmutable();

        expect($event->occurredAt >= $before)->toBeTrue();
        expect($event->occurredAt <= $after)->toBeTrue();
    });

    it('generates a fresh UUID when eventId is not a string', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 12345,
        ]);

        expect(Uuid::isValid($event->eventId->toString()))->toBeTrue();
    });

    it('generates a fresh timestamp when occurredAt is not a string', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => ['invalid' => 'type'],
        ]);

        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    it('throws when eventType is empty', function (): void {
        DomainEvent::fromArray([]);
    })->throws(\InvalidArgumentException::class, 'eventType is required');

    it('throws when eventType is a non-string', function (): void {
        DomainEvent::fromArray(['eventType' => 123]);
    })->throws(\InvalidArgumentException::class, 'eventType is required');

    it('throws when eventType is an empty string', function (): void {
        DomainEvent::fromArray(['eventType' => '']);
    })->throws(\InvalidArgumentException::class, 'eventType is required');

    it('preserves valid UUID and timestamp when both are provided correctly', function (): void {
        $originalUuid = Uuid::uuid4();
        $originalTime = new \DateTimeImmutable('2024-06-15T12:00:00+00:00');

        $event = DomainEvent::fromArray([
            'eventType' => 'preserved.event',
            'eventId' => $originalUuid->toString(),
            'occurredAt' => $originalTime->format(\DateTimeImmutable::ATOM),
        ]);

        expect($event->eventId->toString())->toBe($originalUuid->toString());
        expect($event->occurredAt->getTimestamp())->toBe($originalTime->getTimestamp());
    });

    it('handles empty payload gracefully (defaults to empty array)', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'no.payload',
        ]);

        expect($event->payload)->toBe([]);
    });

    it('handles non-array payload gracefully (defaults to empty array)', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'bad.payload',
            'payload' => 'not-an-array',
        ]);

        expect($event->payload)->toBe([]);
    });
});
