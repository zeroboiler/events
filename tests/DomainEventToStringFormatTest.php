<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Domain\DomainEvent;

describe('DomainEvent::__toString format', function () {
    it('contains the event type in brackets', function () {
        $event = DomainEvent::occur('order.placed', ['id' => 1]);
        $str = (string) $event;
        expect($str)->toContain('DomainEvent[order.placed]');
    });

    it('contains the event ID', function () {
        $event = DomainEvent::occur('user.created');
        $str = (string) $event;
        expect($str)->toContain('id=' . $event->eventId->toString());
    });

    it('contains the timestamp with at= prefix', function () {
        $event = DomainEvent::occur('test.event');
        $str = (string) $event;
        expect($str)->toContain('at=');
        // ISO 8601 timestamps contain 'T'
        expect($str)->toContain('T');
    });

    it('uses ISO 8601 ATOM format for timestamp', function () {
        $event = DomainEvent::occur('test.event');
        $str = (string) $event;
        // Verify the format matches the ATOM format (e.g. 2024-01-15T10:30:00+00:00)
        expect($str)->toMatch('/at=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/');
    });

    it('has the correct structure: DomainEvent[type] id=uuid at=timestamp', function () {
        $event = DomainEvent::occur('payment.received');
        $str = (string) $event;
        // Full pattern match
        $pattern = '/^DomainEvent\[payment\.received\] id=[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12} at=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';
        expect($str)->toMatch($pattern);
    });

    it('preserves original timestamp in string after fromArray reconstruction', function () {
        $original = DomainEvent::occur('order.placed', ['total' => 99.99]);
        $data = $original->toArray();
        $reconstructed = DomainEvent::fromArray($data);

        // Both should produce identical string representations
        expect((string) $original)->toBe((string) $reconstructed);
    });
});
