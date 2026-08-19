<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\Subscription;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected static string $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = fake()->word().'.'.fake()->word();

        return [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'url' => fake()->url(),
            'conditions' => null,
            'priority' => fake()->numberBetween(0, 100),
            'active' => true,
            'secret' => 'whsec_'.Str::random(32),
            'last_fired_at' => null,
            'failure_count' => 0,
            'delivery_count' => 0,
        ];
    }

    /**
     * Set the subscription as active.
     */
    public function active(): self
    {
        return $this->state(fn (array $attributes): array => [
            'active' => true,
        ]);
    }

    /**
     * Set the subscription as inactive.
     */
    public function inactive(): self
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    /**
     * Set the event name for the subscription.
     */
    public function forEvent(string $event): self
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
        ]);
    }

    /**
     * Set the webhook URL for the subscription.
     */
    public function withUrl(string $url): self
    {
        return $this->state(fn (array $attributes): array => [
            'url' => $url,
        ]);
    }

    /**
     * Set conditions for the subscription.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function withConditions(array $conditions): self
    {
        return $this->state(fn (array $attributes): array => [
            'conditions' => $conditions,
        ]);
    }

    /**
     * Set the HMAC signing secret for the subscription.
     */
    public function withSecret(string $secret): self
    {
        return $this->state(fn (array $attributes): array => [
            'secret' => $secret,
        ]);
    }

    /**
     * Remove the HMAC signing secret from the subscription.
     */
    public function withoutSecret(): self
    {
        return $this->state(fn (array $attributes): array => [
            'secret' => null,
        ]);
    }

    /**
     * Set a specific failure count.
     *
     * @param  int  $count  The failure count value
     */
    public function withFailureCount(int $count): self
    {
        return $this->state(fn (array $attributes): array => [
            'failure_count' => $count,
        ]);
    }

    /**
     * Set a specific delivery count.
     *
     * @param  int  $count  The delivery count value
     */
    public function withDeliveryCount(int $count): self
    {
        return $this->state(fn (array $attributes): array => [
            'delivery_count' => $count,
        ]);
    }

    /**
     * Set a specific priority.
     *
     * @param  int  $priority  The priority value (higher = first)
     */
    public function withPriority(int $priority): self
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }
}
