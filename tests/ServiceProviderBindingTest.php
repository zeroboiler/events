<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

test('service provider registers EventManager as singleton', function (): void {
    $manager1 = app(EventManager::class);
    $manager2 = app(EventManager::class);

    expect($manager1)->toBeInstanceOf(EventManager::class)
        ->and($manager1)->toBe($manager2); // Same instance (singleton)
});

test('service provider registers ConditionEngine as singleton', function (): void {
    $engine1 = app(ConditionEngine::class);
    $engine2 = app(ConditionEngine::class);

    expect($engine1)->toBeInstanceOf(ConditionEngine::class)
        ->and($engine1)->toBe($engine2);
});

test('service provider registers ActionResolver as singleton', function (): void {
    $resolver1 = app(ActionResolver::class);
    $resolver2 = app(ActionResolver::class);

    expect($resolver1)->toBeInstanceOf(ActionResolver::class)
        ->and($resolver1)->toBe($resolver2);
});

test('service provider registers SubscriptionBuilder as transient (not shared)', function (): void {
    $builder1 = app(SubscriptionBuilder::class);
    $builder2 = app(SubscriptionBuilder::class);

    expect($builder1)->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder2)->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder1)->not->toBe($builder2); // Different instances (transient)
});

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = app(ConditionEngine::class);

    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('EventManager is injected with correct dependencies', function (): void {
    $manager = app(EventManager::class);

    // Verify the manager has access to the container (which it uses for
    // TriggerBuilder and SubscriptionBuilder resolution)
    $builder = $manager->on('test.event');
    expect($builder)->toBeInstanceOf(TriggerBuilder::class);

    $subBuilder = $manager->subscribe('test.event', 'https://example.com');
    expect($subBuilder)->toBeInstanceOf(SubscriptionBuilder::class);
});

test('facade accessor returns EventManager class name', function (): void {
    expect(EventManagerFacade::getFacadeAccessor())
        ->toBe(EventManager::class);
});

test('facade resolves to EventManager instance', function (): void {
    expect(EventManagerFacade::getFacadeRoot())
        ->toBeInstanceOf(EventManager::class);
});

test('config is merged from package defaults', function (): void {
    $config = app('config');
    assert($config instanceof ConfigRepository);

    // Core config keys should exist after ServiceProvider::register()
    expect($config->get('events.table_names'))->toBeArray();
    expect($config->get('events.table_names.triggers'))->toBe('triggers');
    expect($config->get('events.table_names.event_logs'))->toBe('event_logs');
    expect($config->get('events.table_names.subscriptions'))->toBe('event_subscriptions');

    expect($config->get('events.queue'))->toBeArray();
    expect($config->get('events.retry'))->toBeArray();
    expect($config->get('events.retention'))->toBeArray();
    expect($config->get('events.subscriptions'))->toBeArray();
    expect($config->get('events.wildcard_cache_ttl'))->toBe(300);
});

test('config subscriptions section has all required keys', function (): void {
    $config = app('config');
    assert($config instanceof ConfigRepository);

    $subs = $config->get('events.subscriptions');
    expect($subs)->toBeArray()
        ->and($subs['auto_generate_secret'])->toBe(true)
        ->and($subs['max_failures'])->toBe(10)
        ->and($subs['timeout'])->toBe(30)
        ->and($subs['signature_algorithm'])->toBe('sha256');
});

test('config retry section has correct types', function (): void {
    $config = app('config');
    assert($config instanceof ConfigRepository);

    $retry = $config->get('events.retry');
    expect($retry['tries'])->toBe(3)
        ->and($retry['backoff'])->toBe('60,300,900');
});

test('config retention section has correct types', function (): void {
    $config = app('config');
    assert($config instanceof ConfigRepository);

    $retention = $config->get('events.retention');
    expect($retention['days'])->toBe(30)
        ->and($retention['include_pending'])->toBe(false);
});

test('trigger model table name is configurable', function (): void {
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('triggers');
});

test('event_log model table name uses default', function (): void {
    $log = new EventLog;
    // Uses Eloquent default naming (class name snake_case plural) unless overridden
    expect($log->getTable())->toBe('event_logs');
});

test('subscription model table name is explicitly set', function (): void {
    $subscription = new Subscription;
    expect($subscription->getTable())->toBe('event_subscriptions');
});

test('all models use UUID string keys', function (): void {
    expect((new Trigger)->getKeyType())->toBe('string');
    expect((new EventLog)->getKeyType())->toBe('string');
    expect((new Subscription)->getKeyType())->toBe('string');

    expect((new Trigger)->getIncrementing())->toBeFalse();
    expect((new EventLog)->getIncrementing())->toBeFalse();
    expect((new Subscription)->getIncrementing())->toBeFalse();
});

test('all models use soft deletes', function (): void {
    expect(in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses(Trigger::class), true))->toBeTrue();
    expect(in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses(EventLog::class), true))->toBeTrue();
    expect(in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses(Subscription::class), true))->toBeTrue();
});

test('event log has correct status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toBe([
        'pending',
        'dispatched',
        'completed',
        'failed',
    ]);
});

test('Triggerable interface has handle method', function (): void {
    expect(Triggerable::class)->hasMethod('handle');
});

test('ConditionEngineContract has matches method', function (): void {
    expect(ConditionEngineContract::class)->hasMethod('matches');
});
