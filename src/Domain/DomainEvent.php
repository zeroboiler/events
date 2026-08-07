<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class DomainEvent
{
    #[\Readonly]
    public UuidInterface $eventId;

    #[\Readonly]
    public DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        #[\Readonly] public string $eventType,
        #[\Readonly] public array $payload = [],
        ?UuidInterface $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): void {
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
