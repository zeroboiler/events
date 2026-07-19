<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain\Support;

use ZeroBoiler\Events\Domain\DomainEvent;

trait HasDomainEvents
{
    /** @var array<int, DomainEvent> */
    protected array $domainEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * @return array<int, DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    public function hasUncommittedEvents(): bool
    {
        return $this->domainEvents !== [];
    }

    public function clearEvents(): void
    {
        $this->domainEvents = [];
    }
}
