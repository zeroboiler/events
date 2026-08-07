<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Events\Domain\DomainEvent;

test('DomainEvent has all promoted properties readonly', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $reflection = new ReflectionClass($event);

    // All promoted constructor properties must use the readonly keyword modifier
    // (NOT the #[\Readonly] attribute, which was removed in PHP 8.5).
    $readonlyProps = ['eventType', 'payload', 'eventId', 'occurredAt'];
    foreach ($readonlyProps as $prop) {
        $property = $reflection->getProperty($prop);
        $hasReadonlyModifier = $property->isReadOnly();

        expect($hasReadonlyModifier)->toBeTrue("Property \${$prop} must have readonly modifier");
    }
});

test('DomainEvent identity is based on eventId', function (): void {
    $uuid = Uuid::uuid4();
    $now = new DateTimeImmutable('2025-01-01 12:00:00');

    $event = new DomainEvent('order.placed', ['id' => 42], $uuid, $now);

    expect($event->eventId->toString())->toBe($uuid->toString())
        ->and($event->eventType)->toBe('order.placed')
        ->and($event->payload)->toBe(['id' => 42])
        ->and($event->occurredAt)->toEqual($now);
});

test('DomainEvent::occur factory creates fresh UUID and timestamp', function (): void {
    $before = new DateTimeImmutable();
    $event = DomainEvent::occur('user.created', ['email' => 'test@example.com']);
    $after = new DateTimeImmutable();

    expect($event->eventId)->not->toBeNull()
        ->and($event->eventType)->toBe('user.created')
        ->and($event->payload)->toBe(['email' => 'test@example.com'])
        ->and($event->occurredAt->greaterThanOrEqual($before))->toBeTrue()
        ->and($event->occurredAt->lessThanOrEqual($after))->toBeTrue();
});

test('DomainEvent::toArray serialization preserves all fields', function (): void {
    $uuid = Uuid::uuid4();
    $now = new DateTimeImmutable('2025-06-15T10:30:00+00:00');
    $event = new DomainEvent('payment.completed', ['amount' => 99.99], $uuid, $now);

    $data = $event->toArray();

    expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt'])
        ->and($data['eventId'])->toBe($uuid->toString())
        ->and($data['eventType'])->toBe('payment.completed')
        ->and($data['payload'])->toBe(['amount' => 99.99])
        ->and($data['occurredAt'])->toBe($now->format(DateTimeImmutable::ATOM));
});

test('DomainEvent::fromArray reconstruction preserves eventId and occurredAt', function (): void {
    $uuid = Uuid::uuid4();
    $now = new DateTimeImmutable('2025-03-20T08:00:00+00:00');
    $original = new DomainEvent('order.shipped', ['tracking' => 'ABC123'], $uuid, $now);

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($uuid->toString())
        ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe($now->format(DateTimeImmutable::ATOM))
        ->and($restored->eventType)->toBe('order.shipped')
        ->and($restored->payload)->toBe(['tracking' => 'ABC123']);
});

test('DomainEvent::fromArray handles invalid UUID gracefully', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'payload' => [],
        'eventId' => 'not-a-valid-uuid',
        'occurredAt' => '2025-01-01T00:00:00+00:00',
    ]);

    // Should generate a fresh UUID, not crash
    expect($event->eventId)->not->toBeNull()
        ->and($event->eventType)->toBe('test.event');
});

test('DomainEvent::fromArray handles invalid datetime gracefully', function (): void {
    $uuid = Uuid::uuid4();
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
        'eventId' => $uuid->toString(),
        'occurredAt' => 'not-a-valid-date',
    ]);

    // Should use current time, not crash
    expect($event->eventId->toString())->toBe($uuid->toString())
        ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent::fromArray handles missing eventType', function (): void {
    $event = DomainEvent::fromArray([
        'payload' => ['key' => 'value'],
    ]);

    expect($event->eventType)->toBe('')
        ->and($event->payload)->toBe(['key' => 'value']);
});

test('DomainEvent::fromArray handles missing payload', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'test.event',
    ]);

    expect($event->payload)->toBe([]);
});

test('DomainEvent::fromArray handles completely empty data', function (): void {
    $event = DomainEvent::fromArray([]);

    expect($event->eventType)->toBe('')
        ->and($event->payload)->toBe([])
        ->and($event->eventId)->not->toBeNull()
        ->and($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent roundtrip toArray → fromArray is lossless', function (): void {
    $original = DomainEvent::occur('invoice.paid', [
        'invoice_id' => 'INV-001',
        'amount' => 250.00,
        'currency' => 'EUR',
        'items' => ['item1', 'item2'],
    ]);

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->occurredAt->format(DateTimeImmutable::ATOM))
            ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM))
        ->and($restored->eventType)->toBe($original->eventType)
        ->and($restored->payload)->toBe($original->payload);
});

test('DomainEvent with explicit constructor args overrides defaults', function (): void {
    $uuid = Uuid::uuid4();
    $now = new DateTimeImmutable('2024-12-31T23:59:59+00:00');

    $event = new DomainEvent(
        eventType: 'custom.event',
        payload: ['nested' => ['deep' => true]],
        eventId: $uuid,
        occurredAt: $now,
    );

    expect($event->eventType)->toBe('custom.event')
        ->and($event->payload)->toBe(['nested' => ['deep' => true]])
        ->and($event->eventId->toString())->toBe($uuid->toString())
        ->and($event->occurredAt->format('Y-m-d H:i:s'))->toBe('2024-12-31 23:59:59');
});
