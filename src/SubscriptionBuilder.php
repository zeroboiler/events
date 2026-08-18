<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
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
final class SubscriptionBuilder
{
    protected string $event = '';

    protected string $url = '';

    /** @var array<string, mixed> */
    protected array $conditions = [];

    protected int $priority = 0;

    protected ?string $secret = null;

    protected bool $async = false;

    public function __construct(
        private readonly EventManager $eventManager,
    ) {}

    /**
     * Get the config repository from the container.
     *
     * @internal Not part of the public API.
     */
    protected function getConfig(): ConfigRepository
    {
        // Use the public container() method on EventManager for safe access.
        $config = $this->eventManager->container()->get('config');

        if ($config instanceof ConfigRepository) {
            return $config;
        }

        throw new \RuntimeException('Config repository not available in the container.');
    }

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
     *
     * @throws \InvalidArgumentException If the secret is too short (minimum 16 characters)
     */
    public function withSecret(string $secret): self
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('Webhook signing secret must be at least 16 characters.');
        }

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
     * Uses a database transaction to ensure atomicity — both the subscription
     * record and the internal trigger are created together, or neither.
     * If the trigger save fails, the subscription is rolled back, preventing
     * orphaned subscription records.
     *
     * Also registers an internal trigger that dispatches the WebhookAction
     * when the event fires. The trigger's action_params contain the
     * subscription ID and URL so the WebhookAction can look up the signing
     * secret for HMAC payload verification.
     *
     * @throws \InvalidArgumentException If event name is empty, URL is empty/invalid, or URL is non-HTTP(S)
     * @throws \JsonException If JSON encoding of webhook payload fails during signing
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

        // Reject non-HTTP(S) schemes — webhooks must use HTTP or HTTPS only.
        // filter_var(FILTER_VALIDATE_URL) accepts ftp://, file://, etc.
        $parsed = parse_url($this->url);
        $schemeRaw = is_array($parsed) ? ($parsed['scheme'] ?? null) : null;
        $scheme = is_string($schemeRaw) ? strtolower($schemeRaw) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Webhook URL must use HTTP or HTTPS protocol');
        }

        // Generate a secret if none was provided and auto_generate_secret is enabled
        $autoGenerate = $this->getConfig()->get('events.subscriptions.auto_generate_secret', true);
        if ($this->secret === null && $autoGenerate !== false) {
            $secretLength = $this->getConfig()->get('events.subscriptions.secret_length', 32);
            $length = is_int($secretLength) && $secretLength >= 16
                ? $secretLength
                : (is_numeric($secretLength) && (int) $secretLength >= 16 ? (int) $secretLength : 32);
            $this->secret = 'whsec_'.Str::random($length);
        }

        return DB::transaction(function (): Subscription {
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
        });
    }
}
