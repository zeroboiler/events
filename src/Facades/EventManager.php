<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ZeroBoiler\Events\TriggerBuilder on(string $event)
 * @method static \ZeroBoiler\Events\TriggerBuilder register(string $event)
 * @method static void fire(string $event, array<string, mixed> $payload = [], bool $async = false)
 * @method static void fireModel(string $modelClass, string $action, object $model)
 * @method static bool enable(string $triggerId)
 * @method static bool disable(string $triggerId)
 * @method static void invalidateTriggerCache()
 * @method static bool isDisabled()
 * @method static void setEnabled(bool $enabled)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \ZeroBoiler\Events\Models\Trigger> listTriggers(?string $event = null, ?bool $enabled = null, int $limit = 100)
 * @method static \ZeroBoiler\Events\Models\Trigger|null getTrigger(string $triggerId)
 * @method static bool deleteTrigger(string $triggerId)
 * @method static \ZeroBoiler\Events\SubscriptionBuilder subscribe(string $event, string $url)
 * @method static bool unsubscribe(string $subscriptionId)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \ZeroBoiler\Events\Models\Subscription> listSubscriptions(?string $event = null, bool $activeOnly = false)
 * @method static \ZeroBoiler\Events\Models\Subscription|null getSubscription(string $subscriptionId)
 * @method static string subscribeWebhook(string $event, string $url, array<string, mixed> $conditions = [], int $priority = 0)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \ZeroBoiler\Events\Models\EventLog> getEventHistory(?string $event = null, ?string $status = null, ?string $triggerId = null, int $limit = 100)
 * @method static array{
 *     total_logs: int,
 *     total_triggers: int,
 *     active_triggers: int,
 *     completed: int,
 *     failed: int,
 *     pending: int,
 *     dispatched: int,
 *     success_rate: float|null,
 *     failure_rate: float|null,
 *     avg_duration_ms: float|null,
 *     top_events: array<int, array{event: string, count: int}>,
 *     top_failed_events: array<int, array{event: string, count: int}>
 * } getStats(?\Illuminate\Support\Carbon $since = null)
 * @method static int purgeLogs(\Illuminate\Support\Carbon $before, bool $includePending = false)
 * @method static void executeTrigger(\ZeroBoiler\Events\Models\Trigger $trigger, \ZeroBoiler\Events\Models\EventLog $log)
 *
 * @see \ZeroBoiler\Events\EventManager
 */
final class EventManager extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return \ZeroBoiler\Events\EventManager::class;
    }
}
