<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEvent event sourcing', function (): void {
    test('occur factory creates fresh UUID and timestamp', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::occur('user.created', ['id' => 42]);
        $after = new \DateTimeImmutable();

        expect($event->eventType)->toBe('user.created')
            ->and($event->payload)->toBe(['id' => 42])
            ->and($event->eventId->toString())->toBeString()
            ->and($event->occurredAt)->toBeGreaterThanOrEqual($before)
            ->and($event->occurredAt)->toBeLessThanOrEqual($after);
    });

    test('two events have different UUIDs', function (): void {
        $a = DomainEvent::occur('test', []);
        $b = DomainEvent::occur('test', []);

        expect($a->eventId->toString())->not->toBe($b->eventId->toString());
    });

    test('toArray contains all expected keys', function (): void {
        $event = DomainEvent::occur('order.placed', ['total' => 99.99]);
        $data = $event->toArray();

        expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt'])
            ->and($data['eventId'])->toBe($event->eventId->toString())
            ->and($data['eventType'])->toBe('order.placed')
            ->and($data['payload'])->toBe(['total' => 99.99]);
    });

    test('fromArray preserves original eventId and occurredAt', function (): void {
        $original = DomainEvent::occur('payment.received', ['amount' => 150]);
        $data = $original->toArray();

        $restored = DomainEvent::fromArray($data);

        expect($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp())
            ->and($restored->eventType)->toBe('payment.received')
            ->and($restored->payload)->toBe(['amount' => 150]);
    });

    test('fromArray generates fresh UUID when eventId is invalid', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => [],
            'eventId' => 'not-a-uuid',
        ]);

        expect($event->eventId->toString())->toBeString()
            ->and(Uuid::isValid($event->eventId->toString()))->toBeTrue();
    });

    test('fromArray generates fresh timestamp when occurredAt is invalid', function (): void {
        $before = new \DateTimeImmutable();
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
            'payload' => [],
            'occurredAt' => 'not-a-date',
        ]);

        expect($event->occurredAt)->toBeGreaterThanOrEqual($before);
    });

    test('fromArray handles missing eventType gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'payload' => ['key' => 'val'],
        ]);

        expect($event->eventType)->toBe('');
    });

    test('fromArray handles missing payload gracefully', function (): void {
        $event = DomainEvent::fromArray([
            'eventType' => 'test.event',
        ]);

        expect($event->payload)->toBe([]);
    });

    test('fromArray handles empty data gracefully', function (): void {
        $event = DomainEvent::fromArray([]);

        expect($event->eventType)->toBe('')
            ->and($event->payload)->toBe([])
            ->and($event->eventId->toString())->toBeString()
            ->and($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    });

    test('properties are readonly and immutable', function (): void {
        $event = DomainEvent::occur('test', ['key' => 'value']);

        $reflection = new \ReflectionClass($event);
        $props = $reflection->getProperties();

        foreach ($props as $prop) {
            $attrs = $prop->getAttributes(\Readonly::class);
            expect($attrs)->not->toBeEmpty("Property {$prop->getName()} must have #[Readonly]");
        }
    });
});
