<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    $this->app = $this->createApplication();
    $this->eventManager = $this->app->make(EventManager::class);
});

describe('EventManager::fireModel', function (): void {
    test('it fires event with model attributesToArray flattened into payload', function (): void {
        $handled = false;
        $capturedPayload = [];

        $this->app->bind(FireModelCaptureAction::class, fn (): FireModelCaptureAction => new FireModelCaptureAction(
            static function (array $payload) use (&$handled, &$capturedPayload): void {
                $handled = true;
                $capturedPayload = $payload;
            },
        ));

        $this->eventManager->on('App\\Models\\Order.created')
            ->action(FireModelCaptureAction::class)
            ->save();

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected string $keyType = 'string';

            public bool $incrementing = false;

            public function attributesToArray(): array
            {
                return [
                    'id' => 'order-1',
                    'status' => 'active',
                    'total' => 150.00,
                ];
            }
        };

        $this->eventManager->fireModel('App\\Models\\Order', 'created', $model);

        expect($handled)->toBeTrue();
        expect($capturedPayload)->toHaveKey('status');
        expect($capturedPayload['status'])->toBe('active');
        expect($capturedPayload['total'])->toBe(150.00);
        expect($capturedPayload['model_class'])->toBe('App\\Models\\Order');
        expect($capturedPayload['action'])->toBe('created');
        expect($capturedPayload)->toHaveKey('model');
    });

    test('it falls back to toArray when attributesToArray does not exist', function (): void {
        $handled = false;
        $capturedPayload = [];

        $this->app->bind(FireModelCaptureAction::class, fn (): FireModelCaptureAction => new FireModelCaptureAction(
            static function (array $payload) use (&$handled, &$capturedPayload): void {
                $handled = true;
                $capturedPayload = $payload;
            },
        ));

        $this->eventManager->on('App\\Models\\Product.updated')
            ->action(FireModelCaptureAction::class)
            ->save();

        $model = new class
        {
            public function toArray(): array
            {
                return [
                    'sku' => 'PROD-001',
                    'name' => 'Widget',
                ];
            }
        };

        $this->eventManager->fireModel('App\\Models\\Product', 'updated', $model);

        expect($handled)->toBeTrue();
        expect($capturedPayload['sku'])->toBe('PROD-001');
        expect($capturedPayload['name'])->toBe('Widget');
    });

    test('it works with plain object that has neither attributesToArray nor toArray', function (): void {
        $handled = false;
        $capturedPayload = [];

        $this->app->bind(FireModelCaptureAction::class, fn (): FireModelCaptureAction => new FireModelCaptureAction(
            static function (array $payload) use (&$handled, &$capturedPayload): void {
                $handled = true;
                $capturedPayload = $payload;
            },
        ));

        $this->eventManager->on('App\\Services\\Service.deleted')
            ->action(FireModelCaptureAction::class)
            ->save();

        $model = new class
        {
            public string $name = 'TestService';
        };

        $this->eventManager->fireModel('App\\Services\\Service', 'deleted', $model);

        expect($handled)->toBeTrue();
        // No flattened attributes, but model_class and action are present
        expect($capturedPayload['model_class'])->toBe('App\\Services\\Service');
        expect($capturedPayload['action'])->toBe('deleted');
        expect($capturedPayload)->toHaveKey('model');
    });

    test('it constructs correct event name from class and action', function (): void {
        $this->app->bind(FireModelNoOpAction::class, fn (): FireModelNoOpAction => new FireModelNoOpAction);

        $this->eventManager->on('App\\Models\\Invoice.paid')
            ->action(FireModelNoOpAction::class)
            ->save();

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected string $keyType = 'string';

            public bool $incrementing = false;

            public function attributesToArray(): array
            {
                return ['amount' => 500];
            }
        };

        $this->eventManager->fireModel('App\\Models\\Invoice', 'paid', $model);

        // Verify EventLog was created with the correct event name
        $log = EventLog::query()->where('event', 'App\\Models\\Invoice.paid')->first();
        expect($log)->not->toBeNull();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('it fires with empty payload when model has no serializable attributes', function (): void {
        $handled = false;
        $capturedPayload = [];

        $this->app->bind(FireModelCaptureAction::class, fn (): FireModelCaptureAction => new FireModelCaptureAction(
            static function (array $payload) use (&$handled, &$capturedPayload): void {
                $handled = true;
                $capturedPayload = $payload;
            },
        ));

        $this->eventManager->on('App\\Models\\EmptyModel.archived')
            ->action(FireModelCaptureAction::class)
            ->save();

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected string $keyType = 'string';

            public bool $incrementing = false;

            public function attributesToArray(): array
            {
                return [];
            }
        };

        $this->eventManager->fireModel('App\\Models\\EmptyModel', 'archived', $model);

        expect($handled)->toBeTrue();
        expect($capturedPayload['model_class'])->toBe('App\\Models\\EmptyModel');
        expect($capturedPayload['action'])->toBe('archived');
        expect($capturedPayload)->toHaveKey('model');
    });

    test('it does not fire when no triggers match', function (): void {
        $handled = false;

        $this->app->bind(FireModelCaptureAction::class, fn (): FireModelCaptureAction => new FireModelCaptureAction(
            static function (array $payload) use (&$handled): void {
                $handled = true;
            },
        ));

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected string $keyType = 'string';

            public bool $incrementing = false;

            public function attributesToArray(): array
            {
                return ['id' => '1'];
            }
        };

        $this->eventManager->fireModel('App\\Models\\NonExistent.removed', $model);

        expect($handled)->toBeFalse();
    });

    test('flattened attributes are overridden by model metadata', function (): void {
        $capturedPayload = [];

        $this->app->bind(FireModelCaptureAction::class, fn (): FireModelCaptureAction => new FireModelCaptureAction(
            static function (array $payload) use (&$capturedPayload): void {
                $capturedPayload = $payload;
            },
        ));

        $this->eventManager->on('App\\Models\\MetaTest.saved')
            ->action(FireModelCaptureAction::class)
            ->save();

        // Model returns attributes that include model_class and action keys
        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected string $keyType = 'string';

            public bool $incrementing = false;

            public function attributesToArray(): array
            {
                return [
                    'id' => 'meta-1',
                    'model_class' => 'should-be-overwritten',
                    'action' => 'should-be-overwritten',
                ];
            }
        };

        $this->eventManager->fireModel('App\\Models\\MetaTest', 'saved', $model);

        // The spread operator puts flattened attrs first, then model metadata
        // So model_class and action should be the actual model event metadata
        expect($capturedPayload['model_class'])->toBe('App\\Models\\MetaTest');
        expect($capturedPayload['action'])->toBe('saved');
        expect($capturedPayload['id'])->toBe('meta-1');
    });
});

/**
 * Test action that captures payload for fireModel tests.
 */
final class FireModelCaptureAction implements \ZeroBoiler\Events\Contracts\Triggerable
{
    /** @var \Closure(array<string, mixed>): void */
    private \Closure $callback;

    /**
     * @param \Closure(array<string, mixed>): void $callback
     */
    public function __construct(\Closure $callback)
    {
        $this->callback = $callback;
    }

    public function handle(array $payload): void
    {
        ($this->callback)($payload);
    }
}

/**
 * Test action that does nothing (no-op) for event name verification.
 */
final class FireModelNoOpAction implements \ZeroBoiler\Events\Contracts\Triggerable
{
    public function handle(array $payload): void
    {
        // Intentionally empty
    }
}
