<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

/**
 * @extends Factory<EventLog>
 */
class EventLogFactory extends Factory
{
    protected $model = EventLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trigger_id' => Trigger::factory(),
            'event' => fake()->word().'.'.fake()->word(),
            'payload' => [
                'user_id' => fake()->randomNumber(),
                'amount' => fake()->randomFloat(2, 10, 1000),
                'status' => fake()->randomElement(['pending', 'completed', 'failed']),
            ],
            'status' => fake()->randomElement(EventLog::$statuses),
            'error' => fake()->boolean(20) ? fake()->sentence() : null,
            'duration_ms' => fake()->boolean(70) ? fake()->numberBetween(10, 5000) : null,
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventLog::STATUS_PENDING,
        ]);
    }

    public function dispatched(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventLog::STATUS_DISPATCHED,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => fake()->numberBetween(10, 5000),
            'error' => null,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => EventLog::STATUS_FAILED,
            'error' => fake()->sentence(),
        ]);
    }
}
