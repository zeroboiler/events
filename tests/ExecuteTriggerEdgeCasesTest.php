<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\LogOrderEvent;
use App\Actions\SendOrderNotification;
use Illuminate\Support\Str;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

describe('ActionResolver edge cases', function (): void {
    it('rejects non-existent class', function (): void {
        $resolver = app(ActionResolver::class);
        $resolver->resolve('App\\Actions\\NonExistentClass');
    })->throws(\InvalidArgumentException::class, 'does not exist');

    it('rejects class that does not implement Triggerable', function (): void {
        // Temporarily bind a non-Triggerable class
        $this->app->bind(\stdClass::class);
        $resolver = app(ActionResolver::class);
        $resolver->resolve(\stdClass::class);
    })->throws(\InvalidArgumentException::class, 'must implement');
});

describe('EventManager executeTrigger edge cases', function (): void {
    it('completes successfully when trigger action string is empty', function (): void {
        // Create a trigger with an empty action string
        $trigger = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Empty Action Test',
            'event' => 'test.empty-action-trigger',
            'action' => '',
            'conditions' => [],
            'async' => false,
            'priority' => 0,
            'enabled' => true,
        ]);

        $log = EventLog::create([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.empty-action-trigger',
            'payload' => ['key' => 'value'],
            'status' => EventLog::STATUS_PENDING,
        ]);

        $eventManager = app(EventManager::class);

        // Should not throw — empty action means no handlers to execute
        $eventManager->executeTrigger($trigger, $log);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
        expect($log->duration_ms)->toBeInt();
        expect($log->duration_ms)->toBeGreaterThanOrEqual(0);
    });

    it('completes successfully when trigger action is whitespace-only', function (): void {
        $trigger = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Whitespace Action Test',
            'event' => 'test.whitespace-action',
            'action' => '   ',
            'conditions' => [],
            'async' => false,
            'priority' => 0,
            'enabled' => true,
        ]);

        $log = EventLog::create([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.whitespace-action',
            'payload' => ['key' => 'value'],
            'status' => EventLog::STATUS_PENDING,
        ]);

        $eventManager = app(EventManager::class);
        $eventManager->executeTrigger($trigger, $log);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    it('marks log as failed when action class cannot be resolved', function (): void {
        $trigger = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Bad Action Test',
            'event' => 'test.bad-action',
            'action' => 'NonExistent\\ActionClass',
            'conditions' => [],
            'async' => false,
            'priority' => 0,
            'enabled' => true,
        ]);

        $log = EventLog::create([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.bad-action',
            'payload' => ['key' => 'value'],
            'status' => EventLog::STATUS_PENDING,
        ]);

        $eventManager = app(EventManager::class);

        // Should throw because the action class doesn't exist
        expect(fn () => $eventManager->executeTrigger($trigger, $log))
            ->toThrow(\InvalidArgumentException::class);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_FAILED);
        expect($log->error)->toBeString();
        expect($log->error)->not->toBeEmpty();
    });

    it('dispatches multiple action handlers in sequence', function (): void {
        $trigger = Trigger::create([
            'id' => (string) Str::uuid(),
            'name' => 'Multi Action Test',
            'event' => 'test.multi-action',
            'action' => json_encode([
                SendOrderNotification::class,
                LogOrderEvent::class,
            ]),
            'conditions' => [],
            'async' => false,
            'priority' => 0,
            'enabled' => true,
        ]);

        $log = EventLog::create([
            'id' => (string) Str::uuid(),
            'trigger_id' => $trigger->id,
            'event' => 'test.multi-action',
            'payload' => ['order_id' => 42],
            'status' => EventLog::STATUS_PENDING,
        ]);

        $eventManager = app(EventManager::class);
        $eventManager->executeTrigger($trigger, $log);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
    });
});
