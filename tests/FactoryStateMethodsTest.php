<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Database\Factories\EventLogFactory;
use ZeroBoiler\Events\Database\Factories\SubscriptionFactory;
use ZeroBoiler\Events\Database\Factories\TriggerFactory;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Verify factory state methods produce correct attribute overrides.
 *
 * These tests validate that each factory state method properly
 * overrides the default definition values. They do NOT require
 * database persistence — they only check the raw() output.
 */
test('TriggerFactory::withName overrides the name', function () {
    $factory = TriggerFactory::new()->withName('Custom Trigger Name');
    $attrs = $factory->raw();

    expect($attrs['name'])->toBe('Custom Trigger Name');
});

test('TriggerFactory::withPriority overrides priority', function () {
    $factory = TriggerFactory::new()->withPriority(42);
    $attrs = $factory->raw();

    expect($attrs['priority'])->toBe(42);
});

test('TriggerFactory::forEvent overrides event', function () {
    $factory = TriggerFactory::new()->forEvent('order.placed');
    $attrs = $factory->raw();

    expect($attrs['event'])->toBe('order.placed');
});

test('TriggerFactory::withAction overrides action', function () {
    $factory = TriggerFactory::new()->withAction('App\\Actions\\CustomAction');
    $attrs = $factory->raw();

    expect($attrs['action'])->toBe('App\\Actions\\CustomAction');
});

test('TriggerFactory::withConditions overrides conditions', function () {
    $factory = TriggerFactory::new()->withConditions(['status' => 'active']);
    $attrs = $factory->raw();

    expect($attrs['conditions'])->toBe(['status' => 'active']);
});

test('TriggerFactory::enabled sets enabled to true', function () {
    $factory = TriggerFactory::new()->enabled();
    $attrs = $factory->raw();

    expect($attrs['enabled'])->toBeTrue();
});

test('TriggerFactory::disabled sets enabled to false', function () {
    $factory = TriggerFactory::new()->disabled();
    $attrs = $factory->raw();

    expect($attrs['enabled'])->toBeFalse();
});

test('TriggerFactory::async sets async to true', function () {
    $factory = TriggerFactory::new()->async();
    $attrs = $factory->raw();

    expect($attrs['async'])->toBeTrue();
});

test('TriggerFactory::sync sets async to false', function () {
    $factory = TriggerFactory::new()->sync();
    $attrs = $factory->raw();

    expect($attrs['async'])->toBeFalse();
});

test('EventLogFactory::withEvent overrides event', function () {
    $factory = EventLogFactory::new()->withEvent('user.created');
    $attrs = $factory->raw();

    expect($attrs['event'])->toBe('user.created');
});

test('EventLogFactory::forTrigger overrides trigger_id', function () {
    $id = (string) \Illuminate\Support\Str::uuid();
    $factory = EventLogFactory::new()->forTrigger($id);
    $attrs = $factory->raw();

    expect($attrs['trigger_id'])->toBe($id);
});

test('EventLogFactory::withPayload overrides payload', function () {
    $payload = ['key' => 'value', 'nested' => ['a' => 1]];
    $factory = EventLogFactory::new()->withPayload($payload);
    $attrs = $factory->raw();

    expect($attrs['payload'])->toBe($payload);
});

test('EventLogFactory::withDuration sets completed status and duration', function () {
    $factory = EventLogFactory::new()->withDuration(250);
    $attrs = $factory->raw();

    expect($attrs['duration_ms'])->toBe(250);
    expect($attrs['status'])->toBe(EventLog::STATUS_COMPLETED);
    expect($attrs['error'])->toBeNull();
});

test('EventLogFactory::pending sets status to pending', function () {
    $factory = EventLogFactory::new()->pending();
    $attrs = $factory->raw();

    expect($attrs['status'])->toBe(EventLog::STATUS_PENDING);
});

test('EventLogFactory::dispatched sets status to dispatched', function () {
    $factory = EventLogFactory::new()->dispatched();
    $attrs = $factory->raw();

    expect($attrs['status'])->toBe(EventLog::STATUS_DISPATCHED);
});

test('EventLogFactory::completed sets status to completed with duration and null error', function () {
    $factory = EventLogFactory::new()->completed();
    $attrs = $factory->raw();

    expect($attrs['status'])->toBe(EventLog::STATUS_COMPLETED);
    expect($attrs['error'])->toBeNull();
    expect($attrs['duration_ms'])->toBeInt();
});

test('EventLogFactory::failed sets status to failed with error message', function () {
    $factory = EventLogFactory::new()->failed();
    $attrs = $factory->raw();

    expect($attrs['status'])->toBe(EventLog::STATUS_FAILED);
    expect($attrs['error'])->toBeString();
    expect($attrs['error'])->not->toBeEmpty();
});

test('SubscriptionFactory::withPriority overrides priority', function () {
    $factory = SubscriptionFactory::new()->withPriority(99);
    $attrs = $factory->raw();

    expect($attrs['priority'])->toBe(99);
});

test('SubscriptionFactory::withFailureCount overrides failure_count', function () {
    $factory = SubscriptionFactory::new()->withFailureCount(5);
    $attrs = $factory->raw();

    expect($attrs['failure_count'])->toBe(5);
});

test('SubscriptionFactory::withDeliveryCount overrides delivery_count', function () {
    $factory = SubscriptionFactory::new()->withDeliveryCount(42);
    $attrs = $factory->raw();

    expect($attrs['delivery_count'])->toBe(42);
});

test('SubscriptionFactory::withoutSecret sets secret to null', function () {
    $factory = SubscriptionFactory::new()->withoutSecret();
    $attrs = $factory->raw();

    expect($attrs['secret'])->toBeNull();
});

test('SubscriptionFactory::active sets active to true', function () {
    $factory = SubscriptionFactory::new()->active();
    $attrs = $factory->raw();

    expect($attrs['active'])->toBeTrue();
});

test('SubscriptionFactory::inactive sets active to false', function () {
    $factory = SubscriptionFactory::new()->inactive();
    $attrs = $factory->raw();

    expect($attrs['active'])->toBeFalse();
});

test('SubscriptionFactory::forEvent overrides event', function () {
    $factory = SubscriptionFactory::new()->forEvent('payment.*');
    $attrs = $factory->raw();

    expect($attrs['event'])->toBe('payment.*');
});

test('SubscriptionFactory::withUrl overrides url', function () {
    $factory = SubscriptionFactory::new()->withUrl('https://example.com/webhook');
    $attrs = $factory->raw();

    expect($attrs['url'])->toBe('https://example.com/webhook');
});

test('SubscriptionFactory::withSecret overrides secret', function () {
    $factory = SubscriptionFactory::new()->withSecret('whsec_custom_secret_value');
    $attrs = $factory->raw();

    expect($attrs['secret'])->toBe('whsec_custom_secret_value');
});

test('SubscriptionFactory::withConditions overrides conditions', function () {
    $conditions = ['amount' => ['>', 100]];
    $factory = SubscriptionFactory::new()->withConditions($conditions);
    $attrs = $factory->raw();

    expect($attrs['conditions'])->toBe($conditions);
});

test('TriggerFactory default definition produces valid attributes', function () {
    $attrs = TriggerFactory::new()->raw();

    expect($attrs)->toHaveKeys(['id', 'name', 'event', 'action', 'conditions', 'async', 'priority', 'enabled']);
    expect($attrs['id'])->toBeString();
    expect($attrs['event'])->toBeString();
    expect($attrs['action'])->toBeString();
    expect($attrs['priority'])->toBeInt();
    expect($attrs['enabled'])->toBeBool();
    expect($attrs['async'])->toBeBool();
});

test('EventLogFactory default definition produces valid attributes', function () {
    $attrs = EventLogFactory::new()->raw();

    expect($attrs)->toHaveKeys(['id', 'trigger_id', 'event', 'payload', 'status', 'error', 'duration_ms']);
    expect($attrs['id'])->toBeString();
    expect($attrs['event'])->toBeString();
    expect($attrs['payload'])->toBeArray();
    expect($attrs['status'])->toBeIn(EventLog::$statuses);
});

test('SubscriptionFactory default definition produces valid attributes', function () {
    $attrs = SubscriptionFactory::new()->raw();

    expect($attrs)->toHaveKeys([
        'id', 'event', 'url', 'conditions', 'priority',
        'active', 'secret', 'last_fired_at', 'failure_count', 'delivery_count',
    ]);
    expect($attrs['id'])->toBeString();
    expect($attrs['event'])->toBeString();
    expect($attrs['url'])->toBeString();
    expect($attrs['active'])->toBeTrue();
    expect($attrs['secret'])->toBeString();
    expect($attrs['secret'])->toStartWith('whsec_');
    expect($attrs['failure_count'])->toBe(0);
    expect($attrs['delivery_count'])->toBe(0);
});

test('TriggerFactory chained state methods compose correctly', function () {
    $factory = TriggerFactory::new()
        ->forEvent('order.*')
        ->withName('Order Wildcard')
        ->withPriority(50)
        ->enabled()
        ->async();
    $attrs = $factory->raw();

    expect($attrs['event'])->toBe('order.*');
    expect($attrs['name'])->toBe('Order Wildcard');
    expect($attrs['priority'])->toBe(50);
    expect($attrs['enabled'])->toBeTrue();
    expect($attrs['async'])->toBeTrue();
});

test('SubscriptionFactory chained state methods compose correctly', function () {
    $factory = SubscriptionFactory::new()
        ->forEvent('user.*')
        ->withUrl('https://example.com/hooks')
        ->withPriority(75)
        ->withFailureCount(3)
        ->active();
    $attrs = $factory->raw();

    expect($attrs['event'])->toBe('user.*');
    expect($attrs['url'])->toBe('https://example.com/hooks');
    expect($attrs['priority'])->toBe(75);
    expect($attrs['failure_count'])->toBe(3);
    expect($attrs['active'])->toBeTrue();
});

test('EventLogFactory chained state methods compose correctly', function () {
    $factory = EventLogFactory::new()
        ->withEvent('order.placed')
        ->completed()
        ->withDuration(150);
    $attrs = $factory->raw();

    expect($attrs['event'])->toBe('order.placed');
    expect($attrs['status'])->toBe(EventLog::STATUS_COMPLETED);
    expect($attrs['duration_ms'])->toBe(150);
    expect($attrs['error'])->toBeNull();
});
