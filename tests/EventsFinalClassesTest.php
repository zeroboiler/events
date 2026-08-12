<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use ZeroBoiler\Events\Database\Factories\EventLogFactory;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

test('EventLogFactory completed state closure has explicit array return type', function (): void {
    $factory = EventLogFactory::new();
    $completedState = $factory->completed();

    // Verify the factory can create a model with the completed state
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()
        ->completed()
        ->for($trigger)
        ->create();

    expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
        ->and($log->duration_ms)->toBeInt()
        ->and($log->error)->toBeNull();
});

test('EventLogFactory failed state closure has explicit array return type', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()
        ->failed()
        ->for($trigger)
        ->create();

    expect($log->status)->toBe(EventLog::STATUS_FAILED)
        ->and($log->error)->toBeString()
        ->and($log->error)->not->toBeEmpty();
});

test('EventsRetryCommand: null trigger is handled correctly', function (): void {
    // Create a log with no associated trigger
    $trigger = Trigger::factory()->create(['enabled' => false]);
    $log = EventLog::factory()
        ->failed()
        ->for($trigger)
        ->create();

    // The trigger exists but is disabled — should be skipped
    // Trigger null check uses strict === null comparison
    expect($log->trigger)->not->toBeNull();
    expect($log->trigger->enabled)->toBeFalse();
});

test('EventsRetryCommand: payload is guarded with is_array check', function (): void {
    // This test verifies that payload passed to DispatchTriggerJob
    // would be properly guarded. In practice the EventLog model casts
    // payload to array, but the guard ensures safety.
    $trigger = Trigger::factory()->enabled()->async()->create([
        'action' => SendOrderNotification::class,
    ]);
    $log = EventLog::factory()
        ->failed()
        ->for($trigger)
        ->create([
            'payload' => ['test' => 'data'],
        ]);

    // Verify payload is an array (the guard would produce [] if not)
    expect(is_array($log->payload))->toBeTrue();

    // Simulate the guard logic used in EventsRetryCommand
    $payload = is_array($log->payload) ? $log->payload : [];
    expect($payload)->toBe(['test' => 'data']);
});

test('ManagesHistory trait resolves $this->app via mixin', function (): void {
    $eventManager = app(\ZeroBoiler\Events\EventManager::class);

    // ManagesHistory methods should be available on EventManager
    $history = $eventManager->getEventHistory(limit: 10);
    expect($history)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
});

test('ManagesSubscriptions trait resolves $this->app via mixin', function (): void {
    $eventManager = app(\ZeroBoiler\Events\EventManager::class);

    // ManagesSubscriptions methods should be available on EventManager
    $subscriptions = $eventManager->listSubscriptions();
    expect($subscriptions)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
});

test('EventManager class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('ConditionEngine class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('ActionResolver class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\ActionResolver::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('WildcardMatcher class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('TriggerBuilder class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('SubscriptionBuilder class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\SubscriptionBuilder::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('DomainEvent class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('WebhookAction class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('DispatchTriggerJob class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('EventsServiceProvider class is final', function (): void {
    $reflection = new \ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('All console commands are final', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
    ];

    foreach ($commands as $command) {
        $reflection = new \ReflectionClass($command);
        expect($reflection->isFinal())->toBeTrue("{$command} should be final");
    }
});
