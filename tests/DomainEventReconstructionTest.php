<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Ramsey\Uuid\Uuid;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Tests for DomainEvent::fromArray() reconstruction behavior.
 *
 * Verifies that eventId and occurredAt are preserved during
 * reconstruction, and that invalid data falls back to fresh values.
 *
 * @see \ZeroBoiler\Events\Domain\DomainEvent::fromArray()
 *
 * @since 1.0.0
 */
final class DomainEventReconstructionTest extends TestCase
{
    public function test_from_array_preserves_event_id_and_timestamp(): void
    {
        $original = DomainEvent::occur('order.placed', ['order_id' => '123']);

        $data = $original->toArray();

        $reconstructed = DomainEvent::fromArray($data);

        // UUID and timestamp must be preserved
        expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
        expect($reconstructed->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
        expect($reconstructed->eventType)->toBe('order.placed');
        expect($reconstructed->payload)->toBe(['order_id' => '123']);
    }

    public function test_from_array_with_invalid_uuid_generates_new(): void
    {
        $data = [
            'eventId' => 'not-a-valid-uuid',
            'eventType' => 'test.event',
            'payload' => [],
            'occurredAt' => '2024-01-15T10:30:00+00:00',
        ];

        $event = DomainEvent::fromArray($data);

        expect($event->eventType)->toBe('test.event');
        // Should get a fresh UUID (not the invalid one)
        expect($event->eventId->toString())->not->toBe('not-a-valid-uuid');
    }

    public function test_from_array_with_invalid_date_generates_new(): void
    {
        $originalId = Uuid::uuid4()->toString();

        $data = [
            'eventId' => $originalId,
            'eventType' => 'test.event',
            'payload' => [],
            'occurredAt' => 'not-a-date',
        ];

        $event = DomainEvent::fromArray($data);

        expect($event->eventType)->toBe('test.event');
        expect($event->eventId->toString())->toBe($originalId);
        // Timestamp should be now() fallback
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
    }

    public function test_from_array_with_missing_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('eventType is required');

        DomainEvent::fromArray([
            'eventId' => Uuid::uuid4()->toString(),
            'payload' => [],
        ]);
    }

    public function test_from_array_with_empty_event_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DomainEvent::fromArray([
            'eventId' => Uuid::uuid4()->toString(),
            'eventType' => '',
            'payload' => [],
        ]);
    }

    public function test_from_array_with_missing_payload_defaults_to_empty(): void
    {
        $data = [
            'eventId' => Uuid::uuid4()->toString(),
            'eventType' => 'test.event',
        ];

        $event = DomainEvent::fromArray($data);

        expect($event->payload)->toBe([]);
    }

    public function test_from_array_with_non_array_payload_defaults_to_empty(): void
    {
        $data = [
            'eventId' => Uuid::uuid4()->toString(),
            'eventType' => 'test.event',
            'payload' => 'not-array',
        ];

        $event = DomainEvent::fromArray($data);

        expect($event->payload)->toBe([]);
    }

    public function test_to_string_format(): void
    {
        $event = DomainEvent::occur('order.placed', ['id' => 42]);

        $string = (string) $event;

        expect($string)->toContain('DomainEvent[order.placed]');
        expect($string)->toContain('id=');
        expect($string)->toContain('at=');
    }

    public function test_occur_creates_fresh_uuid_and_timestamp(): void
    {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);

        expect($event->eventId)->not->toBeNull();
        expect($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
        expect($event->eventType)->toBe('test.event');
        expect($event->payload)->toBe(['key' => 'value']);
    }
}
