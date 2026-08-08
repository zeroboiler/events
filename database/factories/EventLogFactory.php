<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

/**
 * @extends Factory<EventLog>
 */
class EventLogFactory extends Factory
{
    protected string $model = EventLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
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
        return $this->state(fn (array $attributes): array => [
            'status' => EventLog::STATUS_PENDING,
        ]);
    }

    public function dispatched(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventLog::STATUS_DISPATCHED,
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventLog::STATUS_COMPLETED,
            'duration_ms' => fake()->numberBetween(10, 5000),
            'error' => null,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventLog::STATUS_FAILED,
            'error' => fake()->sentence(),
        ]);
    }

    /**
     * Set the event name for the log.
     */
    public function withEvent(string $event): self
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
        ]);
    }

    /**
     * Set the trigger ID for the log.
     */
    public function forTrigger(string $triggerId): self
    {
        return $this->state(fn (array $attributes): array => [
            'trigger_id' => $triggerId,
        ]);
    }

    /**
     * Set a specific payload for the log.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(array $payload): self
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => $payload,
        ]);
    }

    /**
     * Set a specific duration in milliseconds.
     */
    public function withDuration(int $durationMs): self
    {
        return $this->state(fn (array $attributes): array => [
            'duration_ms' => $durationMs,
            'status' => EventLog::STATUS_COMPLETED,
            'error' => null,
        ]);
    }
}
