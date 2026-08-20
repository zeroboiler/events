<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

class EventManagerFireModelEdgeCasesTest extends TestCase
{
    /**
     * fireModel() with an object that has neither attributesToArray() nor toArray()
     * should fire with only metadata keys (model, model_class, action).
     */
    #[Test]
    public function fireModelWithPlainObjectFiresWithMetadataOnly(): void
    {
        $capturedPayload = null;

        $action = new class($capturedPayload) implements Triggerable
        {
            /** @var array<string, mixed>|null */
            private mixed $captured;

            /**
             * @param array<string, mixed>|null &$captured
             */
            public function __construct(mixed &$captured)
            {
                $this->captured = &$captured;
            }

            public function handle(array $payload): void
            {
                $this->captured = $payload;
            }
        };

        $this->app->bind(Triggerable::class . ':PlainObjectAction', fn (): Triggerable => $action);

        $plainObject = new \stdClass();

        Trigger::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Plain Object Test',
            'event' => 'stdClass.created',
            'action' => \sprintf('%s:PlainObjectAction', Triggerable::class),
            'enabled' => true,
            'async' => false,
            'priority' => 0,
        ]);

        $manager = $this->app->make(EventManager::class);
        $manager->fireModel(\stdClass::class, 'created', $plainObject);

        $this->assertNotNull($capturedPayload);
        $this->assertArrayHasKey('model_class', $capturedPayload);
        $this->assertArrayHasKey('action', $capturedPayload);
        $this->assertArrayHasKey('model', $capturedPayload);
        $this->assertSame(\stdClass::class, $capturedPayload['model_class']);
        $this->assertSame('created', $capturedPayload['action']);
        $this->assertSame($plainObject, $capturedPayload['model']);
    }

    /**
     * fireModel() with empty model class should throw InvalidArgumentException.
     */
    #[Test]
    public function fireModelWithEmptyModelClassThrows(): void
    {
        $manager = $this->app->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model class name cannot be empty.');

        $manager->fireModel('', 'created', new \stdClass());
    }

    /**
     * fireModel() with empty action should throw InvalidArgumentException.
     */
    #[Test]
    public function fireModelWithEmptyActionThrows(): void
    {
        $manager = $this->app->make(EventManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model action cannot be empty.');

        $manager->fireModel(\stdClass::class, '', new \stdClass());
    }

    /**
     * fireModel() constructs the correct event name from class + action.
     */
    #[Test]
    public function fireModelConstructsCorrectEventName(): void
    {
        $capturedPayload = null;

        $action = new class($capturedPayload) implements Triggerable
        {
            /** @var array<string, mixed>|null */
            private mixed $captured;

            /**
             * @param array<string, mixed>|null &$captured
             */
            public function __construct(mixed &$captured)
            {
                $this->captured = &$captured;
            }

            public function handle(array $payload): void
            {
                $this->captured = $payload;
            }
        };

        $this->app->bind(Triggerable::class . ':EventNameCheckAction', fn (): Triggerable => $action);

        Trigger::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Event Name Check',
            'event' => 'App\\Models\\Order.deleted',
            'action' => \sprintf('%s:EventNameCheckAction', Triggerable::class),
            'enabled' => true,
            'async' => false,
            'priority' => 0,
        ]);

        $model = new class
        {
            public function toArray(): array
            {
                return ['id' => 1, 'status' => 'shipped'];
            }
        };

        $manager = $this->app->make(EventManager::class);
        $manager->fireModel('App\\Models\\Order', 'deleted', $model);

        $this->assertNotNull($capturedPayload);
        $this->assertArrayHasKey('id', $capturedPayload);
        $this->assertArrayHasKey('status', $capturedPayload);
        $this->assertSame(1, $capturedPayload['id']);
        $this->assertSame('shipped', $capturedPayload['status']);
    }

    /**
     * fireModel() prefers attributesToArray() over toArray().
     */
    #[Test]
    public function fireModelPrefersAttributesToArrayOverToArray(): void
    {
        $capturedPayload = null;

        $action = new class($capturedPayload) implements Triggerable
        {
            /** @var array<string, mixed>|null */
            private mixed $captured;

            /**
             * @param array<string, mixed>|null &$captured
             */
            public function __construct(mixed &$captured)
            {
                $this->captured = &$captured;
            }

            public function handle(array $payload): void
            {
                $this->captured = $payload;
            }
        };

        $this->app->bind(Triggerable::class . ':PreferenceAction', fn (): Triggerable => $action);

        Trigger::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Attribute Preference',
            'event' => 'TestModel.updated',
            'action' => \sprintf('%s:PreferenceAction', Triggerable::class),
            'enabled' => true,
            'async' => false,
            'priority' => 0,
        ]);

        // Model that has BOTH methods; attributesToArray() should win
        $model = new class
        {
            public function attributesToArray(): array
            {
                return ['source' => 'attributesToArray', 'value' => 42];
            }

            public function toArray(): array
            {
                return ['source' => 'toArray', 'value' => 99];
            }
        };

        $manager = $this->app->make(EventManager::class);
        $manager->fireModel('TestModel', 'updated', $model);

        $this->assertNotNull($capturedPayload);
        $this->assertSame('attributesToArray', $capturedPayload['source']);
        $this->assertSame(42, $capturedPayload['value']);
    }
}
