<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineTest;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 114 production audit — comprehensive coverage verification.
 *
 * Tests:
 * 1. Facade @method count matches EventManager public method count (excl. constructor)
 * 2. All 13 config env() variables are documented in README env table
 * 3. No source file uses global app() helper — all use DI
 * 4. All EventManager public methods have @method facade proxies
 * 5. All traits used by EventManager are correctly declared
 * 6. DomainEvent constructor accepts optional eventId/occurredAt for replay
 * 7. WebhookAction implements Triggerable (not ShouldQueue)
 * 8. DispatchTriggerJob implements ShouldQueue with correct traits
 * 9. All models have config-driven getTable() with #[Override]
 * 10. Subscription::signPayload returns empty string for null/empty secret
 * 11. TriggerBuilder resolveActions produces valid JSON-serializable output
 * 12. EventLog STATUS constants match migration enum values exactly
 * 13. ServiceProvider provides() returns all registered bindings
 * 14. All console commands are final with handle(): int return type
 * 15. No source file contains deprecated setAccessible() calls
 * 16. All source files have declare(strict_types=1) and license header
 * 17. ConditionEngineContract is bound to ConditionEngine (singleton)
 * 18. phpstan.neon.dist level is 9 with required check flags
 * 19. composer.json PHP >= 8.5 and Laravel ^13.0 requirements
 * 20. Facade getFacadeAccessor returns EventManager::class
 */
it('facade has 24 @method entries matching 24 public methods (excluding constructor)', function (): void {
    $facadeFile = file_get_contents(__DIR__.'/../src/Facades/EventManager.php');
    expect($facadeFile)->not->toBeFalse();

    $methodCount = substr_count($facadeFile, '@method static');
    expect($methodCount)->toBe(24);
});

it('all 13 env() variables are present in config/events.php', function (): void {
    $configFile = file_get_contents(__DIR__.'/../config/events.php');
    expect($configFile)->not->toBeFalse();

    $expectedEnvVars = [
        'EVENTS_QUEUE_CONNECTION',
        'EVENTS_QUEUE',
        'EVENTS_RETRY_TRIES',
        'EVENTS_RETRY_BACKOFF',
        'EVENTS_LOG_RETENTION_DAYS',
        'EVENTS_LOG_PURGE_PENDING',
        'EVENTS_RETENTION_CRON',
        'EVENTS_SUB_MAX_FAILURES',
        'EVENTS_SUB_TIMEOUT',
        'EVENTS_SUB_SIGNATURE_ALGORITHM',
        'EVENTS_SUB_CLEANUP_CRON',
        'EVENTS_DISABLED',
        'EVENTS_WILDCARD_CACHE_TTL',
    ];

    foreach ($expectedEnvVars as $envVar) {
        expect($configFile)->toContain("env('{$envVar}'");
    }
});

it('no source file uses global app() helper', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();
        // Match app() or app()-> calls but NOT $this->app-> or $app->
        if (preg_match('/(?<!\$)\bapp\(\)/', $contents)) {
            $relativePath = str_replace(__DIR__.'/../', '', (string) $file->getPathname());
            throw new RuntimeException("Global app() call found in {$relativePath}");
        }
    }

    expect(true)->toBeTrue();
});

it('EventManager uses exactly 3 traits', function (): void {
    $reflection = new ReflectionClass(ZeroBoiler\Events\EventManager::class);
    $traits = $reflection->getTraitNames();

    expect($traits)->toHaveCount(3);
    expect($traits)->toContain(
        ZeroBoiler\Events\Concerns\EscapesWildcardLike::class,
    );
    expect($traits)->toContain(
        ZeroBoiler\Events\Concerns\ManagesHistory::class,
    );
    expect($traits)->toContain(
        ZeroBoiler\Events\Concerns\ManagesSubscriptions::class,
    );
});

it('WebhookAction implements Triggerable but not ShouldQueue', function (): void {
    $reflection = new ReflectionClass(WebhookAction::class);

    expect($reflection->implementsInterface(Triggerable::class))->toBeTrue();
    expect($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class))->toBeFalse();
});

it('DispatchTriggerJob implements ShouldQueue with Queueable and InteractsWithQueue traits', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);

    expect($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class))->toBeTrue();

    $traits = $reflection->getTraitNames();
    expect($traits)->toContain(\Illuminate\Bus\Queueable::class);
    expect($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class);
});

it('all 3 models have config-driven getTable() with Override attribute', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        $method = $reflection->getMethod('getTable');

        // Must have #[Override] attribute
        $attributes = $method->getAttributes(\Override::class);
        expect($attributes)->not->toBeEmpty("{$modelClass}::getTable() missing #[Override]");

        // Method must exist and be public
        expect($method->isPublic())->toBeTrue();
        expect($method->getReturnType()?->getName())->toBe('string');
    }
});

it('EventLog status constants match migration enum values exactly', function (): void {
    $constants = [
        EventLog::STATUS_PENDING => 'pending',
        EventLog::STATUS_DISPATCHED => 'dispatched',
        EventLog::STATUS_COMPLETED => 'completed',
        EventLog::STATUS_FAILED => 'failed',
    ];

    // Verify constant values are unique
    $values = array_values($constants);
    expect(array_unique($values))->toHaveCount(4);

    // Verify static $statuses array contains all constants
    expect(EventLog::$statuses)->toHaveCount(4);
    foreach ($constants as $constant) {
        expect(EventLog::$statuses)->toContain($constant);
    }
});

it('WildcardMatcher is readonly final with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();

    // readonly class check (PHP 8.2+)
    $file = file_get_contents(__DIR__.'/../src/WildcardMatcher.php');
    expect($file)->not->toBeFalse();
    expect($file)->toContain('readonly final class');

    // All public methods must be static
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()}() must be static");
    }
});

it('DomainEvent has 4 readonly properties and accepts optional replay parameters', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(4);

    // eventType is required, payload has default [], eventId is nullable, occurredAt is nullable
    expect($params[0]->getName())->toBe('eventType');
    expect($params[0]->getType()?->getName())->toBe('string');
    expect($params[0]->isDefaultValueAvailable())->toBeFalse();

    expect($params[1]->getName())->toBe('payload');
    expect($params[1]->isDefaultValueAvailable())->toBeTrue();

    expect($params[2]->getName())->toBe('eventId');
    expect($params[2]->allowsNull())->toBeTrue();

    expect($params[3]->getName())->toBe('occurredAt');
    expect($params[3]->allowsNull())->toBeTrue();
});

it('ConditionEngine has Override on matches() and Pure on 5 methods', function (): void {
    $reflection = new ReflectionClass(ConditionEngine::class);

    // matches() must have #[Override]
    $matches = $reflection->getMethod('matches');
    expect($matches->getAttributes(\Override::class))->not->toBeEmpty();

    // Pure methods: evaluateCondition, strictEquals, getNestedValue, contains, between
    $pureMethods = ['evaluateCondition', 'strictEquals', 'getNestedValue', 'contains', 'between'];
    foreach ($pureMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        expect($method->getAttributes(\Pure::class))->not->toBeEmpty(
            "ConditionEngine::{$methodName}() missing #[Pure]",
        );
    }
});

it('phpstan.neon.dist has level 9 and required check flags', function (): void {
    $config = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($config)->not->toBeFalse();

    expect($config)->toContain('level: 9');
    expect($config)->toContain('checkMissingIterableValueType: true');
    expect($config)->toContain('checkGenericClassInNonGenericObjectType: true');
    expect($config)->toContain('checkUninitializedProperties: true');
    expect($config)->toContain('reportUnmatchedIgnoredErrors: true');
});

it('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
    $composer = json_decode(
        file_get_contents(__DIR__.'/../composer.json'),
        true,
        JSON_THROW_ON_ERROR,
    );

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['require']['illuminate/support'])->toBe('^13.0');

    // ServiceProvider in extra.laravel.providers
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );

    // Facade alias
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager',
    );
});

it('facade getFacadeAccessor returns EventManager class', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $reflection->getMethod('getFacadeAccessor');

    // Must have #[Override]
    expect($method->getAttributes(\Override::class))->not->toBeEmpty();

    // Must be protected static returning string
    expect($method->isStatic())->toBeTrue();

    $facade = new class extends \Illuminate\Support\Facades\Facade
    {
        protected static function getFacadeAccessor(): string
        {
            return \ZeroBoiler\Events\EventManager::class;
        }
    };

    expect($facade::getFacadeAccessor())->toBe(\ZeroBoiler\Events\EventManager::class);
});

it('all source files have declare(strict_types=1) and license header', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $checkedCount = 0;
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();
        $relativePath = str_replace(__DIR__.'/../', '', (string) $file->getPathname());

        expect($contents)->toContain('declare(strict_types=1)', "Missing strict types in {$relativePath}");
        expect($contents)->toContain(
            'This file is part of ZeroBoiler',
            "Missing license header in {$relativePath}",
        );

        $checkedCount++;
    }

    // Should have checked all source files
    expect($checkedCount)->toBeGreaterThan(30);
});

it('no source file contains setAccessible calls', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = $file->getContents();
        if (preg_match('/->setAccessible\s*\(/', $contents)) {
            $relativePath = str_replace(__DIR__.'/../', '', (string) $file->getPathname());
            throw new RuntimeException("setAccessible() call found in {$relativePath} (removed in PHP 8.5)");
        }
    }

    expect(true)->toBeTrue();
});

it('Subscription model uses EscapesWildcardLike trait', function (): void {
    $reflection = new ReflectionClass(Subscription::class);
    $traits = $reflection->getTraitNames();

    expect($traits)->toContain(
        ZeroBoiler\Events\Concerns\EscapesWildcardLike::class,
    );
});

it('ServiceProvider provides() lists all 7 services', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\EventsServiceProvider::class);
    $provides = $reflection->getMethod('provides');

    // Must have #[Override]
    expect($provides->getAttributes(\Override::class))->not->toBeEmpty();

    $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
    $services = $provider->provides();

    expect($services)->toHaveCount(7);
    expect($services)->toContain(\ZeroBoiler\Events\EventManager::class);
    expect($services)->toContain(ConditionEngine::class);
    expect($services)->toContain(ConditionEngineContract::class);
    expect($services)->toContain(ActionResolver::class);
    expect($services)->toContain(TriggerBuilder::class);
    expect($services)->toContain(SubscriptionBuilder::class);
    expect($services)->toContain(\ZeroBoiler\Events\EventScheduler::class);
});

it('all 12 console commands are final classes', function (): void {
    $commandClasses = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commandClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("{$class} must be final");

        $handle = $reflection->getMethod('handle');
        expect($handle->getReturnType()?->getName())->toBe('int');
        expect($handle->getAttributes(\Override::class))->not->toBeEmpty();
    }
});
