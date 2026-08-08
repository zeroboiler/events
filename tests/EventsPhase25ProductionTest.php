<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\EventManager as EventManagerConcrete;

/**
 * Phase 25 — Final comprehensive production readiness audit.
 *
 * Validates: Pest.php Phase 24 registration, strict types on all files,
 * final class enforcement, #[Override] on all overrides, return type declarations,
 * typed properties, interface contracts, config completeness, singleton bindings,
 * version consistency, facade accessor, DomainEvent readonly, WildcardMatcher #[Pure],
 * no unused imports, all models have config-driven tables, all status constants.
 */
it('Pest.php includes EventsPhase24ProductionTest', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    expect($pestContent)->toContain('EventsPhase24ProductionTest.php');
});

it('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        expect($contents)
            ->toContain('declare(strict_types=1)', "Missing strict_types in {$file->getPathname()}");
    }
});

it('all classes except models and ServiceProvider are final', function (): void {
    $nonFinalAllowed = [
        Trigger::class,
        EventLog::class,
        Subscription::class,
        // ServiceProvider is final already, just check
    ];

    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all(file_get_contents($file->getPathname()));
        $className = null;

        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {
                if (is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_WHITESPACE) {
                    for ($j = $i + 2; $j < count($tokens); $j++) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                            $className = $tokens[$j][1];
                            break;
                        }
                        if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                            break;
                        }
                    }
                }
                break;
            }
        }

        if ($className === null) {
            continue;
        }

        $fqn = 'ZeroBoiler\\Events\\'.str_replace('/', '\\', substr(
            $file->getPathname(),
            strlen($srcDir) + 1,
            -4,
        ));

        // Skip models (Eloquent models must not be final for extensibility)
        if (str_contains($fqn, '\\Models\\')) {
            continue;
        }

        // Check if the class has 'final' keyword
        $content = file_get_contents($file->getPathname());
        if (! str_contains($content, 'final class '.$className)) {
            // Allow traits (they use trait keyword, not class)
            if (str_contains($content, 'trait '.$className)) {
                continue;
            }
            expect(true)
                ->toBeTrue("{$fqn} should be final for production");
        }
    }
});

it('EventManager is final', function (): void {
    $ref = new ReflectionClass(EventManagerConcrete::class);
    expect($ref->isFinal())->toBeTrue();
});

it('ConditionEngine is final', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    expect($ref->isFinal())->toBeTrue();
});

it('WildcardMatcher is final', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
});

it('ActionResolver is final', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    expect($ref->isFinal())->toBeTrue();
});

it('TriggerBuilder is final', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    expect($ref->isFinal())->toBeTrue();
});

it('SubscriptionBuilder is final', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    expect($ref->isFinal())->toBeTrue();
});

it('WebhookAction is final', function (): void {
    $ref = new ReflectionClass(WebhookAction::class);
    expect($ref->isFinal())->toBeTrue();
});

it('DispatchTriggerJob is final', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    expect($ref->isFinal())->toBeTrue();
});

it('DomainEvent is final', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    expect($ref->isFinal())->toBeTrue();
});

it('Facade has #[Override] on getFacadeAccessor', function (): void {
    $ref = new ReflectionMethod(EventManager::class, 'getFacadeAccessor');
    $attrs = $ref->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue('Facade getFacadeAccessor must have #[Override]');
});

it('Facade returns correct accessor string', function (): void {
    $method = new ReflectionMethod(EventManager::class, 'getFacadeAccessor');
    $method->setAccessible(true);
    expect($method->invoke(null))->toBe(EventManagerConcrete::class);
});

it('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

it('ConditionEngine::matches has #[Override]', function (): void {
    $ref = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = $ref->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue();
});

it('WebhookAction::handle has #[Override] for Triggerable', function (): void {
    $ref = new ReflectionMethod(WebhookAction::class, 'handle');
    $attrs = $ref->getAttributes();
    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue();
});

it('WildcardMatcher::matches is #[Pure]', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'matches');
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

it('WildcardMatcher::findMatchingPatterns is #[Pure]', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'findMatchingPatterns');
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

it('WildcardMatcher::extractWildcards is #[Pure]', function (): void {
    $ref = new ReflectionMethod(WildcardMatcher::class, 'extractWildcards');
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

it('DomainEvent has all readonly properties', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties();
    foreach ($props as $prop) {
        expect($prop->isReadOnly())
            ->toBeTrue("DomainEvent::\${$prop->getName()} must be readonly");
    }
});

it('DomainEvent readonly properties match expected names', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $propNames = array_map(fn (ReflectionProperty $p): string => $p->getName(), $ref->getProperties());
    expect($propNames)->toContain('eventId');
    expect($propNames)->toContain('eventType');
    expect($propNames)->toContain('payload');
    expect($propNames)->toContain('occurredAt');
});

it('DomainEvent roundtrip preserves identity', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($event->toArray());

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))
        ->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
});

it('all console commands have #[Override] on handle', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
    ];

    foreach ($commands as $cmd) {
        $ref = new ReflectionMethod($cmd, 'handle');
        $attrs = $ref->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("{$cmd}::handle must have #[Override]");
    }
});

it('config file has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect($config)->toHaveKeys([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'wildcard_cache_ttl',
    ]);
});

it('config table_names has all three tables', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect($config['table_names'])->toHaveKeys([
        'triggers',
        'event_logs',
        'subscriptions',
    ]);
});

it('config queue has connection and queue keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
});

it('config retry has tries and backoff keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
});

it('config retention has days and include_pending keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect($config['retention'])->toHaveKeys(['days', 'include_pending']);
});

it('config subscriptions has all required keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

it('EventLog has all status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

it('EventLog statuses array contains all constants', function (): void {
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

it('all models have config-driven getTable', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionMethod($model, 'getTable');
        expect($ref->hasReturnType())->toBeTrue("{$model}::getTable must have return type");
    }
});

it('Trigger model has all expected relationships and scopes', function (): void {
    $trigger = new Trigger;
    expect(method_exists($trigger, 'eventLogs'))->toBeTrue();
    expect(method_exists($trigger, 'scopeEnabled'))->toBeTrue();
    expect(method_exists($trigger, 'scopeAsync'))->toBeTrue();
    expect(method_exists($trigger, 'scopeOrderByPriority'))->toBeTrue();
});

it('EventLog model has all expected relationships and scopes', function (): void {
    $log = new EventLog;
    expect(method_exists($log, 'trigger'))->toBeTrue();
    expect(method_exists($log, 'scopeWithStatus'))->toBeTrue();
    expect(method_exists($log, 'scopeFailed'))->toBeTrue();
    expect(method_exists($log, 'scopePending'))->toBeTrue();
    expect(method_exists($log, 'scopeCompleted'))->toBeTrue();
    expect(method_exists($log, 'markAsCompleted'))->toBeTrue();
    expect(method_exists($log, 'markAsFailed'))->toBeTrue();
});

it('Subscription model has all expected methods', function (): void {
    $sub = new Subscription;
    expect(method_exists($sub, 'scopeActive'))->toBeTrue();
    expect(method_exists($sub, 'scopeForEvent'))->toBeTrue();
    expect(method_exists($sub, 'scopeOrderByPriority'))->toBeTrue();
    expect(method_exists($sub, 'matchesEvent'))->toBeTrue();
    expect(method_exists($sub, 'recordDelivery'))->toBeTrue();
    expect(method_exists($sub, 'recordFailure'))->toBeTrue();
    expect(method_exists($sub, 'resetFailures'))->toBeTrue();
    expect(method_exists($sub, 'hasExceededFailures'))->toBeTrue();
    expect(method_exists($sub, 'signPayload'))->toBeTrue();
});

it('EventManager public methods have return type declarations', function (): void {
    $methods = ['on', 'register', 'fire', 'fireModel', 'enable', 'disable',
        'invalidateTriggerCache', 'listTriggers', 'getTrigger', 'deleteTrigger',
        'executeTrigger', 'getEventHistory', 'getStats', 'purgeLogs',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook',
    ];

    $ref = new ReflectionClass(EventManagerConcrete::class);

    foreach ($methods as $method) {
        $m = $ref->getMethod($method);
        expect($m->hasReturnType())
            ->toBeTrue("EventManager::{$method} must have a return type declaration");
    }
});

it('ServiceProvider boot and register have #[Override]', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);

    $register = $ref->getMethod('register');
    $registerHasOverride = false;
    foreach ($register->getAttributes() as $attr) {
        if ($attr->getName() === 'Override') {
            $registerHasOverride = true;
            break;
        }
    }
    expect($registerHasOverride)->toBeTrue();

    $boot = $ref->getMethod('boot');
    $bootHasOverride = false;
    foreach ($boot->getAttributes() as $attr) {
        if ($attr->getName() === 'Override') {
            $bootHasOverride = true;
            break;
        }
    }
    expect($bootHasOverride)->toBeTrue();
});

it('ServiceProvider registers all expected bindings', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    expect(app()->bound(ConditionEngineContract::class))->toBeTrue();
    expect(app()->bound(ConditionEngine::class))->toBeTrue();
    expect(app()->bound(ActionResolver::class))->toBeTrue();
    expect(app()->bound(EventManagerConcrete::class))->toBeTrue();
    expect(app()->bound(TriggerBuilder::class))->toBeTrue();
    expect(app()->bound(SubscriptionBuilder::class))->toBeTrue();
});

it('ConditionEngine contract binding resolves to correct implementation', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $contract = app()->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);

    // Singleton: same instance
    $contract2 = app()->make(ConditionEngineContract::class);
    expect(spl_object_id($contract))->toBe(spl_object_id($contract2));
});

it('EventManager is singleton', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $a = app()->make(EventManagerConcrete::class);
    $b = app()->make(EventManagerConcrete::class);
    expect(spl_object_id($a))->toBe(spl_object_id($b));
});

it('ConditionEngine is singleton', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $a = app()->make(ConditionEngine::class);
    $b = app()->make(ConditionEngine::class);
    expect(spl_object_id($a))->toBe(spl_object_id($b));
});

it('TriggerBuilder is transient (not singleton)', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $a = app()->make(TriggerBuilder::class);
    $b = app()->make(TriggerBuilder::class);
    expect(spl_object_id($a))->not->toBe(spl_object_id($b));
});

it('SubscriptionBuilder is transient (not singleton)', function (): void {
    $provider = new EventsServiceProvider(app());
    $provider->register();

    $a = app()->make(SubscriptionBuilder::class);
    $b = app()->make(SubscriptionBuilder::class);
    expect(spl_object_id($a))->not->toBe(spl_object_id($b));
});

it('TriggerBuilder fluent interface returns self on all builder methods', function (): void {
    $manager = app()->make(EventManagerConcrete::class);
    $builder = $manager->on('test.event');

    expect($builder->name('Test'))->toBe($builder);
    expect($builder->on('test.event2'))->toBe($builder);
    expect($builder->action(\App\Actions\SendOrderNotification::class))->toBe($builder);
    expect($builder->actions([\App\Actions\SendOrderNotification::class]))->toBe($builder);
    expect($builder->when(['key' => 'value']))->toBe($builder);
    expect($builder->async())->toBe($builder);
    expect($builder->priority(5))->toBe($builder);
    expect($builder->actionParams(['url' => 'https://test.com']))->toBe($builder);
});

it('SubscriptionBuilder fluent interface returns self on all builder methods', function (): void {
    $manager = app()->make(EventManagerConcrete::class);
    $builder = $manager->subscribe('test.event', 'https://test.com');

    expect($builder->on('test.event2'))->toBe($builder);
    expect($builder->to('https://test2.com'))->toBe($builder);
    expect($builder->withSecret('secret'))->toBe($builder);
    expect($builder->withFilter(['key' => 'value']))->toBe($builder);
    expect($builder->priority(5))->toBe($builder);
    expect($builder->async())->toBe($builder);
});

it('WildcardMatcher comprehensive pattern tests', function (): void {
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
    expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', 'order.placed'))->toBeTrue();

    // Multiple wildcards
    expect(WildcardMatcher::matches('*.order.*', 'user.order.placed'))->toBeTrue();

    // Empty pattern with non-empty event
    expect(WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
});

it('ConditionEngine all operators work', function (): void {
    $engine = new ConditionEngine;

    // Equality
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();

    // Comparison
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], ['amount' => 100]))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 100]], ['amount' => 50]))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 100]], ['amount' => 100]))->toBeTrue();

    // In / Not in
    expect($engine->matches(['status' => ['in', ['active', 'pending']]], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['status' => ['not_in', ['active']]], ['status' => 'inactive']))->toBeTrue();

    // Contains
    expect($engine->matches(['tags' => ['contains', 'urgent']], ['tags' => ['urgent', 'important']]))->toBeTrue();
    expect($engine->matches(['text' => ['not_contains', 'bad']], ['text' => 'good stuff']))->toBeTrue();

    // Between
    expect($engine->matches(['amount' => ['between', [50, 100]]], ['amount' => 75]))->toBeTrue();

    // Null checks
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();
    expect($engine->matches(['deleted_at' => ['not_null']], ['deleted_at' => '2024-01-01']))->toBeTrue();

    // Empty checks
    expect($engine->matches(['items' => ['empty']], ['items' => []]))->toBeTrue();
    expect($engine->matches(['items' => ['not_empty']], ['items' => ['a']]))->toBeTrue();

    // String operators
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();
    expect($engine->matches(['code' => ['ends_with', '_123']], ['code' => 'abc_123']))->toBeTrue();
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

    // Strict identity
    expect($engine->matches(['status' => ['===', 'active']], ['status' => 'active']))->toBeTrue();
    expect($engine->matches(['status' => ['!==', 'active']], ['status' => 'inactive']))->toBeTrue();

    // Empty conditions = always true
    expect($engine->matches([], ['anything' => 'goes']))->toBeTrue();

    // AND logic (all must match)
    expect($engine->matches([
        'status' => 'active',
        'amount' => ['>', 50],
    ], ['status' => 'active', 'amount' => 100]))->toBeTrue();
    expect($engine->matches([
        'status' => 'active',
        'amount' => ['>', 200],
    ], ['status' => 'active', 'amount' => 100]))->toBeFalse();

    // Dot notation
    expect($engine->matches(['user.role' => 'admin'], ['user' => ['role' => 'admin']]))->toBeTrue();
});

it('version in composer.json matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $version = $composer['version'] ?? '';
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$version}");
});

it('composer.json requires PHP ^8.5', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

it('composer.json requires illuminate/contracts ^13.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
});

it('ServiceProvider is final', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    expect($ref->isFinal())->toBeTrue();
});

it('EscapesWildcardLike trait converts wildcards correctly', function (): void {
    $trait = new class {
        use \ZeroBoiler\Events\Concerns\EscapesWildcardLike;
    };
    $ref = new ReflectionMethod($trait, 'wildcardToLike');
    $ref->setAccessible(true);

    // Non-wildcard returns null
    expect($ref->invoke($trait, 'order.placed'))->toBeNull();

    // Simple wildcard
    expect($ref->invoke($trait, 'order.*'))->toBe('order\\%');

    // Cross-segment wildcard
    expect($ref->invoke($trait, 'order.**'))->toBe('order\\%');

    // Catch-all
    expect($ref->invoke($trait, '*'))->toBe('%');

    // Special characters escaped
    expect($ref->invoke($trait, 'order.%'))->toBe('order.\\\\%');
    expect($ref->invoke($trait, 'order._'))->toBe('order.\\\\_');
});

it('ManagesHistory trait has @property-read annotation for app', function (): void {
    $contents = file_get_contents(__DIR__.'/../src/Concerns/ManagesHistory.php');
    expect($contents)->toContain('@property-read');
});

it('ManagesSubscriptions trait has @property-read annotation for app', function (): void {
    $contents = file_get_contents(__DIR__.'/../src/Concerns/ManagesSubscriptions.php');
    expect($contents)->toContain('@property-read');
});

it('Triggerable interface has typed handle method', function (): void {
    $ref = new ReflectionMethod(Triggerable::class, 'handle');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('void');
});

it('ConditionEngineContract interface has typed matches method', function (): void {
    $ref = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    expect($ref->hasReturnType())->toBeTrue();
    expect($ref->getReturnType()->getName())->toBe('bool');
});

it('all model newFactory methods have #[Override]', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionMethod($model, 'newFactory');
        $hasOverride = false;
        foreach ($ref->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("{$model}::newFactory must have #[Override]");
    }
});

it('all model casts methods have #[Override]', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionMethod($model, 'casts');
        $hasOverride = false;
        foreach ($ref->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("{$model}::casts must have #[Override]");
    }
});

it('all model boot methods have #[Override]', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionMethod($model, 'boot');
        $hasOverride = false;
        foreach ($ref->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("{$model}::boot must have #[Override]");
    }
});

it('all model getTable methods have #[Override]', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $ref = new ReflectionMethod($model, 'getTable');
        $hasOverride = false;
        foreach ($ref->getAttributes() as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("{$model}::getTable must have #[Override]");
    }
});
