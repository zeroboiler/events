<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Domain\Facades;

use Illuminate\Support\Facades\Facade;
use ZeroBoiler\Events\Domain\DomainEventDispatcher as DomainEventDispatcherImpl;

/**
 * @method static void dispatch(\ZeroBoiler\Events\Domain\DomainEvent $event)
 * @method static void dispatchQuietly(\ZeroBoiler\Events\Domain\DomainEvent $event)
 * @method static void subscribe(string $eventType, \Closure $listener)
 * @method static void defer(\ZeroBoiler\Events\Domain\DomainEvent $event)
 * @method static void releaseDeferred()
 * @method static void clearDeferred()
 * @method static bool hasDeferredEvents()
 * @method static int getDeferredEventsCount()
 * @method static void clearListeners(?string $eventType = null)
 * @method static void flush()
 * @method static bool hasListeners(string $eventType)
 * @method static int getListenerCount()
 * @method static void setEventForwarder(?\Closure $forwarder)
 *
 * @see DomainEventDispatcherImpl
 */
class DomainEventDispatcher extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return DomainEventDispatcherImpl::class;
    }
}
