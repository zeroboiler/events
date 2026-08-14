<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Concerns;

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\SubscriptionBuilder;

/**
 * Webhook subscription management operations.
 *
 * Extracted from EventManager to reduce class size and improve
 * single-responsibility. Must be used inside the EventManager class
 * which provides the `$app` container property and `register()` method.
 *
 * @see \ZeroBoiler\Events\EventManager
 *
 * @property-read \Illuminate\Container\Container $app
 */
trait ManagesSubscriptions
{
    use EscapesWildcardLike;

    /**
     * Start building a webhook subscription for an external system.
     *
     * Creates a SubscriptionBuilder that registers a webhook trigger
     * when saved. Includes HMAC signing, condition filtering, and
     * delivery tracking.
     *
     * @param  string  $event  Event name (supports wildcards)
     * @param  string  $url  Webhook endpoint URL
     */
    public function subscribe(string $event, string $url): SubscriptionBuilder
    {
        $builder = $this->app->make(SubscriptionBuilder::class);

        if (! $builder instanceof SubscriptionBuilder) {
            throw new \RuntimeException('SubscriptionBuilder could not be resolved from the container.');
        }

        $builder->on($event)->to($url);

        return $builder;
    }

    /**
     * Remove a webhook subscription by its ID.
     *
     * Deletes the subscription record. Does not delete the associated
     * trigger (use disable() for that if needed).
     */
    public function unsubscribe(string $subscriptionId): bool
    {
        $subscription = Subscription::find($subscriptionId);

        if ($subscription === null) {
            return false;
        }

        $subscription->delete();

        return true;
    }

    /**
     * List webhook subscriptions with optional filtering.
     *
     * @param  string|null  $event  Filter by event name (supports wildcards)
     * @param  bool  $activeOnly  Show only active subscriptions
     * @return Collection<int, Subscription>
     */
    public function listSubscriptions(?string $event = null, bool $activeOnly = false): Collection
    {
        $query = Subscription::query();

        if ($event !== null && $event !== '') {
            $likePattern = $this->wildcardToLike($event);
            if ($likePattern !== null) {
                $query->where('event', 'like', $likePattern);
            } else {
                $query->where('event', $event);
            }
        }

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderByPriority()
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Get a subscription by ID.
     */
    public function getSubscription(string $subscriptionId): ?Subscription
    {
        return Subscription::find($subscriptionId);
    }

    /**
     * Subscribe an external webhook URL to an event.
     *
     * Registers a trigger that dispatches an HTTP POST to the given
     * URL whenever the event fires. Optional conditions can be provided
     * to filter when the webhook is actually called.
     *
     * @param  array<string, mixed>  $conditions  Optional condition filters
     * @return string The created trigger ID
     */
    public function subscribeWebhook(
        string $event,
        string $url,
        array $conditions = [],
        int $priority = 0,
    ): string
    {
        $trigger = $this->register($event)
            ->action(WebhookAction::class)
            ->actionParams(['url' => $url])
            ->when($conditions)
            ->priority($priority)
            ->name("Webhook: {$event} → {$url}")
            ->save();

        return $trigger->id;
    }
}
