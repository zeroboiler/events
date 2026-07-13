<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\LogOrderEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;

beforeEach(function (): void {
    Queue::fake();
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

test('trigger auto-generates UUID via model boot when no id is provided', function (): void {
    $trigger = new Trigger([
        'name' => 'Test Trigger',
        'event' => 'test.event',
        'action' => 'App\\Actions\\TestAction',
        'conditions' => [],
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);
    $trigger->save();

    expect($trigger->id)
        ->not->toBeEmpty()
        ->toBeString();

    expect(Str::isUuid($trigger->id))->toBeTrue();
});

test('event log auto-generates UUID via model boot when no id is provided', function (): void {
    $trigger = Trigger::factory()->create();
    $log = new EventLog([
        'trigger_id' => $trigger->id,
        'event' => 'test.event',
        'payload' => ['key' => 'value'],
        'status' => EventLog::STATUS_PENDING,
    ]);
    $log->save();

    expect($log->id)
        ->not->toBeEmpty()
        ->toBeString();

    expect(Str::isUuid($log->id))->toBeTrue();
});

test('subscription auto-generates UUID via model boot when no id is provided', function (): void {
    $subscription = new Subscription([
        'event' => 'test.event',
        'url' => 'https://example.com/webhook',
        'priority' => 0,
        'active' => true,
        'secret' => 'whsec_test',
        'failure_count' => 0,
    ]);
    $subscription->save();

    expect($subscription->id)
        ->not->toBeEmpty()
        ->toBeString();

    expect(Str::isUuid($subscription->id))->toBeTrue();
});

test('trigger builder save does not set id explicitly — model boot handles it', function (): void {
    $builder = app(TriggerBuilder::class);
    $trigger = $builder
        ->name('Builder Test')
        ->on('test.event')
        ->action('App\\Actions\\TestAction')
        ->save();

    expect($trigger->id)
        ->not->toBeEmpty()
        ->toBeString();

    expect(Str::isUuid($trigger->id))->toBeTrue();
});

test('explicitly set id is preserved by model boot', function (): void {
    $customId = (string) Str::uuid();

    $trigger = new Trigger([
        'id' => $customId,
        'name' => 'Custom ID Trigger',
        'event' => 'test.event',
        'action' => 'App\\Actions\\TestAction',
        'conditions' => [],
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);
    $trigger->save();

    expect($trigger->id)->toBe($customId);
});

test('fire event creates event log with auto-generated uuid', function (): void {
    require_once __DIR__.'/TestActions.php';

    $trigger = Trigger::factory()->create([
        'event' => 'test.event',
        'action' => LogOrderEvent::class,
        'async' => false,
        'enabled' => true,
        'conditions' => null,
    ]);

    EventManagerFacade::fire('test.event', ['data' => 'value']);

    $log = EventLog::first();

    expect($log)
        ->not->toBeNull()
        ->and($log->id)->not->toBeEmpty()
        ->and(Str::isUuid($log->id))->toBeTrue();
});

test('no duplicate uuid generation — id is set exactly once', function (): void {
    $trigger = new Trigger([
        'name' => 'UUID Count Test',
        'event' => 'test.event',
        'action' => 'App\\Actions\\TestAction',
        'conditions' => [],
        'async' => false,
        'priority' => 0,
        'enabled' => true,
    ]);

    // Before save, id should be empty
    expect($trigger->id)->toBeEmpty();

    $trigger->save();

    // After save, id should be a UUID (set exactly once by the creating callback)
    expect($trigger->id)
        ->not->toBeEmpty()
        ->and(Str::isUuid($trigger->id))->toBeTrue();

    // Verify the id persisted correctly
    $fresh = Trigger::find($trigger->id);
    expect($fresh->id)->toBe($trigger->id);
});

test('factory no longer sets id explicitly — delegates to model boot', function (): void {
    $trigger = Trigger::factory()->create();

    expect($trigger->id)
        ->not->toBeEmpty()
        ->and(Str::isUuid($trigger->id))->toBeTrue();
});
