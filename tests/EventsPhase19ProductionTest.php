<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Console\EventsDisableCommand;
use ZeroBoiler\Events\Console\EventsEnableCommand;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Console\EventsListCommand;
use ZeroBoiler\Events\Console\EventsLogCommand;
use ZeroBoiler\Events\Console\EventsRedeliverCommand;
use ZeroBoiler\Events\Console\EventsRegisterCommand;
use ZeroBoiler\Events\Console\EventsRetryCommand;
use ZeroBoiler\Events\Console\EventsSubscribeCommand;
use ZeroBoiler\Events\Console\EventsSubscriptionsCommand;
use ZeroBoiler\Events\Console\EventsUnsubscribeCommand;

/**
 * Phase 19 Production Tests — Console #[\Override] attributes, strict types, final classes.
 */
describe('Phase 19: Console Command #[\Override] Attributes', function (): void {

    $consoleCommands = [
        EventsListCommand::class,
        EventsFireCommand::class,
        EventsRegisterCommand::class,
        EventsEnableCommand::class,
        EventsDisableCommand::class,
        EventsRetryCommand::class,
        EventsRedeliverCommand::class,
        EventsSubscribeCommand::class,
        EventsUnsubscribeCommand::class,
        EventsSubscriptionsCommand::class,
        EventsLogCommand::class,
    ];

    test('all console commands have #[\Override] on handle() method', function () use ($consoleCommands): void {
        foreach ($consoleCommands as $commandClass) {
            $ref = new ReflectionClass($commandClass);
            $handleMethod = $ref->getMethod('handle');
            $hasOverride = false;

            foreach ($handleMethod->getAttributes() as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }

            expect($hasOverride)->toBeTrue("{$commandClass}::handle() is missing #[\Override] attribute");
        }
    });

    test('all console commands are final', function () use ($consoleCommands): void {
        foreach ($consoleCommands as $commandClass) {
            $ref = new ReflectionClass($commandClass);
            expect($ref->isFinal())->toBeTrue("{$commandClass} must be final");
        }
    });

    test('all console commands have typed $signature property', function () use ($consoleCommands): void {
        foreach ($consoleCommands as $commandClass) {
            $ref = new ReflectionClass($commandClass);
            $prop = $ref->getProperty('signature');
            expect($prop->hasType())->toBeTrue("{$commandClass}::\$signature must have a type declaration");
            expect($prop->getType()->getName())->toBe('string');
        }
    });

    test('all console commands have typed $description property', function () use ($consoleCommands): void {
        foreach ($consoleCommands as $commandClass) {
            $ref = new ReflectionClass($commandClass);
            $prop = $ref->getProperty('description');
            expect($prop->hasType())->toBeTrue("{$commandClass}::\$description must have a type declaration");
            expect($prop->getType()->getName())->toBe('string');
        }
    });

    test('all console commands extend Illuminate\Console\Command', function () use ($consoleCommands): void {
        foreach ($consoleCommands as $commandClass) {
            $ref = new ReflectionClass($commandClass);
            expect($ref->isSubclassOf(\Illuminate\Console\Command::class))->toBeTrue(
                "{$commandClass} must extend Illuminate\Console\Command"
            );
        }
    });

    test('all console commands handle() return type is int', function () use ($consoleCommands): void {
        foreach ($consoleCommands as $commandClass) {
            $ref = new ReflectionClass($commandClass);
            $handleMethod = $ref->getMethod('handle');
            $returnType = $handleMethod->getReturnType();
            expect($returnType)->not->toBeNull("{$commandClass}::handle() must have a return type");
            expect($returnType->getName())->toBe('int');
        }
    });
});

describe('Phase 19: Strict Types Enforcement', function (): void {

    test('all src files have declare(strict_types=1)', function (): void {
        $srcDir = __DIR__.'/../src';
        $files = glob_recursive($srcDir.'/*.php');
        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $hasStrict = str_contains($contents, 'declare(strict_types=1)');
            expect($hasStrict)->toBeTrue(basename($file).' is missing declare(strict_types=1)');
        }
    });

    test('all test files have declare(strict_types=1)', function (): void {
        $testDir = __DIR__;
        $files = glob_recursive($testDir.'/*.php');
        // Skip Pest.php and helpers.php which may have different requirements
        $skipFiles = ['Pest.php', 'helpers.php', 'CreatesApplication.php'];

        foreach ($files as $file) {
            if (in_array(basename($file), $skipFiles, true)) {
                continue;
            }
            $contents = file_get_contents($file);
            $hasStrict = str_contains($contents, 'declare(strict_types=1)');
            expect($hasStrict)->toBeTrue(basename($file).' is missing declare(strict_types=1)');
        }
    });
});

describe('Phase 19: Config Completeness Validation', function (): void {

    test('config has all required top-level keys', function (): void {
        $config = config('events');
        expect($config)->toBeArray();
        expect($config)->toHaveKeys([
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'wildcard_cache_ttl',
        ]);
    });

    test('table_names config has all required tables', function (): void {
        $tables = config('events.table_names');
        expect($tables)->toBeArray();
        expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    test('queue config has connection and queue keys', function (): void {
        $queue = config('events.queue');
        expect($queue)->toBeArray();
        expect($queue)->toHaveKeys(['connection', 'queue']);
    });

    test('retry config has tries and backoff keys', function (): void {
        $retry = config('events.retry');
        expect($retry)->toBeArray();
        expect($retry)->toHaveKeys(['tries', 'backoff']);
    });

    test('subscriptions config has all required keys', function (): void {
        $subs = config('events.subscriptions');
        expect($subs)->toBeArray();
        expect($subs)->toHaveKeys([
            'auto_generate_secret',
            'max_failures',
            'timeout',
            'signature_algorithm',
        ]);
    });
});

describe('Phase 19: ServiceProvider Binding Verification', function (): void {

    test('EventManager is registered as singleton', function (): void {
        $first = app()->make(\ZeroBoiler\Events\EventManager::class);
        $second = app()->make(\ZeroBoiler\Events\EventManager::class);
        expect($first)->toBe($second);
    });

    test('ConditionEngine is registered as singleton', function (): void {
        $first = app()->make(\ZeroBoiler\Events\ConditionEngine::class);
        $second = app()->make(\ZeroBoiler\Events\ConditionEngine::class);
        expect($first)->toBe($second);
    });

    test('ActionResolver is registered as singleton', function (): void {
        $first = app()->make(\ZeroBoiler\Events\ActionResolver::class);
        $second = app()->make(\ZeroBoiler\Events\ActionResolver::class);
        expect($first)->toBe($second);
    });

    test('TriggerBuilder is registered as transient', function (): void {
        $first = app()->make(\ZeroBoiler\Events\TriggerBuilder::class);
        $second = app()->make(\ZeroBoiler\Events\TriggerBuilder::class);
        expect($first)->not->toBe($second);
    });

    test('SubscriptionBuilder is registered as transient', function (): void {
        $first = app()->make(\ZeroBoiler\Events\SubscriptionBuilder::class);
        $second = app()->make(\ZeroBoiler\Events\SubscriptionBuilder::class);
        expect($first)->not->toBe($second);
    });

    test('ConditionEngineContract resolves to ConditionEngine', function (): void {
        $contract = app()->make(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
        expect($contract)->toBeInstanceOf(\ZeroBoiler\Events\ConditionEngine::class);
    });

    test('ConditionEngineContract and ConditionEngine are the same singleton instance', function (): void {
        $contract = app()->make(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class);
        $concrete = app()->make(\ZeroBoiler\Events\ConditionEngine::class);
        expect($contract)->toBe($concrete);
    });
});

describe('Phase 19: Final Class Verification', function (): void {

    $finalClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events.ConditionEngine::class,
        \ZeroBoiler\Events.ActionResolver::class,
        \ZeroBoiler\Events.TriggerBuilder::class,
        \ZeroBoiler\Events.SubscriptionBuilder::class,
        \ZeroBoiler\Events.WildcardMatcher::class,
        \ZeroBoiler\Events.Domain\DomainEvent::class,
        \ZeroBoiler\Events.Actions\WebhookAction::class,
        \ZeroBoiler\Events.Jobs\DispatchTriggerJob::class,
        \ZeroBoiler\Events.EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
    ];

    test('all core classes are final', function () use ($finalClasses): void {
        foreach ($finalClasses as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be declared as final");
        }
    });
});

// Helper function for recursive glob (global namespace, used in tests)
if (! function_exists('glob_recursive')) {
    function glob_recursive(string $pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
        }

        return $files;
    }
}
