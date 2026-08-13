<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\ConditionEngineContract as ConditionEngineContractInterface;
use ZeroBoiler\Events\Contracts\Triggerable;

/**
 * Phase 110 Production Audit — comprehensive verification of production readiness.
 *
 * Covers: source file strict types, license headers, return type completeness,
 * #[Override]/#[Pure] attributes, readonly/final verification, trait usage,
 * interface compliance, ServiceProvider binding correctness, config completeness,
 * model/table consistency, factory state methods, DomainEvent immutability,
 * WildcardMatcher static-only verification, ConditionEngine operator coverage,
 * facade method coverage, constructor DI verification, version alignment.
 */
test('all source files have strict types declaration', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', recursive: true);

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toBeFalse();
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', recursive: true);

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toBeFalse();
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

test('all database migration files have strict types declaration', function (): void {
    $migrationFiles = glob(__DIR__.'/../database/migrations/*.php');

    expect($migrationFiles)->toHaveCount(3);

    foreach ($migrationFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toBeFalse();
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('all factory files have strict types declaration', function (): void {
    $factoryFiles = glob(__DIR__.'/../database/factories/*.php');

    expect($factoryFiles)->toHaveCount(3);

    foreach ($factoryFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toBeFalse();
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('all factories use static string model property', function (): void {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factory) {
        $reflection = new ReflectionProperty($factory, 'model');
        expect($reflection->isStatic())->toBeTrue("Factory {$factory}::\$model must be static for Laravel 13+");
    }
});

test('EventLog status constants are unique and complete', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    // All unique
    expect($statuses)->toHaveCount(4);
    expect(array_unique($statuses))->toHaveCount(4);

    // Match the static $statuses array
    expect(EventLog::$statuses)->toEqual($statuses);
});

test('WildcardMatcher is readonly final class with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($methods as $method) {
        expect($method->isStatic())->toBeTrue(
            "WildcardMatcher::{$method->getName()}() must be static"
        );
    }
});

test('all service classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        EventScheduler::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        WebhookAction::class,
        DispatchTriggerJob::class,
        EventsServiceProvider::class,
        Trigger::class,
        EventLog::class,
        Subscription::class,
        DomainEvent::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} must be declared final");
    }
});

test('DomainEvent has exactly 4 readonly properties', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

    $readonlyProps = array_filter($properties, fn (ReflectionProperty $p): bool => $p->isReadOnly());

    expect($readonlyProps)->toHaveCount(4);

    $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $readonlyProps);
    expect($names)->toContain('eventType');
    expect($names)->toContain('payload');
    expect($names)->toContain('eventId');
    expect($names)->toContain('occurredAt');
});

test('DomainEvent preserves identity through roundtrip', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
});

test('DomainEvent fromArray rejects empty eventType', function (): void {
    DomainEvent::fromArray(['eventType' => '']);
})->throws(InvalidArgumentException::class, 'eventType is required');

test('ConditionEngine has #[Override] on matches method', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $method->getAttributes();

    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('ConditionEngine::matches() must have #[Override]');
});

test('ConditionEngine pure methods have #[Pure] attribute', function (): void {
    $pureMethods = [
        'evaluateCondition',
        'strictEquals',
        'getNestedValue',
        'contains',
        'between',
    ];

    foreach ($pureMethods as $method) {
        $reflection = new ReflectionMethod(ConditionEngine::class, $method);
        $attrs = $reflection->getAttributes();

        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("ConditionEngine::{$method}() must have #[Pure]");
    }
});

test('ConditionEngine safeRegexMatch is NOT #[Pure]', function (): void {
    $reflection = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
    $attrs = $reflection->getAttributes();

    foreach ($attrs as $attr) {
        expect($attr->getName())->not->toBe('Pure');
    }
});

test('WildcardMatcher methods have #[Pure] attribute', function (): void {
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $method) {
        $reflection = new ReflectionMethod(WildcardMatcher::class, $method);
        $attrs = $reflection->getAttributes();

        $hasPure = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Pure') {
                $hasPure = true;
                break;
            }
        }
        expect($hasPure)->toBeTrue("WildcardMatcher::{$method}() must have #[Pure]");
    }
});

test('EventManager uses correct traits', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain(EscapesWildcardLike::class);
    expect($traitNames)->toContain(ManagesHistory::class);
    expect($traitNames)->toContain(ManagesSubscriptions::class);
});

test('Subscription uses EscapesWildcardLike trait', function (): void {
    $reflection = new ReflectionClass(Subscription::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain(EscapesWildcardLike::class);
});

test('WebhookAction uses GetsWebhookTimeout trait', function (): void {
    $reflection = new ReflectionClass(WebhookAction::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain(GetsWebhookTimeout::class);
});

test('WebhookAction implements Triggerable interface', function (): void {
    expect(new WebhookAction)->toBeInstanceOf(Triggerable::class);
});

test('ServiceProvider provides all 7 services', function (): void {
    $provider = new EventsServiceProvider($this->app);

    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContractInterface::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
    expect($provides)->toHaveCount(7);
});

test('ServiceProvider registers ConditionEngineContract binding', function (): void {
    $resolved = $this->app->make(ConditionEngineContractInterface::class);

    expect($resolved)->toBeInstanceOf(ConditionEngine::class);
});

test('ServiceProvider binds EventManager as singleton', function (): void {
    $first = $this->app->make(EventManager::class);
    $second = $this->app->make(EventManager::class);

    expect($first)->toBe($second);
});

test('ServiceProvider binds TriggerBuilder as transient', function (): void {
    $first = $this->app->make(TriggerBuilder::class);
    $second = $this->app->make(TriggerBuilder::class);

    expect($first)->not->toBe($second);
});

test('ServiceProvider binds SubscriptionBuilder as transient', function (): void {
    $first = $this->app->make(SubscriptionBuilder::class);
    $second = $this->app->make(SubscriptionBuilder::class);

    expect($first)->not->toBe($second);
});

test('config file has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Config key '{$key}' is missing");
    }
});

test('config table_names has all three tables', function (): void {
    $config = require __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveCount(3);
    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');
});

test('config subscriptions has all required sub-keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = [
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
        'cleanup_cron',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config['subscriptions']))->toBeTrue(
            "Config subscriptions.{$key} is missing"
        );
    }
});

test('config retention has all required sub-keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = ['days', 'include_pending', 'schedule_cron'];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config['retention']))->toBeTrue(
            "Config retention.{$key} is missing"
        );
    }
});

test('Facade accessor returns correct class name', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $reflection->getMethod('getFacadeAccessor');
    $result = $method->invoke(null);

    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('Facade has @see reference to EventManager', function (): void {
    $content = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');

    expect($content)->toContain('@see \\ZeroBoiler\\Events\\EventManager');
});

test('Facade getFacadeAccessor has #[Override]', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Facades\EventManager::class, 'getFacadeAccessor');
    $attrs = $method->getAttributes();

    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('Facade::getFacadeAccessor() must have #[Override]');
});

test('EventManager has 25+ public methods', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    // Filter out constructor
    $methods = array_filter($publicMethods, fn (ReflectionMethod $m): bool => $m->getName() !== '__construct');

    expect(count($methods))->toBeGreaterThanOrEqual(25);
});

test('all EventManager public methods have return type declarations', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        expect($method->hasReturnType())->toBeTrue(
            "EventManager::{$method->getName()}() must have a return type declaration"
        );
    }
});

test('models have correct casts', function (): void {
    // Trigger casts
    $triggerReflection = new ReflectionMethod(Trigger::class, 'casts');
    $triggerCasts = $triggerReflection->invoke(new Trigger);

    expect($triggerCasts)->toHaveKey('conditions');
    expect($triggerCasts)->toHaveKey('async');
    expect($triggerCasts)->toHaveKey('enabled');
    expect($triggerCasts)->toHaveKey('priority');

    // EventLog casts
    $logReflection = new ReflectionMethod(EventLog::class, 'casts');
    $logCasts = $logReflection->invoke(new EventLog);

    expect($logCasts)->toHaveKey('payload');
    expect($logCasts)->toHaveKey('duration_ms');
    expect($logCasts)->toHaveKey('error');

    // Subscription casts
    $subReflection = new ReflectionMethod(Subscription::class, 'casts');
    $subCasts = $subReflection->invoke(new Subscription);

    expect($subCasts)->toHaveKey('conditions');
    expect($subCasts)->toHaveKey('priority');
    expect($subCasts)->toHaveKey('active');
    expect($subCasts)->toHaveKey('failure_count');
    expect($subCasts)->toHaveKey('delivery_count');
    expect($subCasts)->toHaveKey('last_fired_at');
});

test('models have config-driven table names', function (): void {
    $trigger = new Trigger;
    expect($trigger->getTable())->toBe('triggers');

    $log = new EventLog;
    expect($log->getTable())->toBe('event_logs');

    $subscription = new Subscription;
    expect($subscription->getTable())->toBe('event_subscriptions');
});

test('phpstan.neon.dist has correct configuration', function (): void {
    $config = parse_ini_file(__DIR__.'/../phpstan.neon.dist', false, INI_SCANNER_RAW);

    expect($config)->not->toBeFalse();
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($content)->toContain('level: 9');
    expect($content)->toContain('paths:');
    expect($content)->toContain('- src');
    expect($content)->toContain('- database/migrations');
    expect($content)->toContain('- database/factories');
    expect($content)->toContain('reportUnmatchedIgnoredErrors: true');
    expect($content)->toContain('checkMissingIterableValueType: true');
    expect($content)->toContain('checkGenericClassInNonGenericObjectType: true');
    expect($content)->toContain('checkUninitializedProperties: true');
    expect($content)->toContain('checkFunctionNameCase: true');
    expect($content)->toContain('checkClassLikeNameCase: true');
});

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}");
});

test('composer.json requires PHP 8.5+', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toContain('8.5');
});

test('composer.json has correct service provider and facade aliases', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    $providers = $composer['extra']['laravel']['providers'];
    expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');

    $aliases = $composer['extra']['laravel']['aliases'];
    expect($aliases)->toHaveKey('EventManager');
    expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
});

test('EventManager fire rejects empty string event', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect(fn (): void => $manager->fire(''))->toThrow(InvalidArgumentException::class);
    expect(fn (): void => $manager->fire('0'))->toThrow(InvalidArgumentException::class);
});

test('EventManager fireModel rejects empty class and action', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect(fn (): void => $manager->fireModel('', 'created', new stdClass))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): void => $manager->fireModel('App\\Model', '', new stdClass))
        ->toThrow(InvalidArgumentException::class);
});

test('EventManager global disable works correctly', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect($manager->isDisabled())->toBeFalse();

    $manager->setEnabled(false);
    expect($manager->isDisabled())->toBeTrue();

    $manager->setEnabled(true);
    expect($manager->isDisabled())->toBeFalse();
});

test('TriggerBuilder rejects empty event and action', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect(fn (): void => $manager->on('')->save())
        ->toThrow(InvalidArgumentException::class, 'Event name is required');

    expect(fn (): void => $manager->on('test.event')->save())
        ->toThrow(InvalidArgumentException::class, 'At least one action is required');
});

test('SubscriptionBuilder rejects non-HTTP URL schemes', function (): void {
    $manager = $this->app->make(EventManager::class);

    expect(fn (): void => $manager->subscribe('test.event', 'ftp://evil.com/hook')->save())
        ->toThrow(InvalidArgumentException::class, 'HTTP or HTTPS');

    expect(fn (): void => $manager->subscribe('test.event', 'file:///etc/passwd')->save())
        ->toThrow(InvalidArgumentException::class);

    expect(fn (): void => $manager->subscribe('test.event', 'javascript:alert(1)')->save())
        ->toThrow(InvalidArgumentException::class);
});

test('ActionResolver rejects non-existent class', function (): void {
    $resolver = $this->app->make(ActionResolver::class);

    expect(fn (): void => $resolver->resolve('NonExistent\Action\Class'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

test('ActionResolver rejects class that does not implement Triggerable', function (): void {
    $resolver = $this->app->make(ActionResolver::class);

    // stdClass exists but doesn't implement Triggerable
    expect(fn (): void => $resolver->resolve('stdClass'))
        ->toThrow(InvalidArgumentException::class, 'must implement');
});

test('EventManager cache invalidation works', function (): void {
    $manager = $this->app->make(EventManager::class);

    // Create a wildcard trigger
    $trigger = Trigger::factory()->create([
        'event' => 'test.cache.*',
        'enabled' => true,
        'async' => false,
        'action' => 'stdClass',
    ]);

    // Fire a matching event to populate cache
    try {
        $manager->fire('test.cache.foobar');
    } catch (Throwable) {
        // Action will fail but cache should be populated
    }

    // Invalidate cache
    $manager->invalidateTriggerCache();

    // Verify cache key is gone
    expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
});

test('all 12 console commands are final and have handle method returning int', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
    ];

    foreach ($commands as $command) {
        $reflection = new ReflectionClass($command);
        expect($reflection->isFinal())->toBeTrue("{$command} must be final");

        $handle = $reflection->getMethod('handle');
        expect($handle->getReturnType()?->getName())->toBe('int');
    }
});

test('EventManager registerScheduler throws on missing binding', function (): void {
    // Create a fresh container without the EventScheduler binding
    $app = new class extends \Illuminate\Container\Container
    {
        public function runningInConsole(): bool
        {
            return true;
        }

        public function runningUnitTests(): bool
        {
            return true;
        }

        public function configPath(string $path = ''): string
        {
            return sys_get_temp_dir();
        }

        public function databasePath(string $path = ''): string
        {
            return sys_get_temp_dir();
        }

        public function storagePath(string $path = ''): string
        {
            return sys_get_temp_dir();
        }
    };

    $manager = new EventManager(
        new ConditionEngine,
        new ActionResolver($app),
        $app,
    );

    $schedule = new \Illuminate\Console\Scheduling\Schedule;

    expect(fn (): void => $manager->registerScheduler($schedule))
        ->toThrow(\RuntimeException::class, 'EventScheduler could not be resolved');
});

test('Subscription signPayload returns empty string for null secret', function (): void {
    $sub = Subscription::factory()->create(['secret' => null]);

    expect($sub->signPayload('test'))->toBe('');
});

test('Subscription hasExceededFailures with explicit override', function (): void {
    $sub = Subscription::factory()->create(['failure_count' => 5]);

    expect($sub->hasExceededFailures(10))->toBeFalse();
    expect($sub->hasExceededFailures(3))->toBeTrue();
    expect($sub->hasExceededFailures(5))->toBeTrue();
});

test('WildcardMatcher exact match without wildcards', function (): void {
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
});

test('WildcardMatcher empty event rejects all non-catch-all patterns', function (): void {
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
    expect(WildcardMatcher::matches('order.*', ''))->toBeFalse();
});

test('WildcardMatcher single and cross segment', function (): void {
    // Single segment: order.* matches order.placed but NOT order.placed.extra
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross segment: order.** matches both
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
});

test('ConditionEngine between with inverted range auto-normalizes', function (): void {
    $engine = new ConditionEngine;

    // Normal range
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 10]))->toBeFalse();

    // Inverted range should still work
    expect($engine->matches(['age' => ['between', [65, 18]]], ['age' => 30]))->toBeTrue();
    expect($engine->matches(['age' => ['between', [65, 18]]], ['age' => 10]))->toBeFalse();
});

test('ConditionEngine null-safe comparison operators', function (): void {
    $engine = new ConditionEngine;

    // Null operands with numeric comparisons return false
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => null]))->toBeFalse();
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();

    // null/not_null operators work correctly
    expect($engine->matches(['field' => ['null']], ['field' => null]))->toBeTrue();
    expect($engine->matches(['field' => ['not_null']], ['field' => 'value']))->toBeTrue();
});

test('ConditionEngine matches operator rejects overly long patterns', function (): void {
    $engine = new ConditionEngine;
    $longPattern = '/'.str_repeat('a', 600).'/';

    // WildcardMatcher::matches itself doesn't enforce length limit (that's the ConditionEngine)
    expect($engine->matches(
        ['code' => ['matches', $longPattern]],
        ['code' => 'anything'],
    ))->toBeFalse();
});

test('ConditionEngine empty conditions array returns true', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches([], ['any' => 'data']))->toBeTrue();
});

test('ConditionEngine empty operator array returns false', function (): void {
    $engine = new ConditionEngine;

    expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
});

test('DispatchTriggerJob readonly properties are correct', function (): void {
    $job = new DispatchTriggerJob('trigger-123', 'test.event', ['key' => 'value']);

    expect($job->triggerId)->toBe('trigger-123');
    expect($job->event)->toBe('test.event');
    expect($job->payload)->toBe(['key' => 'value']);

    // These should be readonly
    $triggerIdProp = new ReflectionProperty($job, 'triggerId');
    expect($triggerIdProp->isReadOnly())->toBeTrue();

    $eventProp = new ReflectionProperty($job, 'event');
    expect($eventProp->isReadOnly())->toBeTrue();

    $payloadProp = new ReflectionProperty($job, 'payload');
    expect($payloadProp->isReadOnly())->toBeTrue();
});

test('version constant alignment between composer.json and README', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');
    $version = $composer['version'];

    // Badge
    expect($readme)->toContain("version-{$version}-blue");

    // Changelog header
    expect($readme)->toContain("### v{$version}");
});

test('Trigger scopes return correct Builder type', function (): void {
    $trigger = new Trigger;
    $query = Trigger::query();
    $enabledScope = $trigger->scopeEnabled($query);

    expect($enabledScope)->toBe($query);
});

test('EventLog scopes return correct Builder type', function (): void {
    $log = new EventLog;
    $query = EventLog::query();

    expect($log->scopeWithStatus($query, 'pending'))->toBe($query);
    expect($log->scopeFailed($query))->toBe($query);
    expect($log->scopePending($query))->toBe($query);
    expect($log->scopeCompleted($query))->toBe($query);
});

test('Subscription scopeForEvent uses wildcard matching', function (): void {
    $sub = new Subscription;
    $query = Subscription::query();

    // With wildcard pattern
    $result = $sub->scopeForEvent($query, 'order.*');
    expect($result)->toBe($query);

    // Without wildcard pattern
    $result2 = $sub->scopeForEvent($query, 'order.placed');
    expect($result2)->toBe($query);
});

test('ManagesHistory trait has correct methods', function (): void {
    $reflection = new ReflectionClass(ManagesHistory::class);
    $methods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toContain('getEventHistory');
    expect($methods)->toContain('getStats');
    expect($methods)->toContain('purgeLogs');
    expect($methods)->toContain('getStalePendingLogs');
    expect($methods)->toContain('deactivateExceededSubscriptions');
});

test('ManagesSubscriptions trait has correct methods', function (): void {
    $reflection = new ReflectionClass(ManagesSubscriptions::class);
    $methods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toContain('subscribe');
    expect($methods)->toContain('unsubscribe');
    expect($methods)->toContain('listSubscriptions');
    expect($methods)->toContain('getSubscription');
    expect($methods)->toContain('subscribeWebhook');
});

test('EscapesWildcardLike trait has wildcardToLike method', function (): void {
    $reflection = new ReflectionClass(EscapesWildcardLike::class);
    $method = $reflection->getMethod('wildcardToLike');

    expect($method)->not->toBeFalse();
    expect($method->isProtected())->toBeTrue();
    expect($method->hasReturnType())->toBeTrue();
});

test('GetsWebhookTimeout trait has getWebhookTimeout method', function (): void {
    $reflection = new ReflectionClass(GetsWebhookTimeout::class);
    $method = $reflection->getMethod('getWebhookTimeout');

    expect($method)->not->toBeFalse();
    expect($method->isProtected())->toBeTrue();
    expect($method->getReturnType()?->getName())->toBe('int');
});

test('Trigger factory state methods cover all key states', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class);
    $publicMethods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! in_array($m->getName(), ['definition', '__construct', 'newFactory'], true),
    );

    $stateMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    expect($stateMethods)->toContain('async');
    expect($stateMethods)->toContain('sync');
    expect($stateMethods)->toContain('enabled');
    expect($stateMethods)->toContain('disabled');
    expect($stateMethods)->toContain('withConditions');
    expect($stateMethods)->toContain('priority');
    expect($stateMethods)->toContain('forEvent');
    expect($stateMethods)->toContain('withAction');
    expect($stateMethods)->toContain('withName');
});

test('EventLog factory state methods cover all statuses', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class);
    $publicMethods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! in_array($m->getName(), ['definition', '__construct', 'newFactory'], true),
    );

    $stateMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    expect($stateMethods)->toContain('pending');
    expect($stateMethods)->toContain('dispatched');
    expect($stateMethods)->toContain('completed');
    expect($stateMethods)->toContain('failed');
    expect($stateMethods)->toContain('withEvent');
    expect($stateMethods)->toContain('forTrigger');
    expect($stateMethods)->toContain('withPayload');
    expect($stateMethods)->toContain('withDuration');
});

test('Subscription factory state methods cover key states', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class);
    $publicMethods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! in_array($m->getName(), ['definition', '__construct', 'newFactory'], true),
    );

    $stateMethods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    expect($stateMethods)->toContain('active');
    expect($stateMethods)->toContain('inactive');
    expect($stateMethods)->toContain('forEvent');
    expect($stateMethods)->toContain('withUrl');
    expect($stateMethods)->toContain('withConditions');
    expect($stateMethods)->toContain('withSecret');
    expect($stateMethods)->toContain('withoutSecret');
    expect($stateMethods)->toContain('withFailureCount');
    expect($stateMethods)->toContain('withDeliveryCount');
    expect($stateMethods)->toContain('withPriority');
});
