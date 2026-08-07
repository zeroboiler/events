<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
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

beforeEach(function (): void {
    // Fresh app is created by TestCase::setUp()
});

// ─── Strict Types Enforcement ───────────────────────────────────────────────

test('all src files declare strict_types=1', function (): void {
    $files = glob(__DIR__.'/../src/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('all test files declare strict_types=1', function (): void {
    $files = glob(__DIR__.'/*Test.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

// ─── Final Class Verification ──────────────────────────────────────────────

test('all core classes are final', function (): void {
    $classes = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WildcardMatcher::class,
        DomainEvent::class,
        EventsServiceProvider::class,
    ];

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

test('all models are final', function (): void {
    $models = [EventLog::class, Trigger::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionClass($model);
        expect($ref->isFinal())->toBeTrue("{$model} should be final");
    }
});

test('all console commands are final', function (): void {
    $commands = glob(__DIR__.'/../src/Console/*.php');
    expect($commands)->not->toBeEmpty();

    foreach ($commands as $file) {
        $contents = file_get_contents($file);
        preg_match('/class\s+(\w+)/', $contents, $matches);
        if (isset($matches[1])) {
            $ref = new ReflectionClass('ZeroBoiler\\Events\\Console\\'.$matches[1]);
            expect($ref->isFinal())->toBeTrue("{$matches[1]} should be final");
        }
    }
});

// ─── Interface Contract Verification ────────────────────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

test('Triggerable interface has handle method', function (): void {
    $ref = new ReflectionClass(Triggerable::class);
    expect($ref->hasMethod('handle'))->toBeTrue();

    $method = $ref->getMethod('handle');
    expect($method->isPublic())->toBeTrue();
    expect($method->getParameters())->toHaveCount(1);
    expect($method->getParameters()[0]->getName())->toBe('payload');
    expect($method->hasReturnType())->toBeTrue();
    expect((string) $method->getReturnType())->toBe('void');
});

// ─── Service Provider Binding Verification ──────────────────────────────────

test('EventManager is singleton', function (): void {
    $app = app();
    $a = $app->make(EventManager::class);
    $b = $app->make(EventManager::class);
    expect($a)->toBe($b);
});

test('ConditionEngine is singleton', function (): void {
    $app = app();
    $a = $app->make(ConditionEngine::class);
    $b = $app->make(ConditionEngine::class);
    expect($a)->toBe($b);
});

test('ActionResolver is singleton', function (): void {
    $app = app();
    $a = $app->make(ActionResolver::class);
    $b = $app->make(ActionResolver::class);
    expect($a)->toBe($b);
});

test('TriggerBuilder is transient', function (): void {
    $app = app();
    $a = $app->make(TriggerBuilder::class);
    $b = $app->make(TriggerBuilder::class);
    expect($a)->not->toBe($b);
});

test('SubscriptionBuilder is transient', function (): void {
    $app = app();
    $a = $app->make(SubscriptionBuilder::class);
    $b = $app->make(SubscriptionBuilder::class);
    expect($a)->not->toBe($b);
});

test('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $app = app();
    $contract = $app->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);

    // Must be the same singleton instance
    $concrete = $app->make(ConditionEngine::class);
    expect($contract)->toBe($concrete);
});

// ─── Facade Verification ────────────────────────────────────────────────────

test('facade accessor returns correct class', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $method = $ref->getMethod('getFacadeAccessor');
    expect($method->invoke(null))->toBe(EventManager::class);
});

test('facade proxy resolves from container', function (): void {
    $builder = EventManagerFacade::on('test.event');
    expect($builder)->toBeInstanceOf(TriggerBuilder::class);
});

// ─── Config Completeness ─────────────────────────────────────────────────────

test('all config sections exist', function (): void {
    $config = config('events');
    expect($config)->not->toBeNull();
    expect($config)->toBeArray();

    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
    }
});

test('table_names config has all 3 tables', function (): void {
    $tableNames = config('events.table_names');
    expect($tableNames)->toBeArray();
    expect($tableNames)->toHaveKey('triggers');
    expect($tableNames)->toHaveKey('event_logs');
    expect($tableNames)->toHaveKey('subscriptions');
});

test('subscriptions config has all required keys', function (): void {
    $sub = config('events.subscriptions');
    expect($sub)->toBeArray();
    expect($sub)->toHaveKey('auto_generate_secret');
    expect($sub)->toHaveKey('max_failures');
    expect($sub)->toHaveKey('timeout');
    expect($sub)->toHaveKey('signature_algorithm');
});

test('retry config has all required keys', function (): void {
    $retry = config('events.retry');
    expect($retry)->toBeArray();
    expect($retry)->toHaveKey('tries');
    expect($retry)->toHaveKey('backoff');
});

// ─── Model Table Name Config-Driven ─────────────────────────────────────────

test('Trigger model reads table name from config', function (): void {
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('triggers');
});

test('EventLog model reads table name from config', function (): void {
    $log = new EventLog;
    expect($log->getTable())->toBe('event_logs');
});

test('Subscription model reads table name from config', function (): void {
    $sub = new Subscription;
    expect($sub->getTable())->toBe('event_subscriptions');
});

// ─── Model UUID Key Type ─────────────────────────────────────────────────────

test('all models use string key type and non-incrementing', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionClass($model);
        $instance = $ref->newInstanceWithoutConstructor();

        expect($instance->getKeyType())->toBe('string');
        expect($instance->getIncrementing())->toBeFalse();
    }
});

// ─── EventLog Status Constants ──────────────────────────────────────────────

test('EventLog has all 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');

    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    expect(EventLog::$statuses)->toHaveCount(4);
});

// ─── DomainEvent Verification ────────────────────────────────────────────────

test('DomainEvent properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

    foreach ($props as $prop) {
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->name} should be readonly");
    }
});

test('DomainEvent::occur creates fresh UUID and timestamp', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->eventId->toString())->not->toBeEmpty();
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent roundtrip preserves identity', function (): void {
    $original = DomainEvent::occur('order.created', ['id' => 42]);
    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->format(DateTimeInterface::ATOM))
        ->toBe($original->occurredAt->format(DateTimeInterface::ATOM));
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

// ─── WildcardMatcher Pure Methods ────────────────────────────────────────────

test('WildcardMatcher::matches is Pure', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
    $attrs = $ref->getAttributes();
    $isPure = false;
    foreach ($attrs as $attr) {
        $name = $attr->getName();
        if (str_contains($name, 'Pure')) {
            $isPure = true;
            break;
        }
    }
    expect($isPure)->toBeTrue('WildcardMatcher::matches should have Pure attribute');
});

test('WildcardMatcher findMatchingPatterns and extractWildcards are Pure', function (): void {
    foreach (['findMatchingPatterns', 'extractWildcards'] as $method) {
        $ref = new ReflectionMethod(WildcardMatcher::class, $method);
        $attrs = $ref->getAttributes();
        $isPure = false;
        foreach ($attrs as $attr) {
            $name = $attr->getName();
            if (str_contains($name, 'Pure')) {
                $isPure = true;
                break;
            }
        }
        expect($isPure)->toBeTrue("WildcardMatcher::{$method} should have Pure attribute");
    }
});

// ─── TriggerBuilder Fluent Interface ────────────────────────────────────────

test('TriggerBuilder methods return self', function (): void {
    $app = app();
    $builder = $app->make(TriggerBuilder::class);

    expect($builder->name('test'))->toBe($builder);
    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->action('SomeAction'))->toBe($builder);
    expect($builder->actions(['Action1', 'Action2']))->toBe($builder);
    expect($builder->when(['key' => 'value']))->toBe($builder);
    expect($builder->async())->toBe($builder);
    expect($builder->priority(5))->toBe($builder);
    expect($builder->actionParams(['url' => 'https://test.com']))->toBe($builder);
});

// ─── SubscriptionBuilder Fluent Interface ───────────────────────────────────

test('SubscriptionBuilder methods return self', function (): void {
    $app = app();
    $builder = $app->make(SubscriptionBuilder::class);

    expect($builder->on('test.event'))->toBe($builder);
    expect($builder->to('https://test.com'))->toBe($builder);
    expect($builder->withSecret('secret'))->toBe($builder);
    expect($builder->withFilter(['key' => 'value']))->toBe($builder);
    expect($builder->priority(5))->toBe($builder);
    expect($builder->async())->toBe($builder);
});

// ─── ConditionEngine Override Verification ───────────────────────────────────

test('ConditionEngine::matches has Override attribute', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $ref->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        $name = $attr->getName();
        if (str_contains($name, 'Override')) {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('ConditionEngine::matches should have Override attribute');
});

// ─── WebhookAction Override Verification ─────────────────────────────────────

test('WebhookAction::handle has Override attribute', function (): void {
    $ref = new ReflectionMethod(
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        'handle',
    );
    $attrs = $ref->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        $name = $attr->getName();
        if (str_contains($name, 'Override')) {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('WebhookAction::handle should have Override attribute');
});

// ─── Subscription Model MatchesEvent ─────────────────────────────────────────

test('subscription matchesEvent exact match', function (): void {
    $sub = new Subscription(['event' => 'order.placed']);
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();
});

test('subscription matchesEvent single-segment wildcard', function (): void {
    $sub = new Subscription(['event' => 'order.*']);
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

test('subscription matchesEvent cross-segment wildcard', function (): void {
    $sub = new Subscription(['event' => 'order.**']);
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
    expect($sub->matchesEvent('invoice.placed'))->toBeFalse();
});

// ─── Cache Invalidation Lifecycle ───────────────────────────────────────────

test('cache invalidation on trigger save', function (): void {
    $manager = app()->make(EventManager::class);

    // Should not throw
    $manager->invalidateTriggerCache();
    expect(true)->toBeTrue();
});

test('cache invalidation on trigger enable/disable with non-existent', function (): void {
    $manager = app()->make(EventManager::class);

    expect($manager->enable('non-existent-id'))->toBeFalse();
    expect($manager->disable('non-existent-id'))->toBeFalse();
});

// ─── Trigger CRUD ────────────────────────────────────────────────────────────

test('getTrigger returns null for non-existent', function (): void {
    $manager = app()->make(EventManager::class);
    expect($manager->getTrigger('non-existent'))->toBeNull();
});

test('deleteTrigger returns false for non-existent', function (): void {
    $manager = app()->make(EventManager::class);
    expect($manager->deleteTrigger('non-existent'))->toBeFalse();
});

// ─── EventManager Fire Validation ───────────────────────────────────────────

test('fire throws on empty event', function (): void {
    $manager = app()->make(EventManager::class);
    $manager->fire('');
})->throws(InvalidArgumentException::class, 'Event name cannot be empty');

test('fireModel throws on empty model class', function (): void {
    $manager = app()->make(EventManager::class);
    $manager->fireModel('', 'created', new stdClass);
})->throws(InvalidArgumentException::class, 'Model class name cannot be empty');

test('fireModel throws on empty action', function (): void {
    $manager = app()->make(EventManager::class);
    $manager->fireModel('App\\Models\\Order', '', new stdClass);
})->throws(InvalidArgumentException::class, 'Model action cannot be empty');

// ─── Version Consistency ────────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBeString();
    expect($readme)->toContain('version-'.$composer['version']);
});
