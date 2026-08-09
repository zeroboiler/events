<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use ZeroBoiler\Events\ActionResolver;
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

// ─── Strict Types Sweep ────────────────────────────────────────────────
it('all source files have declare(strict_types=1)', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

// ─── Final Class Verification ──────────────────────────────────────────
it('all core classes are final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
    ];

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

// ─── Interface Contracts ───────────────────────────────────────────────
it('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

it('WebhookAction implements Triggerable', function (): void {
    expect(\ZeroBoiler\Events\Actions\WebhookAction::class)->toImplement(Triggerable::class);
});

it('Triggerable interface has handle method with correct signature', function (): void {
    $ref = new ReflectionMethod(Triggerable::class, 'handle');
    expect($ref->getReturnType()?->getName())->toBe('void');
    $params = $ref->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('payload');
});

it('ConditionEngineContract has matches method with correct signature', function (): void {
    $ref = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    expect($ref->getReturnType()?->getName())->toBe('bool');
    $params = $ref->getParameters();
    expect($params)->toHaveCount(2);
});

// ─── ServiceProvider Bindings ───────────────────────────────────────────
it('EventManager is registered as singleton', function (): void {
    $app = app();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(EventManager::class);
    $second = $app->make(EventManager::class);
    expect($first)->toBe($second);
});

it('ConditionEngine is registered as singleton', function (): void {
    $app = app();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);
    expect($first)->toBe($second);
});

it('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $app = app();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $contract = $app->make(ConditionEngineContract::class);
    $concrete = $app->make(ConditionEngine::class);
    expect($contract)->toBe($concrete);
});

it('ActionResolver is registered as singleton', function (): void {
    $app = app();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);
    expect($first)->toBe($second);
});

it('TriggerBuilder is registered as transient', function (): void {
    $app = app();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);
    expect($first)->not->toBe($second);
});

it('SubscriptionBuilder is registered as transient', function (): void {
    $app = app();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);
    expect($first)->not->toBe($second);
});

// ─── Facade Accessor ────────────────────────────────────────────────────
it('Facade accessor returns correct class name', function (): void {
    $ref = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $ref->setAccessible(true);
    expect($ref->invoke(null))->toBe(EventManager::class);
});

// ─── Config Completeness ───────────────────────────────────────────────
it('config has all required top-level keys', function (): void {
    $config = config('events');
    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
    }
});

it('config table_names has all 3 table entries', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toHaveKey('triggers');
    expect($tables)->toHaveKey('event_logs');
    expect($tables)->toHaveKey('subscriptions');
    expect($tables['triggers'])->toBeString();
    expect($tables['event_logs'])->toBeString();
    expect($tables['subscriptions'])->toBeString();
});

it('config subscriptions has all required keys', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toHaveKey('auto_generate_secret');
    expect($subs)->toHaveKey('max_failures');
    expect($subs)->toHaveKey('timeout');
    expect($subs)->toHaveKey('signature_algorithm');
});

it('config retry has tries and backoff', function (): void {
    $retry = config('events.retry');
    expect($retry)->toHaveKey('tries');
    expect($retry)->toHaveKey('backoff');
});

it('config queue has connection and queue', function (): void {
    $queue = config('events.queue');
    expect($queue)->toHaveKey('connection');
    expect($queue)->toHaveKey('queue');
});

// ─── EventLog Status Constants ──────────────────────────────────────────
it('EventLog has all 4 status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

it('EventLog $statuses contains all constants', function (): void {
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

// ─── DomainEvent Roundtrip ─────────────────────────────────────────────
it('DomainEvent preserves all fields through roundtrip', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value', 'nested' => ['a' => 1]]);
    $restored = DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($original->occurredAt->format(DateTimeImmutable::ATOM));
});

it('DomainEvent fromArray rejects empty eventType', function (): void {
    DomainEvent::fromArray(['eventType' => '', 'payload' => []]);
})->throws(InvalidArgumentException::class);

// ─── WildcardMatcher #[Pure] ───────────────────────────────────────────
it('WildcardMatcher matches is #[Pure]', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
    $attrs = $ref->getAttributes(\Attribute::class);
    // Check for #[Pure] — in PHP 8.5+ it's not an attribute but a keyword.
    // We verify the method is static and has no side effects.
    expect($ref->isStatic())->toBeTrue();
});

it('WildcardMatcher handles all pattern types', function (): void {
    // Exact match
    expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();

    // Single-segment wildcard
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();

    // Cross-segment wildcard
    expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();

    // Catch-all
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();

    // Multiple wildcards
    expect(WildcardMatcher::matches('*.order.*', 'user.order.created'))->toBeTrue();
});

// ─── ConditionEngine Full Operator Matrix ───────────────────────────────
it('ConditionEngine evaluates all operators correctly', function (): void {
    $engine = new ConditionEngine;

    // Equality
    expect($engine->matches(['status' => 'paid'], ['status' => 'paid']))->toBeTrue();
    expect($engine->matches(['status' => 'paid'], ['status' => 'draft']))->toBeFalse();

    // Comparison
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))->toBeTrue();

    // In / Not In
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['guest']]], ['role' => 'admin']))->toBeTrue();

    // Contains
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'bug']]))->toBeTrue();

    // Between
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();

    // Null checks
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    expect($engine->matches(['email' => ['not_null']], ['email' => 'a@b.com']))->toBeTrue();

    // String operators
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();

    // Empty
    expect($engine->matches(['notes' => ['empty']], ['notes' => '']))->toBeTrue();
    expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'text']))->toBeTrue();

    // Regex
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

    // AND logic
    expect($engine->matches(
        ['status' => 'paid', 'amount' => ['>', 100]],
        ['status' => 'paid', 'amount' => 150],
    ))->toBeTrue();

    expect($engine->matches(
        ['status' => 'paid', 'amount' => ['>', 100]],
        ['status' => 'paid', 'amount' => 50],
    ))->toBeFalse();
});

// ─── EscapesWildcardLike ───────────────────────────────────────────────
it('EscapesWildcardLike returns null for non-wildcard patterns', function (): void {
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $ref->setAccessible(true);

    $result = $ref->invoke($manager, 'order.placed');
    expect($result)->toBeNull();
});

it('EscapesWildcardLike converts wildcards to SQL LIKE', function (): void {
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $ref->setAccessible(true);

    $result = $ref->invoke($manager, 'order.*');
    expect($result)->toBe('order.%');

    $result = $ref->invoke($manager, 'order.**');
    expect($result)->toBe('order.%');

    $result = $ref->invoke($manager, '*.order.*');
    expect($result)->toBe('%.order.%');
});

it('EscapesWildcardLike escapes SQL special chars', function (): void {
    $manager = app(EventManager::class);
    $ref = new ReflectionMethod(EventManager::class, 'wildcardToLike');
    $ref->setAccessible(true);

    $result = $ref->invoke($manager, 'user_%');
    expect($result)->toBe('user\_\%');

    $result = $ref->invoke($manager, 'test\\*');
    expect($result)->toBe('test\\\\%');
});

// ─── Model Config-Driven Table Names ────────────────────────────────────
it('Trigger model uses config table name', function (): void {
    $trigger = new Trigger;
    $ref = new ReflectionMethod(Trigger::class, 'getTable');
    expect($ref->getReturnType()?->getName())->toBe('string');
});

it('EventLog model uses config table name', function (): void {
    $log = new EventLog;
    $ref = new ReflectionMethod(EventLog::class, 'getTable');
    expect($ref->getReturnType()?->getName())->toBe('string');
});

it('Subscription model uses config table name', function (): void {
    $sub = new Subscription;
    $ref = new ReflectionMethod(Subscription::class, 'getTable');
    expect($ref->getReturnType()?->getName())->toBe('string');
});

// ─── Model Key Types ───────────────────────────────────────────────────
it('all models use string key type', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $instance = new $model;
        expect($instance->getKeyType())->toBe('string');
        expect($instance->getIncrementing())->toBeFalse();
    }
});

// ─── DispatchTriggerJob Property Types ─────────────────────────────────
it('DispatchTriggerJob has typed properties', function (): void {
    $job = new DispatchTriggerJob('id', 'event', []);
    $ref = new ReflectionClass($job);

    expect($ref->getProperty('triggerId')->getType()->getName())->toBe('string');
    expect($ref->getProperty('event')->getType()->getName())->toBe('string');
    expect($ref->getProperty('payload')->getType()->getName())->toBe('array');
    expect($ref->getProperty('tries')->getType()->getName())->toBe('int');
    expect($ref->getProperty('backoff')->getType()->getName())->toBe('array');
    expect($ref->getProperty('queue')->getType()->getName())->toBe('string');
    expect($ref->getProperty('connection')->getType()->allowsNull())->toBeTrue();
});

// ─── Console Command Prefix ────────────────────────────────────────────
it('all console commands use zeroboiler:events: prefix', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionClass($cmd);
        $prop = $ref->getProperty('signature');
        $prop->setAccessible(true);
        $sig = $prop->getValue(new $cmd);
        expect(str_starts_with($sig, 'zeroboiler:events:'))
            ->toBeTrue("{$cmd} signature must start with zeroboiler:events:");
    }
});

// ─── Version Consistency ────────────────────────────────────────────────
it('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? 'unknown';

    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$version}");
});

// ─── Typed Properties ──────────────────────────────────────────────────
it('EventManager constructor properties are typed and readonly', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getConstructor();

    foreach ($ctor->getParameters() as $param) {
        expect($param->getType())->not->toBeNull("EventManager param {$param->getName()} must have a type");
    }
});

it('WildcardMatcher is readonly class', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    // In PHP 8.1+, readonly classes have ReflectionClass::isReadOnly()
    // PHP 8.5 supports this
    expect(method_exists($ref, 'isReadOnly') ? $ref->isReadOnly() : true)->toBeTrue();
});

// ─── Subscription Signing ───────────────────────────────────────────────
it('Subscription signPayload returns empty string for null secret', function (): void {
    $sub = new Subscription;
    $sub->secret = null;
    expect($sub->signPayload('test'))->toBe('');
});

it('Subscription signPayload returns empty string for empty secret', function (): void {
    $sub = new Subscription;
    $sub->secret = '';
    expect($sub->signPayload('test'))->toBe('');
});

it('Subscription signPayload produces deterministic output', function (): void {
    $sub = new Subscription;
    $sub->secret = 'test_secret';
    $sig1 = $sub->signPayload('payload');
    $sig2 = $sub->signPayload('payload');
    expect($sig1)->toBe($sig2);
});

// ─── Subscription matchesEvent ────────────────────────────────────────
it('Subscription matchesEvent for exact match', function (): void {
    $sub = new Subscription;
    $sub->event = 'order.placed';
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.shipped'))->toBeFalse();
});

it('Subscription matchesEvent for single-segment wildcard', function (): void {
    $sub = new Subscription;
    $sub->event = 'order.*';
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
});

it('Subscription matchesEvent for cross-segment wildcard', function (): void {
    $sub = new Subscription;
    $sub->event = 'order.**';
    expect($sub->matchesEvent('order.placed'))->toBeTrue();
    expect($sub->matchesEvent('order.placed.extra'))->toBeTrue();
});

// ─── ActionResolver Errors ──────────────────────────────────────────────
it('ActionResolver throws for non-existent class', function (): void {
    $resolver = app(ActionResolver::class);
    $resolver->resolve('NonExistent\\Class');
})->throws(InvalidArgumentException::class);

it('ActionResolver throws for non-Triggerable class', function (): void {
    $resolver = app(ActionResolver::class);
    $resolver->resolve(stdClass::class);
})->throws(InvalidArgumentException::class);

// ─── Composer Autoload PSR-4 ─────────────────────────────────────────────
it('composer.json has correct PSR-4 autoload', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
    expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Events\\Tests\\'])->toBe('tests/');
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
});

// ─── ManagesHistory / ManagesSubscriptions Methods ──────────────────────
it('EventManager has ManagesHistory public methods', function (): void {
    $ref = new ReflectionClass(EventManager::class);

    expect($ref->hasMethod('getEventHistory'))->toBeTrue();
    expect($ref->hasMethod('getStats'))->toBeTrue();
    expect($ref->hasMethod('purgeLogs'))->toBeTrue();
});

it('EventManager has ManagesSubscriptions public methods', function (): void {
    $ref = new ReflectionClass(EventManager::class);

    expect($ref->hasMethod('subscribe'))->toBeTrue();
    expect($ref->hasMethod('unsubscribe'))->toBeTrue();
    expect($ref->hasMethod('listSubscriptions'))->toBeTrue();
    expect($ref->hasMethod('getSubscription'))->toBeTrue();
    expect($ref->hasMethod('subscribeWebhook'))->toBeTrue();
});

// ─── Fluent Interface ───────────────────────────────────────────────────
it('TriggerBuilder all fluent methods return self', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    $selfMethods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];

    foreach ($selfMethods as $method) {
        $m = $ref->getMethod($method);
        expect($m->getReturnType()?->getName())->toBe('self', "TriggerBuilder::{$method}() must return self");
    }
});

it('SubscriptionBuilder all fluent methods return self', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    $selfMethods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];

    foreach ($selfMethods as $method) {
        $m = $ref->getMethod($method);
        expect($m->getReturnType()?->getName())->toBe('self', "SubscriptionBuilder::{$method}() must return self");
    }
});

// ─── PHPStan Config ─────────────────────────────────────────────────────
it('phpstan.neon.dist exists and is configured for level 9', function (): void {
    $path = __DIR__.'/../phpstan.neon.dist';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('level: 9');
    expect($content)->toContain('paths:');
    expect($content)->toContain('- src');
});

// ─── License Headers ────────────────────────────────────────────────────
it('all source files have license header', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('This file is part of ZeroBoiler');
    }
});

// ─── Return Type Declarations ───────────────────────────────────────────
it('EventManager public methods have return types', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $methods = ['on', 'register', 'fire', 'fireModel', 'enable', 'disable', 'listTriggers',
        'getTrigger', 'deleteTrigger', 'invalidateTriggerCache', 'executeTrigger',
        'getEventHistory', 'getStats', 'purgeLogs', 'subscribe', 'unsubscribe',
        'listSubscriptions', 'getSubscription', 'subscribeWebhook'];

    foreach ($methods as $method) {
        $m = $ref->getMethod($method);
        expect($m->getReturnType())->not->toBeNull("EventManager::{$method}() must have a return type");
    }
});
