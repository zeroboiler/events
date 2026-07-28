<?php

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain\Collections;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use ZeroBoiler\Events\Domain\DomainEvent;

/**
 * @implements IteratorAggregate<int, DomainEvent>
 */
class DomainEventCollection implements Countable, IteratorAggregate
{
    /**
     * @param  array<int, DomainEvent>  $events
     */
    public function __construct(
        private array $events = [],
    ) {
        foreach ($events as $event) {
            if (! $event instanceof DomainEvent) {
                throw new InvalidArgumentException(
                    'All events must implement '.DomainEvent::class
                );
            }
        }
    }

    /**
     * @param  array<int, DomainEvent>  $events
     *
     * @throws InvalidArgumentException if any element is not a DomainEvent
     */
    public static function fromArray(array $events): self
    {
        return new self($events);
    }

    public function add(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return ArrayIterator<int, DomainEvent>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->events);
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * @return array<int, DomainEvent>
     */
    public function toArray(): array
    {
        return $this->events;
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function first(): ?DomainEvent
    {
        return $this->events[0] ?? null;
    }

    public function last(): ?DomainEvent
    {
        return $this->events[count($this->events) - 1] ?? null;
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->events, $callback));
    }

    /**
     * @return array<int, mixed>
     */
    public function map(callable $callback): array
    {
        /** @var array<int, mixed> $result */
        $result = array_map($callback, $this->events);

        return $result;
    }

    public function merge(self $other): self
    {
        return new self([...$this->events, ...$other->events]);
    }
}
