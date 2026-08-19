<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEvent constructor validation', function (): void {
    test('occur creates event with fresh UUID and current timestamp', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::occur('test.event', ['key' => 'value']);
        $after = new \DateTimeImmutable();

        expect($event->eventType)->toBe('test.event');
        expect($event->payload)->toBe(['key' => 'value']);
        expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        expect($event->occurredAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp());
        expect($event->occurredAt->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
    });

    test('constructor accepts pre-existing UUID and timestamp for replay', function (): void {
        $uuid = \Ramsey\Uuid\Uuid::uuid4();
        $time = new \DateTimeImmutable('2024-06-15T10:30:00+00:00');

        $event = new DomainEvent('order.created', ['id' => 1], $uuid, $time);

        expect($event->eventId->toString())->toBe($uuid->toString());
        expect($event->occurredAt)->toBe($time);
        expect($event->eventType)->toBe('order.created');
    });

    test('fromArray preserves eventId and occurredAt', function (): void {
        $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
        $data = $original->toArray();

        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString());
        expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
        expect($restored->eventType)->toBe($original->eventType);
        expect($restored->payload)->toBe($original->payload);
    });

    test('fromArray throws on missing eventType', function (): void {
        expect(fn (): mixed => DomainEvent::fromArray(['payload' => []]))
            ->toThrow(\InvalidArgumentException::class, 'eventType is required');
    });

    test('fromArray generates fresh UUID for invalid UUID string', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'eventId' => 'not-a-uuid',
        ]);

        // Should not throw — invalid UUID falls back to a fresh one
        expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        expect($event->eventType)->toBe('test.event');
    });

    test('fromArray falls back to now for invalid datetime', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'occurredAt' => 'not-a-date',
        ]);
        $after = new \DateTimeImmutable();

        expect($event->occurredAt->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp());
        expect($event->occurredAt->getTimestamp())->toBeLessThanOrEqual($after->getTimestamp());
    });

    test('fromArray handles missing optional fields gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'minimal.event',
        ]);

        expect($event->eventType)->toBe('minimal.event');
        expect($event->payload)->toBe([]);
        expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    test('toArray contains all required keys', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'val']);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
        expect(is_string($data['eventId']))->toBeTrue();
        expect(is_string($data['occurredAt']))->toBeTrue();
    });

    test('__toString returns expected format', function (): void {
        $event = DomainEvent::occur('order.placed', []);
        $str = (string) $event;

        expect($str)->toMatch('/^DomainEvent\[order\.placed\] id=[\w-]+ at=/');
    });

    test('readonly properties prevent mutation', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);

        $ref = new \ReflectionClass($event);

        expect($ref->getProperty('eventId')->isReadOnly())->toBeTrue();
        expect($ref->getProperty('eventType')->isReadOnly())->toBeTrue();
        expect($ref->getProperty('payload')->isReadOnly())->toBeTrue();
        expect($ref->getProperty('occurredAt')->isReadOnly())->toBeTrue();
    });

    test('class is final', function (): void {
        $ref = new \ReflectionClass(DomainEvent::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('class is readonly', function (): void {
        // In PHP 8.5, readonly classes can be checked via attributes or the language
        // The class has readonly properties — verifying via reflection
        $ref = new \ReflectionClass(DomainEvent::class);

        foreach ($ref->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            expect($prop->isReadOnly())->toBeTrue("Property \${$prop->getName()} should be readonly");
        }
    });
});
