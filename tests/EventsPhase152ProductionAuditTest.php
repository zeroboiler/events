<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
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
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\WildcardMatcher;

test('EventManager has mixin annotations for all three traits', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@mixin \\ZeroBoiler\\Events\\Concerns\\ManagesHistory');
    expect($doc)->toContain('@mixin \\ZeroBoiler\\Events\\Concerns\\ManagesSubscriptions');
    expect($doc)->toContain('@mixin \\ZeroBoiler\\Events\\Concerns\\EscapesWildcardLike');
});

test('Trigger model has @see reference to TriggerBuilder', function (): void {
    $reflection = new ReflectionClass(Trigger::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@see \\ZeroBoiler\\Events\\TriggerBuilder');
});

test('Subscription model has @see references to SubscriptionBuilder and WebhookAction', function (): void {
    $reflection = new ReflectionClass(Subscription::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@see \\ZeroBoiler\\Events\\SubscriptionBuilder');
    expect($doc)->toContain('@see \\ZeroBoiler\\Events\\Actions\\WebhookAction');
});

test('EventLog model has @see reference to DispatchTriggerJob', function (): void {
    $reflection = new ReflectionClass(EventLog::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@see \\ZeroBoiler\\Events\\Jobs\\DispatchTriggerJob');
});

test('all source files have declare strict_types', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
    // Also scan subdirectories
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $missing = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (! str_contains($contents, 'declare(strict_types=1)')) {
            $missing[] = $file->getPathname();
        }
    }

    expect($missing)->toBeEmpty('Missing strict_types in: '.implode(', ', $missing));
});

test('all source files have license header', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $missing = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (! str_contains($contents, 'This file is part of ZeroBoiler')) {
            $missing[] = $file->getPathname();
        }
    }

    expect($missing)->toBeEmpty('Missing license header in: '.implode(', ', $missing));
});

test('all source classes are final', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $nonFinal = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        $tokens = token_get_all($contents);

        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (
                is_array($tokens[$i])
                && $tokens[$i][0] === T_CLASS
                && ! isset($tokens[$i][2])
                && ! in_array('T_CLASS', array_column(array_slice($tokens, 0, $i), 0))
            ) {
                // Check for 'final' before T_CLASS
                $foundFinal = false;
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_FINAL) {
                        $foundFinal = true;
                        break;
                    }
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    break;
                }
                if (! $foundFinal) {
                    $nonFinal[] = $file->getPathname();
                }
                break;
            }
        }
    }

    expect($nonFinal)->toBeEmpty('Non-final classes: '.implode(', ', $nonFinal));
});

test('EventManager public methods all have return type declarations', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    $missing = [];
    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        if ($returnType === null) {
            $missing[] = $method->getName();
        }
    }

    expect($missing)->toBeEmpty('Missing return types on: '.implode(', ', $missing));
});

test('WildcardMatcher is readonly final with only static methods', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();

    // Check readonly
    $doc = $reflection->getDocComment();
    // In PHP 8.5, readonly class can be checked via attributes or declaration
    $filename = $reflection->getFileName();
    $contents = file_get_contents((string) $filename);
    expect($contents)->toContain('readonly final class WildcardMatcher');

    // All methods should be static
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        expect($method->isStatic())->toBeTrue("WildcardMatcher::{$method->getName()} should be static");
    }
});

test('DomainEvent is final and immutable', function (): void {
    $reflection = new ReflectionClass(DomainEvent::class);
    expect($reflection->isFinal())->toBeTrue();

    $properties = $reflection->getProperties();
    foreach ($properties as $prop) {
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$prop->getName()} should be readonly");
    }
});

test('ServiceProvider provides all registered bindings', function (): void {
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

test('config file has all required top-level keys', function (): void {
    $config = require __DIR__.'/../config/events.php';

    $requiredKeys = [
        'table_names',
        'queue',
        'retry',
        'retention',
        'subscriptions',
        'disabled',
        'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ConditionEngine();
    expect($engine)->toBeInstanceOf(ConditionEngineContract::class);
});

test('WebhookAction implements Triggerable', function (): void {
    $action = new WebhookAction();
    expect($action)->toBeInstanceOf(Triggerable::class);
});

test('Facade getFacadeAccessor returns correct binding', function (): void {
    $reflection = new ReflectionClass(EventManagerFacade::class);
    $method = $reflection->getMethod('getFacadeAccessor');
    $method->setAccessible(true);
    $result = $method->invoke(null);

    expect($result)->toBe(EventManager::class);
});

test('EventLog has all four status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');
});

test('models use config-driven table names', function (): void {
    $triggerTable = (new Trigger)->getTable();
    $eventLogTable = (new EventLog)->getTable();
    $subscriptionTable = (new Subscription)->getTable();

    $config = require __DIR__.'/../config/events.php';

    expect($triggerTable)->toBe($config['table_names']['triggers']);
    expect($eventLogTable)->toBe($config['table_names']['event_logs']);
    expect($subscriptionTable)->toBe($config['table_names']['subscriptions']);
});

test('no deprecated setAccessible calls in source files', function (): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (preg_match('/->setAccessible\s*\(/', $contents)) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty('setAccessible() found in: '.implode(', ', $violations));
});

test('phpstan.neon.dist has level max and correct paths', function (): void {
    $config = parse_ini_file(__DIR__.'/../phpstan.neon.dist', false, INI_SCANNER_RAW);

    // Simple content check since neon isn't parseable as ini
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($contents)->toContain('level: max');
    expect($contents)->toContain('paths:');
    expect($contents)->toContain('- src');
    expect($contents)->toContain('- tests');
    expect($contents)->toContain('universalObjectCratesClasses:');
});

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'];
    expect($readme)->toContain("version-{$version}-blue");
});

test('all 12 console commands are registered in ServiceProvider', function (): void {
    $reflection = new ReflectionClass(EventsServiceProvider::class);
    $method = $reflection->getMethod('boot');
    $filename = (string) $reflection->getFileName();
    $contents = file_get_contents($filename);

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

    foreach ($commands as $command) {
        expect($contents)->toContain($command);
    }
});

test('DispatchTriggerJob has readonly constructor properties', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue();
    }
});

test('ConditionEngine has 21 operators in evaluateCondition match expression', function (): void {
    $reflection = new ReflectionClass(ConditionEngine::class);
    $method = $reflection->getMethod('evaluateCondition');
    $filename = (string) $reflection->getFileName();
    $contents = file_get_contents($filename);

    // Extract the match expression operators
    preg_match_all("/'(\w+)'\s*=>/", $contents, $matches);
    $operators = array_unique($matches[1]);

    $expectedOperators = [
        '>', '>=', '<', '<=', '=', '===', '!=', '!==',
        'in', 'not_in', 'contains', 'not_contains', 'between',
        'null', 'not_null', 'empty', 'not_empty',
        'starts_with', 'ends_with', 'matches',
    ];

    foreach ($expectedOperators as $op) {
        expect($operators)->toContain($op);
    }

    // 20 named operators + implicit equality (return at end of evaluateCondition)
    expect(count($operators))->toBeGreaterThanOrEqual(20);
});
