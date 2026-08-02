<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Events\Domain\DomainEvent;

it('creates a domain event with default values', function (): void {
    $event = new DomainEvent('user.created', ['id' => 42]);

    expect($event->eventType)->toBe('user.created')
        ->and($event->payload)->toBe(['id' => 42])
        ->and($event->eventId)->not->toBeNull()
        ->and($event->occurredAt)->not->toBeNull();
});

it('generates a unique UUID v4 for each event', function (): void {
    $event1 = new DomainEvent('a');
    $event2 = new DomainEvent('b');

    expect($event1->eventId->toString())
        ->not->toBe($event2->eventId->toString())
        ->and($event1->eventId->getVersion())->toBe(4);
});

it('accepts custom eventId and occurredAt', function (): void {
    $uuid = Uuid::uuid4();
    $timestamp = new DateTimeImmutable('2025-01-15T10:30:00Z');

    $event = new DomainEvent('order.placed', ['total' => 99.99], $uuid, $timestamp);

    expect($event->eventId)->toBe($uuid)
        ->and($event->occurredAt)->toBe($timestamp);
});

it('creates event via occur() static factory', function (): void {
    $event = DomainEvent::occur('item.added', ['sku' => 'ABC123']);

    expect($event)->toBeInstanceOf(DomainEvent::class)
        ->and($event->eventType)->toBe('item.added')
        ->and($event->payload)->toBe(['sku' => 'ABC123']);
});

it('creates event via occur() with empty payload', function (): void {
    $event = DomainEvent::occur('noop');

    expect($event->payload)->toBe([]);
});

it('serializes to array correctly', function (): void {
    $uuid = Uuid::uuid4();
    $timestamp = new DateTimeImmutable('2025-06-15T12:00:00+00:00');

    $event = new DomainEvent('user.registered', ['email' => 'test@example.com'], $uuid, $timestamp);

    $array = $event->toArray();

    expect($array)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt'])
        ->and($array['eventId'])->toBe($uuid->toString())
        ->and($array['eventType'])->toBe('user.registered')
        ->and($array['payload'])->toBe(['email' => 'test@example.com'])
        ->and($array['occurredAt'])->toBe($timestamp->format(DateTimeImmutable::ATOM));
});

it('reconstructs from array with full data', function (): void {
    $original = DomainEvent::occur('payment.processed', ['amount' => 150.00]);

    $data = $original->toArray();
    $reconstructed = DomainEvent::fromArray($data);

    expect($reconstructed->eventType)->toBe($original->eventType)
        ->and($reconstructed->payload)->toBe($original->payload)
        ->and($reconstructed->eventId->toString())->toBe($original->eventId->toString())
        ->and($reconstructed->occurredAt->format(DateTimeImmutable::ATOM))->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
});

it('reconstructs from array without eventId', function (): void {
    $data = [
        'eventType' => 'manual.event',
        'payload' => ['key' => 'value'],
        'occurredAt' => '2025-03-10T08:00:00+00:00',
    ];

    $event = DomainEvent::fromArray($data);

    expect($event->eventType)->toBe('manual.event')
        ->and($event->payload)->toBe(['key' => 'value'])
        ->and($event->eventId)->not->toBeNull()
        ->and($event->occurredAt->format(DateTimeImmutable::ATOM))->toBe('2025-03-10T08:00:00+00:00');
});

it('reconstructs from array without occurredAt', function (): void {
    $data = [
        'eventType' => 'missing.timestamp',
        'payload' => [],
        'eventId' => Uuid::uuid4()->toString(),
    ];

    $event = DomainEvent::fromArray($data);

    expect($event->eventType)->toBe('missing.timestamp')
        ->and($event->occurredAt)->not->toBeNull();
});

it('reconstructs from array with only required fields', function (): void {
    $event = DomainEvent::fromArray([
        'eventType' => 'minimal',
    ]);

    expect($event->eventType)->toBe('minimal')
        ->and($event->payload)->toBe([])
        ->and($event->eventId)->not->toBeNull()
        ->and($event->occurredAt)->not->toBeNull();
});

it('round-trips through toArray and fromArray preserving identity', function (): void {
    $original = new DomainEvent(
        'lifecycle.changed',
        ['old' => 'draft', 'new' => 'published'],
        Uuid::uuid4(),
        new DateTimeImmutable('2024-12-25T15:45:30+00:00'),
    );

    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString())
        ->and($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
});

it('handles complex nested payload', function (): void {
    $payload = [
        'user' => [
            'id' => 1,
            'roles' => ['admin', 'editor'],
        ],
        'meta' => [
            'source' => 'api',
            'version' => 2,
        ],
    ];

    $event = DomainEvent::occur('complex.event', $payload);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->payload)->toBe($payload);
});
