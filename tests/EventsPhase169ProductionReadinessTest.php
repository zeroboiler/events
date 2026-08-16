<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

/**
 * Phase 169 — Final production readiness verification.
 *
 * Validates all aspects of the events package for production deployment:
 * - Source file compliance (strict types, final, readonly, typed properties)
 * - ServiceProvider bindings (register, boot, provides)
 * - Config completeness
 * - Facade coverage
 * - Contract implementations
 * - PHPStan 9 configuration
 * - Composer metadata
 * - Migration structure
 * - Test registration
 */
test('all source files have declare(strict_types=1)', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php', GLOB_ERR) ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('all source files have license header', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php', GLOB_ERR) ?: [];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license.');
    }
});

test('all service classes are final', function (): void {
    $finalClasses = [
        'EventManager',
        'ConditionEngine',
        'ActionResolver',
        'TriggerBuilder',
        'SubscriptionBuilder',
        'EventScheduler',
        'WildcardMatcher',
        'DomainEvent',
        'EventsServiceProvider',
    ];

    foreach ($finalClasses as $class) {
        $fqcn = match ($class) {
            'EventManager' => \ZeroBoiler\Events\EventManager::class,
            'ConditionEngine' => \ZeroBoiler\Events\ConditionEngine::class,
            'ActionResolver' => \ZeroBoiler\Events\ActionResolver::class,
            'TriggerBuilder' => \ZeroBoiler\Events\TriggerBuilder::class,
            'SubscriptionBuilder' => \ZeroBoiler\Events\SubscriptionBuilder::class,
            'EventScheduler' => \ZeroBoiler\Events\EventScheduler::class,
            'WildcardMatcher' => \ZeroBoiler\Events\WildcardMatcher::class,
            'DomainEvent' => \ZeroBoiler\Events\Domain\DomainEvent::class,
            'EventsServiceProvider' => \ZeroBoiler\Events\EventsServiceProvider::class,
            default => '',
        };

        expect($fqcn)->toBeFinal();
    }
});

test('all models are final', function (): void {
    $models = [
        \ZeroBoiler\Events\Models\Trigger::class,
        \ZeroBoiler\Events\Models\EventLog::class,
        \ZeroBoiler\Events\Models\Subscription::class,
    ];

    foreach ($models as $model) {
        expect($model)->toBeFinal();
    }
});

test('all console commands are final', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $command) {
        expect($command)->toBeFinal();
    }
});

test('WildcardMatcher is readonly final class', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

test('DomainEvent has readonly properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    $properties = ['eventId', 'eventType', 'payload', 'occurredAt'];
    foreach ($properties as $prop) {
        $rp = $ref->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue("{$prop} should be readonly");
    }
});

test('ConditionEngine implements ConditionEngineContract', function (): void {
    expect(\ZeroBoiler\Events\ConditionEngine::class)
        ->toImplement(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    expect(\ZeroBoiler\Events\Actions\WebhookAction::class)
        ->toImplement(\ZeroBoiler\Events\Contracts\Triggerable::class);
});

test('EventManager constructor has 3 promoted readonly properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $ctor = $ref->getConstructor();

    expect($ctor)->not->toBeNull();
    $params = $ctor->getParameters();
    expect($params)->toHaveCount(3);

    $paramNames = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        $params,
    );

    expect($paramNames)->toBe(['conditionEngine', 'actionResolver', 'app']);

    foreach ($params as $param) {
        expect($param->isReadOnly())->toBeTrue();
        expect($param->hasType())->toBeTrue();
    }
});

test('ServiceProvider provides 7 bindings', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);

    $provides = $provider->provides();

    expect($provides)->toHaveCount(7);
    expect($provides)->toContain(\ZeroBoiler\Events\EventManager::class);
    expect($provides)->toContain(\ZeroBoiler\Events\ConditionEngine::class);
    expect($provides)->toContain(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
    expect($provides)->toContain(\ZeroBoiler\Events\ActionResolver::class);
    expect($provides)->toContain(\ZeroBoiler\Events\TriggerBuilder::class);
    expect($provides)->toContain(\ZeroBoiler\Events\SubscriptionBuilder::class);
    expect($provides)->toContain(\ZeroBoiler\Events\EventScheduler::class);
});

test('Facade accessor returns correct class', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
    $method = $ref->getMethod('getFacadeAccessor');

    expect($method->invoke(null))->toBe(\ZeroBoiler\Events\EventManager::class);
});

test('config has 8 top-level keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config)->toBeArray();
    expect(array_keys($config))->toHaveCount(8);

    $expectedKeys = ['table_names', 'queue', 'retry', 'retention', 'subscriptions', 'disabled', 'wildcard_cache_ttl'];
    foreach ($expectedKeys as $key) {
        expect($config)->toHaveKey($key);
    }
});

test('phpstan.neon.dist has level 9 and bootstrapFiles', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($content)->toContain('level: 9');
    expect($content)->toContain('bootstrapFiles:');
    expect($content)->toContain('tests/helpers.php');
    expect($content)->toContain('- src');
    expect($content)->toContain('checkExplicitMixed: true');
    expect($content)->toContain('reportUnusedIgnoredErrors: true');
});

test('composer.json requires PHP 8.5 and Laravel 13', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
    expect($composer['extra']['laravel']['aliases'])->toHaveKey('EventManager');
});

test('composer.json version matches 5.26.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['version'])->toBe('5.26.0');
});

test('EventLog has 4 status constants', function (): void {
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_PENDING)->toBe('pending');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(\ZeroBoiler\Events\Models\EventLog::STATUS_FAILED)->toBe('failed');

    expect(\ZeroBoiler\Events\Models\EventLog::$statuses)->toHaveCount(4);
});

test('ConditionEngine has ReDoS protection', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
    $maxRegex = $ref->getConstant('MAX_REGEX_LENGTH');

    expect($maxRegex)->toBeGreaterThan(0);
});

test('DomainEvent preserves identity during roundtrip', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);

    $restored = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($original->toArray());

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))
        ->toBe($original->occurredAt->format(\DateTimeImmutable::ATOM));
    expect($restored->eventType)->toBe($original->eventType);
    expect($restored->payload)->toBe($original->payload);
});

test('EscapesWildcardLike escapes SQL special characters', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);
    // The trait is used by EventManager, so test via Trigger model
    $trigger = new \ZeroBoiler\Events\Models\Trigger;

    $ref = new ReflectionMethod($trigger, 'wildcardToLike');
    $ref->setAccessible(true);

    // Pattern without wildcards returns null
    expect($ref->invoke($trigger, 'order.placed'))->toBeNull();

    // Pattern with wildcard converts
    $result = $ref->invoke($trigger, 'order.*');
    expect($result)->toBe('order.%');

    // Special chars are escaped
    $result = $ref->invoke($trigger, '100%.test*');
    expect($result)->toBe('100\\%%.test%');
});

test('all 12 console commands have zeroboiler:events: prefix', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $command) {
        $ref = new ReflectionClass($command);
        $prop = $ref->getProperty('signature');
        $prop->setAccessible(true);
        $signature = $prop->getValue(new $command);

        expect($signature)->toContain('zeroboiler:events:');
    }
});

test('all console commands have int return type on handle()', function (): void {
    $commands = [
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
    ];

    foreach ($commands as $command) {
        $method = new ReflectionMethod($command, 'handle');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull();
        expect($returnType->getName())->toBe('int');
    }
});

test('Pest.php registers all test files', function (): void {
    $pestContent = file_get_contents(__DIR__.'/Pest.php');
    $testDir = __DIR__;

    $testFiles = glob($testDir.'/*Test.php');
    expect($testFiles)->not->toBeEmpty();

    foreach ($testFiles as $file) {
        $filename = basename($file);
        // Skip support files
        if (in_array($filename, ['TestCase.php'], true)) {
            continue;
        }

        expect($pestContent)->toContain(
            $filename,
            "Test file {$filename} must be registered in Pest.php"
        );
    }
});

test('migrations exist and use config-driven table names', function (): void {
    $migrationDir = __DIR__.'/../database/migrations';
    $files = glob($migrationDir.'/*.php');

    expect($files)->toHaveCount(3);

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('getTableName()');
        expect($content)->toContain("config('events.table_names.");
    }
});

test('factories reference correct model classes', function (): void {
    $factoryDir = __DIR__.'/../database/factories';
    $files = glob($factoryDir.'/*.php');

    expect($files)->toHaveCount(3);

    $expectedModels = [
        'TriggerFactory.php' => \ZeroBoiler\Events\Models\Trigger::class,
        'EventLogFactory.php' => \ZeroBoiler\Events\Models\EventLog::class,
        'SubscriptionFactory.php' => \ZeroBoiler\Events\Models\Subscription::class,
    ];

    foreach ($expectedModels as $file => $model) {
        $content = file_get_contents($factoryDir.'/'.$file);
        expect($content)->toContain("protected static string \$model = {$model}::class");
    }
});

test('Trigger model has getTable from config', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\Trigger::class);
    $method = $ref->getMethod('getTable');

    expect($method)->hasOverride();
});

test('SubscriptionBuilder rejects non-HTTP(S) URLs', function (): void {
    $builder = new \ZeroBoiler\Events\SubscriptionBuilder(
        $this->app->make(\ZeroBoiler\Events\EventManager::class)
    );

    // ftp:// should be rejected
    $builder->on('test.event')->to('ftp://evil.com/hook');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('HTTP or HTTPS protocol');
    $builder->save();
});

test('DispatchTriggerJob implements ShouldQueue', function (): void {
    expect(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class)
        ->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);

    $ref = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
    expect($ref->isFinal())->toBeTrue();
});

test('no source files contain TODO or FIXME', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = glob($srcDir.'/**/*.php', GLOB_ERR) ?: [];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        $normalized = strtolower($content);
        expect($normalized)->not->toContain('todo:');
        expect($normalized)->not->toContain('fixme:');
    }
});

test('EventManager public methods have return type declarations', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\EventManager::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    $skipMethods = ['__construct', '__clone', '__wakeup', '__serialize', '__unserialize'];

    foreach ($methods as $method) {
        if (in_array($method->getName(), $skipMethods, true)) {
            continue;
        }

        if ($method->getDeclaringClass()->getName() === \ZeroBoiler\Events\EventManager::class) {
            $rt = $method->getReturnType();
            expect($rt)->not->toBeNull(
                "EventManager::{$method->getName()}() must have a return type declaration"
            );
        }
    }
});
