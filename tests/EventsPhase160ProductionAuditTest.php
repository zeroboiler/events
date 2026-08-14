<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

test('Phase 160: all source files declare strict types', function (): void {
    $srcFiles = glob(__DIR__.'/../../src/{**,}.php', GLOB_BRACE) ?: [];

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "File {$file} missing declare(strict_types=1)");
    }
});

test('Phase 160: all public service classes are final', function (): void {
    $classes = [
        EventManager::class,
        EventScheduler::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        ConditionEngine::class,
        WildcardMatcher::class,
        ActionResolver::class,
        WebhookAction::class,
        EventsServiceProvider::class,
        EventLog::class,
        Trigger::class,
        Subscription::class,
        DispatchTriggerJob::class,
        DomainEvent::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())
            ->toBeTrue("{$class} should be final");
    }
});

test('Phase 160: TriggerBuilder readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(TriggerBuilder::class);
    $constructor = $reflection->getMethod('__construct');
    $parameters = $constructor->getParameters();

    foreach ($parameters as $param) {
        if ($param->getName() === 'event' || $param->getName() === 'app') {
            expect($param->isPromoted())->toBeTrue("{$param->getName()} should be promoted");
            expect($param->isReadOnly())->toBeTrue("{$param->getName()} should be readonly");
        }
    }
});

test('Phase 160: SubscriptionBuilder readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    $constructor = $reflection->getMethod('__construct');
    $parameters = $constructor->getParameters();

    foreach ($parameters as $param) {
        if ($param->getName() === 'app' || $param->getName() === 'event' || $param->getName() === 'url') {
            if ($param->isPromoted()) {
                expect($param->isReadOnly())->toBeTrue("{$param->getName()} should be readonly");
            }
        }
    }
});

test('Phase 160: EventManager has all required public methods', function (): void {
    $methods = ['on', 'register', 'fire', 'fireModel', 'enable', 'disable', 'invalidateTriggerCache',
        'isDisabled', 'setEnabled', 'listTriggers', 'getTrigger', 'deleteTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook',
        'getEventHistory', 'getStats', 'purgeLogs', 'getStalePendingLogs',
        'deactivateExceededSubscriptions', 'executeTrigger', 'registerScheduler',
    ];

    foreach ($methods as $method) {
        expect(method_exists(EventManager::class, $method))
            ->toBeTrue("EventManager::{$method}() should exist");
    }
});

test('Phase 160: facade getFacadeAccessor returns correct binding', function (): void {
    $reflection = new ReflectionClass(EventManagerFacade::class);
    $method = $reflection->getMethod('getFacadeAccessor');
    expect($method->isPublic())->toBeTrue();
    expect($method->getReturnType()?->getName())->toBe('string');

    $facadeAccessor = EventManagerFacade::getFacadeAccessor();
    expect($facadeAccessor)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('Phase 160: ConditionEngine implements ConditionEngineContract', function (): void {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('Phase 160: WebhookAction implements Triggerable', function (): void {
    $reflection = new ReflectionClass(WebhookAction::class);
    expect($reflection->implementsInterface(Triggerable::class))->toBeTrue();
});

test('Phase 160: EventsServiceProvider provides all bindings', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    $expected = [
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    ];

    foreach ($expected as $binding) {
        expect(in_array($binding, $provides, true))
            ->toBeTrue("ServiceProvider should provide {$binding}");
    }
});

test('Phase 160: WildcardMatcher is readonly final class', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

test('Phase 160: DomainEvent is immutable value object', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->eventId)->not->toBeNull();
    expect($event->occurredAt)->not->toBeNull();

    $array = $event->toArray();
    expect($array)->toHaveKeys(['event_id', 'event_type', 'payload', 'occurred_at']);
});

test('Phase 160: DispatchTriggerJob has typed constructor properties', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    $constructor = $reflection->getMethod('__construct');

    $triggerId = $constructor->getParameters()[0];
    expect($triggerId->getName())->toBe('triggerId');
    expect($triggerId->getType()?->getName())->toBe('string');
    expect($triggerId->isReadOnly())->toBeTrue();
    expect($triggerId->isPromoted())->toBeTrue();

    $event = $constructor->getParameters()[1];
    expect($event->getName())->toBe('event');
    expect($event->getType()?->getName())->toBe('string');
    expect($event->isReadOnly())->toBeTrue();
    expect($event->isPromoted())->toBeTrue();

    $payload = $constructor->getParameters()[2];
    expect($payload->getName())->toBe('payload');
    expect($payload->getType()?->getName())->toBe('array');
    expect($payload->isReadOnly())->toBeTrue();
    expect($payload->isPromoted())->toBeTrue();
});

test('Phase 160: all models have UUID primary keys', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $reflection = new ReflectionClass($model);
        $keyType = $reflection->getDefaultProperties()['keyType'] ?? null;
        expect($keyType)->toBe('string', "{$model} should use string (UUID) primary key");
    }
});

test('Phase 160: no deprecated setAccessible calls in source', function (): void {
    $srcFiles = glob(__DIR__.'/../../src/{**,}.php', GLOB_BRACE) ?: [];

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('setAccessible(', "File {$file} contains deprecated setAccessible() call");
    }
});

test('Phase 160: EscapesWildcardLike properly escapes SQL LIKE characters', function (): void {
    $trait = new class {
        use EscapesWildcardLike;

        public function testWildcardToLike(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };

    // No wildcard → null
    expect($trait->testWildcardToLike('order.created'))->toBeNull();

    // Single wildcard
    expect($trait->testWildcardToLike('order.*'))->toBe('order\%');

    // Cross-segment wildcard
    expect($trait->testWildcardToLike('order.**'))->toBe('order.\%');

    // Percent literal escaped
    expect($trait->testWildcardToLike('user.100%.test.*'))->toBe('user.100\%.test\%');

    // Underscore literal escaped
    expect($trait->testWildcardToLike('test_*'))->toBe('test\_%');
});

test('Phase 160: GetsWebhookTimeout returns positive int', function (): void {
    $trait = new class {
        use GetsWebhookTimeout;

        public function testGet(): int
        {
            return $this->getWebhookTimeout();
        }
    };

    $timeout = $trait->testGet();
    expect($timeout)->toBeInt();
    expect($timeout)->toBeGreaterThan(0);
});

test('Phase 160: README test file count is accurate', function (): void {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    $readmeTestCount = (int) preg_match('/245 test files/', $readme);
    expect($readmeTestCount)->toBe(1, 'README should claim 245 test files');

    $testFiles = glob(__DIR__.'/../*Test.php');
    expect(count($testFiles))->toBe(245);
});
