<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

describe('Migrations are config-driven', function (): void {
    test('triggers table exists with default config name', function (): void {
        $tableName = config('events.table_names.triggers', 'triggers');

        expect(\Schema::hasTable($tableName))->toBeTrue(
            "Table '{$tableName}' must exist"
        );
    });

    test('event_logs table exists with default config name', function (): void {
        $tableName = config('events.table_names.event_logs', 'event_logs');

        expect(\Schema::hasTable($tableName))->toBeTrue(
            "Table '{$tableName}' must exist"
        );
    });

    test('event_subscriptions table exists with default config name', function (): void {
        $tableName = config('events.table_names.subscriptions', 'event_subscriptions');

        expect(\Schema::hasTable($tableName))->toBeTrue(
            "Table '{$tableName}' must exist"
        );
    });

    test('migrations read table names from config', function (): void {
        $migrationsDir = __DIR__.'/../database/migrations';

        $triggersMigration = file_get_contents($migrationsDir.'/2024_01_01_000001_create_triggers_table.php');
        $logsMigration = file_get_contents($migrationsDir.'/2024_01_01_000002_create_event_logs_table.php');
        $subsMigration = file_get_contents($migrationsDir.'/2025_06_28_000001_create_event_subscriptions_table.php');

        // Verify migrations use config() instead of hardcoded table names
        expect($triggersMigration)->toContain("config('events.table_names.triggers'");
        expect($logsMigration)->toContain("config('events.table_names.event_logs'");
        expect($subsMigration)->toContain("config('events.table_names.subscriptions'");
    });

    test('event_logs foreign key references triggers table from config', function (): void {
        $migrationsDir = __DIR__.'/../database/migrations';

        $logsMigration = file_get_contents($migrationsDir.'/2024_01_01_000002_create_event_logs_table.php');

        expect($logsMigration)->toContain("config('events.table_names.triggers'");
    });
});
