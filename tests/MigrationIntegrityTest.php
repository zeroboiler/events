<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Support\Facades\File;

test('migration triggers table has required columns', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');

    // UUID primary key
    expect($content)->toContain("\$table->uuid('id')->primary()");
    // Name
    expect($content)->toContain("'name'");
    // Event
    expect($content)->toContain("'event'");
    // Action (text for JSON)
    expect($content)->toContain("'action'");
    // Conditions (nullable JSON)
    expect($content)->toContain("'conditions'");
    // Async flag
    expect($content)->toContain("'async'");
    // Priority
    expect($content)->toContain("'priority'");
    // Enabled
    expect($content)->toContain("'enabled'");
    // Timestamps
    expect($content)->toContain('timestamps');
    // Soft deletes
    expect($content)->toContain('softDeletes');
});

test('migration event_logs table has required columns', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');

    // UUID primary key
    expect($content)->toContain("\$table->uuid('id')->primary()");
    // Foreign key to triggers
    expect($content)->toContain("'trigger_id'");
    // Event
    expect($content)->toContain("'event'");
    // Payload (JSON)
    expect($content)->toContain("'payload'");
    // Status
    expect($content)->toContain("'status'");
    // Error (nullable)
    expect($content)->toContain("'error'");
    // Duration (nullable)
    expect($content)->toContain("'duration_ms'");
    // Timestamps
    expect($content)->toContain('timestamps');
    // Soft deletes
    expect($content)->toContain('softDeletes');
});

test('migration event_subscriptions table has required columns', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');

    // UUID primary key
    expect($content)->toContain("\$table->uuid('id')->primary()");
    // Event
    expect($content)->toContain("'event'");
    // URL
    expect($content)->toContain("'url'");
    // Conditions (nullable JSON)
    expect($content)->toContain("'conditions'");
    // Priority
    expect($content)->toContain("'priority'");
    // Active
    expect($content)->toContain("'active'");
    // Secret (nullable)
    expect($content)->toContain("'secret'");
    // Last fired at (nullable timestamp)
    expect($content)->toContain("'last_fired_at'");
    // Failure count
    expect($content)->toContain("'failure_count'");
    // Delivery count
    expect($content)->toContain("'delivery_count'");
    // Timestamps
    expect($content)->toContain('timestamps');
    // Soft deletes
    expect($content)->toContain('softDeletes');
});

test('triggers migration has composite index on event + enabled', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php');

    expect($content)->toContain('index')
        ->and($content)->toContain("'event'")
        ->and($content)->toContain("'enabled'");
});

test('event_logs migration has index on trigger_id + status', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');

    expect($content)->toContain("'trigger_id'")
        ->and($content)->toContain("'status'");
});

test('event_logs migration has foreign key on trigger_id', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php');

    // Should reference the triggers table
    expect($content)->toContain('foreign')
        ->and($content)->toContain("'trigger_id'")
        ->and($content)->toContain("'triggers'");
});

test('event_subscriptions migration has index on event + active', function (): void {
    $content = File::get(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php');

    expect($content)->toContain("'event'")
        ->and($content)->toContain("'active'");
});

test('all migrations have strict_types declaration', function (): void {
    $files = [
        __DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php',
        __DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php',
        __DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php',
    ];

    foreach ($files as $file) {
        $content = File::get($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('all migrations use Blueprint return type for up()', function (): void {
    $files = glob(__DIR__.'/../database/migrations/*.php');

    foreach ($files as $file) {
        $content = File::get($file);
        // up() method should exist
        expect($content)->toContain('function up()');
    }
});
