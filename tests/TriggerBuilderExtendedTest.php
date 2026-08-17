<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\Actions\HighPriority;
use ZeroBoiler\Events\Tests\Actions\LogOrderCreated;
use ZeroBoiler\Events\Tests\Actions\LogOrderEvent;
use ZeroBoiler\Events\Tests\Actions\SendOrderNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;

// Load test action classes (ZeroBoiler\Events\Tests\Actions namespace)

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
});

it('saves a trigger with minimal config (event + action)', function (): void {
    $trigger = EventManagerFacade::on('user.created')
        ->action(SendOrderNotification::class)
        ->save();

    expect($trigger)->toBeInstanceOf(Trigger::class)
        ->and($trigger->exists)->toBeTrue()
        ->and($trigger->event)->toBe('user.created')
        ->and($trigger->action)->toBe(SendOrderNotification::class)
        ->and($trigger->enabled)->toBeTrue()
        ->and($trigger->async)->toBeFalse()
        ->and($trigger->priority)->toBe(0);
});

it('auto-generates name from event when not provided', function (): void {
    $trigger = EventManagerFacade::on('order.shipped')
        ->action(LogOrderEvent::class)
        ->save();

    expect($trigger->name)->toBe('order.shipped Trigger');
});

it('uses custom name when provided', function (): void {
    $trigger = EventManagerFacade::on('order.shipped')
        ->name('Custom Trigger Name')
        ->action(LogOrderEvent::class)
        ->save();

    expect($trigger->name)->toBe('Custom Trigger Name');
});

it('throws when event name is empty', function (): void {
    EventManagerFacade::on('')
        ->action(LogOrderEvent::class)
        ->save();
})->throws(InvalidArgumentException::class, 'Event name is required');

it('throws when no action is provided', function (): void {
    EventManagerFacade::on('test.event')->save();
})->throws(InvalidArgumentException::class, 'At least one action is required');

it('saves trigger with conditions', function (): void {
    $conditions = ['status' => 'active', 'amount' => ['operator' => '>', 'value' => 100]];

    $trigger = EventManagerFacade::on('order.placed')
        ->action(LogOrderEvent::class)
        ->when($conditions)
        ->save();

    expect($trigger->conditions)->toBe($conditions);
});

it('saves trigger with async and priority', function (): void {
    $trigger = EventManagerFacade::on('email.sent')
        ->action(SendOrderNotification::class)
        ->async()
        ->priority(10)
        ->save();

    expect($trigger->async)->toBeTrue()
        ->and($trigger->priority)->toBe(10);
});

it('saves trigger with action params as JSON', function (): void {
    $params = ['url' => 'https://example.com/webhook', 'timeout' => 30];

    $trigger = EventManagerFacade::on('webhook.event')
        ->action(SendOrderNotification::class)
        ->actionParams($params)
        ->save();

    $decoded = json_decode($trigger->action, true);

    expect($decoded)->toBeArray()
        ->and($decoded['class'])->toBe(SendOrderNotification::class)
        ->and($decoded['params'])->toBe($params);
});

it('saves trigger with multiple actions as JSON array', function (): void {
    $trigger = EventManagerFacade::on('multi.event')
        ->actions([LogOrderEvent::class, SendOrderNotification::class])
        ->save();

    $decoded = json_decode($trigger->action, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveCount(2)
        ->and($decoded[0])->toBe(LogOrderEvent::class)
        ->and($decoded[1])->toBe(SendOrderNotification::class);
});

it('saves trigger with multiple actions AND params using classes key', function (): void {
    $params = ['url' => 'https://example.com'];

    $trigger = EventManagerFacade::on('multi.params')
        ->actions([LogOrderEvent::class, SendOrderNotification::class])
        ->actionParams($params)
        ->save();

    $decoded = json_decode($trigger->action, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['classes', 'params'])
        ->and($decoded['classes'])->toBe([LogOrderEvent::class, SendOrderNotification::class])
        ->and($decoded['params'])->toBe($params);
});

it('merges single action() and actions() when both are called', function (): void {
    $trigger = EventManagerFacade::on('merge.event')
        ->action(SendOrderNotification::class)
        ->actions([LogOrderEvent::class, HighPriority::class])
        ->save();

    $decoded = json_decode($trigger->action, true);

    // Single action is prepended (if not already in the list)
    expect($decoded)->toBeArray()
        ->and($decoded[0])->toBe(SendOrderNotification::class)
        ->and($decoded[1])->toBe(LogOrderEvent::class)
        ->and($decoded[2])->toBe(HighPriority::class);
});

it('does not duplicate action when action() and actions() have same class', function (): void {
    $trigger = EventManagerFacade::on('dedup.event')
        ->action(LogOrderEvent::class)
        ->actions([LogOrderEvent::class, HighPriority::class])
        ->save();

    $decoded = json_decode($trigger->action, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveCount(2)
        ->and($decoded[0])->toBe(LogOrderEvent::class)
        ->and($decoded[1])->toBe(HighPriority::class);
});

it('generates unique UUID id for each trigger', function (): void {
    $t1 = EventManagerFacade::on('event.one')->action(LogOrderEvent::class)->save();
    $t2 = EventManagerFacade::on('event.two')->action(LogOrderEvent::class)->save();

    expect($t1->id)->not->toBe($t2->id);
});

it('register() is alias for on()', function (): void {
    $manager = app(EventManager::class);

    $builder1 = $manager->on('alias.test');
    $builder2 = $manager->register('alias.test');

    expect($builder1)->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder2)->toBeInstanceOf(TriggerBuilder::class);
});

it('invalidates trigger cache on save', function (): void {
    Cache::put('zeroboiler:events:enabled_wildcard_triggers', 'stale', 300);

    EventManagerFacade::on('cached.event')
        ->action(LogOrderEvent::class)
        ->save();

    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

it('correctly formats single action with params (class key not classes)', function (): void {
    $trigger = EventManagerFacade::on('single.params')
        ->action(LogOrderCreated::class)
        ->actionParams(['topic' => 'orders'])
        ->save();

    $decoded = json_decode($trigger->action, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('class')
        ->and($decoded)->not->toHaveKey('classes')
        ->and($decoded['class'])->toBe(LogOrderCreated::class)
        ->and($decoded['params'])->toBe(['topic' => 'orders']);
});
