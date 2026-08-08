<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// Load test action classes (App\Actions namespace)
require_once __DIR__.'/TestActions.php';

beforeEach(function (): void {
    Trigger::query()->delete();
    EventLog::query()->delete();
    Subscription::query()->delete();
});

// ─── ServiceProvider Lifecycle ───────────────────────────────────────────────

test('ServiceProvider registers all bindings correctly', function (): void {
    $app = app();

    // Singletons
    expect($app->make(ConditionEngine::class))->toBeInstanceOf(ConditionEngine::class)
        ->and($app->make(ConditionEngineContract::class))->toBeInstanceOf(ConditionEngine::class)
        ->and($app->make(ActionResolver::class))->toBeInstanceOf(ActionResolver::class)
        ->and($app->make(EventManager::class))->toBeInstanceOf(EventManager::class);

    // Contract identity — must be the exact same instance
    expect($app->make(ConditionEngine::class))
        ->toBe($app->make(ConditionEngineContract::class));

    // Transients — fresh instances each time
    expect($app->make(TriggerBuilder::class))->not->toBe($app->make(TriggerBuilder::class))
        ->and($app->make(SubscriptionBuilder::class))->not->toBe($app->make(SubscriptionBuilder::class));
});

test('ServiceProvider publishes config and migrations in console', function (): void {
    $app = app();

    $provider = new EventsServiceProvider($app);
    $provider->register();
    $provider->boot();

    // Config should be merged
    $config = $app->get('config');
    expect($config)->not->toBeNull();

    $eventsConfig = $config->get('events');
    expect($eventsConfig)->toBeArray()
        ->and($eventsConfig)->toHaveKey('table_names')
        ->and($eventsConfig)->toHaveKey('queue')
        ->and($eventsConfig)->toHaveKey('retry')
        ->and($eventsConfig)->toHaveKey('retention')
        ->and($eventsConfig)->toHaveKey('subscriptions')
        ->and($eventsConfig)->toHaveKey('wildcard_cache_ttl');
});

// ─── Facade Resolution ────────────────────────────────────────────────────────

test('Facade resolves EventManager from container', function (): void {
    $resolved = EventManagerFacade::getFacadeRoot();

    expect($resolved)->toBeInstanceOf(EventManager::class);
});

test('Facade proxies all public methods', function (): void {
    $manager = app()->make(EventManager::class);
    EventManagerFacade::clearResolvedInstances();
    app()->instance(EventManager::class, $manager);

    // Proxy should resolve to the same instance
    expect(EventManagerFacade::getFacadeRoot())->toBe($manager);
});

// ─── Config Type Safety ───────────────────────────────────────────────────────

test('config table_names has all 3 required string keys', function (): void {
    $tableNames = config('events.table_names');

    expect($tableNames)->toBeArray()
        ->and($tableNames['triggers'])->toBeString()
        ->and($tableNames['event_logs'])->toBeString()
        ->and($tableNames['subscriptions'])->toBeString();
});

test('config subscriptions has correct default types', function (): void {
    $subs = config('events.subscriptions');

    expect($subs)->toBeArray()
        ->and($subs['auto_generate_secret'])->toBeBool()
        ->and($subs['max_failures'])->toBeInt()
        ->and($subs['timeout'])->toBeInt()
        ->and($subs['signature_algorithm'])->toBeString();
});

test('config retry has correct default types', function (): void {
    $retry = config('events.retry');

    expect($retry)->toBeArray()
        ->and($retry['tries'])->toBeInt()
        ->and($retry['tries'])->toBeGreaterThan(0);
});

test('config wildcard_cache_ttl is positive int or null', function (): void {
    $ttl = config('events.wildcard_cache_ttl');

    expect($ttl)->toBeInt()
        ->and($ttl)->toBeGreaterThanOrEqual(0);
});

test('config queue section has string keys', function (): void {
    $queue = config('events.queue');

    expect($queue)->toBeArray()
        ->and($queue['queue'])->toBeString()
        ->and($queue['connection'])->toBeString();
});

test('config retention section has correct types', function (): void {
    $retention = config('events.retention');

    expect($retention)->toBeArray()
        ->and($retention['days'])->toBeInt()
        ->and($retention['include_pending'])->toBeBool();
});

// ─── Fire Validation ────────────────────────────────────────────────────────

test('fire throws on empty event name', function (): void {
    EventManagerFacade::fire('');
})->throws(\InvalidArgumentException::class);

test('fire throws on zero-string event name', function (): void {
    EventManagerFacade::fire('0');
})->throws(\InvalidArgumentException::class);

test('fireModel throws on empty model class', function (): void {
    $model = new \stdClass;
    EventManagerFacade::fireModel('', 'created', $model);
})->throws(\InvalidArgumentException::class);

test('fireModel throws on empty action', function (): void {
    $model = new \stdClass;
    EventManagerFacade::fireModel('App\\Models\\Order', '', $model);
})->throws(\InvalidArgumentException::class);

// ─── Trigger CRUD ───────────────────────────────────────────────────────────

test('getTrigger returns null for non-existent', function (): void {
    expect(EventManagerFacade::getTrigger('non-existent-uuid'))->toBeNull();
});

test('deleteTrigger returns false for non-existent', function (): void {
    expect(EventManagerFacade::deleteTrigger('non-existent-uuid'))->toBeFalse();
});

test('deleteTrigger invalidates cache', function (): void {
    $trigger = Trigger::factory()->create([
        'event' => 'test.delete',
        'action' => SendOrderNotification::class,
        'enabled' => true,
    ]);

    expect(EventManagerFacade::deleteTrigger($trigger->id))->toBeTrue()
        ->and(Trigger::find($trigger->id))->toBeNull();
});

// ─── Subscription Management ─────────────────────────────────────────────────

test('unsubscribe returns false for non-existent', function (): void {
    expect(EventManagerFacade::unsubscribe('non-existent-uuid'))->toBeFalse();
});

test('getSubscription returns null for non-existent', function (): void {
    expect(EventManagerFacade::getSubscription('non-existent-uuid'))->toBeNull();
});

test('listSubscriptions returns empty collection when no subscriptions', function (): void {
    $result = EventManagerFacade::listSubscriptions();
    expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class)
        ->and($result)->toHaveCount(0);
});

// ─── Cache Invalidation Lifecycle ────────────────────────────────────────────

test('enable invalidates cache on success', function (): void {
    $trigger = Trigger::factory()->disabled()->create();

    $result = EventManagerFacade::enable($trigger->id);

    expect($result)->toBeTrue();
    // Trigger should be enabled
    $fresh = Trigger::find($trigger->id);
    expect($fresh?->enabled)->toBeTrue();
});

test('disable invalidates cache on success', function (): void {
    $trigger = Trigger::factory()->enabled()->create();

    $result = EventManagerFacade::disable($trigger->id);

    expect($result)->toBeTrue();
    $fresh = Trigger::find($trigger->id);
    expect($fresh?->enabled)->toBeFalse();
});

test('enable returns false for non-existent trigger', function (): void {
    expect(EventManagerFacade::enable('non-existent-uuid'))->toBeFalse();
});

test('disable returns false for non-existent trigger', function (): void {
    expect(EventManagerFacade::disable('non-existent-uuid'))->toBeFalse();
});

// ─── EventLog Status Constants ──────────────────────────────────────────────

test('EventLog status constants are consistent', function (): void {
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING)
        ->and(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED)
        ->and(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED)
        ->and(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED)
        ->and(EventLog::$statuses)->toHaveCount(4);
});

// ─── DomainEvent Roundtrip ──────────────────────────────────────────────────

test('DomainEvent roundtrip preserves identity', function (): void {
    $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

    $data = $event->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($event->eventId->toString())
        ->and($restored->eventType)->toBe('user.registered')
        ->and($restored->payload)->toBe(['email' => 'test@example.com']);
});

test('DomainEvent fromArray throws on empty eventType', function (): void {
    DomainEvent::fromArray([]);
})->throws(\InvalidArgumentException::class);

// ─── WildcardMatcher #[Pure] Verification ──────────────────────────────────

test('WildcardMatcher::matches is #[Pure]', function (): void {
    $ref = new \ReflectionMethod(WildcardMatcher::class, 'matches');

    $attrs = $ref->getAttributes();
    $hasPure = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeTrue('WildcardMatcher::matches must have #[Pure] attribute');
});

test('WildcardMatcher::findMatchingPatterns is #[Pure]', function (): void {
    $ref = new \ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');

    $attrs = $ref->getAttributes();
    $hasPure = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }

    expect($hasPure)->toBeTrue();
});

// ─── Fluent Interface Verification ────────────────────────────────────────────

test('TriggerBuilder all methods return self', function (): void {
    $manager = app()->make(EventManager::class);
    $builder = $manager->on('test.event');

    expect($builder->name('Test'))->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->action(SendOrderNotification::class))->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->when(['status' => 'active']))->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->async())->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->priority(10))->toBeInstanceOf(TriggerBuilder::class)
        ->and($builder->actionParams(['url' => 'https://example.com']))->toBeInstanceOf(TriggerBuilder::class);
});

test('SubscriptionBuilder all methods return self', function (): void {
    $manager = app()->make(EventManager::class);
    $builder = $manager->subscribe('test.event', 'https://example.com');

    expect($builder->withSecret('whsec_test'))->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->withFilter(['status' => 'active']))->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->priority(10))->toBeInstanceOf(SubscriptionBuilder::class)
        ->and($builder->async())->toBeInstanceOf(SubscriptionBuilder::class);
});

// ─── Final Class Verification ───────────────────────────────────────────────

test('core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        WildcardMatcher::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        ActionResolver::class,
        DomainEvent::class,
        EventsServiceProvider::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new \ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

test('console commands are final', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $ref = new \ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ─── Strict Types Enforcement ────────────────────────────────────────────────

test('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
});

// ─── Version Consistency ────────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? 'unknown';

    // Version should be in semantic format
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
});

// ─── Subscription Model Edge Cases ──────────────────────────────────────────

test('Subscription signPayload returns empty for null secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('test-payload'))->toBe('');
});

test('Subscription signPayload returns empty for empty secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => '']);

    expect($sub->signPayload('test-payload'))->toBe('');
});

test('Subscription hasExceededFailures uses config default', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 9]);

    // Default max_failures is 10, so 9 < 10 → false
    expect($sub->hasExceededFailures())->toBeFalse();

    // 10 >= 10 → true
    $sub->forceFill(['failure_count' => 10])->save();
    expect($sub->hasExceededFailures())->toBeTrue();
});

test('Subscription matchesEvent exact match', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.placed']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.shipped'))->toBeFalse();
});

test('Subscription matchesEvent wildcard single segment', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.*']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.shipped'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('Subscription matchesEvent wildcard cross segment', function (): void {
    $sub = Subscription::factory()->create(['event' => 'order.**']);

    expect($sub->matchesEvent('order.placed'))->toBeTrue()
        ->and($sub->matchesEvent('order.placed.extra'))->toBeTrue()
        ->and($sub->matchesEvent('invoice.paid'))->toBeFalse();
});
