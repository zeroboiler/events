<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
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

// ─── Source File Strict Types & License Header Verification ───

test('all source files have declare(strict_types=1)', function () {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');

    if ($srcFiles === false) {
        $srcFiles = [];
    }

    // Also search subdirectories
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $allFiles = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $allFiles[] = $file->getPathname();
        }
    }

    expect($allFiles)->not->toBeEmpty();

    foreach ($allFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toBeFalse();
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

// ─── Interface Contract Compliance ───

test('Triggerable interface has handle method with correct signature', function () {
    $reflection = new ReflectionClass(Triggerable::class);
    $method = $reflection->getMethod('handle');

    expect($method->getName())->toBe('handle');
    expect($method->getParameters())->toHaveCount(1);
    expect($method->getParameters()[0]->getName())->toBe('payload');
    expect($method->getReturnType()?->getName())->toBe('void');
});

test('ConditionEngineContract has matches method with correct signature', function () {
    $reflection = new ReflectionClass(ConditionEngineContract::class);
    $method = $reflection->getMethod('matches');

    expect($method->getName())->toBe('matches');
    expect($method->getParameters())->toHaveCount(2);
    expect($method->getReturnType()?->getName())->toBe('bool');
});

test('ConditionEngine implements ConditionEngineContract', function () {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

// ─── WebhookAction Triggerable Compliance ───

test('WebhookAction implements Triggerable', function () {
    $action = new \ZeroBoiler\Events\Actions\WebhookAction;
    expect($action)->toBeInstanceOf(Triggerable::class);
});

test('WebhookAction handle method has #[Override]', function () {
    $reflection = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');
    $attributes = $reflection->getAttributes(\Override::class);

    expect($attributes)->toHaveCount(1);
});

// ─── WildcardMatcher Static-Only Verification ───

test('WildcardMatcher is readonly and final', function () {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

test('WildcardMatcher has only static methods', function () {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue(
            "WildcardMatcher::{$method->getName()}() must be static",
        );
    }
});

test('WildcardMatcher no public constructor', function () {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->toBeNull();
});

// ─── DomainEvent Value Object Verification ───

test('DomainEvent is final and has 4 readonly properties', function () {
    $reflection = new ReflectionClass(DomainEvent::class);
    $defaults = $reflection->getDefaultProperties();

    expect($reflection->isFinal())->toBeTrue();

    // 2 promoted readonly properties + 2 manually assigned readonly properties
    $roProps = array_filter(
        $reflection->getProperties(),
        fn (ReflectionProperty $p): bool => $p->isReadOnly(),
    );
    expect(count($roProps))->toBe(4);

    $names = array_map(fn (ReflectionProperty $p): string => $p->getName(), $roProps);
    expect($names)->toContain('eventId');
    expect($names)->toContain('eventType');
    expect($names)->toContain('payload');
    expect($names)->toContain('occurredAt');
});

test('DomainEvent occur() factory returns same class', function () {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    expect($event)->toBeInstanceOf(DomainEvent::class);
});

test('DomainEvent fromArray roundtrip preserves identity', function () {
    $original = DomainEvent::occur('order.created', ['order_id' => 42]);
    $data = $original->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

test('DomainEvent fromArray rejects empty eventType', function () {
    DomainEvent::fromArray([]);
})->throws(InvalidArgumentException::class);

// ─── ServiceProvider Completeness ───

test('EventsServiceProvider provides all 7 services', function () {
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

test('EventsServiceProvider has #[Override] on register, boot, provides', function () {
    $reflection = new ReflectionClass(EventsServiceProvider::class);

    $register = $reflection->getMethod('register');
    expect($register->getAttributes(\Override::class))->toHaveCount(1);

    $boot = $reflection->getMethod('boot');
    expect($boot->getAttributes(\Override::class))->toHaveCount(1);

    $provides = $reflection->getMethod('provides');
    expect($provides->getAttributes(\Override::class))->toHaveCount(1);
});

test('EventsServiceProvider is final', function () {
    $reflection = new ReflectionClass(EventsServiceProvider::class);
    expect($reflection->isFinal())->toBeTrue();
});

// ─── Facade Verification ───

test('Facade getFacadeAccessor returns EventManager FQN', function () {
    $reflection = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    // PHP 8.5: setAccessible() removed — reflection methods are directly accessible
    $result = $reflection->invoke(null);

    expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('Facade getFacadeAccessor has #[Override]', function () {
    $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    expect($method->getAttributes(\Override::class))->toHaveCount(1);
});

test('Facade is final', function () {
    expect((new ReflectionClass(EventManagerFacade::class))->isFinal())->toBeTrue();
});

// ─── EventManager Public API Surface ───

test('EventManager has all expected public methods with return types', function () {
    $reflection = new ReflectionClass(EventManager::class);
    $expectedMethods = [
        'on', 'register', 'fire', 'fireModel',
        'enable', 'disable', 'deleteTrigger',
        'invalidateTriggerCache', 'isDisabled', 'setEnabled',
        'listTriggers', 'getTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
        'subscribeWebhook',
        'getEventHistory', 'getStats', 'purgeLogs',
        'getStalePendingLogs', 'deactivateExceededSubscriptions',
        'executeTrigger', 'registerScheduler',
    ];

    foreach ($expectedMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        expect($method->isPublic())->toBeTrue("EventManager::{$methodName}() must be public");
        expect($method->getReturnType())->not->toBeNull("EventManager::{$methodName}() must have a return type");
    }

    // Count total public methods (at least 25)
    $publicMethods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isStatic(),
    );
    expect(count($publicMethods))->toBeGreaterThanOrEqual(25);
});

test('EventManager is final', function () {
    $reflection = new ReflectionClass(EventManager::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('EventManager constructor has promoted readonly properties', function () {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();
    $params = $constructor->getParameters();

    expect($params)->toHaveCount(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue("Constructor param {$param->getName()} must be promoted");
        $prop = $reflection->getProperty($param->getName());
        expect($prop->isReadOnly())->toBeTrue("Property {$param->getName()} must be readonly");
    }
});

// ─── DispatchTriggerJob Verification ───

test('DispatchTriggerJob implements ShouldQueue', function () {
    $job = new DispatchTriggerJob('id', 'event', []);
    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('DispatchTriggerJob has #[Override] on handle and failed', function () {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);

    $handle = $reflection->getMethod('handle');
    expect($handle->getAttributes(\Override::class))->toHaveCount(1);

    $failed = $reflection->getMethod('failed');
    expect($failed->getAttributes(\Override::class))->toHaveCount(1);
});

test('DispatchTriggerJob readonly constructor properties are set', function () {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);

    $triggerIdProp = $reflection->getProperty('triggerId');
    expect($triggerIdProp->isReadOnly())->toBeTrue();
    expect($triggerIdProp->getType()?->getName())->toBe('string');

    $eventProp = $reflection->getProperty('event');
    expect($eventProp->isReadOnly())->toBeTrue();

    $payloadProp = $reflection->getProperty('payload');
    expect($payloadProp->isReadOnly())->toBeTrue();
    expect($payloadProp->getType()?->getName())->toBe('array');
});

test('DispatchTriggerJob is final', function () {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    expect($reflection->isFinal())->toBeTrue();
});

// ─── Model Verification ───

test('all models are final with UUID key type and non-incrementing', function () {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isFinal())->toBeTrue("{$modelClass} must be final");

        $keyTypeProp = $reflection->getProperty('keyType');
        expect($keyTypeProp->isPublic())->toBeTrue();
        expect($keyTypeProp->getDefaultValue())->toBe('string');

        $incrementingProp = $reflection->getProperty('incrementing');
        expect($incrementingProp->getDefaultValue())->toBeFalse();
    }
});

test('EventLog status constants are unique and complete', function () {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    // All unique
    expect(array_unique($statuses))->toHaveCount(4);

    // Match $statuses array
    expect(EventLog::$statuses)->toEqual($statuses);
});

test('all models have getTable, boot, newFactory, casts with #[Override]', function () {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $modelClass) {
        $reflection = new ReflectionClass($modelClass);

        $getTable = $reflection->getMethod('getTable');
        expect($getTable->getAttributes(\Override::class))->toHaveCount(1);

        $boot = $reflection->getMethod('boot');
        expect($boot->getAttributes(\Override::class))->toHaveCount(1);

        $newFactory = $reflection->getMethod('newFactory');
        expect($newFactory->getAttributes(\Override::class))->toHaveCount(1);

        $casts = $reflection->getMethod('casts');
        expect($casts->getAttributes(\Override::class))->toHaveCount(1);
    }
});

// ─── Builder Verification ───

test('TriggerBuilder is final with constructor injection', function () {
    $reflection = new ReflectionClass(TriggerBuilder::class);
    expect($reflection->isFinal())->toBeTrue();

    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();
    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('eventManager');
    expect($params[0]->isPromoted())->toBeTrue();
    expect($params[0]->isReadOnly())->toBeTrue();
});

test('SubscriptionBuilder is final with constructor injection', function () {
    $reflection = new ReflectionClass(SubscriptionBuilder::class);
    expect($reflection->isFinal())->toBeTrue();

    $constructor = $reflection->getConstructor();
    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('eventManager');
    expect($params[0]->isPromoted())->toBeTrue();
    expect($params[0]->isReadOnly())->toBeTrue();
});

// ─── EventScheduler Verification ───

test('EventScheduler is final with constructor injection', function () {
    $reflection = new ReflectionClass(EventScheduler::class);
    expect($reflection->isFinal())->toBeTrue();

    $constructor = $reflection->getConstructor();
    $params = $constructor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('app');
    expect($params[0]->isPromoted())->toBeTrue();
    expect($params[0]->isReadOnly())->toBeTrue();
});

// ─── ConditionEngine #[Pure] Verification ───

test('ConditionEngine pure methods are correctly annotated', function () {
    $reflection = new ReflectionClass(ConditionEngine::class);
    $pureMethods = ['evaluateCondition', 'strictEquals', 'getNestedValue', 'contains', 'between'];

    foreach ($pureMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        $attrs = $method->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1, "ConditionEngine::{$methodName}() must have #[Pure]");
    }

    // safeRegexMatch must NOT be #[Pure]
    $safeRegex = $reflection->getMethod('safeRegexMatch');
    expect($safeRegex->getAttributes(\Pure::class))->toHaveCount(0);
});

test('ConditionEngine #[Override] on matches()', function () {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    expect($method->getAttributes(\Override::class))->toHaveCount(1);
});

test('WildcardMatcher pure methods are correctly annotated', function () {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    $pureMethods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($pureMethods as $methodName) {
        $method = $reflection->getMethod($methodName);
        $attrs = $method->getAttributes(\Pure::class);
        expect($attrs)->toHaveCount(1, "WildcardMatcher::{$methodName}() must have #[Pure]");
    }
});

// ─── Trait Usage Verification ───

test('EventManager uses all 3 expected traits', function () {
    $reflection = new ReflectionClass(EventManager::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('EscapesWildcardLike');
    expect($traitNames)->toContain('ManagesHistory');
    expect($traitNames)->toContain('ManagesSubscriptions');
});

test('Subscription uses EscapesWildcardLike trait', function () {
    $reflection = new ReflectionClass(Subscription::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('EscapesWildcardLike');
});

test('WebhookAction uses GetsWebhookTimeout trait', function () {
    $reflection = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
    $traitNames = array_map(
        fn (ReflectionClass $t): string => $t->getShortName(),
        $reflection->getTraits(),
    );

    expect($traitNames)->toContain('GetsWebhookTimeout');
});

// ─── Config Completeness Verification ───

test('config file has all 7 top-level keys', function () {
    $config = include __DIR__.'/../config/events.php';

    $expectedKeys = [
        'table_names', 'queue', 'retry', 'retention',
        'subscriptions', 'disabled', 'wildcard_cache_ttl',
    ];

    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Config key '{$key}' is missing");
    }
});

test('config table_names has all 3 entries', function () {
    $config = include __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');
});

test('config subscriptions has all 5 keys', function () {
    $config = include __DIR__.'/../config/events.php';

    $subKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm', 'cleanup_cron'];
    foreach ($subKeys as $key) {
        expect(array_key_exists($key, $config['subscriptions']))->toBeTrue("subscriptions.{$key} is missing");
    }
});

test('config retention has all 3 keys', function () {
    $config = include __DIR__.'/../config/events.php';

    expect($config['retention'])->toHaveKey('days');
    expect($config['retention'])->toHaveKey('include_pending');
    expect($config['retention'])->toHaveKey('schedule_cron');
});

// ─── Factory Verification ───

test('all factories have static $model property', function () {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factoryClass) {
        $reflection = new ReflectionClass($factoryClass);
        $prop = $reflection->getProperty('model');
        expect($prop->isStatic())->toBeTrue("{$factoryClass}::\$model must be static");
        expect($prop->isPublic())->toBeTrue("{$factoryClass}::\$model must be public");
    }
});

test('all factories have definition method with return type', function () {
    $factories = [
        \ZeroBoiler\Events\Database\Factories\TriggerFactory::class,
        \ZeroBoiler\Events\Database\Factories\EventLogFactory::class,
        \ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class,
    ];

    foreach ($factories as $factoryClass) {
        $method = new ReflectionMethod($factoryClass, 'definition');
        expect($method->getReturnType()?->getName())->toBe('array');
    }
});

// ─── PHPStan Config Verification ───

test('phpstan.neon.dist has level 9 and correct paths', function () {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($content)->not->toBeFalse();

    expect($content)->toContain('level: max');
    expect($content)->toContain('paths:');
    expect($content)->toContain('- src');
    expect($content)->toContain('- database/migrations');
    expect($content)->toContain('- database/factories');
});

test('phpstan.neon.dist has all required strict checks', function () {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    $checks = [
        'checkMissingIterableValueType',
        'checkGenericClassInNonGenericObjectType',
        'checkUninitializedProperties',
        'checkFunctionNameCase',
        'checkClassLikeNameCase',
        'checkPropertyHookNameCase',
        'checkEnumCaseValueNameCase',
    ];

    foreach ($checks as $check) {
        expect($content)->toContain($check);
    }
});

// ─── Composer.json Verification ───

test('composer.json version matches README badge', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBeString();
    expect($readme)->toContain("version-{$composer['version']}");
});

test('composer.json requires PHP 8.5+', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

test('composer.json has correct service provider and alias', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager',
    );
});

// ─── Console Commands Verification ───

test('all 12 console commands are final with int handle return type', function () {
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

    foreach ($commandClasses as $commandClass) {
        $reflection = new ReflectionClass($commandClass);
        expect($reflection->isFinal())->toBeTrue("{$commandClass} must be final");

        $handle = $reflection->getMethod('handle');
        expect($handle->getReturnType()?->getName())->toBe('int');
    }
});

// ─── Migration Verification ───

test('migrations use config-driven table names', function () {
    $migrationDir = __DIR__.'/../database/migrations';

    $files = glob($migrationDir.'/*.php');
    expect($files)->not->toBeEmpty();
    expect(count($files))->toBe(3);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        // All migrations must have declare(strict_types=1)
        expect($content)->toContain('declare(strict_types=1)');
        // All migrations must reference config('events.table_names
        expect($content)->toContain("config('events.table_names.");
    }
});

test('migrations have license headers', function () {
    $files = glob(__DIR__.'/../database/migrations/*.php');

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

// ─── setAccessible Removal Verification ───

test('no setAccessible calls in test files', function () {
    $testFiles = glob(__DIR__.'/*.php');

    if ($testFiles === false) {
        $testFiles = [];
    }

    foreach ($testFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('setAccessible(');
    }
});

// ─── Version Alignment ───

test('composer.json version is valid semver', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['version'])->toMatch(
        '/^\d+\.\d+\.\d+$/',
        'Version must follow semver (e.g., 4.38.0)',
    );
});

// ─── ConditionEngine Operator Coverage ───

test('ConditionEngine supports all 19 documented operators', function () {
    $operators = [
        '>', '>=', '<', '<=',
        '=', '===', '!=', '!==',
        'in', 'not_in',
        'contains', 'not_contains',
        'between',
        'null', 'not_null',
        'empty', 'not_empty',
        'starts_with', 'ends_with',
        'matches',
    ];

    $engine = new ConditionEngine;

    foreach ($operators as $operator) {
        switch ($operator) {
            case '>':
                expect($engine->matches(['val' => [$operator, 5]], ['val' => 10]))->toBeTrue();
                break;
            case '>=':
                expect($engine->matches(['val' => [$operator, 5]], ['val' => 5]))->toBeTrue();
                break;
            case '<':
                expect($engine->matches(['val' => [$operator, 10]], ['val' => 5]))->toBeTrue();
                break;
            case '<=':
                expect($engine->matches(['val' => [$operator, 5]], ['val' => 5]))->toBeTrue();
                break;
            case '=':
                expect($engine->matches(['val' => 'hello'], ['val' => 'hello']))->toBeTrue();
                break;
            case '===':
                expect($engine->matches(['val' => [$operator, true]], ['val' => true]))->toBeTrue();
                break;
            case '!=':
                expect($engine->matches(['val' => [$operator, 'a']], ['val' => 'b']))->toBeTrue();
                break;
            case '!==':
                expect($engine->matches(['val' => [$operator, 1]], ['val' => '1']))->toBeTrue();
                break;
            case 'in':
                expect($engine->matches(['val' => [$operator, ['a', 'b']]], ['val' => 'a']))->toBeTrue();
                break;
            case 'not_in':
                expect($engine->matches(['val' => [$operator, ['a']]], ['val' => 'b']))->toBeTrue();
                break;
            case 'contains':
                expect($engine->matches(['val' => [$operator, 'hello']], ['val' => 'say hello world']))->toBeTrue();
                break;
            case 'not_contains':
                expect($engine->matches(['val' => [$operator, 'xyz']], ['val' => 'hello']))->toBeTrue();
                break;
            case 'between':
                expect($engine->matches(['val' => [$operator, [1, 10]]], ['val' => 5]))->toBeTrue();
                break;
            case 'null':
                expect($engine->matches(['val' => [$operator]], ['val' => null]))->toBeTrue();
                break;
            case 'not_null':
                expect($engine->matches(['val' => [$operator]], ['val' => 'exists']))->toBeTrue();
                break;
            case 'empty':
                expect($engine->matches(['val' => [$operator]], ['val' => null]))->toBeTrue();
                break;
            case 'not_empty':
                expect($engine->matches(['val' => [$operator]], ['val' => 'data']))->toBeTrue();
                break;
            case 'starts_with':
                expect($engine->matches(['val' => [$operator, 'pre']], ['val' => 'prefix']))->toBeTrue();
                break;
            case 'ends_with':
                expect($engine->matches(['val' => [$operator, 'fix']], ['val' => 'suffix']))->toBeTrue();
                break;
            case 'matches':
                expect($engine->matches(['val' => [$operator, '/^\\d+$/']], ['val' => '123']))->toBeTrue();
                break;
        }
    }
});
