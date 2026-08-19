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
final class TriggerFactory extends Factory
{
    protected static string $model = Trigger::class;

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
            'action' => 'ZeroBoiler\\Events\\Tests\\Actions\\'.fake()->word().'Action',
            'conditions' => fake()->boolean(50) ? [
                'status' => fake()->randomElement(['active', 'pending']),
            ] : null,
            'async' => fake()->boolean(60),
            'priority' => fake()->numberBetween(0, 100),
            'enabled' => fake()->boolean(90),
        ];
    }

    /**
     * Set the trigger as async.
     */
    public function async(): self
    {
        return $this->state(fn (array $attributes): array => [
            'async' => true,
        ]);
    }

    /**
     * Set the trigger as sync.
     */
    public function sync(): self
    {
        return $this->state(fn (array $attributes): array => [
            'async' => false,
        ]);
    }

    /**
     * Set the trigger as enabled.
     */
    public function enabled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => true,
        ]);
    }

    /**
     * Set the trigger as disabled.
     */
    public function disabled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }

    /**
     * Set conditions for the trigger.
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
     * Set the priority for the trigger.
     */
    public function priority(int $priority): self
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    /**
     * Set the event name for the trigger.
     */
    public function forEvent(string $event): self
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
        ]);
    }

    /**
     * Set the action handler class for the trigger.
     */
    public function withAction(string $action): self
    {
        return $this->state(fn (array $attributes): array => [
            'action' => $action,
        ]);
    }

    /**
     * Set a specific trigger name.
     */
    public function withName(string $name): self
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
        ]);
    }
}
