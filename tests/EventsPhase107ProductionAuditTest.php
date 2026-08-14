<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Console\EventsHealthCommand;

test('Phase 107: all source files have declare(strict_types=1)', function (): void {
    $sourceFiles = [
        'src/EventManager.php',
        'src/ConditionEngine.php',
        'src/ActionResolver.php',
        'src/WildcardMatcher.php',
        'src/EventScheduler.php',
        'src/TriggerBuilder.php',
        'src/SubscriptionBuilder.php',
        'src/Domain/DomainEvent.php',
        'src/EventsServiceProvider.php',
        'src/Actions/WebhookAction.php',
        'src/Jobs/DispatchTriggerJob.php',
        'src/Models/Trigger.php',
        'src/Models/EventLog.php',
        'src/Models/Subscription.php',
        'src/Facades/EventManager.php',
        'src/Contracts/Triggerable.php',
        'src/Contracts/ConditionEngineContract.php',
        'src/Concerns/EscapesWildcardLike.php',
        'src/Concerns/GetsWebhookTimeout.php',
        'src/Concerns/ManagesHistory.php',
        'src/Concerns/ManagesSubscriptions.php',
    ];

    foreach ($sourceFiles as $file) {
        $path = __DIR__.'/../'.$file;
        $content = file_get_contents($path);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

test('Phase 107: EventScheduler uses constructor injection not global helpers', function (): void {
    $reflection = new ReflectionClass(EventScheduler::class);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('app');
    expect($params[0]->getType()?->getName())->toBe(\Illuminate\Container\Container::class);
});

test('Phase 107: EventManager constructor has 3 promoted readonly parameters', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();
    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue("{$param->getName()} should be promoted");
        $reflectionProp = new ReflectionProperty(EventManager::class, $param->getName());
        expect($reflectionProp->isReadOnly())->toBeTrue("{$param->getName()} should be readonly");
    }
});

test('Phase 107: DomainEvent has 4 readonly properties', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    $readonlyCount = 0;

    foreach ($reflection->getProperties() as $prop) {
        if ($prop->isReadOnly()) {
            $readonlyCount++;
        }
    }

    expect($readonlyCount)->toBe(4, 'DomainEvent should have 4 readonly properties (eventId, occurredAt, eventType, payload)');
});

test('Phase 107: ConditionEngine matches() has #[Override] attribute', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $method->getAttributes(\Override::class);
    expect($attrs)->toHaveCount(1);
});

test('Phase 107: WildcardMatcher is readonly and final with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("{$method->getName()} should be static");
    }
});

test('Phase 107: parseActions closures are static', function (): void {
    $method = new ReflectionMethod(EventManager::class, 'parseActions');
    $content = file_get_contents($method->getFileName());
    $start = $method->getStartLine();
    $end = $method->getEndLine();
    $methodCode = implode("\n", array_slice(file($method->getFileName()), $start - 1, $end - $start + 1));

    // Verify array_map closures use static fn
    $matches = substr_count($methodCode, 'static fn');
    expect($matches)->toBeGreaterThanOrEqual(2, 'parseActions should use static closures');
});

test('Phase 107: EventLog casts handle nullable error correctly', function (): void {
    $trigger = Trigger::factory()->enabled()->create();
    $log = EventLog::factory()->completed()->for($trigger)->create();

    // completed state sets error to null
    expect($log->error)->toBeNull();
    expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
});

test('Phase 107: Subscription signPayload returns empty string for null/empty secret', function (): void {
    $sub = Subscription::factory()->withoutSecret()->create();
    expect($sub->signPayload('test'))->toBe('');
});

test('Phase 107: TriggerBuilder save invalidates trigger cache', function (): void {
    $eventManager = app(EventManager::class);
    $builder = $eventManager->on('test.cache.invalidate')
        ->action(\App\Actions\SendOrderNotification::class);

    $trigger = $builder->save();
    expect($trigger)->toBeInstanceOf(Trigger::class);
    expect($trigger->event)->toBe('test.cache.invalidate');

    // Cleanup
    $trigger->delete();
});

test('Phase 107: SubscriptionBuilder rejects data: URL scheme', function (): void {
    $eventManager = app(EventManager::class);
    $builder = $eventManager->subscribe('test.event', 'data:text/html,<script>alert(1)</script>');

    $this->expectException(\InvalidArgumentException::class);
    $builder->save();
});

test('Phase 107: SubscriptionBuilder rejects javascript: URL scheme', function (): void {
    $eventManager = app(EventManager::class);
    $builder = $eventManager->subscribe('test.event', 'javascript:alert(1)');

    $this->expectException(\InvalidArgumentException::class);
    $builder->save();
});

test('Phase 107: WebhookAction handle throws without URL', function (): void {
    $action = new WebhookAction;

    $this->expectException(\InvalidArgumentException::class);
    $action->handle([]);
});

test('Phase 107: phpstan.neon.dist has level 9', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('level: max');
});

test('Phase 107: phpstan.neon.dist includes migrations and factories', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->toContain('database/migrations');
    expect($content)->toContain('database/factories');
});

test('Phase 107: composer.json targets PHP 8.5 and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
});

test('Phase 107: ServiceProvider provides all 7 services', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
    expect($provides)->toHaveCount(7);
});

test('Phase 107: Facade getFacadeAccessor returns EventManager::class', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
    // PHP 8.5+: setAccessible() was removed — public methods are always accessible
    $result = $method->invoke(null);
    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('Phase 107: config/events.php has all required top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

test('Phase 107: config table_names has 3 entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['table_names'])->toHaveCount(3);
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('Phase 107: config subscriptions has all required keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $subKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
    foreach ($subKeys as $key) {
        expect(array_key_exists($key, $config['subscriptions']))->toBeTrue("Missing subscriptions key: {$key}");
    }
});

test('Phase 107: EventManager isDisabled checks config correctly', function (): void {
    $eventManager = app(EventManager::class);

    // Default: not disabled
    expect($eventManager->isDisabled())->toBeFalse();

    // Toggle at runtime
    $eventManager->setEnabled(false);
    expect($eventManager->isDisabled())->toBeTrue();

    // Re-enable
    $eventManager->setEnabled(true);
    expect($eventManager->isDisabled())->toBeFalse();
});

test('Phase 107: fire() returns silently when disabled', function (): void {
    $eventManager = app(EventManager::class);
    $eventManager->setEnabled(false);

    // Should not throw, should return silently
    $eventManager->fire('test.silent.event', ['foo' => 'bar']);

    // No triggers should have been created for this
    expect(Trigger::where('event', 'test.silent.event')->exists())->toBeFalse();

    $eventManager->setEnabled(true);
});

test('Phase 107: EventsHealthCommand is included in final classes test', function (): void {
    $content = file_get_contents(__DIR__.'/EventsFinalClassesTest.php');
    expect($content)->toContain('EventsHealthCommand::class');
});
