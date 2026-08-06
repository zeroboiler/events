<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

it('models use table_names from config by default', function (): void {
    config(['events.table_names.triggers' => 'triggers']);
    expect((new Trigger)->getTable())->toBe('triggers');

    config(['events.table_names.event_logs' => 'event_logs']);
    expect((new EventLog)->getTable())->toBe('event_logs');

    config(['events.table_names.subscriptions' => 'event_subscriptions']);
    expect((new Subscription)->getTable())->toBe('event_subscriptions');
});

it('models respect custom table_names from config', function (): void {
    config(['events.table_names.triggers' => 'custom_triggers']);
    expect((new Trigger)->getTable())->toBe('custom_triggers');

    config(['events.table_names.event_logs' => 'custom_logs']);
    expect((new EventLog)->getTable())->toBe('custom_logs');

    config(['events.table_names.subscriptions' => 'custom_subs']);
    expect((new Subscription)->getTable())->toBe('custom_subs');
});

it('models fall back to default table names when config is missing', function (): void {
    // Remove events config entirely
    config(['events' => null]);

    expect((new Trigger)->getTable())->toBe('triggers');
    expect((new EventLog)->getTable())->toBe('event_logs');
    expect((new Subscription)->getTable())->toBe('event_subscriptions');
});
