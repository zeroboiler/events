<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Models\Subscription;

/**
 * Fluent builder for creating external webhook subscriptions.
 *
 * Usage:
 * ```php
 * EventManager::subscribe('order.placed', 'https://api.partner.com/webhooks/order')
 *     ->withSecret('whsec_abc123')
 *     ->withFilter(['status' => 'paid'])
 *     ->async()
 *     ->save();
 * ```
 */
class SubscriptionBuilder
{
    protected string $event = '';

    protected string $url = '';

    /** @var array<string, mixed> */
    protected array $conditions = [];

    protected int $priority = 0;

    protected ?string $secret = null;

    protected bool $async = false;

    public function __construct(
        protected EventManager $eventManager
    ) {}

    /**
     * Set the event name to subscribe to.
     */
    public function on(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Set the webhook URL to deliver to.
     */
    public function to(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Set the HMAC signing secret for webhook payload verification.
     */
    public function withSecret(string $secret): self
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * Set condition filters — webhook only fires when conditions match.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function withFilter(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    /**
     * Set the priority (higher = dispatched first).
     */
    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Mark the subscription for async dispatch (queued delivery).
     */
    public function async(bool $async = true): self
    {
        $this->async = $async;

        return $this;
    }

    /**
     * Save the subscription to the database.
     *
     * Also registers an internal trigger that dispatches the WebhookAction
     * when the event fires. The trigger's action_params contain the
     * subscription ID and URL so the WebhookAction can look up the signing
     * secret for HMAC payload verification.
     */
    public function save(): Subscription
    {
        if ($this->event === '' || $this->event === '0') {
            throw new \InvalidArgumentException('Event name is required for subscription');
        }

        if ($this->url === '' || $this->url === '0') {
            throw new \InvalidArgumentException('Webhook URL is required for subscription');
        }

        if (! filter_var($this->url, \FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Webhook URL must be a valid URL');
        }

        // Generate a secret if none was provided and auto_generate_secret is enabled
        $autoGenerate = Config::get('events.subscriptions.auto_generate_secret', true);
        if ($this->secret === null && $autoGenerate !== false) {
            $this->secret = 'whsec_'.Str::random(32);
        }

        $subscription = new Subscription([
            'id' => (string) Str::uuid(),
            'event' => $this->event,
            'url' => $this->url,
            'conditions' => $this->conditions !== [] ? $this->conditions : null,
            'priority' => $this->priority,
            'active' => true,
            'secret' => $this->secret,
            'failure_count' => 0,
        ]);
        $subscription->save();

        // Register an internal trigger that will dispatch the webhook
        // when the event fires. The trigger references the subscription
        // so the WebhookAction can look up the signing secret.
        $this->eventManager
            ->on($this->event)
            ->action(WebhookAction::class)
            ->actionParams([
                'url' => $this->url,
                'subscription_id' => $subscription->id,
            ])
            ->when($this->conditions)
            ->priority($this->priority)
            ->async($this->async)
            ->name("Subscription: {$this->event} → {$this->url}")
            ->save();

        return $subscription;
    }
}
