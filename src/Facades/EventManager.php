<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Facades;

use Illuminate\Support\Facades\Facade;
use ZeroBoiler\Events\TriggerBuilder;

/**
 * @method static TriggerBuilder on(string $event)
 * @method static TriggerBuilder register(string $event)
 * @method static void fire(string $event, array<string, mixed> $payload = [])
 * @method static void fireModel(string $modelClass, string $action, object $model)
 * @method static bool enable(string $triggerId)
 * @method static bool disable(string $triggerId)
 *
 * @see \ZeroBoiler\Events\EventManager
 */
class EventManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ZeroBoiler\Events\EventManager::class;
    }
}
