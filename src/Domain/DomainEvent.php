<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Immutable domain event value object for event sourcing patterns.
 *
 * Each domain event carries a unique identifier, timestamp, event type,
 * and arbitrary payload data. Events can be serialized to arrays and
 * reconstructed from persisted data for event replay.
 */
final class DomainEvent
{
    public readonly UuidInterface $eventId;

    public readonly DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload = [],
        ?UuidInterface $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? Uuid::uuid4();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function occur(string $eventType, array $payload = []): self
    {
        return new self($eventType, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventId->toString(),
            'eventType' => $this->eventType,
            'payload' => $this->payload,
            'occurredAt' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Reconstruct an event from persisted data, preserving the original
     * eventId and occurredAt to prevent information loss during event replay.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $eventType = isset($data['eventType']) && is_string($data['eventType'])
            ? $data['eventType']
            : '';

        if ($eventType === '') {
            throw new \InvalidArgumentException('DomainEvent eventType is required for reconstruction.');
        }

        $payload = isset($data['payload']) && is_array($data['payload'])
            ? $data['payload']
            : [];

        $eventId = null;
        if (isset($data['eventId']) && is_string($data['eventId'])) {
            try {
                $eventId = Uuid::fromString($data['eventId']);
            } catch (\InvalidArgumentException) {
                // Invalid UUID — generate a fresh one (default)
            }
        }

        $occurredAt = null;
        if (isset($data['occurredAt']) && is_string($data['occurredAt'])) {
            try {
                $occurredAt = new DateTimeImmutable($data['occurredAt']);
            } catch (\Exception) {
                // Invalid datetime — use default (now)
            }
        }

        return new self(
            $eventType,
            $payload,
            $eventId,
            $occurredAt,
        );
    }
}
