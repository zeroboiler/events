<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\HighPriority;
use App\Actions\LogOrderEvent;
use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;

// Load test action classes
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('TriggerBuilder action merging', function (): void {
    test('action() + actions() merges both lists without duplication', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->name('Merge Test')
            ->on('merge.test')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class, HighPriority::class])
            ->save();

        $actions = json_decode($trigger->action, true);

        // Should be a JSON array of 3 class names
        expect($actions)->toBeArray()
            ->and($actions)->toHaveCount(3)
            ->and($actions[0])->toBe(SendOrderNotification::class)
            ->and($actions[1])->toBe(LogOrderEvent::class)
            ->and($actions[2])->toBe(HighPriority::class);
    });

    test('action() + actions() deduplicates when same class appears in both', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->name('Dedup Test')
            ->on('dedup.test')
            ->action(SendOrderNotification::class)
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $actions = json_decode($trigger->action, true);

        // SendOrderNotification should appear only once (prepended, deduped from actions)
        expect($actions)->toBeArray()
            ->and($actions)->toHaveCount(2)
            ->and($actions[0])->toBe(SendOrderNotification::class)
            ->and($actions[1])->toBe(LogOrderEvent::class);
    });

    test('only action() produces plain class name string', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('single.action')
            ->action(SendOrderNotification::class)
            ->save();

        expect($trigger->action)->toBe(SendOrderNotification::class);
    });

    test('only actions() produces JSON array string', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('multi.actions')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $actions = json_decode($trigger->action, true);
        expect($actions)->toBeArray()
            ->toHaveCount(2);
    });

    test('action() with actionParams produces JSON object', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('params.action')
            ->action(SendOrderNotification::class)
            ->actionParams(['url' => 'https://example.com/hook'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->toHaveKey('class')
            ->toHaveKey('params')
            ->and($decoded['class'])->toBe(SendOrderNotification::class)
            ->and($decoded['params']['url'])->toBe('https://example.com/hook');
    });

    test('action() + actions() with actionParams uses classes key format', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('multi.params')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class])
            ->actionParams(['topic' => 'orders'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->toHaveKey('classes')
            ->toHaveKey('params')
            ->and($decoded['classes'])->toHaveCount(2)
            ->and($decoded['params']['topic'])->toBe('orders');
    });
});

describe('executeTrigger exception propagation', function (): void {
    test('executeTrigger marks log as failed and re-throws exception', function (): void {
        Trigger::factory()->create([
            'event' => 'fail.event',
            'action' => 'App\Actions\AlwaysFails',
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        // Register a failing action class dynamically in the container
        app()->bind('App\Actions\AlwaysFails', function () {
            return new class implements \ZeroBoiler\Events\Contracts\Triggerable {
                public function handle(array $payload): void
                {
                    throw new \RuntimeException('Deliberate failure for testing');
                }
            };
        });

        $trigger = Trigger::where('event', 'fail.event')->first();
        expect($trigger)->not->toBeNull();

        $eventManager = app(\ZeroBoiler\Events\EventManager::class);
        $log = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'fail.event',
            'payload' => [],
            'status' => EventLog::STATUS_PENDING,
        ]);
        $log->save();

        expect(fn () => $eventManager->executeTrigger($trigger, $log))
            ->toThrow(\RuntimeException::class, 'Deliberate failure for testing');

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toBe('Deliberate failure for testing');
    });

    test('executeTrigger marks log as completed on success', function (): void {
        Trigger::factory()->create([
            'event' => 'success.event',
            'action' => SendOrderNotification::class,
            'conditions' => null,
            'enabled' => true,
            'async' => false,
        ]);

        $trigger = Trigger::where('event', 'success.event')->first();
        $eventManager = app(\ZeroBoiler\Events\EventManager::class);
        $log = new EventLog([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'success.event',
            'payload' => ['test' => true],
            'status' => EventLog::STATUS_PENDING,
        ]);
        $log->save();

        $eventManager->executeTrigger($trigger, $log);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->duration_ms)->toBeInt()
            ->and($log->duration_ms)->toBeGreaterThanOrEqual(0);
    });
});

describe('EventManager fire with no triggers', function (): void {
    test('fire event with no matching triggers does nothing', function (): void {
        EventManager::fire('nonexistent.event', ['key' => 'value']);

        expect(EventLog::count())->toBe(0);
    });

    test('fire event with empty string does nothing', function (): void {
        EventManager::fire('', ['key' => 'value']);

        expect(EventLog::count())->toBe(0);
    });
});
