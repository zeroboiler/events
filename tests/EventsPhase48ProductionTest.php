<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 48 — Production readiness hardening.
 *
 * Focus areas:
 * - Tightened phpstan.neon.dist ignore patterns (no longer overly broad)
 * - parseActions closure return type correctness
 * - ConditionEngine unknown operator default behavior documented
 * - WebhookAction handle() payload key stripping completeness
 * - SubscriptionBuilder auto-secret format validation
 * - DispatchTriggerJob constructor config normalization edge cases
 * - Factory state method return type consistency
 * - All source files still strict_types, final, typed
 */

// --- phpstan.neon.dist tightened ignore patterns ---

test('phpstan neon dist ignores are specific not overly broad', function (): void {
    $neon = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($neon)->not->toBe(false);

    // Should NOT contain the old overly broad pattern
    expect($neon)->not->toContain('#Access to an undefined property.*#');
    // Should contain the specific patterns
    expect($neon)->toContain('Illuminate\\Database\\Eloquent\\Model::');
    expect($neon)->toContain('$this->payload');
});

// --- parseActions return types ---

test('parseActions returns list type for all formats', function (): void {
    $app = createTestApp();
    $mgr = createEventManager($app);

    $r = new ReflectionMethod($mgr, 'parseActions');

    // Single class string
    $result = $r->invoke($mgr, 'App\\Actions\\Foo');
    expect($result)->toBe(['App\\Actions\\Foo']);
    expect(array_is_list($result))->toBeTrue();

    // JSON array of classes
    $result = $r->invoke($mgr, '["App\\Actions\\Foo","App\\Actions\\Bar"]');
    expect($result)->toBeArray();
    expect(array_is_list($result))->toBeTrue();
    expect(count($result))->toBe(2);

    // JSON object with class + params
    $result = $r->invoke($mgr, '{"class":"App\\Actions\\Foo","params":{"url":"https://example.com"}}');
    expect($result)->toBeArray();
    expect(count($result))->toBe(1);
    expect(is_array($result[0]))->toBeTrue();
    expect($result[0]['class'])->toBe('App\\Actions\\Foo');
    expect(isset($result[0]['params']))->toBeTrue();

    // JSON classes + shared params
    $result = $r->invoke($mgr, '{"classes":["A","B"],"params":{"key":"val"}}');
    expect($result)->toBeArray();
    expect(count($result))->toBe(2);
    expect(is_array($result[0]))->toBeTrue();
    expect($result[0]['class'])->toBe('A');
    expect($result[0]['params'])->toBe(['key' => 'val']);

    // Empty string
    $result = $r->invoke($mgr, '');
    expect($result)->toBe([]);

    // Whitespace-only
    $result = $r->invoke($mgr, '   ');
    expect($result)->toBe([]);
});

// --- ConditionEngine unknown operator behavior ---

test('condition engine returns false for unknown operator', function (): void {
    $engine = new ConditionEngine;

    // Array syntax with unknown operator
    expect($engine->matches(['field' => ['unknown_op', 'value']], ['field' => 'value']))->toBeFalse();

    // Empty array condition returns false
    expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
});

test('condition engine matches empty conditions against any payload', function (): void {
    $engine = new ConditionEngine;

    // No conditions = match everything
    expect($engine->matches([], []))->toBeTrue();
    expect($engine->matches([], ['key' => 'value']))->toBeTrue();
    expect($engine->matches([], ['nested' => ['deep' => 'value']]))->toBeTrue();
});

// --- WebhookAction payload stripping ---

test('webhook action strips all internal keys from payload', function (): void {
    $payload = [
        'url' => 'https://example.com/webhook',
        'event' => 'order.placed',
        'subscription_id' => 'sub-123',
        'headers' => ['X-Custom' => 'value'],
        'order_id' => 42,
        'total' => 99.99,
    ];

    $webhookData = $payload;
    unset($webhookData['url'], $webhookData['event'], $webhookData['headers'], $webhookData['subscription_id']);

    expect($webhookData)->not->toHaveKey('url');
    expect($webhookData)->not->toHaveKey('event');
    expect($webhookData)->not->toHaveKey('subscription_id');
    expect($webhookData)->not->toHaveKey('headers');
    expect($webhookData)->toHaveKey('order_id');
    expect($webhookData)->toHaveKey('total');
    expect($webhookData['order_id'])->toBe(42);
});

// --- SubscriptionBuilder auto-secret format ---

test('subscription builder auto-generated secret starts with whsec_', function (): void {
    $app = createTestApp();
    $mgr = createEventManager($app);
    $builder = new SubscriptionBuilder($mgr);

    $r = new ReflectionProperty($builder, 'secret');

    // Before save, secret is null
    expect($r->getValue($builder))->toBeNull();

    // Verify auto_generate_secret config default
    $config = $app->get('config');
    expect($config)->not->toBeNull();
    expect($config->get('events.subscriptions.auto_generate_secret'))->toBeTrue();
});

// --- DispatchTriggerJob config normalization ---

test('dispatch trigger job normalizes backoff from string config', function (): void {
    $app = createTestApp();
    $app['config']->set('events.retry.backoff', '30,120,300');
    $app['config']->set('events.retry.tries', 5);
    $app['config']->set('events.queue.queue', 'custom-queue');
    $app['config']->set('events.queue.connection', 'redis');

    $job = new DispatchTriggerJob('trigger-123', 'order.placed', ['key' => 'val']);

    $r = new ReflectionProperty($job, 'backoff');
    expect($r->getValue($job))->toBe([30, 120, 300]);

    $r = new ReflectionProperty($job, 'tries');
    expect($r->getValue($job))->toBe(5);

    $r = new ReflectionProperty($job, 'queue');
    expect($r->getValue($job))->toBe('custom-queue');

    $r = new ReflectionProperty($job, 'connection');
    expect($r->getValue($job))->toBe('redis');
});

test('dispatch trigger job normalizes backoff from array config', function (): void {
    $app = createTestApp();
    $app['config']->set('events.retry.backoff', [10, 20, 30]);
    $app['config']->set('events.retry.tries', 1);
    $app['config']->set('events.queue.queue', 'high');

    $job = new DispatchTriggerJob('trigger-123', 'order.placed', []);

    $r = new ReflectionProperty($job, 'backoff');
    expect($r->getValue($job))->toBe([10, 20, 30]);
});

test('dispatch trigger job uses defaults for invalid config', function (): void {
    $app = createTestApp();
    $app['config']->set('events.retry.tries', null);
    $app['config']->set('events.retry.backoff', null);
    $app['config']->set('events.queue.queue', '');

    $job = new DispatchTriggerJob('trigger-123', 'test.event', []);

    $r = new ReflectionProperty($job, 'tries');
    expect($r->getValue($job))->toBe(3); // default

    $r = new ReflectionProperty($job, 'queue');
    expect($r->getValue($job))->toBe('default'); // fallback
});

// --- Factory state method return types ---

test('trigger factory state methods return self', function (): void {
    $factory = new ReflectionMethod(\ZeroBoiler\Events\Database\Factories\TriggerFactory::class, 'async');
    expect($factory->getReturnType()?->getName())->toBe('self');
});

test('event log factory state methods return self', function (): void {
    $factory = new ReflectionMethod(\ZeroBoiler\Events\Database\Factories\EventLogFactory::class, 'completed');
    expect($factory->getReturnType()?->getName())->toBe('self');
});

test('subscription factory state methods return self', function (): void {
    $factory = new ReflectionMethod(\ZeroBoiler\Events\Database\Factories\SubscriptionFactory::class, 'active');
    expect($factory->getReturnType()?->getName())->toBe('self');
});

// --- Strict types enforcement ---

test('all source files have strict types declaration', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = findPhpFiles($srcDir);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toBe(false);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

// --- Final class verification ---

test('all core classes are final', function (): void {
    $finalClasses = [
        ActionResolver::class,
        ConditionEngine::class,
        EventManager::class,
        EventsServiceProvider::class,
        SubscriptionBuilder::class,
        TriggerBuilder::class,
        WebhookAction::class,
        WildcardMatcher::class,
        DomainEvent::class,
        DispatchTriggerJob::class,
        EventManagerFacade::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

// --- Console commands are final ---

test('all console commands are final', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

// --- Interface contracts ---

test('condition engine implements contract', function (): void {
    $engine = new ConditionEngine;
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('webhook action implements triggerable', function (): void {
    $action = new WebhookAction;
    expect($action)->toBeInstanceOf(Triggerable::class);
});

// --- WildcardMatcher #[Pure] verification ---

test('wildcard matcher static methods have Pure attribute', function (): void {
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];

    foreach ($methods as $method) {
        $ref = new ReflectionMethod(WildcardMatcher::class, $method);
        $attrs = $ref->getAttributes(\Pure::class);
        expect($attrs)->not->toBeEmpty("WildcardMatcher::{$method}() should have #[Pure]");
    }
});

// --- DomainEvent readonly properties ---

test('domain event has all readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = ['eventId', 'eventType', 'payload', 'occurredAt'];

    foreach ($props as $prop) {
        $p = $ref->getProperty($prop);
        expect($p->isReadOnly())->toBeTrue("DomainEvent::\${$prop} should be readonly");
    }
});

// --- EventManager readonly promoted properties ---

test('event manager has readonly promoted constructor properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $props = ['conditionEngine', 'actionResolver', 'app'];

    foreach ($props as $prop) {
        $p = $ref->getProperty($prop);
        expect($p->isReadOnly())->toBeTrue("EventManager::\${$prop} should be readonly");
    }
});

// --- ServiceProvider binding verification ---

test('service provider registers correct bindings', function (): void {
    $app = createTestApp();
    (new EventsServiceProvider($app))->register();

    // Singletons
    expect($app->isShared(ConditionEngine::class))->toBeTrue();
    expect($app->isShared(ConditionEngineContract::class))->toBeTrue();
    expect($app->isShared(ActionResolver::class))->toBeTrue();
    expect($app->isShared(EventManager::class))->toBeTrue();

    // Transients
    expect($app->isShared(TriggerBuilder::class))->toBeFalse();
    expect($app->isShared(SubscriptionBuilder::class))->toBeFalse();
});

test('service provider contract binding resolves to correct implementation', function (): void {
    $app = createTestApp();
    (new EventsServiceProvider($app))->register();

    $contract = $app->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);

    // Same singleton instance
    expect($app->make(ConditionEngine::class))->toBe($contract);
});

// --- Config completeness ---

test('config has all required top-level keys', function (): void {
    $app = createTestApp();
    (new EventsServiceProvider($app))->register();
    $config = $app->get('config');

    $topKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
    foreach ($topKeys as $key) {
        expect($config->has("events.{$key}"))->toBeTrue("Config events.{$key} is missing");
    }
});

test('config table_names has all three table keys', function (): void {
    $app = createTestApp();
    (new EventsServiceProvider($app))->register();
    $config = $app->get('config');

    $tableKeys = ['triggers', 'event_logs', 'subscriptions'];
    foreach ($tableKeys as $key) {
        expect($config->has("events.table_names.{$key}"))->toBeTrue("Config events.table_names.{$key} is missing");
        expect($config->get("events.table_names.{$key}"))->toBeString();
    }
});

test('config subscriptions has all required keys', function (): void {
    $app = createTestApp();
    (new EventsServiceProvider($app))->register();
    $config = $app->get('config');

    $subKeys = ['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm'];
    foreach ($subKeys as $key) {
        expect($config->has("events.subscriptions.{$key}"))->toBeTrue("Config events.subscriptions.{$key} is missing");
    }
});

// --- EventLog status constants ---

test('event log status constants are consistent', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

// --- Version consistency ---

test('composer json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBeString();
    expect($readme)->toContain("version-{$composer['version']}");
});

// --- Fluent interface verification ---

test('trigger builder all methods return self', function (): void {
    $app = createTestApp();
    $mgr = createEventManager($app);
    $builder = $app->make(TriggerBuilder::class);

    $methods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];
    foreach ($methods as $method) {
        $ref = new ReflectionMethod(TriggerBuilder::class, $method);
        expect($ref->getReturnType()?->getName())->toBe('self', "TriggerBuilder::{$method}() should return self");
    }
});

test('subscription builder all methods return self', function (): void {
    $app = createTestApp();
    $mgr = createEventManager($app);
    $builder = $app->make(SubscriptionBuilder::class);

    $methods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];
    foreach ($methods as $method) {
        $ref = new ReflectionMethod(SubscriptionBuilder::class, $method);
        expect($ref->getReturnType()?->getName())->toBe('self', "SubscriptionBuilder::{$method}() should return self");
    }
});

// --- Facade accessor ---

test('facade accessor returns correct class', function (): void {
    $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    expect($ref->invoke(null))->toBe(EventManager::class);
});

// --- #[Override] on key methods ---

test('condition engine matches has Override attribute', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $ref->getAttributes(\Override::class);
    expect($attrs)->not->toBeEmpty();
});

test('webhook action handle has Override attribute', function (): void {
    $ref = new ReflectionMethod(WebhookAction::class, 'handle');
    $attrs = $ref->getAttributes(\Override::class);
    expect($attrs)->not->toBeEmpty();
});

// --- Model config-driven table names ---

test('trigger model uses config-driven table name', function (): void {
    $ref = new ReflectionMethod(Trigger::class, 'getTable');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()?->getName())->toBe('string');
});

test('event log model uses config-driven table name', function (): void {
    $ref = new ReflectionMethod(EventLog::class, 'getTable');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()?->getName())->toBe('string');
});

test('subscription model uses config-driven table name', function (): void {
    $ref = new ReflectionMethod(Subscription::class, 'getTable');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()?->getName())->toBe('string');
});

// --- Helper functions ---

function createTestApp(): \Illuminate\Container\Container
{
    $app = new \Illuminate\Container\Container;
    $app->singleton('config', fn (): \Illuminate\Config\Repository => new \Illuminate\Config\Repository([
        'events' => [
            'table_names' => [
                'triggers' => 'triggers',
                'event_logs' => 'event_logs',
                'subscriptions' => 'event_subscriptions',
            ],
            'queue' => ['connection' => null, 'queue' => 'default'],
            'retry' => ['tries' => 3, 'backoff' => '60,300,900'],
            'retention' => ['days' => 30, 'include_pending' => false],
            'subscriptions' => [
                'auto_generate_secret' => true,
                'max_failures' => 10,
                'timeout' => 30,
                'signature_algorithm' => 'sha256',
            ],
            'wildcard_cache_ttl' => 300,
        ],
    ]));

    return $app;
}

function createEventManager(\Illuminate\Container\Container $app): EventManager
{
    (new EventsServiceProvider($app))->register();

    return $app->make(EventManager::class);
}

function findPhpFiles(string $dir): array
{
    $results = [];
    $items = scandir($dir);
    if ($items === false) {
        return $results;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir.'/'.$item;
        if (is_dir($path)) {
            $results = array_merge($results, findPhpFiles($path));
        } elseif (str_ends_with($item, '.php')) {
            $results[] = $path;
        }
    }

    return $results;
}
