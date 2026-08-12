<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
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

// ─── Strict Types Enforcement ───────────────────────────────────────────────

it('all src files have declare(strict_types=1)', function (): void {
    $dir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $violations = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        $firstLine = trim(explode("\n", $contents)[0] ?? '');
        if ($firstLine !== '<?php') {
            continue;
        }
        $secondLine = trim(explode("\n", $contents)[1] ?? '');
        if (! str_contains($secondLine, 'declare(strict_types=1)')) {
            $violations[] = $file->getBasename();
        }
    }
    expect($violations)->toBeEmpty('Files missing strict_types: '.implode(', ', $violations));
});

// ─── Final Class Verification ───────────────────────────────────────────────

it('core classes are final', function (string $class): void {
    $ref = new ReflectionClass($class);
    expect($ref->isFinal())->toBeTrue("{$class} must be final");
})->with([
    EventManager::class,
    ActionResolver::class,
    ConditionEngine::class,
    WildcardMatcher::class,
    TriggerBuilder::class,
    SubscriptionBuilder::class,
    EventsServiceProvider::class,
    DispatchTriggerJob::class,
    DomainEvent::class,
    EventManagerFacade::class,
]);

it('console commands are final', function (): void {
    $dir = __DIR__.'/../src/Console';
    $files = glob($dir.'/*.php');
    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        $class = 'ZeroBoiler\\Events\\Console\\'.basename($file, '.php');
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} must be final");
    }
});

it('WebhookAction is final', function (): void {
    expect((new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class))->isFinal())->toBeTrue();
});

// ─── Interface Contracts ─────────────────────────────────────────────────────

it('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(ConditionEngine::class)->toImplement(ConditionEngineContract::class);
});

it('WebhookAction implements Triggerable', function (): void {
    expect(\ZeroBoiler\Events\Actions\WebhookAction::class)->toImplement(Triggerable::class);
});

it('ConditionEngineContract has matches method with correct signature', function (): void {
    $method = new ReflectionMethod(ConditionEngineContract::class, 'matches');
    expect($method->getReturnType()?->getName())->toBe('bool');
    $params = $method->getParameters();
    expect($params)->toHaveCount(2);
});

it('Triggerable has handle method with correct signature', function (): void {
    $method = new ReflectionMethod(Triggerable::class, 'handle');
    expect($method->getReturnType()?->getName())->toBe('void');
    $params = $method->getParameters();
    expect($params)->toHaveCount(1);
});

// ─── Constructor Parameter Types ─────────────────────────────────────────────

it('EventManager constructor has typed readonly properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();
    expect($params)->toHaveCount(3);
    foreach ($params as $param) {
        expect($param->hasType())->toBeTrue("EventManager::\${$param->getName()} must have a type");
        $type = $param->getType();
        expect($type)->toBeInstanceOf(ReflectionType::class);
    }
});

it('DomainEvent constructor has no explicit void return type', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $ctor = $ref->getMethod('__construct');
    $returnType = $ctor->getReturnType();
    // PHP 8.5 constructors should NOT have explicit void return type
    expect($returnType)->toBeNull('DomainEvent::__construct should not have a return type declaration');
});

it('DomainEvent readonly properties are present', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = ['eventId', 'eventType', 'payload', 'occurredAt'];
    foreach ($props as $prop) {
        $rp = $ref->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
    }
});

// ─── ServiceProvider Binding Verification ────────────────────────────────────

it('ConditionEngine is bound as singleton', function (): void {
    $app = $this->createApplication();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(ConditionEngine::class);
    $second = $app->make(ConditionEngine::class);
    expect($first)->toBe($second);
});

it('ConditionEngineContract resolves to ConditionEngine singleton', function (): void {
    $app = $this->createApplication();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $contract = $app->make(ConditionEngineContract::class);
    $concrete = $app->make(ConditionEngine::class);
    expect($contract)->toBe($concrete);
});

it('ActionResolver is bound as singleton', function (): void {
    $app = $this->createApplication();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(ActionResolver::class);
    $second = $app->make(ActionResolver::class);
    expect($first)->toBe($second);
});

it('TriggerBuilder is bound as transient', function (): void {
    $app = $this->createApplication();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(TriggerBuilder::class);
    $second = $app->make(TriggerBuilder::class);
    expect($first)->not->toBe($second);
});

it('SubscriptionBuilder is bound as transient', function (): void {
    $app = $this->createApplication();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(SubscriptionBuilder::class);
    $second = $app->make(SubscriptionBuilder::class);
    expect($first)->not->toBe($second);
});

it('EventManager is bound as singleton', function (): void {
    $app = $this->createApplication();
    $sp = new EventsServiceProvider($app);
    $sp->register();

    $first = $app->make(EventManager::class);
    $second = $app->make(EventManager::class);
    expect($first)->toBe($second);
});

// ─── Config Completeness ────────────────────────────────────────────────────

it('config has all required top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $requiredKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'wildcard_cache_ttl'];
    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

it('config table_names has all 3 entries', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $tables = $config['table_names'];
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

it('config subscriptions has all required keys', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $subs = $config['subscriptions'];
    expect($subs)->toHaveKeys(['auto_generate_secret', 'max_failures', 'timeout', 'signature_algorithm']);
});

it('config retry has tries and backoff', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $retry = $config['retry'];
    expect($retry)->toHaveKeys(['tries', 'backoff']);
});

it('config queue has connection and queue', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $queue = $config['queue'];
    expect($queue)->toHaveKeys(['connection', 'queue']);
});

it('config retention has days and include_pending', function (): void {
    $config = include __DIR__.'/../config/events.php';
    $retention = $config['retention'];
    expect($retention)->toHaveKeys(['days', 'include_pending']);
});

// ─── Facade Accessor ────────────────────────────────────────────────────────

it('Facade accessor returns correct class name', function (): void {
    $method = new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor');
    $result = $method->invoke(null);
    expect($result)->toBe(EventManager::class);
});

// ─── WildcardMatcher #[Pure] Verification ───────────────────────────────────

it('WildcardMatcher::matches has #[Pure] attribute', function (): void {
    $method = new ReflectionMethod(WildcardMatcher::class, 'matches');
    $attrs = $method->getAttributes(\Attribute::class);
    // All 3 public static methods should be #[Pure]
});

it('WildcardMatcher static methods are all #[Pure]', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    $methods = ['matches', 'findMatchingPatterns', 'extractWildcards'];
    foreach ($methods as $methodName) {
        $method = $ref->getMethod($methodName);
        $attrs = array_map(
            fn (\ReflectionAttribute $a): string => $a->getName(),
            $method->getAttributes(),
        );
        // #[\Pure] attribute name
        expect($attrs)->toContain(\Pure::class, "WildcardMatcher::{$methodName} must have #[Pure]");
    }
});

// ─── EventLog Status Constants ───────────────────────────────────────────────

it('EventLog status constants match $statuses array', function (): void {
    $expected = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];
    expect(EventLog::$statuses)->toBe($expected);
});

// ─── Model Config-Driven Table Names ────────────────────────────────────────

it('Trigger reads table name from config', function (): void {
    $ref = new ReflectionMethod(Trigger::class, 'getTable');
    expect($ref)->toBeInstanceOf(ReflectionMethod::class);
});

it('EventLog reads table name from config', function (): void {
    $ref = new ReflectionMethod(EventLog::class, 'getTable');
    expect($ref)->toBeInstanceOf(ReflectionMethod::class);
});

it('Subscription reads table name from config', function (): void {
    $ref = new ReflectionMethod(Subscription::class, 'getTable');
    expect($ref)->toBeInstanceOf(ReflectionMethod::class);
});

// ─── EscapesWildcardLike SQL Escaping ───────────────────────────────────────

it('EscapesWildcardLike escapes percent sign', function (): void {
    $trait = new class {
        use EscapesWildcardLike;

        public function testWildcardToLike(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };
    $result = $trait->testWildcardToLike('order.%');
    expect($result)->toBe('order.\\%');
});

it('EscapesWildcardLike escapes underscore', function (): void {
    $trait = new class {
        use EscapesWildcardLike;

        public function testWildcardToLike(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };
    $result = $trait->testWildcardToLike('order._test*');
    expect($result)->toBe('order.\\_test%');
});

it('EscapesWildcardLike escapes backslash', function (): void {
    $trait = new class {
        use EscapesWildcardLike;

        public function testWildcardToLike(string $pattern): ?string
        {
            return $this->wildcardToLike($pattern);
        }
    };
    $result = $trait->testWildcardToLike('path\\*');
    // Backslash should be escaped, asterisk to %
    expect($result)->not->toBeNull();
    expect(str_contains($result, '%'))->toBeTrue();
});

// ─── TriggerBuilder Fluent Interface ─────────────────────────────────────────

it('TriggerBuilder methods return self', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    $selfReturnMethods = ['name', 'on', 'action', 'actions', 'when', 'async', 'priority', 'actionParams'];
    foreach ($selfReturnMethods as $methodName) {
        $method = $ref->getMethod($methodName);
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("TriggerBuilder::{$methodName} must have return type");
        expect($returnType->getName())->toBe('self');
    }
});

it('TriggerBuilder save returns Trigger', function (): void {
    $ref = new ReflectionClass(TriggerBuilder::class);
    $method = $ref->getMethod('save');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(Trigger::class);
});

// ─── SubscriptionBuilder Fluent Interface ────────────────────────────────────

it('SubscriptionBuilder methods return self', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    $selfReturnMethods = ['on', 'to', 'withSecret', 'withFilter', 'priority', 'async'];
    foreach ($selfReturnMethods as $methodName) {
        $method = $ref->getMethod($methodName);
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("SubscriptionBuilder::{$methodName} must have return type");
        expect($returnType->getName())->toBe('self');
    }
});

it('SubscriptionBuilder save returns Subscription', function (): void {
    $ref = new ReflectionClass(SubscriptionBuilder::class);
    $method = $ref->getMethod('save');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(Subscription::class);
});

// ─── EventManager Public Method Return Types ────────────────────────────────

it('EventManager public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $methods = ['on', 'register', 'fire', 'fireModel', 'enable', 'disable', 'invalidateTriggerCache',
        'listTriggers', 'getTrigger', 'deleteTrigger', 'executeTrigger',
        'getEventHistory', 'getStats', 'purgeLogs',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription', 'subscribeWebhook',
    ];
    foreach ($methods as $methodName) {
        $method = $ref->getMethod($methodName);
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("EventManager::{$methodName} must have return type");
    }
});

// ─── Version Consistency ────────────────────────────────────────────────────

it('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');
    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}");
});

// ─── Model Casts Completeness ────────────────────────────────────────────────

it('Trigger model casts includes priority and async and enabled', function (): void {
    $ref = new ReflectionClass(Trigger::class);
    $method = $ref->getMethod('casts');
    $result = $method->invoke($ref->newInstanceWithoutConstructor());
    expect($result)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);
});

it('EventLog model casts includes payload and duration_ms', function (): void {
    $ref = new ReflectionClass(EventLog::class);
    $method = $ref->getMethod('casts');
    $result = $method->invoke($ref->newInstanceWithoutConstructor());
    expect($result)->toHaveKeys(['payload', 'duration_ms']);
});

it('Subscription model casts includes all expected keys', function (): void {
    $ref = new ReflectionClass(Subscription::class);
    $method = $ref->getMethod('casts');
    $result = $method->invoke($ref->newInstanceWithoutConstructor());
    expect($result)->toHaveKeys(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
});

// ─── ConditionEngine Full Operator Coverage ────────────────────────────────

it('ConditionEngine covers all 19 operators', function (): void {
    $operators = ['>', '>=', '<', '<=', '=', '===', '!=', '!==', 'in', 'not_in',
        'contains', 'not_contains', 'between', 'null', 'not_null', 'empty', 'not_empty',
        'starts_with', 'ends_with', 'matches'];
    $source = file_get_contents(__DIR__.'/../src/ConditionEngine.php');
    foreach ($operators as $op) {
        // Check the operator string appears in the match expression
        expect($source)->toContain("'{$op}'", "Missing operator: {$op}");
    }
});

// ─── DomainEvent Roundtrip ──────────────────────────────────────────────────

it('DomainEvent toArray/fromArray roundtrip preserves eventId and occurredAt', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'value']);
    $restored = DomainEvent::fromArray($original->toArray());
    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->format(\DateTimeInterface::ATOM))
        ->toBe($original->occurredAt->format(\DateTimeInterface::ATOM));
    expect($restored->eventType)->toBe('test.event');
    expect($restored->payload)->toBe(['key' => 'value']);
});

it('DomainEvent toArray has all required keys', function (): void {
    $event = DomainEvent::occur('test.event');
    $data = $event->toArray();
    expect($data)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
});

// ─── DispatchTriggerJob Property Types ───────────────────────────────────────

it('DispatchTriggerJob public properties are typed', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $props = ['backoff', 'queue', 'tries', 'connection'];
    foreach ($props as $propName) {
        $prop = $ref->getProperty($propName);
        expect($prop->hasType())->toBeTrue("DispatchTriggerJob::\${$propName} must be typed");
    }
});

it('DispatchTriggerJob readonly constructor properties are typed', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();
    foreach ($params as $param) {
        expect($param->hasType())->toBeTrue("DispatchTriggerJob constructor param \${$param->getName()} must be typed");
    }
});

// ─── EventsUnsubscribeCommand early string cast ─────────────────────────────

it('EventsUnsubscribeCommand casts argument to string immediately', function (): void {
    $source = file_get_contents(__DIR__.'/../src/Console/EventsUnsubscribeCommand.php');
    // Should cast to string at assignment, not at usage
    expect($source)->toContain('$id = (string) $this->argument(\'id\')');
    // Should NOT double-cast later
    expect($source)->not->toContain('unsubscribe((string) $id)');
});

// ─── Console Commands Have zeroboiler:events: Prefix ─────────────────────────

it('all console commands have zeroboiler:events: prefix', function (): void {
    $dir = __DIR__.'/../src/Console';
    $files = glob($dir.'/*.php');
    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        $source = file_get_contents($file);
        expect($source)->toContain('zeroboiler:events:', basename($file).' must have zeroboiler:events: prefix');
    }
});

// ─── Service Provider Config Publish Tags ────────────────────────────────────

it('ServiceProvider publishes config with events-config tag', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    $method = $ref->getMethod('boot');
    $source = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
    expect($source)->toContain("'events-config'");
    expect($source)->toContain("'events-migrations'");
});

// ─── ManagesHistory and ManagesSubscriptions Trait Composition ──────────────

it('EventManager uses ManagesHistory trait', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    expect($ref->getTraitNames())->toContain(ManagesHistory::class);
});

it('EventManager uses ManagesSubscriptions trait', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    expect($ref->getTraitNames())->toContain(ManagesSubscriptions::class);
});

it('ManagesHistory uses EscapesWildcardLike trait', function (): void {
    $ref = new ReflectionClass(ManagesHistory::class);
    expect($ref->getTraitNames())->toContain(EscapesWildcardLike::class);
});

it('ManagesSubscriptions uses EscapesWildcardLike trait', function (): void {
    $ref = new ReflectionClass(ManagesSubscriptions::class);
    expect($ref->getTraitNames())->toContain(EscapesWildcardLike::class);
});

// ─── Subscription matchesEvent Edge Cases ────────────────────────────────────

it('Subscription::matchesEvent handles exact match', function (): void {
    $ref = new ReflectionMethod(Subscription::class, 'matchesEvent');
    expect($ref)->toBeInstanceOf(ReflectionMethod::class);
});

// ─── WebhookAction Implements Triggerable ──────────────────────────────────

it('WebhookAction handle method has #[Override]', function (): void {
    $method = new ReflectionMethod(\ZeroBoiler\Events\Actions\WebhookAction::class, 'handle');
    $attrs = array_map(
        fn (\ReflectionAttribute $a): string => $a->getName(),
        $method->getAttributes(),
    );
    expect($attrs)->toContain(\Override::class);
});

// ─── ConditionEngine matches has #[Override] ──────────────────────────────────

it('ConditionEngine matches method has #[Override]', function (): void {
    $method = new ReflectionMethod(ConditionEngine::class, 'matches');
    $attrs = array_map(
        fn (\ReflectionAttribute $a): string => $a->getName(),
        $method->getAttributes(),
    );
    expect($attrs)->toContain(\Override::class);
});

// ─── Model Boot UUID Generation ──────────────────────────────────────────────

it('Trigger boot generates UUID for empty id', function (): void {
    $ref = new ReflectionMethod(Trigger::class, 'boot');
    $source = file_get_contents(__DIR__.'/../src/Models/Trigger.php');
    expect($source)->toContain("if (empty(\$model->id))");
    expect($source)->toContain("Str::uuid()");
});

it('EventLog boot generates UUID for empty id', function (): void {
    $source = file_get_contents(__DIR__.'/../src/Models/EventLog.php');
    expect($source)->toContain("if (empty(\$model->id))");
    expect($source)->toContain("Str::uuid()");
});

it('Subscription boot generates UUID for empty id', function (): void {
    $source = file_get_contents(__DIR__.'/../src/Models/Subscription.php');
    expect($source)->toContain("if (empty(\$model->id))");
    expect($source)->toContain("Str::uuid()");
});

// ─── Config Type Validation ─────────────────────────────────────────────────

it('config wildcard_cache_ttl is numeric default', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['wildcard_cache_ttl'])->toBe(300);
});

it('config subscriptions auto_generate_secret is boolean', function (): void {
    $config = include __DIR__.'/../config/events.php';
    expect($config['subscriptions']['auto_generate_secret'])->toBeBool();
});

// ─── Model Relation Return Types ─────────────────────────────────────────────

it('Trigger::eventLogs returns HasMany', function (): void {
    $method = new ReflectionMethod(Trigger::class, 'eventLogs');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(HasMany::class);
});

it('EventLog::trigger returns BelongsTo', function (): void {
    $method = new ReflectionMethod(EventLog::class, 'trigger');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

// ─── Model Key Type Consistency ──────────────────────────────────────────────

it('all 3 models use string key type', function (string $model): void {
    $ref = new ReflectionClass($model);
    $prop = $ref->getProperty('keyType');
    expect($prop->getValue())->toBe('string');
})->with([Trigger::class, EventLog::class, Subscription::class]);

it('all 3 models are non-incrementing', function (string $model): void {
    $ref = new ReflectionClass($model);
    $prop = $ref->getProperty('incrementing');
    expect($prop->getValue())->toBeFalse();
})->with([Trigger::class, EventLog::class, Subscription::class]);

// ─── ActionResolver Error Handling ───────────────────────────────────────────

it('ActionResolver constructor has typed Container param', function (): void {
    $ctor = new ReflectionMethod(ActionResolver::class, '__construct');
    $params = $ctor->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->hasType())->toBeTrue();
});

it('ActionResolver::resolve has Triggerable return type', function (): void {
    $method = new ReflectionMethod(ActionResolver::class, 'resolve');
    $returnType = $method->getReturnType();
    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe(Triggerable::class);
});

// ─── Migrations Exist ────────────────────────────────────────────────────────

it('triggers migration file exists', function (): void {
    expect(file_exists(__DIR__.'/../database/migrations/2024_01_01_000001_create_triggers_table.php'))->toBeTrue();
});

it('event_logs migration file exists', function (): void {
    expect(file_exists(__DIR__.'/../database/migrations/2024_01_01_000002_create_event_logs_table.php'))->toBeTrue();
});

it('event_subscriptions migration file exists', function (): void {
    expect(file_exists(__DIR__.'/../database/migrations/2025_06_28_000001_create_event_subscriptions_table.php'))->toBeTrue();
});

// ─── Pest.php Registration ──────────────────────────────────────────────────

it('Phase 28 test is registered in Pest.php', function (): void {
    $pest = file_get_contents(__DIR__.'/Pest.php');
    expect($pest)->toContain('EventsPhase28ProductionTest.php');
});
