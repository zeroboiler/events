<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;

it('can be created with type and payload', function (): void {
    $event = DomainEvent::occur('TestEvent', ['foo' => 'bar']);

    expect($event->eventType)->toBe('TestEvent');
    expect($event->payload)->toBe(['foo' => 'bar']);
});

it('generates unique event ID', function (): void {
    $event1 = DomainEvent::occur('Event1');
    $event2 = DomainEvent::occur('Event2');

    expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
});

it('records occurrence time', function (): void {
    $event = DomainEvent::occur('TestEvent');
    $now = new DateTimeImmutable;

    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
    expect($event->occurredAt->getTimestamp())->toBeLessThanOrEqual($now->getTimestamp());
});

it('can convert to array', function (): void {
    $event = DomainEvent::occur('TestEvent', ['foo' => 'bar']);
    $array = $event->toArray();

    expect($array['eventType'])->toBe('TestEvent');
    expect($array['payload'])->toBe(['foo' => 'bar']);
    expect($array['eventId'])->toBeString();
    expect($array['occurredAt'])->toBeString();
});

it('can be restored from array', function (): void {
    $original = DomainEvent::occur('TestEvent', ['foo' => 'bar']);
    $array = $original->toArray();

    $restored = DomainEvent::fromArray($array);

    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});
