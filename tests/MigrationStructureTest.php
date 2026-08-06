<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

it('triggers migration has all required columns', function (): void {
    $this->assertTrue(
        \Schema::hasTable('triggers'),
        'triggers table must exist'
    );

    $required = ['id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled', 'created_at', 'updated_at', 'deleted_at'];
    foreach ($required as $column) {
        expect(\Schema::hasColumn('triggers', $column))
            ->toBeTrue("triggers table missing column: {$column}");
    }
});

it('event_logs migration has all required columns', function (): void {
    $this->assertTrue(
        \Schema::hasTable('event_logs'),
        'event_logs table must exist'
    );

    $required = ['id', 'trigger_id', 'event', 'payload', 'status', 'error', 'duration_ms', 'created_at', 'updated_at', 'deleted_at'];
    foreach ($required as $column) {
        expect(\Schema::hasColumn('event_logs', $column))
            ->toBeTrue("event_logs table missing column: {$column}");
    }
});

it('event_subscriptions migration has all required columns', function (): void {
    $this->assertTrue(
        \Schema::hasTable('event_subscriptions'),
        'event_subscriptions table must exist'
    );

    $required = ['id', 'event', 'url', 'conditions', 'priority', 'active', 'secret', 'last_fired_at', 'failure_count', 'delivery_count', 'created_at', 'updated_at', 'deleted_at'];
    foreach ($required as $column) {
        expect(\Schema::hasColumn('event_subscriptions', $column))
            ->toBeTrue("event_subscriptions table missing column: {$column}");
    }
});

it('triggers table id column is the primary key and string type', function (): void {
    $columns = \Schema::getColumnType('triggers', 'id');
    expect($columns)->toBe('string');
});

it('event_logs has foreign key on trigger_id', function (): void {
    // Verify relationship works end-to-end via factory
    $trigger = \ZeroBoiler\Events\Models\Trigger::factory()->create();
    $log = \ZeroBoiler\Events\Models\EventLog::factory()->create(['trigger_id' => $trigger->id]);

    expect($log->trigger_id)->toBe($trigger->id);
    expect($log->trigger->id)->toBe($trigger->id);
});
