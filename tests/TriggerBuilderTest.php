<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 *
 * Regression tests for issue #6: TriggerBuilder loses multiple actions.
 *
 * Covers:
 * - Bug 1: actions() is no longer ignored when action() is also set
 * - Bug 2: single save (no double INSERT→UPDATE)
 * - Bug 3: parseActions correctly handles all storage formats
 */

declare(strict_types=1);

use App\Actions\LogOrderCreated;
use App\Actions\LogOrderEvent;
use App\Actions\SendOrderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
});

describe('TriggerBuilder actions handling', function (): void {
    test('actions() with multiple classes stores JSON array', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->name('Multi Action Trigger')
            ->on('order.placed')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)
            ->toBeArray()
            ->toHaveCount(2)
            ->and($decoded[0])->toBe(SendOrderNotification::class)
            ->and($decoded[1])->toBe(LogOrderEvent::class);
    });

    test('actions() with single class stores plain string (not JSON)', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('order.placed')
            ->actions([SendOrderNotification::class])
            ->save();

        // Single action should be stored as a plain class name string,
        // not wrapped in a JSON array.
        expect($trigger->action)->toBe(SendOrderNotification::class);
    });

    test('action() and actions() together merge without data loss', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('order.placed')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class, LogOrderCreated::class])
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)
            ->toBeArray()
            ->toHaveCount(3)
            ->and($decoded)->toContain(SendOrderNotification::class)
            ->and($decoded)->toContain(LogOrderEvent::class)
            ->and($decoded)->toContain(LogOrderCreated::class);
    });

    test('action() value not duplicated when also present in actions()', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('order.placed')
            ->action(SendOrderNotification::class)
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $decoded = json_decode($trigger->action, true);

        // The single action() value should not be duplicated in the result.
        expect($decoded)
            ->toBeArray()
            ->toHaveCount(2)
            ->and($decoded[0])->toBe(SendOrderNotification::class)
            ->and($decoded[1])->toBe(LogOrderEvent::class);
    });

    test('action() alone stores plain string', function (): void {
        $builder = app(TriggerBuilder::class);
        $trigger = $builder
            ->on('order.placed')
            ->action(SendOrderNotification::class)
            ->save();

        expect($trigger->action)->toBe(SendOrderNotification::class);
    });
});

describe('TriggerBuilder single save (no double INSERT)', function (): void {
    test('save() issues exactly one INSERT query', function (): void {
        $builder = app(TriggerBuilder::class);

        DB::enableQueryLog();

        $trigger = $builder
            ->on('order.placed')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $insertQueries = array_filter(
            $queries,
            fn (array $q): bool => str_starts_with(strtolower(trim($q['query'])), 'insert into'),
        );

        expect($insertQueries)
            ->toHaveCount(1, 'TriggerBuilder::save() should issue exactly one INSERT query');
    });

    test('save() with actionParams issues exactly one INSERT query', function (): void {
        $builder = app(TriggerBuilder::class);

        DB::enableQueryLog();

        $trigger = $builder
            ->on('order.placed')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->actionParams(['webhook_url' => 'https://example.com/hook'])
            ->save();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $insertQueries = array_filter(
            $queries,
            fn (array $q): bool => str_starts_with(strtolower(trim($q['query'])), 'insert into'),
        );

        expect($insertQueries)
            ->toHaveCount(1, 'TriggerBuilder::save() with actionParams should issue exactly one INSERT query');
    });
});

describe('TriggerBuilder with actionParams', function (): void {
    test('multiple actions with params stored as JSON object with classes key', function (): void {
        $builder = app(TriggerBuilder::class);
        $params = ['webhook_url' => 'https://example.com/hook'];
        $trigger = $builder
            ->on('order.placed')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->actionParams($params)
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)
            ->toBeArray()
            ->toHaveKey('classes')
            ->toHaveKey('params')
            ->and($decoded['classes'])->toBe([SendOrderNotification::class, LogOrderEvent::class])
            ->and($decoded['params'])->toBe($params);
    });

    test('single action with params stored as JSON object with class key', function (): void {
        $builder = app(TriggerBuilder::class);
        $params = ['webhook_url' => 'https://example.com/hook'];
        $trigger = $builder
            ->on('order.placed')
            ->action(SendOrderNotification::class)
            ->actionParams($params)
            ->save();

        $decoded = json_decode($trigger->action, true);

        expect($decoded)
            ->toBeArray()
            ->toHaveKey('class')
            ->toHaveKey('params')
            ->and($decoded['class'])->toBe(SendOrderNotification::class)
            ->and($decoded['params'])->toBe($params);
    });
});

describe('parseActions round-trip via fire()', function (): void {
    test('multiple actions trigger fires successfully on event', function (): void {
        $builder = app(TriggerBuilder::class);
        $builder
            ->on('order.placed')
            ->actions([SendOrderNotification::class, LogOrderEvent::class])
            ->save();

        EventManagerFacade::fire('order.placed', ['order_id' => 123]);

        // One trigger → one EventLog, but all actions within it are executed.
        $logs = EventLog::where('event', 'order.placed')->get();
        expect($logs)->toHaveCount(1)
            ->and($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });

    test('merged action() + actions() trigger fires successfully on event', function (): void {
        $builder = app(TriggerBuilder::class);
        $builder
            ->on('order.created')
            ->action(SendOrderNotification::class)
            ->actions([LogOrderEvent::class, LogOrderCreated::class])
            ->save();

        EventManagerFacade::fire('order.created', ['order_id' => 456]);

        // One trigger with 3 merged actions → one completed EventLog.
        $logs = EventLog::where('event', 'order.created')->get();
        expect($logs)->toHaveCount(1)
            ->and($logs->first()->status)->toBe(EventLog::STATUS_COMPLETED);
    });
});
