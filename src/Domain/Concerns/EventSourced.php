<?php

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain\Concerns;

use RuntimeException;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * Provides event sourcing capabilities to aggregate roots.
 *
 * Reconstitutes aggregate state by replaying domain events.
 * The aggregate ID is extracted from the first event's payload.
 */
trait EventSourced
{
    /**
     * Reconstitute an aggregate from its event history.
     *
     * The aggregate ID is extracted from the first event's payload
     * (using the 'id' or 'aggregate_id' key) and passed to the constructor.
     *
     * @param  DomainEvent  ...$events  The events to replay.
     * @param  bool  $lenient  When true, events without an apply* handler are silently skipped.
     * @return static The reconstituted aggregate.
     *
     * @throws RuntimeException If no events are provided or the ID cannot be extracted.
     */
    public static function fromHistory(DomainEvent ...$events): static
    {
        if ($events === []) {
            throw new RuntimeException(
                sprintf('Cannot reconstitute %s from empty event history.', static::class)
            );
        }

        // Extract the aggregate ID from the first event's payload
        $firstEvent = $events[0];
        $idValue = $firstEvent->payload['id']
            ?? $firstEvent->payload['aggregate_id']
            ?? throw new RuntimeException(
                sprintf(
                    'Cannot reconstitute %s: first event must contain an "id" or "aggregate_id" in its payload.',
                    static::class
                )
            );

        // Construct the aggregate properly through its constructor
        $aggregateId = is_string($idValue)
            ? \ZeroBoiler\Domain\AggregateRootId::fromString($idValue)
            : $idValue;

        $instance = new static($aggregateId);

        // Reset version to 0 before replaying — the constructor may have incremented it.
        // Use the public setVersion() method instead of reflection to avoid issues
        // with deeper class hierarchies where getProperty('version') might resolve
        // to the wrong declaration (BUG-3 R33).
        $instance->setVersion(0);

        foreach ($events as $event) {
            $instance->applyEvent($event, lenient: true);
        }

        // Clear any recorded events — reconstituted aggregates have no uncommitted events
        $instance->clearEvents();

        return $instance;
    }

    /**
     * Increment the aggregate version.
     */
    public function incrementVersion(): void
    {
        $this->version++;
    }

    protected function applyEvent(DomainEvent $event, bool $lenient = false): void
    {
        // Use the eventType property for method resolution.
        // DomainEvent is a single class (not one class per event type),
        // so reflection on the class name would always yield 'applyDomainEvent'.
        // Instead, convert the eventType string (e.g., 'order.placed') to
        // a method name (e.g., 'applyOrderPlaced').
        // Split on dots, underscores, and hyphens to support various naming conventions
        $parts = preg_split('/[._-]/', $event->eventType) ?: [];
        $method = 'apply' . implode('', array_map(ucfirst(...), $parts));

        if (! method_exists($this, $method)) {
            if ($lenient) {
                // In lenient mode, silently skip events that have no handler.
                // This is useful for purely informational events that don't
                // change aggregate state, or for forward-compatible replaying
                // when new event types are introduced.
                return;
            }

            throw new RuntimeException(
                sprintf(
                    'Method %s not found in %s for event type "%s"',
                    $method,
                    static::class,
                    $event->eventType
                )
            );
        }

        $this->$method($event);
        $this->incrementVersion();
    }
}
