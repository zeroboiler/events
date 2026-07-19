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
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = fake()->word().'.'.fake()->word();

        return [
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

    public function active(): self
    {
        return $this->state(fn (array $attributes): array => [
            'active' => true,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attributes): array => [
            'active' => false,
        ]);
    }

    public function forEvent(string $event): self
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
        ]);
    }

    public function withUrl(string $url): self
    {
        return $this->state(fn (array $attributes): array => [
            'url' => $url,
        ]);
    }

    public function withConditions(array $conditions): self
    {
        return $this->state(fn (array $attributes): array => [
            'conditions' => $conditions,
        ]);
    }

    public function withSecret(string $secret): self
    {
        return $this->state(fn (array $attributes): array => [
            'secret' => $secret,
        ]);
    }

    public function withoutSecret(): self
    {
        return $this->state(fn (array $attributes): array => [
            'secret' => null,
        ]);
    }
}
