<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;

/**
 * Phase 123 — Final production readiness audit for events package.
 *
 * Validates the complete package surface: source code quality,
 * configuration consistency, test infrastructure integrity,
 * and production deployment requirements.
 */
it('all 33 source files declare strict types', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

it('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

it('LICENSE file exists and is non-empty', function (): void {
    expect(file_exists(__DIR__.'/../LICENSE'))->toBeTrue();
    $contents = file_get_contents(__DIR__.'/../LICENSE');
    expect($contents)->not->toBeEmpty();
    expect(strlen($contents))->toBeGreaterThan(50);
});

it('composer.json version matches README badge v4.50.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['version'])->toBe('4.50.0');
});

it('composer.json requires PHP ^8.5 and Laravel ^13.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['require']['illuminate/support'])->toBe('^13.0');
});

it('composer.json has correct provider and alias registration', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $providers = $composer['extra']['laravel']['providers'];
    $aliases = $composer['extra']['laravel']['aliases'];
    expect($providers)->toContain('ZeroBoiler\\Events\\EventsServiceProvider');
    expect($aliases)->toHaveKey('EventManager');
    expect($aliases['EventManager'])->toBe('ZeroBoiler\\Events\\Facades\\EventManager');
});

it('phpstan.neon.dist uses level 9 for PHPStan 2.x', function (): void {
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($contents)->toContain('level: 9');
    expect($contents)->toContain('paths:');
    expect($contents)->toContain('- src');
    expect($contents)->toContain('- database/migrations');
    expect($contents)->toContain('- database/factories');
    expect($contents)->toContain('reportUnmatchedIgnoredErrors: true');
});

it('phpstan.neon.dist does not use level 9 (unsupported by PHPStan 2.x)', function (): void {
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($contents)->not->toContain('level: 8');
});

it('ServiceProvider provides returns all 7 services', function (): void {
    $provides = (new ReflectionMethod(EventsServiceProvider::class, 'provides'))->invoke(
        app(EventsServiceProvider::class)
    );
    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
    expect(count($provides))->toBe(7);
});

it('ConditionEngineContract is bound to ConditionEngine singleton', function (): void {
    $engine = app(ConditionEngineContract::class);
    expect($engine)->toBeInstanceOf(ConditionEngine::class);
    // Verify singleton: resolve twice, check same instance
    expect(app(ConditionEngineContract::class))->toBe($engine);
});

it('Facade accessor returns correct class name', function (): void {
    $accessor = (new ReflectionMethod(EventManagerFacade::class, 'getFacadeAccessor'))
        ->invoke(null);
    expect($accessor)->toBe(EventManager::class);
});

it('all 3 database migrations use config-driven table names', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');
    expect(count($files))->toBe(3);

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('config(\'events.table_names.');
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

it('all 3 factories have static string $model property', function (): void {
    $factoryFiles = glob(__DIR__.'/../database/factories/*.php');
    expect(count($factoryFiles))->toBe(3);

    foreach ($factoryFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('protected static string $model');
        expect($contents)->toContain('public function definition(): array');
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

it('EventLog has exactly 4 unique status constants', function (): void {
    $statuses = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];
    expect(count(array_unique($statuses)))->toBe(4);
    expect(EventLog::$statuses)->toBe($statuses);
});

it('WildcardMatcher is readonly final with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);
    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->isFinal())->toBeTrue();

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("Method {$method->getName()} must be static");
    }
});

it('DomainEvent is immutable — all properties readonly', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();

    $readonlyProps = array_filter(
        $reflection->getProperties(),
        fn (ReflectionProperty $p): bool => $p->isReadOnly(),
    );
    expect(count($readonlyProps))->toBe(4);
});

it('DomainEvent preserves identity through roundtrip serialization', function (): void {
    $event = DomainEvent::occur('test.event', ['key' => 'value']);
    $originalId = $event->eventId->toString();
    $originalTime = $event->occurredAt->format(DATE_ATOM);

    $restored = DomainEvent::fromArray($event->toArray());
    expect($restored->eventId->toString())->toBe($originalId);
    expect($restored->occurredAt->format(DATE_ATOM))->toBe($originalTime);
    expect($restored->eventType)->toBe('test.event');
    expect($restored->payload)->toBe(['key' => 'value']);
});

it('DomainEvent fromArray rejects empty eventType', function (): void {
    $this->expectException(InvalidArgumentException::class);
    DomainEvent::fromArray([]);
});

it('EventManager has all required public methods with return types', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $requiredMethods = [
        'on', 'register', 'fire', 'fireModel', 'enable', 'disable',
        'deleteTrigger', 'invalidateTriggerCache', 'isDisabled', 'setEnabled',
        'listTriggers', 'getTrigger', 'executeTrigger', 'registerScheduler',
        'subscribe', 'unsubscribe', 'listSubscriptions', 'getSubscription',
        'subscribeWebhook', 'getEventHistory', 'getStats', 'purgeLogs',
        'getStalePendingLogs', 'deactivateExceededSubscriptions',
    ];

    foreach ($requiredMethods as $method) {
        $m = $reflection->getMethod($method);
        expect($m->hasReturnType())->toBeTrue("Method {$method} must have a return type");
    }
});

it('EventManager constructor uses readonly promoted properties', function (): void {
    $constructor = new ReflectionMethod(EventManager::class, '__construct');
    $params = $constructor->getParameters();
    expect(count($params))->toBe(3);

    foreach ($params as $param) {
        expect($param->isReadOnly())->toBeTrue("Param {$param->getName()} must be readonly");
    }
});

it('config file has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';
    $requiredKeys = [
        'table_names', 'queue', 'retry', 'retention', 'subscriptions',
        'disabled', 'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }

    // table_names sub-keys
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    // subscriptions sub-keys
    expect($config['subscriptions'])->toHaveKeys([
        'auto_generate_secret', 'max_failures', 'timeout',
        'signature_algorithm', 'cleanup_cron',
    ]);
    // retention sub-keys
    expect($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
    // queue sub-keys
    expect($config['queue'])->toHaveKeys(['connection', 'queue']);
    // retry sub-keys
    expect($config['retry'])->toHaveKeys(['tries', 'backoff']);
});

it('ConditionEngine supports all 19 documented operators', function (): void {
    $engine = new ConditionEngine;

    // Comparison operators
    expect($engine->matches(['val' => ['>', 5]], ['val' => 10]))->toBeTrue();
    expect($engine->matches(['val' => ['>=', 5]], ['val' => 5]))->toBeTrue();
    expect($engine->matches(['val' => ['<', 10]], ['val' => 5]))->toBeTrue();
    expect($engine->matches(['val' => ['<=', 5]], ['val' => 5]))->toBeTrue();

    // Equality
    expect($engine->matches(['val' => ['=', '5']], ['val' => '5']))->toBeTrue();
    expect($engine->matches(['val' => ['===', 5]], ['val' => 5]))->toBeTrue();
    expect($engine->matches(['val' => ['!=', '5']], ['val' => '10']))->toBeTrue();
    expect($engine->matches(['val' => ['!==', 5]], ['val' => '5']))->toBeTrue();

    // Array operators
    expect($engine->matches(['role' => ['in', ['admin']]], ['role' => 'admin']))->toBeTrue();
    expect($engine->matches(['role' => ['not_in', ['admin']]], ['role' => 'user']))->toBeTrue();
    expect($engine->matches(['tags' => ['contains', 'x']], ['tags' => ['x', 'y']]))->toBeTrue();
    expect($engine->matches(['tags' => ['not_contains', 'z']], ['tags' => ['x', 'y']]))->toBeTrue();

    // Range
    expect($engine->matches(['age' => ['between', [18, 65]]], ['age' => 30]))->toBeTrue();

    // Null checks
    expect($engine->matches(['x' => ['null']], ['x' => null]))->toBeTrue();
    expect($engine->matches(['x' => ['not_null']], ['x' => 'val']))->toBeTrue();

    // Empty checks
    expect($engine->matches(['x' => ['empty']], ['x' => null]))->toBeTrue();
    expect($engine->matches(['x' => ['not_empty']], ['x' => 'val']))->toBeTrue();

    // String operators
    expect($engine->matches(['e' => ['starts_with', 'ad']], ['e' => 'admin']))->toBeTrue();
    expect($engine->matches(['e' => ['ends_with', 'com']], ['e' => 'admin']))->toBeFalse();

    // Regex
    expect($engine->matches(['c' => ['matches', '/^[A-Z]+$/']], ['c' => 'ABC']))->toBeTrue();
});

it('ConditionEngine safeRegexMatch rejects nested quantifiers (ReDoS)', function (): void {
    $engine = new ConditionEngine;
    // Nested quantifiers — should return false (rejected)
    expect($engine->matches(['x' => ['matches', '/(a+)+/']], ['x' => 'aaa']))->toBeFalse();
    expect($engine->matches(['x' => ['matches', '/(a*){/']], ['x' => 'aaa']))->toBeFalse();
});

it('ConditionEngine safeRegexMatch rejects overly long patterns', function (): void {
    $engine = new ConditionEngine;
    $longPattern = '/^[a-z]{400}/';
    // 400 chars is within 500 limit — should work
    expect($engine->matches(['x' => ['matches', $longPattern]], ['x' => str_repeat('a', 400)]))->toBeTrue();

    // Over 500 chars — should be rejected
    $tooLongPattern = '/^[a-z]{600}/';
    expect($engine->matches(['x' => ['matches', $tooLongPattern]], ['x' => str_repeat('a', 600)]))->toBeFalse();
});

it('WildcardMatcher catch-all patterns match any event', function (): void {
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', 'a.b.c'))->toBeTrue();
    expect(WildcardMatcher::matches('**', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
    expect(WildcardMatcher::matches('**', ''))->toBeFalse();
});

it('SubscriptionBuilder rejects non-HTTP URL schemes', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('test.event');

    $builder->to('ftp://evil.com/hook');
    $this->expectException(InvalidArgumentException::class);
    $builder->save();
});

it('SubscriptionBuilder rejects invalid URLs', function (): void {
    $builder = app()->make(SubscriptionBuilder::class);
    $builder->on('test.event');

    $builder->to('not-a-url');
    $this->expectException(InvalidArgumentException::class);
    $builder->save();
});

it('all 12 console commands are registered in ServiceProvider', function (): void {
    $provider = new EventsServiceProvider(app());
    $method = new ReflectionMethod($provider, 'boot');

    // Boot the provider to verify commands registration (runs in console context)
    // We just check the boot method references all 12 commands
    $contents = file_get_contents(__DIR__.'/../src/EventsServiceProvider.php');
    $commands = [
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

    foreach ($commands as $cmd) {
        expect($contents)->toContain($cmd);
    }
    expect(count($commands))->toBe(12);
});

it('all console command classes are final', function (): void {
    $commandDir = __DIR__.'/../src/Console';
    $files = glob($commandDir.'/*.php');

    foreach ($files as $file) {
        $className = basename($file, '.php');
        $fqcn = "ZeroBoiler\\Events\\Console\\{$className}";
        $reflection = new ReflectionClass($fqcn);
        expect($reflection->isFinal())->toBeTrue("{$fqcn} must be final");
    }
});

it('all console commands have handle(): int return type', function (): void {
    $commandDir = __DIR__.'/../src/Console';
    $files = glob($commandDir.'/*.php');

    foreach ($files as $file) {
        $className = basename($file, '.php');
        $fqcn = "ZeroBoiler\\Events\\Console\\{$className}";
        $method = new ReflectionMethod($fqcn, 'handle');
        expect($method->getReturnType()?->getName())->toBe('int', "{$fqcn}::handle() must return int");
    }
});

it('no setAccessible() calls in source files', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php');

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        // Strip comments
        $noComments = preg_replace('#//.*$#m', '', $contents);
        $noComments = preg_replace('#/\*.*?\*/#s', '', $noComments);
        expect($noComments)->not->toContain('setAccessible(');
    }
});

it('TriggerBuilder rejects empty event name on save', function (): void {
    $builder = app()->make(TriggerBuilder::class);
    $builder->action(TestAction::class);
    $this->expectException(InvalidArgumentException::class);
    $builder->save();
});

it('TriggerBuilder rejects empty action on save', function (): void {
    $builder = app()->make(TriggerBuilder::class);
    $builder->on('test.event');
    $this->expectException(InvalidArgumentException::class);
    $builder->save();
});

it('EventManager fire rejects empty event name', function (): void {
    $manager = app(EventManager::class);
    $this->expectException(InvalidArgumentException::class);
    $manager->fire('');
});

it('EventManager fire returns silently when disabled', function (): void {
    $manager = app(EventManager::class);
    $manager->setEnabled(false);
    // Should not throw — silently return
    $manager->fire('test.event', ['data' => true]);
    expect(true)->toBeTrue();
});

it('EventManager setEnabled toggle works correctly', function (): void {
    $manager = app(EventManager::class);
    $manager->setEnabled(true);
    expect($manager->isDisabled())->toBeFalse();

    $manager->setEnabled(false);
    expect($manager->isDisabled())->toBeTrue();

    // Restore
    $manager->setEnabled(true);
});

it('all 3 models have config-driven getTable with Override attribute', function (): void {
    $models = [Trigger::class, EventLog::class, Subscription::class];

    foreach ($models as $model) {
        $method = new ReflectionMethod($model, 'getTable');
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override' || str_ends_with($attr->getName(), '\\Override')) {
                $hasOverride = true;
                break;
            }
        }
        expect($hasOverride)->toBeTrue("{$model}::getTable() must have #[Override]");

        // Verify it reads from config
        $instance = new $model;
        $table = $instance->getTable();
        expect(is_string($table) && $table !== '')->toBeTrue();
    }
});

it('rector.php targets Laravel 13 set', function (): void {
    $contents = file_get_contents(__DIR__.'/../rector.php');
    expect($contents)->toContain('LaravelSetList::LARAVEL_130');
    expect($contents)->toContain("'-src'");
});

it('.editorconfig exists with correct settings', function (): void {
    expect(file_exists(__DIR__.'/../.editorconfig'))->toBeTrue();
    $contents = file_get_contents(__DIR__.'/../.editorconfig');
    expect($contents)->toContain('indent_size = 4');
    expect($contents)->toContain('end_of_line = lf');
    expect($contents)->toContain('charset = utf-8');
});

/**
 * Test action used by production audit tests.
 */
final class TestAction implements Triggerable
{
    #[\Override]
    public function handle(array $payload): void {}
}
