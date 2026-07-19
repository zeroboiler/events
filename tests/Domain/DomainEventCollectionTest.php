<?php

declare(strict_types=1);

use ZeroBoiler\Events\Domain\Collections\DomainEventCollection;
use ZeroBoiler\Events\Domain\DomainEvent;

beforeEach(function (): void {
    $this->event1 = DomainEvent::occur('TestEvent', ['id' => 'test-id-1']);
    $this->event2 = DomainEvent::occur('TestEvent', ['id' => 'test-id-2']);
});

test('can create empty collection', function (): void {
    $collection = new DomainEventCollection;

    expect($collection->isEmpty())->toBeTrue();
    expect($collection->count())->toBe(0);
});

test('can add events', function (): void {
    $collection = new DomainEventCollection;

    $collection->add($this->event1);
    $collection->add($this->event2);

    expect($collection->isEmpty())->toBeFalse();
    expect($collection->count())->toBe(2);
});

test('can create from array', function (): void {
    $events = [$this->event1, $this->event2];
    $collection = DomainEventCollection::fromArray($events);

    expect($collection->count())->toBe(2);
});

test('can iterate events', function (): void {
    $collection = DomainEventCollection::fromArray([$this->event1, $this->event2]);

    $iterated = [];
    foreach ($collection as $event) {
        $iterated[] = $event;
    }

    expect($iterated)->toHaveCount(2);
});

test('can convert to array', function (): void {
    $events = [$this->event1, $this->event2];
    $collection = DomainEventCollection::fromArray($events);

    expect($collection->toArray())->toBe($events);
});

test('can get first event', function (): void {
    $collection = DomainEventCollection::fromArray([$this->event1, $this->event2]);

    expect($collection->first())->toBe($this->event1);
});

test('can get last event', function (): void {
    $collection = DomainEventCollection::fromArray([$this->event1, $this->event2]);

    expect($collection->last())->toBe($this->event2);
});

test('returns null for first on empty', function (): void {
    $collection = new DomainEventCollection;

    expect($collection->first())->toBeNull();
});

test('returns null for last on empty', function (): void {
    $collection = new DomainEventCollection;

    expect($collection->last())->toBeNull();
});

test('can filter events', function (): void {
    $event3 = DomainEvent::occur('TestEvent', ['id' => 'test-id-3']);
    $collection = DomainEventCollection::fromArray([$this->event1, $this->event2, $event3]);

    $filtered = $collection->filter(fn ($event): bool => $event->payload['id'] === 'test-id-1');

    expect($filtered->count())->toBe(1);
    expect($filtered->first()->payload['id'])->toBe('test-id-1');
});

test('can map events', function (): void {
    $collection = DomainEventCollection::fromArray([$this->event1, $this->event2]);

    $mapped = $collection->map(fn ($event) => $event->payload['id']);

    expect($mapped)->toBe(['test-id-1', 'test-id-2']);
});

test('can merge collections', function (): void {
    $collection1 = DomainEventCollection::fromArray([$this->event1]);
    $collection2 = DomainEventCollection::fromArray([$this->event2]);

    $merged = $collection1->merge($collection2);

    expect($merged->count())->toBe(2);
});

test('fromArray throws InvalidArgumentException for non-DomainEvent items', function (): void {
    DomainEventCollection::fromArray(['not-an-event', 42, null]);
})->throws(InvalidArgumentException::class, 'All events must implement');

test('fromArray accepts empty array', function (): void {
    $collection = DomainEventCollection::fromArray([]);

    expect($collection->isEmpty())->toBeTrue();
    expect($collection->count())->toBe(0);
});
