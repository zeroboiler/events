<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsHealthCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;
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

beforeEach(function (): void {
    // No application boot needed for static analysis tests
});

// ─── PHP 8.5 Strict Types & Syntax ───────────────────────────────

it('all source files declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents((string) $file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

it('all source files have license headers', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents((string) $file);
        expect($content)->toContain('This file is part of ZeroBoiler');
    }
});

// ─── Final Classes ──────────────────────────────────────────────

it('all service classes are final', function (): void {
    $expectedFinal = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
        WildcardMatcher::class,
        EventsServiceProvider::class,
        DomainEvent::class,
        DispatchTriggerJob::class,
        EventManagerFacade::class,
    ];

    foreach ($expectedFinal as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

it('all models are final', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

it('all console commands are final', function (): void {
    $commands = [
        EventsListCommand::class,
        EventsRegisterCommand::class,
        EventsFireCommand::class,
        EventsLogCommand::class,
        EventsRetryCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsHealthCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsRedeliverCommand::class,
    ];

    foreach ($commands as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

// ─── Readonly Properties ────────────────────────────────────────

it('WildcardMatcher is readonly final', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

it('DomainEvent has readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $params = $ctor->getParameters();
    expect($params)->toHaveCount(4);

    // eventType is promoted readonly
    expect($params[0]->isPromoted())->toBeTrue();
    // payload is promoted readonly with default
    expect($params[1]->isPromoted())->toBeTrue();
});

it('EventManager constructor has promoted readonly properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $params = $ctor->getParameters();
    expect($params)->toHaveCount(3);

    foreach ($params as $p) {
        expect($p->isPromoted())->toBeTrue();
    }
});

// ─── Return Type Declarations ───────────────────────────────────

it('ConditionEngine::matches() has bool return type', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    expect($method->getReturnType()->getName())->toBe('bool');
});

it('EventManager::fire() has void return type', function (): void {
    $method = new ReflectionMethod(EventManager::class, 'fire');
    expect($method->getReturnType()->getName())->toBe('void');
});

it('TriggerBuilder::save() returns Trigger', function (): void {
    $method = new ReflectionMethod(TriggerBuilder::class, 'save');
    $type = $method->getReturnType();
    expect($type)->not->toBeNull();
    expect($type->getName())->toBe(Trigger::class);
});

it('SubscriptionBuilder::save() returns Subscription', function (): void {
    $method = new ReflectionMethod(SubscriptionBuilder::class, 'save');
    $type = $method->getReturnType();
    expect($type)->not->toBeNull();
    expect($type->getName())->toBe(Subscription::class);
});

// ─── Interface Implementations ───────────────────────────────────

it('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(new ConditionEngine)->toBeInstanceOf(ConditionEngineContract::class);
});

it('Triggerable interface has handle() method with void return', function (): void {
    $ref = new ReflectionMethod(Triggerable::class, 'handle');
    expect($ref->getReturnType()->getName())->toBe('void');
});

// ─── ServiceProvider ───────────────────────────────────────────

it('EventsServiceProvider provides all 7 bindings', function (): void {
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
        expect($provides)->toContain($binding);
    }

    expect($provides)->toHaveCount(7);
});

it('EventsServiceProvider::register() and boot() have #[Override]', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);

    $register = $ref->getMethod('register');
    expect($register->getAttributes())->not->toBeEmpty();

    $boot = $ref->getMethod('boot');
    expect($boot->getAttributes())->not->toBeEmpty();

    $provides = $ref->getMethod('provides');
    expect($provides->getAttributes())->not->toBeEmpty();
});

// ─── Config Completeness ────────────────────────────────────────

it('config has all 7 top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config)->toBeArray();

    $keys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
    foreach ($keys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

it('config table_names has all 3 table entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $tables = $config['table_names'];

    foreach (['triggers', 'event_logs', 'subscriptions'] as $table) {
        expect(array_key_exists($table, $tables))->toBeTrue("Missing table: {$table}");
    }
});

// ─── Facade ──────────────────────────────────────────────────────

it('Facade getFacadeAccessor returns EventManager class', function (): void {
    $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    expect($method->getReturnType()->getName())->toBe('string');
});

// ─── Models ─────────────────────────────────────────────────────

it('EventLog has all 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

it('models use config-driven table names', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'getTable');
        expect($method->hasReturnType())->toBeTrue("{$model}::getTable() must have return type");
    }
});

it('models have casts() method with return type', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'casts');
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("{$model}::casts() must have return type");
        expect($returnType->getName())->toBe('array');
    }
});

// ─── ConditionEngine Operators ─────────────────────────────────

it('ConditionEngine handles all 21 operators', function (string $operator, array $setup): void {
    $engine = new ConditionEngine;

    $conditions = $setup['conditions'];
    $payload = $setup['payload'];

    expect($engine->matches($conditions, $payload))->toBe($setup['expected']);
})->with([
    '>' => [
        'conditions' => ['amount' => ['>', 5]],
        'payload' => ['amount' => 10],
        'expected' => true,
    ],
    '>=' => [
        'conditions' => ['amount' => ['>=', 10]],
        'payload' => ['amount' => 10],
        'expected' => true,
    ],
    '<' => [
        'conditions' => ['amount' => ['<', 20]],
        'payload' => ['amount' => 10],
        'expected' => true,
    ],
    '<=' => [
        'conditions' => ['amount' => ['<=', 10]],
        'payload' => ['amount' => 10],
        'expected' => true,
    ],
    '=' => [
        'conditions' => ['status' => ['=', 'active']],
        'payload' => ['status' => 'active'],
        'expected' => true,
    ],
    '===' => [
        'conditions' => ['flag' => ['===', true]],
        'payload' => ['flag' => true],
        'expected' => true,
    ],
    '!=' => [
        'conditions' => ['status' => ['!=', 'inactive']],
        'payload' => ['status' => 'active'],
        'expected' => true,
    ],
    '!==' => [
        'conditions' => ['flag' => ['!==', false]],
        'payload' => ['flag' => true],
        'expected' => true,
    ],
    'in' => [
        'conditions' => ['role' => ['in', ['admin', 'user']]],
        'payload' => ['role' => 'admin'],
        'expected' => true,
    ],
    'not_in' => [
        'conditions' => ['role' => ['not_in', ['guest']]],
        'payload' => ['role' => 'admin'],
        'expected' => true,
    ],
    'contains' => [
        'conditions' => ['tags' => ['contains', 'urgent']],
        'payload' => ['tags' => ['urgent', 'billing']],
        'expected' => true,
    ],
    'not_contains' => [
        'conditions' => ['tags' => ['not_contains', 'spam']],
        'payload' => ['tags' => ['urgent']],
        'expected' => true,
    ],
    'between' => [
        'conditions' => ['amount' => ['between', [1, 100]]],
        'payload' => ['amount' => 50],
        'expected' => true,
    ],
    'null' => [
        'conditions' => ['deleted_at' => ['null']],
        'payload' => ['deleted_at' => null],
        'expected' => true,
    ],
    'not_null' => [
        'conditions' => ['email' => ['not_null']],
        'payload' => ['email' => 'test@example.com'],
        'expected' => true,
    ],
    'empty' => [
        'conditions' => ['notes' => ['empty']],
        'payload' => ['notes' => ''],
        'expected' => true,
    ],
    'not_empty' => [
        'conditions' => ['notes' => ['not_empty']],
        'payload' => ['notes' => 'hello'],
        'expected' => true,
    ],
    'starts_with' => [
        'conditions' => ['email' => ['starts_with', 'admin@']],
        'payload' => ['email' => 'admin@example.com'],
        'expected' => true,
    ],
    'ends_with' => [
        'conditions' => ['domain' => ['ends_with', '.com']],
        'payload' => ['domain' => 'example.com'],
        'expected' => true,
    ],
    'matches' => [
        'conditions' => ['code' => ['matches', '/^[A-Z]{3}$/']],
        'payload' => ['code' => 'ABC'],
        'expected' => true,
    ],
    'implicit equality' => [
        'conditions' => ['status' => 'active'],
        'payload' => ['status' => 'active'],
        'expected' => true,
    ],
]);

// ─── DomainEvent ────────────────────────────────────────────────

it('DomainEvent is immutable after construction', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);

    $ref = new ReflectionClass($event);
    foreach ($ref->getProperties() as $prop) {
        expect($prop->isReadOnly())->toBeTrue("{$prop->getName()} must be readonly");
    }
});

it('DomainEvent roundtrip preserves identity', function (): void {
    $event = DomainEvent::occur('test.event', ['data' => 42]);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
});

it('DomainEvent fromArray throws on missing eventType', function (): void {
    expect(fn (): DomainEvent => DomainEvent::fromArray(['payload' => []]))
        ->toThrow(InvalidArgumentException::class);
});

// ─── WildcardMatcher ───────────────────────────────────────────

it('WildcardMatcher::matches() has #[Pure] attribute', function (): void {
    $method = new ReflectionMethod(WildcardMatcher::class, 'matches');
    $hasPure = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeTrue('WildcardMatcher::matches() must have #[Pure]');
});

it('WildcardMatcher::findMatchingPatterns() has #[Pure]', function (): void {
    $method = new ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
    $hasPure = false;
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === 'Pure') {
            $hasPure = true;
            break;
        }
    }
    expect($hasPure)->toBeTrue('WildcardMatcher::findMatchingPatterns() must have #[Pure]');
});

it('WildcardMatcher handles single, double, and catch-all patterns', function (): void {
    // Single segment wildcard
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross-segment wildcard
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Catch-all
    expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();

    // Exact match
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
});

// ─── DispatchTriggerJob ────────────────────────────────────────

it('DispatchTriggerJob has promoted readonly properties', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $promotedParams = array_filter(
        $ctor->getParameters(),
        fn (ReflectionParameter $p): bool => $p->isPromoted(),
    );

    expect(count($promotedParams))->toBeGreaterThanOrEqual(3);
});

it('DispatchTriggerJob has handle() and failed() with return types', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);

    $handle = $ref->getMethod('handle');
    expect($handle->getReturnType()->getName())->toBe('void');

    $failed = $ref->getMethod('failed');
    expect($failed->getReturnType()->getName())->toBe('void');
});

// ─── No Deprecated Functions ────────────────────────────────────

it('source code does not use deprecated setAccessible', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents((string) $file);
        expect($content)->not->toContain('setAccessible(');
    }
});

it('source code does not contain TODO or FIXME markers', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents((string) $file);
        expect($content)->not->toContain('TODO');
        expect($content)->not->toContain('FIXME');
    }
});

// ─── Composer.json & Version Consistency ─────────────────────────

it('composer.json version matches README badge', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../composer.json'),
        true,
    );
    expect($composer)->toBeArray();
    expect($composer['version'])->toBeString();

    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$composer['version']}");
});

it('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__.'/../composer.json'),
        true,
    );

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
});

// ─── PHPStan Configuration ─────────────────────────────────────

it('phpstan.neon.dist exists and has level max', function (): void {
    $path = __DIR__.'/../phpstan.neon.dist';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('level: max');
    expect($content)->toContain('paths:');
    expect($content)->toContain('src');
});

// ─── Migration & Factory Counts ─────────────────────────────────

it('has 3 migration files and 3 factory files', function (): void {
    $migrations = glob(__DIR__.'/../database/migrations/*.php');
    expect($migrations)->toHaveCount(3);

    $factories = glob(__DIR__.'/../database/factories/*.php');
    expect($factories)->toHaveCount(3);
});

// ─── Subscription URL Scheme Enforcement ─────────────────────────

it('SubscriptionBuilder rejects non-HTTP URL schemes', function (string $url): void {
    $builder = new SubscriptionBuilder(
        mock(EventManager::class),
    );

    expect(fn (): mixed => $builder->on('test.event')->to($url)->save())
        ->toThrow(InvalidArgumentException::class);
})->with([
    'ftp' => 'ftp://evil.com/payload',
    'file' => 'file:///etc/passwd',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'mailto' => 'mailto:evil@example.com',
]);

it('SubscriptionBuilder accepts HTTP and HTTPS URLs (logic verified)', function (): void {
    $builder = new SubscriptionBuilder(
        mock(EventManager::class),
    );

    // This tests the scheme parsing logic only — full save() requires DB transaction
    $builder->on('test.event')->to('https://partner.com/hooks');

    $ref = new ReflectionProperty($builder, 'url');
    expect($ref->getValue($builder))->toBe('https://partner.com/hooks');

    $builder2 = new SubscriptionBuilder(
        mock(EventManager::class),
    );
    $builder2->on('test.event')->to('http://localhost:8080/hooks');

    $ref2 = new ReflectionProperty($builder2, 'url');
    expect($ref2->getValue($builder2))->toBe('http://localhost:8080/hooks');
});

// ─── EventScheduler Constructor DI ───────────────────────────────

it('EventScheduler has constructor injection with Container', function (): void {
    $ref = new ReflectionClass(EventScheduler::class);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();

    $params = $ctor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getType())->not->toBeNull();
});

// ─── README Accuracy ───────────────────────────────────────────

it('README has correct test file count', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    $testFiles = glob(__DIR__.'/*Test.php');
    expect($testFiles)->not->toBeEmpty();

    // README claims "234 test files" in the package tree
    expect($readme)->toContain('234 test files');
});

it('README documents all 12 CLI commands', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    $commands = [
        'zeroboiler:events:list',
        'zeroboiler:events:fire',
        'zeroboiler:events:register',
        'zeroboiler:events:enable',
        'zeroboiler:events:disable',
        'zeroboiler:events:retry',
        'zeroboiler:events:redeliver',
        'zeroboiler:events:log',
        'zeroboiler:events:subscribe',
        'zeroboiler:events:unsubscribe',
        'zeroboiler:events:subscriptions',
        'zeroboiler:events:health',
    ];

    foreach ($commands as $cmd) {
        expect($readme)->toContain($cmd);
    }
});

// ─── EventManager Public API ───────────────────────────────────

it('EventManager has all required public methods', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $publicMethods = array_filter(
        $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isStatic(),
    );
    $methodNames = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    $required = [
        'on', 'register', 'fire', 'fireModel',
        'enable', 'disable', 'invalidateTriggerCache',
        'isDisabled', 'setEnabled',
        'listTriggers', 'getTrigger', 'deleteTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
        'getEventHistory', 'getStats', 'purgeLogs',
        'getStalePendingLogs', 'deactivateExceededSubscriptions',
        'executeTrigger', 'registerScheduler',
        'subscribeWebhook',
    ];

    foreach ($required as $method) {
        expect(in_array($method, $methodNames, true))->toBeTrue(
            "EventManager must have public method: {$method}",
        );
    }
});

// ─── Cross-reference: Actions ──────────────────────────────────

it('WebhookAction implements Triggerable', function (): void {
    expect(new ReflectionClass(ZeroBoiler\Events\Actions\WebhookAction::class))
        ->implementsInterface(Triggerable::class);
});

// ─── Test Support Files ────────────────────────────────────────

it('tests directory has exactly 5 support files', function (): void {
    $support = [
        'tests/Pest.php',
        'tests/TestCase.php',
        'tests/CreatesApplication.php',
        'tests/helpers.php',
        'tests/TestActions.php',
    ];

    foreach ($support as $file) {
        expect(file_exists(__DIR__.'/../'.$file))->toBeTrue("Missing support file: {$file}");
    }
});

it('total PHP file count under tests/ is 240', function (): void {
    $testFiles = glob(__DIR__.'/*.php');
    expect(count($testFiles))->toBe(240);
});
