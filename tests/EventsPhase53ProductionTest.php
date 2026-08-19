<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;
use Illuminate\Console\Command;

// ─── EventsRegisterCommand type safety ───────────────────────────────────────

test('EventsRegisterCommand has is_string() guard on event argument', function (): void {
    $reflection = new ReflectionMethod(EventsRegisterCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    // Must have is_string guard for event
    expect($methodBody)->toContain('is_string($event)');
});

test('EventsRegisterCommand has is_string() guard on action argument', function (): void {
    $reflection = new ReflectionMethod(EventsRegisterCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('is_string($action)');
});

test('EventsRegisterCommand does not use raw (string) cast on argument', function (): void {
    $reflection = new ReflectionMethod(EventsRegisterCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    // Should NOT cast arguments blindly — must use is_string guard
    expect($methodBody)->not->toContain('(string) $event');
    expect($methodBody)->not->toContain('(string) $action');
});

// ─── EventsSubscribeCommand type safety ─────────────────────────────────────

test('EventsSubscribeCommand has is_string() guard on event argument', function (): void {
    $reflection = new ReflectionMethod(EventsSubscribeCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('is_string($event)');
});

test('EventsSubscribeCommand has is_string() guard on url argument', function (): void {
    $reflection = new ReflectionMethod(EventsSubscribeCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('is_string($url)');
});

test('EventsSubscribeCommand uses is_string() guard on secret option', function (): void {
    $reflection = new ReflectionMethod(EventsSubscribeCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    // Secret should use is_string() guard, not ternary is_string($secret) ? $secret : ''
    expect($methodBody)->toContain('is_string($secret)');
});

test('EventsSubscribeCommand uses is_string() guard on filter option', function (): void {
    $reflection = new ReflectionMethod(EventsSubscribeCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('is_string($filter)');
});

test('EventsSubscribeCommand does not use raw (string) cast on arguments', function (): void {
    $reflection = new ReflectionMethod(EventsSubscribeCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->not->toContain('(string) $event');
    expect($methodBody)->not->toContain('(string) $url');
});

// ─── EventsRetryCommand type safety ─────────────────────────────────────────

test('EventsRetryCommand has is_string() guard on status option', function (): void {
    $reflection = new ReflectionMethod(EventsRetryCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('is_string($statusOption)');
});

test('EventsRetryCommand does not use raw (string) cast on status option', function (): void {
    $reflection = new ReflectionMethod(EventsRetryCommand::class, 'handle');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->not->toContain('(string) $this->option(\'status\')');
});

// ─── Core quality verification (regression guard) ───────────────────────────

test('all source files have declare strict_types=1', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)', "File {$file} is missing strict_types declaration");
    }
});

test('all final classes are actually final', function (): void {
    $finalClasses = [
        EventManager::class,
        ConditionEngine::class,
        WildcardMatcher::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        DomainEvent::class,
        EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
    ];

    foreach ($finalClasses as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue("Class {$class} should be final");
    }
});

test('all console commands are final', function (): void {
    $commandFiles = glob(__DIR__.'/../src/Console/*.php');
    foreach ($commandFiles as $file) {
        $contents = file_get_contents($file);
        preg_match('/^class\s+(\w+)/m', $contents, $matches);
        if (isset($matches[1])) {
            $className = $matches[1];
            // Check for 'final class' keyword
            expect($contents)->toMatch('/final\s+class\s+'.$className.'/', "Command {$className} should be final");
        }
    }
});

test('EventManager singleton binding', function (): void {
    $app = app();
    $instance1 = $app->make(EventManager::class);
    $instance2 = $app->make(EventManager::class);
    expect($instance1)->toBe($instance2);
});

test('ConditionEngine singleton binding', function (): void {
    $app = app();
    $instance1 = $app->make(ConditionEngine::class);
    $instance2 = $app->make(ConditionEngine::class);
    expect($instance1)->toBe($instance2);
});

test('ConditionEngineContract resolves to ConditionEngine', function (): void {
    $app = app();
    $contract = $app->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

test('TriggerBuilder transient binding', function (): void {
    $app = app();
    $instance1 = $app->make(\ZeroBoiler\Events\TriggerBuilder::class);
    $instance2 = $app->make(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($instance1)->not->toBe($instance2);
});

test('SubscriptionBuilder transient binding', function (): void {
    $app = app();
    $instance1 = $app->make(\ZeroBoiler\Events\SubscriptionBuilder::class);
    $instance2 = $app->make(\ZeroBoiler\Events\SubscriptionBuilder::class);
    expect($instance1)->not->toBe($instance2);
});

test('Facade accessor returns EventManager class name', function (): void {
    $accessor = \ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor();
    expect($accessor)->toBe(EventManager::class);
});

test('config completeness — all 7 sections present', function (): void {
    $config = app()->make('config')->get('events');
    expect($config)->toBeArray();
    expect($config)->toHaveKeys([
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ]);
});

test('config completeness — table_names has all 3 keys', function (): void {
    $tables = app()->make('config')->get('events.table_names');
    expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
});

test('config completeness — subscriptions has all required keys', function (): void {
    $subs = app()->make('config')->get('events.subscriptions');
    expect($subs)->toHaveKeys([
        'auto_generate_secret',
        'max_failures',
        'timeout',
        'signature_algorithm',
    ]);
});

test('config completeness — queue has connection and queue keys', function (): void {
    $queue = app()->make('config')->get('events.queue');
    expect($queue)->toHaveKeys(['connection', 'queue']);
});

test('config completeness — retry has tries and backoff keys', function (): void {
    $retry = app()->make('config')->get('events.retry');
    expect($retry)->toHaveKeys(['tries', 'backoff']);
});

test('config completeness — retention has days and include_pending keys', function (): void {
    $retention = app()->make('config')->get('events.retention');
    expect($retention)->toHaveKeys(['days', 'include_pending']);
});

test('EventLog status constants completeness', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
    expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
});

test('DomainEvent roundtrip preserves all fields', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $array = $event->toArray();

    expect($array)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);

    $restored = DomainEvent::fromArray($array);
    expect($restored->eventType)->toBe('test.event');
    expect($restored->payload)->toBe(['key' => 'value']);
    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe($event->occurredAt->format(DateTimeImmutable::ATOM));
});

test('WildcardMatcher #[Pure] on all public methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        $doc = $method->getDocComment();
        $hasPure = $doc !== false && str_contains($doc, '#[\Pure]');
        expect($hasPure)->toBeTrue("WildcardMatcher::{$method->getName()}() should have #[Pure] attribute");
    }
});

test('ConditionEngine full operator coverage — 19 operators', function (): void {
    $engine = new ConditionEngine;
    $payload = ['amount' => 100, 'name' => 'Test', 'tags' => ['a', 'b'], 'status' => null];

    // Comparison
    expect($engine->matches(['amount' => ['>', 50]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['>=', 100]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<', 200]], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['<=', 100]], $payload))->toBeTrue();

    // Equality
    expect($engine->matches(['name' => 'Test'], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['=', 'Test']], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['===', 100]], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['!=', 'Other']], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['!==', '100']], $payload))->toBeTrue();

    // Array operators
    expect($engine->matches(['tags' => ['in', ['a', 'c']]], $payload))->toBeTrue();
    expect($engine->matches(['tags' => ['not_in', ['x', 'y']]], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['contains', 'es']], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['not_contains', 'xyz']], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['between', [50, 200]]], $payload))->toBeTrue();

    // Null operators
    expect($engine->matches(['status' => ['null']], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['not_null']], $payload))->toBeTrue();

    // Empty operators
    expect($engine->matches(['empty_key' => ['empty']], $payload))->toBeTrue();
    expect($engine->matches(['amount' => ['not_empty']], $payload))->toBeTrue();

    // String operators
    expect($engine->matches(['name' => ['starts_with', 'Te']], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['ends_with', 'st']], $payload))->toBeTrue();
    expect($engine->matches(['name' => ['matches', '/^Test$/']], $payload))->toBeTrue();
});

test('version consistency — composer.json matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}");
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
});

test('ServiceProvider registers all 12 commands', function (): void {
    $provider = new EventsServiceProvider(app());
    $reflection = new ReflectionMethod($provider, 'boot');
    $contents = file_get_contents((string) $reflection->getFileName());
    $startLine = $reflection->getStartLine();
    $endLine = $reflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    $expectedCommands = [
        'EventsListCommand',
        'EventsRegisterCommand',
        'EventsFireCommand',
        'EventsLogCommand',
        'EventsRetryCommand',
        'EventsEnableCommand',
        'EventsDisableCommand',
        'EventsHealthCommand',
        'EventsSubscribeCommand',
        'EventsUnsubscribeCommand',
        'EventsSubscriptionsCommand',
        'EventsRedeliverCommand',
    ];

    foreach ($expectedCommands as $command) {
        expect($methodBody)->toContain($command, "ServiceProvider should register {$command}");
    }
});

test('all models use config-driven table names via app(\'config\')', function (): void {
    $triggerReflection = new ReflectionMethod(Trigger::class, 'getTable');
    $triggerContents = file_get_contents((string) $triggerReflection->getFileName());
    expect($triggerContents)->toContain("app('config')");

    $logReflection = new ReflectionMethod(EventLog::class, 'getTable');
    $logContents = file_get_contents((string) $logReflection->getFileName());
    expect($logContents)->toContain("app('config')");

    $subReflection = new ReflectionMethod(Subscription::class, 'getTable');
    $subContents = file_get_contents((string) $subReflection->getFileName());
    expect($subContents)->toContain("app('config')");
});

test('all models have UUID string key type and non-incrementing', function (): void {
    foreach ([Trigger::class, EventLog::class, Subscription::class] as $model) {
        $reflection = new ReflectionClass($model);
        $defaults = $reflection->getDefaultProperties();
        expect($defaults['keyType'])->toBe('string', "{$model} should have string key type");
        expect($defaults['incrementing'])->toBeFalse("{$model} should not auto-increment");
    }
});

test('EscapesWildcardLike trait used in correct classes', function (): void {
    $expectedUsages = [
        \ZeroBoiler\Events\Concerns\ManagesHistory::class,
        \ZeroBoiler\Events\Concerns\ManagesSubscriptions::class,
        Subscription::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($expectedUsages as $class) {
        $reflection = new ReflectionClass($class);
        $traitNames = array_map(
            fn (ReflectionClass $t): string => $t->getShortName(),
            $reflection->getTraits(),
        );
        expect($traitNames)->toContain('EscapesWildcardLike', "{$class} should use EscapesWildcardLike trait");
    }
});

test('model boot methods generate UUID when id is empty or null', function (): void {
    $triggerReflection = new ReflectionMethod(Trigger::class, 'boot');
    $contents = file_get_contents((string) $triggerReflection->getFileName());
    $startLine = $triggerReflection->getStartLine();
    $endLine = $triggerReflection->getEndLine();
    $methodBody = implode("\n", array_slice(explode("\n", $contents), $startLine - 1, $endLine - $startLine + 1));

    expect($methodBody)->toContain('Str::uuid()');
    // The boot method should check for empty or null id
    expect($methodBody)->toMatch('/\$model->id\s*===\s*[\'"]\s*[\'"]|\$model->id\s*===\s*null/');
});

test('EventManager public API surface completeness', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    $expectedMethods = [
        'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
        'invalidateTriggerCache', 'listTriggers', 'getTrigger', 'deleteTrigger',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
        'subscribeWebhook', 'getEventHistory', 'getStats', 'purgeLogs',
        'executeTrigger',
    ];

    foreach ($expectedMethods as $method) {
        expect($publicMethods)->toContain($method, "EventManager should have public method {$method}");
    }
});

test('all EventManager public methods have return type declarations', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        expect($method->hasReturnType())->toBeTrue("EventManager::{$method->getName()}() should have return type");
    }
});

test('TriggerBuilder and SubscriptionBuilder fluent interface — all public methods return self', function (): void {
    foreach ([\ZeroBoiler\Events\TriggerBuilder::class, \ZeroBoiler\Events\SubscriptionBuilder::class] as $class) {
        $reflection = new ReflectionClass($class);
        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            $name = $method->getName();
            if ($name === '__construct') {
                continue;
            }

            $returnType = $method->getReturnType();
            if ($returnType === null) {
                continue;
            }

            $resolvedName = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : null;

            if ($name === 'save') {
                // save() returns Trigger or Subscription, not self
                continue;
            }

            expect($resolvedName)->toBe('self', "{$class}::{$name}() should return self for fluent interface");
        }
    }
});
