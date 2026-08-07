<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;

/**
 * Production deployment readiness tests.
 * Verifies that all contracts, configurations, and service bindings
 * are correctly wired for a production environment.
 */
test('all contracts are implemented by concrete classes', function (): void {
    $contractImplementations = [
        ConditionEngineContract::class => ConditionEngine::class,
    ];

    foreach ($contractImplementations as $contract => $concrete) {
        expect($concrete)->toImplement($contract);
    }
});

test('Triggerable interface exists with handle method', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Contracts\Triggerable::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->hasMethod('handle'))->toBeTrue();

    $method = $reflection->getMethod('handle');
    expect($method->isPublic())->toBeTrue();
});

test('service provider merges config correctly', function (): void {
    $tables = config('events.table_names');

    expect($tables)->toBeArray()
        ->and($tables)->toHaveCount(3)
        ->and($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('service provider loads migrations from correct path', function (): void {
    $provider = new EventsServiceProvider(app());

    $reflection = new ReflectionClass($provider);
    $bootMethod = $reflection->getMethod('boot');

    $contents = file_get_contents((string) $reflection->getFileName());
    expect($contents)->toContain('database/migrations');
});

test('EventManager is registered as singleton', function (): void {
    $instance1 = app(\ZeroBoiler\Events\EventManager::class);
    $instance2 = app(\ZeroBoiler\Events\EventManager::class);

    expect($instance1)->toBe($instance2);
});

test('ConditionEngine is registered as singleton', function (): void {
    $instance1 = app(ConditionEngine::class);
    $instance2 = app(ConditionEngine::class);

    expect($instance1)->toBe($instance2);
});

test('ActionResolver is registered as singleton', function (): void {
    $instance1 = app(\ZeroBoiler\Events\ActionResolver::class);
    $instance2 = app(\ZeroBoiler\Events\ActionResolver::class);

    expect($instance1)->toBe($instance2);
});

test('TriggerBuilder is transient (not singleton)', function (): void {
    $instance1 = app(TriggerBuilder::class);
    $instance2 = app(TriggerBuilder::class);

    expect($instance1)->not->toBe($instance2);
});

test('SubscriptionBuilder is transient (not singleton)', function (): void {
    $instance1 = app(SubscriptionBuilder::class);
    $instance2 = app(SubscriptionBuilder::class);

    expect($instance1)->not->toBe($instance2);
});

test('all config keys have sensible defaults', function (): void {
    // Table names
    expect(config('events.table_names.triggers'))->toBeString()->not->toBeEmpty();
    expect(config('events.table_names.event_logs'))->toBeString()->not->toBeEmpty();
    expect(config('events.table_names.subscriptions'))->toBeString()->not->toBeEmpty();

    // Queue
    expect(config('events.queue.queue'))->toBeString();
    expect(config('events.queue.connection'))->toBeString();

    // Retry
    expect(config('events.retry.tries'))->toBeInt()->toBeGreaterThan(0);
    expect(config('events.retry.backoff'))->toBeString();

    // Retention
    expect(config('events.retention.days'))->toBeInt()->toBeGreaterThan(0);
    expect(config('events.retention.include_pending'))->toBeBool();

    // Subscriptions
    expect(config('events.subscriptions.auto_generate_secret'))->toBeBool();
    expect(config('events.subscriptions.max_failures'))->toBeInt()->toBeGreaterThan(0);
    expect(config('events.subscriptions.timeout'))->toBeInt()->toBeGreaterThan(0);
    expect(config('events.subscriptions.signature_algorithm'))->toBeString();

    // Cache TTL
    expect(config('events.wildcard_cache_ttl'))->toBeInt()->toBeGreaterThan(0);
});

test('event log statuses are complete and consistent', function (): void {
    $expectedStatuses = ['pending', 'dispatched', 'completed', 'failed'];

    expect(\ZeroBoiler\Events\Models\EventLog::$statuses)->toEqualCanonicalizing($expectedStatuses);

    // Verify each status constant exists
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Models\EventLog::class);
    foreach ($expectedStatuses as $status) {
        $constName = 'STATUS_' . strtoupper($status);
        expect($reflection->hasConstant($constName))->toBeTrue();
    }
});

test('DomainEvent is immutable with readonly properties', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    expect($reflection->isFinal())->toBeTrue();

    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
    foreach ($properties as $property) {
        expect($property->isReadOnly())->toBeTrue("Property {$property->getName()} should be readonly");
    }
});

test('WebhookAction implements Triggerable', function (): void {
    expect(\ZeroBoiler\Events\Actions\WebhookAction::class)
        ->toImplement(\ZeroBoiler\Events\Contracts\Triggerable::class);
});

test('DispatchTriggerJob implements ShouldQueue', function (): void {
    expect(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class)
        ->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('all models have proper UUID key type', function (): void {
    $models = [
        \ZeroBoiler\Events\Models\Trigger::class,
        \ZeroBoiler\Events\Models\EventLog::class,
        \ZeroBoiler\Events\Models\Subscription::class,
    ];

    foreach ($models as $model) {
        $reflection = new ReflectionClass($model);
        $instance = $reflection->newInstanceWithoutConstructor();

        expect($instance->getKeyName())->toBe('id')
            ->and($instance->getKeyType())->toBe('string')
            ->and($instance->incrementing)->toBeFalse();
    }
});

test('WildcardMatcher has all static methods typed', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();

    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];
    foreach ($methods as $method) {
        $m = $reflection->getMethod($method);
        expect($m->isStatic())->toBeTrue();
        expect($m->getReturnType()?->getName())->toBe('array');
    }
});

test('facade accessor resolves to correct class', function (): void {
    $accessor = \ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor();

    expect($accessor)->toBe(\ZeroBoiler\Events\EventManager::class);
});
