<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Facades;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

/**
 * @method static TriggerBuilder on(string $event)
 * @method static TriggerBuilder register(string $event)
 * @method static void fire(string $event, array<string, mixed> $payload = [])
 * @method static void fireModel(string $modelClass, string $action, object $model)
 * @method static bool enable(string $triggerId)
 * @method static bool disable(string $triggerId)
 * @method static void invalidateTriggerCache()
 * @method static SubscriptionBuilder subscribe(string $event, string $url)
 * @method static bool unsubscribe(string $subscriptionId)
 * @method static Collection<int, Subscription> listSubscriptions(?string $event = null, bool $activeOnly = false)
 * @method static Subscription|null getSubscription(string $subscriptionId)
 * @method static string subscribeWebhook(string $event, string $url, array<string, mixed> $conditions = [], int $priority = 0)
 * @method static Collection<int, EventLog> getEventHistory(?string $event = null, ?string $status = null, ?string $triggerId = null, int $limit = 100)
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
 * } getStats(?Carbon $since = null)
 * @method static int purgeLogs(Carbon $before, bool $includePending = false)
 * @method static void executeTrigger(Trigger $trigger, EventLog $log)
 *
 * @see \ZeroBoiler\Events\EventManager
 */
final class EventManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ZeroBoiler\Events\EventManager::class;
    }
}
