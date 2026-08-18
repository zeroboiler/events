<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEvent __toString', function (): void {
    test('returns formatted string with event type, id, and timestamp', function (): void {
        $event = new DomainEvent('order.placed', ['order_id' => 123]);

        $string = (string) $event;

        expect($string)->toBeString();
        expect($string)->toContain('DomainEvent[order.placed]');
        expect($string)->toContain('id=');
        expect($string)->toContain('at=');
    });

    test('includes full UUID in string representation', function (): void {
        $event = new DomainEvent('user.created');
        $string = (string) $event;

        // UUIDs are 36 characters (8-4-4-4-12 format)
        expect($event->eventId->toString())->toHaveLength(36);
        expect($string)->toContain($event->eventId->toString());
    });

    test('includes ISO 8601 timestamp', function (): void {
        $event = new DomainEvent('payment.processed');
        $string = (string) $event;

        // ISO 8601 dates contain 'T' separator and timezone offset
        expect($string)->toContain('T');
    });

    test('preserves original eventId and timestamp when reconstructed', function (): void {
        $original = new DomainEvent('item.updated', ['price' => 29.99]);
        $array = $original->toArray();
        $reconstructed = DomainEvent::fromArray($array);

        $originalString = (string) $original;
        $reconstructedString = (string) $reconstructed;

        expect($originalString)->toBe($reconstructedString);
    });

    test('works with empty payload', function (): void {
        $event = new DomainEvent('system.ping');
        $string = (string) $event;

        expect($string)->toBeString();
        expect($string)->toContain('system.ping');
    });
});
