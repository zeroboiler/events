<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
});

test('trigger can be created', function (): void {
    $trigger = Trigger::factory()->create();

    expect($trigger->id)->not->toBeNull()
        ->and($trigger->name)->not->toBeEmpty()
        ->and($trigger->event)->not->toBeEmpty()
        ->and($trigger->action)->not->toBeEmpty();
});

test('trigger casts conditions to array', function (): void {
    $conditions = ['amount' => ['>', 100], 'status' => 'paid'];
    $trigger = Trigger::factory()->create(['conditions' => $conditions]);

    expect($trigger->conditions)->toBeArray()
        ->and($trigger->conditions)->toBe($conditions);
});

test('trigger casts async to boolean', function (): void {
    $trigger = Trigger::factory()->async()->create();

    expect($trigger->async)->toBeTrue();

    $syncTrigger = Trigger::factory()->sync()->create();

    expect($syncTrigger->async)->toBeFalse();
});

test('trigger casts enabled to boolean', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    expect($trigger->enabled)->toBeTrue();

    $disabledTrigger = Trigger::factory()->disabled()->create();

    expect($disabledTrigger->enabled)->toBeFalse();
});

test('trigger casts priority to integer', function (): void {
    $trigger = Trigger::factory()->priority(42)->create();

    expect($trigger->priority)->toBeInt()
        ->and($trigger->priority)->toBe(42);
});

test('trigger has many event logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->count(3)->create(['trigger_id' => $trigger->id]);

    expect($trigger->eventLogs)->toHaveCount(3);
});

test('scope enabled returns only enabled triggers', function (): void {
    Trigger::factory()->enabled()->count(3)->create();
    Trigger::factory()->disabled()->count(2)->create();

    $enabledTriggers = Trigger::enabled()->get();

    expect($enabledTriggers)->toHaveCount(3);
});

test('scope async returns only async triggers', function (): void {
    Trigger::factory()->async()->count(3)->create();
    Trigger::factory()->sync()->count(2)->create();

    $asyncTriggers = Trigger::async()->get();

    expect($asyncTriggers)->toHaveCount(3);
});

test('scope order by priority sorts by priority descending', function (): void {
    Trigger::factory()->priority(10)->create(['name' => 'Low']);
    Trigger::factory()->priority(100)->create(['name' => 'High']);
    Trigger::factory()->priority(50)->create(['name' => 'Medium']);

    $triggers = Trigger::orderByPriority()->get();

    expect($triggers[0]->name)->toBe('High')
        ->and($triggers[1]->name)->toBe('Medium')
        ->and($triggers[2]->name)->toBe('Low');
});

test('trigger is soft deleted', function (): void {
    $trigger = Trigger::factory()->create();

    $trigger->delete();

    expect(Trigger::find($trigger->id))->toBeNull()
        ->and(Trigger::withTrashed()->find($trigger->id))->not->toBeNull();
});

test('trigger factory generates valid data', function (): void {
    $trigger = Trigger::factory()->make();

    expect($trigger->name)->not->toBeEmpty()
        ->and($trigger->event)->toBeString()
        ->and($trigger->action)->toBeString();
});

test('trigger can be updated', function (): void {
    $trigger = Trigger::factory()->create();

    $trigger->update(['name' => 'Updated Trigger', 'priority' => 99]);

    $trigger->refresh();

    expect($trigger->name)->toBe('Updated Trigger')
        ->and($trigger->priority)->toBe(99);
});

test('trigger can store multiple actions as JSON', function (): void {
    $actions = [\ZeroBoiler\Events\Tests\Actions\ActionOne', \ZeroBoiler\Events\Tests\Actions\ActionTwo'];
    $trigger = Trigger::factory()->create([
        'action' => json_encode($actions),
    ]);

    expect($trigger->action)->toBe(json_encode($actions));
});
