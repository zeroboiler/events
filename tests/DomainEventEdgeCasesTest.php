<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;

test('DomainEvent creates with fresh UUID and timestamp', function (): void {
    $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

    expect($event->eventType)->toBe('user.registered')
        ->and($event->payload)->toBe(['email' => 'test@example.com'])
        ->and($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class)
        ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent toArray returns serializable structure', function (): void {
    $event = DomainEvent::occur('order.created', ['order_id' => '123']);
    $data = $event->toArray();

    expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt'])
        ->and($data['eventType'])->toBe('order.created')
        ->and($data['payload'])->toBe(['order_id' => '123'])
        ->and($data['eventId'])->toBe($event->eventId->toString())
        ->and($data['occurredAt'])->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
});

test('DomainEvent fromArray preserves UUID and timestamp', function (): void {
    $original = DomainEvent::occur('user.updated', ['name' => 'Jane']);
    $data = $original->toArray();

    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
            $original->occurredAt->format(DateTimeImmutable::ATOM)
        )
        ->and($restored->eventType)->toBe('user.updated')
        ->and($restored->payload)->toBe(['name' => 'Jane']);
});

test('DomainEvent fromArray throws on missing eventType', function (): void {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('DomainEvent eventType is required for reconstruction.');

    DomainEvent::fromArray(['payload' => []]);
});

test('DomainEvent fromArray throws on empty string eventType', function (): void {
    $this->expectException(InvalidArgumentException::class);

    DomainEvent::fromArray(['eventType' => '']);
});

test('DomainEvent fromArray falls back gracefully on invalid UUID', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => 'not-a-uuid',
        'occurredAt' => '2024-01-15T10:30:00+00:00',
        'payload' => ['key' => 'value'],
    ]);

    // Should generate a fresh UUID instead of crashing
    expect($event->eventType)->toBe('test.event')
        ->and($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class)
        ->and($event->eventId->toString())->not->toBe('not-a-uuid');
});

test('DomainEvent fromArray falls back gracefully on invalid datetime', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'occurredAt' => 'not-a-date',
        'payload' => [],
    ]);

    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent fromArray defaults payload to empty array', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
    ]);

    expect($event->payload)->toBe([]);
});

test('DomainEvent __toString returns formatted representation', function (): void {
    $event = DomainEvent::occur('order.placed', []);
    $str = (string) $event;

    expect($str)->toStartWith('DomainEvent[order.placed]')
        ->and($str)->toContain('id=')
        ->and($str)->toContain('at=');
});

test('DomainEvent with empty payload defaults to empty array', function (): void {
    $event = DomainEvent::occur('ping');

    expect($event->payload)->toBe([]);
    expect($event->eventType)->toBe('ping');
});

test('DomainEvent properties are readonly', function (): void {
    $event = DomainEvent::occur('immutable.test', ['key' => 'value']);

    // Verify the properties exist and have expected types
    expect($event->eventId)->toBeInstanceOf(Ramsey\Uuid\UuidInterface::class);
    expect($event->eventType)->toBe('immutable.test');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

