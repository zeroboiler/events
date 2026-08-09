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

test('event log can be created', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create(['trigger_id' => $trigger->id]);

    expect($log->id)->not->toBeNull()
        ->and($log->event)->not->toBeEmpty()
        ->and($log->payload)->toBeArray();
});

test('event log casts payload to array', function (): void {
    $payload = ['order_id' => 123, 'amount' => 99.99];
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'payload' => $payload,
    ]);

    expect($log->payload)->toBeArray()
        ->and($log->payload)->toBe($payload);
});

test('event log casts duration_ms to integer', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->completed()->create([
        'trigger_id' => $trigger->id,
    ]);

    expect($log->duration_ms)->toBeInt();
});

test('event log belongs to trigger', function (): void {
    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create(['trigger_id' => $trigger->id]);

    expect($log->trigger)->not->toBeNull()
        ->and($log->trigger->id)->toBe($trigger->id);
});

test('scope with status filters logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->completed()->count(3)->create(['trigger_id' => $trigger->id]);
    EventLog::factory()->failed()->count(2)->create(['trigger_id' => $trigger->id]);

    $completedLogs = EventLog::withStatus(EventLog::STATUS_COMPLETED)->get();
    $failedLogs = EventLog::withStatus(EventLog::STATUS_FAILED)->get();

    expect($completedLogs)->toHaveCount(3)
        ->and($failedLogs)->toHaveCount(2);
});

test('scope failed returns only failed logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->completed()->count(3)->create(['trigger_id' => $trigger->id]);
    EventLog::factory()->failed()->count(2)->create(['trigger_id' => $trigger->id]);

    $failedLogs = EventLog::failed()->get();

    expect($failedLogs)->toHaveCount(2)
        ->and($failedLogs->every(fn ($log): bool => $log->status === EventLog::STATUS_FAILED))->toBeTrue();
});

test('scope pending returns only pending logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->pending()->count(3)->create(['trigger_id' => $trigger->id]);
    EventLog::factory()->completed()->count(2)->create(['trigger_id' => $trigger->id]);

    $pendingLogs = EventLog::pending()->get();

    expect($pendingLogs)->toHaveCount(3)
        ->and($pendingLogs->every(fn ($log): bool => $log->status === EventLog::STATUS_PENDING))->toBeTrue();
});

test('scope completed returns only completed logs', function (): void {
    $trigger = Trigger::factory()->create();
    EventLog::factory()->completed()->count(3)->create(['trigger_id' => $trigger->id]);
    EventLog::factory()->failed()->count(2)->create(['trigger_id' => $trigger->id]);

    $completedLogs = EventLog::completed()->get();

    expect($completedLogs)->toHaveCount(3)
        ->and($completedLogs->every(fn ($log): bool => $log->status === EventLog::STATUS_COMPLETED))->toBeTrue();
});

test('mark as completed updates status and duration', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsCompleted(250);

    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->duration_ms)->toBe(250);
});

test('mark as failed updates status and error', function (): void {
    $log = EventLog::factory()->pending()->create();

    $log->markAsFailed('Something went wrong');

    $log->refresh();

    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->toBe('Something went wrong');
});

test('event log is soft deleted', function (): void {
    $log = EventLog::factory()->create();

    $log->delete();

    expect(EventLog::find($log->id))->toBeNull()
        ->and(EventLog::withTrashed()->find($log->id))->not->toBeNull();
});

test('event log factory generates valid data', function (): void {
    $log = EventLog::factory()->make();

    expect($log->event)->toBeString()
        ->and($log->payload)->toBeArray()
        ->and($log->status)->toBeIn(EventLog::$statuses);
});

test('event log factory pending state creates pending log', function (): void {
    $log = EventLog::factory()->pending()->make();

    expect($log->status)->toBe(EventLog::STATUS_PENDING);
});

test('event log factory dispatched state creates dispatched log', function (): void {
    $log = EventLog::factory()->dispatched()->make();

    expect($log->status)->toBe(EventLog::STATUS_DISPATCHED);
});

test('event log factory completed state creates completed log', function (): void {
    $log = EventLog::factory()->completed()->make();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->duration_ms)->not->toBeNull()
        ->and($log->error)->toBeNull();
});

test('event log factory failed state creates failed log', function (): void {
    $log = EventLog::factory()->failed()->make();

    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->not->toBeNull();
});

test('event log can store complex payload', function (): void {
    $payload = [
        'user' => [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ],
        'order' => [
            'id' => 123,
            'items' => [
                ['sku' => 'ABC123', 'qty' => 2],
                ['sku' => 'DEF456', 'qty' => 1],
            ],
            'total' => 149.99,
        ],
    ];

    $trigger = Trigger::factory()->create();
    $log = EventLog::factory()->create([
        'trigger_id' => $trigger->id,
        'payload' => $payload,
    ]);

    expect($log->payload['user']['name'])->toBe('John Doe')
        ->and($log->payload['order']['total'])->toBe(149.99);
});

test('event log casts error to string', function (): void {
    $log = EventLog::factory()->failed()->create();

    expect($log->error)->toBeString()
        ->and($log->error)->not->toBeEmpty();
});

test('event log error is null for non-failed logs', function (): void {
    $log = EventLog::factory()->completed()->create();

    expect($log->error)->toBeNull();
});
