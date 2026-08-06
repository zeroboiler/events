<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\Trigger;

/**
 * @extends Factory<Trigger>
 */
class TriggerFactory extends Factory
{
    protected string $model = Trigger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = fake()->word().'.'.fake()->word();

        return [
            'id' => (string) Str::uuid(),
            'name' => ucfirst(str_replace('.', ' ', $event)).' Trigger',
            'event' => $event,
            'action' => fake()->sentence(),
            'conditions' => fake()->boolean(50) ? [
                'status' => fake()->randomElement(['active', 'pending']),
            ] : null,
            'async' => fake()->boolean(60),
            'priority' => fake()->numberBetween(0, 100),
            'enabled' => fake()->boolean(90),
        ];
    }

    public function async(): self
    {
        return $this->state(fn (array $attributes): array => [
            'async' => true,
        ]);
    }

    public function sync(): self
    {
        return $this->state(fn (array $attributes): array => [
            'async' => false,
        ]);
    }

    public function enabled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => true,
        ]);
    }

    public function disabled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }

    public function withConditions(array $conditions): self
    {
        return $this->state(fn (array $attributes): array => [
            'conditions' => $conditions,
        ]);
    }

    public function priority(int $priority): self
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }
}
