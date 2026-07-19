<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Domain\DomainEventDispatcher;
use ZeroBoiler\Events\Domain\Exceptions\ListenerException;

beforeEach(function (): void {
    $this->dispatcher = new DomainEventDispatcher;
});

afterEach(function (): void {
    // Ensure complete test isolation — no listener leakage between tests.
    $this->dispatcher->flush();
});

it('can subscribe to events', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($called)->toBeTrue();
});

it('calls all listeners for an event', function (): void {
    $count = 0;

    $this->dispatcher->subscribe('TestEvent', function () use (&$count): void {
        $count++;
    });

    $this->dispatcher->subscribe('TestEvent', function () use (&$count): void {
        $count++;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));

    expect($count)->toBe(2);
});

it('does not call listeners for different event types', function (): void {
    $called = false;

    $this->dispatcher->subscribe('EventA', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->dispatch(DomainEvent::occur('EventB'));

    expect($called)->toBeFalse();
});

it('can defer events', function (): void {
    $called = false;

    $this->dispatcher->subscribe('TestEvent', function () use (&$called): void {
        $called = true;
    });

    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    expect($called)->toBeFalse();
});

it('can release deferred events', function (): void {
    $count = 0;

    $this->dispatcher->subscribe('TestEvent', function () use (&$count): void {
        $count++;
    });

    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->releaseDeferred();

    expect($count)->toBe(2);
});

it('clears deferred events after release', function (): void {
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->releaseDeferred();

    expect($this->dispatcher->hasDeferredEvents())->toBeFalse();
});

it('can clear deferred events manually', function (): void {
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->clearDeferred();

    expect($this->dispatcher->hasDeferredEvents())->toBeFalse();
});

it('reports deferred events count', function (): void {
    expect($this->dispatcher->getDeferredEventsCount())->toBe(0);

    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    expect($this->dispatcher->getDeferredEventsCount())->toBe(2);
});

it('forwards events to an external forwarder when set', function (): void {
    $forwarded = [];

    $this->dispatcher->setEventForwarder(
        function (string $eventType, array $payload) use (&$forwarded): void {
            $forwarded[] = ['type' => $eventType, 'payload' => $payload];
        }
    );

    $this->dispatcher->dispatch(DomainEvent::occur('OrderPlaced', ['id' => 42]));

    expect($forwarded)->toHaveCount(1)
        ->and($forwarded[0]['type'])->toBe('OrderPlaced')
        ->and($forwarded[0]['payload'])->toBe(['id' => 42]);
});

it('can clear all listeners', function (): void {
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventB', fn () => true);

    expect($this->dispatcher->getListenerCount())->toBe(2);

    $this->dispatcher->clearListeners();

    expect($this->dispatcher->getListenerCount())->toBe(0)
        ->and($this->dispatcher->hasListeners('EventA'))->toBeFalse()
        ->and($this->dispatcher->hasListeners('EventB'))->toBeFalse();
});

it('can clear listeners for a specific event type only', function (): void {
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventA', fn () => true);
    $this->dispatcher->subscribe('EventB', fn () => true);

    $this->dispatcher->clearListeners('EventA');

    expect($this->dispatcher->hasListeners('EventA'))->toBeFalse()
        ->and($this->dispatcher->hasListeners('EventB'))->toBeTrue()
        ->and($this->dispatcher->getListenerCount())->toBe(1);
});

it('can flush all state completely', function (): void {
    $this->dispatcher->subscribe('TestEvent', fn () => true);
    $this->dispatcher->setEventForwarder(fn () => true);
    $this->dispatcher->defer(DomainEvent::occur('TestEvent'));

    $this->dispatcher->flush();

    expect($this->dispatcher->getListenerCount())->toBe(0)
        ->and($this->dispatcher->hasListeners('TestEvent'))->toBeFalse()
        ->and($this->dispatcher->hasDeferredEvents())->toBeFalse();
});

it('continues calling remaining listeners when one throws', function (): void {
    $results = [];

    $this->dispatcher->subscribe('TestEvent', function () use (&$results): void {
        $results[] = 'first';
    });

    $this->dispatcher->subscribe('TestEvent', function () use (&$results): void {
        $results[] = 'throwing';
        throw new RuntimeException('listener failed');
    });

    $this->dispatcher->subscribe('TestEvent', function () use (&$results): void {
        $results[] = 'third';
    });

    try {
        $this->dispatcher->dispatch(DomainEvent::occur('TestEvent'));
    } catch (ListenerException) {
        // Expected
    }

    expect($results)->toBe(['first', 'throwing', 'third']);
});

it('dispatchQuietly swallows listener exceptions', function (): void {
    $secondCalled = false;

    $this->dispatcher->subscribe('TestEvent', function (): void {
        throw new RuntimeException('fail');
    });
    $this->dispatcher->subscribe('TestEvent', function () use (&$secondCalled): void {
        $secondCalled = true;
    });

    $this->dispatcher->dispatchQuietly(DomainEvent::occur('TestEvent'));

    expect($secondCalled)->toBeTrue();
});
