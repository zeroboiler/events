<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

/**
 * Phase 146 production audit — verifies README accuracy, PHP 8.5 compliance,
 * ServiceProvider bindings, Facade coverage, config completeness,
 * ConditionEngine operators, and Pest.php registration.
 */
it('has correct version badge in README', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('4.75.0');
});

it('has correct test file count in README', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('225 test files');
    expect($readme)->toContain('5 support');
});

it('has no syntax errors in README Testing Strategies condition example', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    // The correct syntax: ['status' => ['in', ['active', 'pending']]]
    expect($readme)->toContain("'status' => ['in', ['active', 'pending']]");
});

it('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(base_path('../composer.json')), true);
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain((string) $composer['version']);
});

it('has strict_types in every source file', function (): void {
    $sourceDir = base_path('src');
    $files = glob_recursive($sourceDir.'/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict_types in {$file}");
    }
});

it('has license header in every source file', function (): void {
    $sourceDir = base_path('src');
    $files = glob_recursive($sourceDir.'/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('ZeroBoiler', "Missing license header in {$file}");
    }
});

it('all source classes are final', function (): void {
    $sourceDir = base_path('src');
    $files = glob_recursive($sourceDir.'/*.php');
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Skip interfaces and traits
        if (preg_match('/^(abstract )?(final )?(class |interface |trait )/m', $content, $m)) {
            if (str_contains($m[0], 'class ') && ! str_contains($m[0], 'final')) {
                // Also check if "final" appears before "class" on the same declaration line
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    if (preg_match('/^\s*class\s+/', $line) && ! str_contains($line, 'final')) {
                        expect($line)->toBeEmpty("Non-final class in {$file}: {$line}");
                    }
                }
            }
        }
    }

    expect(true)->toBeTrue();
});

it('EventManager is final', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    expect($ref->isFinal())->toBeTrue();
});

it('WildcardMatcher is readonly final', function (): void {
    $ref = new ReflectionClass(WildcardMatcher::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeTrue();
});

it('ConditionEngine is final and implements contract', function (): void {
    $ref = new ReflectionClass(ConditionEngine::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->implementsInterface(ConditionEngineContract::class))->toBeTrue();
});

it('DomainEvent is final', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
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

it('ActionResolver is final', function (): void {
    $ref = new ReflectionClass(ActionResolver::class);
    expect($ref->isFinal())->toBeTrue();
});

it('EventsServiceProvider is final', function (): void {
    $ref = new ReflectionClass(EventsServiceProvider::class);
    expect($ref->isFinal())->toBeTrue();
});

it('DispatchTriggerJob is final', function (): void {
    $ref = new ReflectionClass(DispatchTriggerJob::class);
    expect($ref->isFinal())->toBeTrue();
});

it('all EventManager public methods have return types', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "Method {$method->getName()} in EventManager has no return type",
        );
    }
});

it('Facade has registerScheduler method documented', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('registerScheduler');
});

it('Facade has fireModel method documented', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('fireModel');
});

it('Facade has executeTrigger method documented', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('executeTrigger');
});

it('Facade has deactivateExceededSubscriptions method documented', function (): void {
    $ref = new ReflectionClass(EventManagerFacade::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('deactivateExceededSubscriptions');
});

it('ServiceProvider register binds ConditionEngineContract to ConditionEngine', function (): void {
    $provider = $this->app->getProvider(EventsServiceProvider::class);
    expect($provider)->not->toBeNull();

    $contract = $this->app->make(ConditionEngineContract::class);
    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

it('ServiceProvider register binds EventManager as singleton', function (): void {
    $first = $this->app->make(EventManager::class);
    $second = $this->app->make(EventManager::class);
    expect($first)->toBe($second);
});

it('ServiceProvider register binds TriggerBuilder as transient', function (): void {
    $first = $this->app->make(TriggerBuilder::class);
    $second = $this->app->make(TriggerBuilder::class);
    expect($first)->not->toBe($second);
});

it('ServiceProvider register binds SubscriptionBuilder as transient', function (): void {
    $first = $this->app->make(SubscriptionBuilder::class);
    $second = $this->app->make(SubscriptionBuilder::class);
    expect($first)->not->toBe($second);
});

it('ServiceProvider provides all registered services', function (): void {
    $provider = $this->app->getProvider(EventsServiceProvider::class);
    $provides = $provider->provides();

    expect($provides)->toContain(EventManager::class);
    expect($provides)->toContain(ConditionEngine::class);
    expect($provides)->toContain(ConditionEngineContract::class);
    expect($provides)->toContain(ActionResolver::class);
    expect($provides)->toContain(TriggerBuilder::class);
    expect($provides)->toContain(SubscriptionBuilder::class);
    expect($provides)->toContain(EventScheduler::class);
});

it('config has all required top-level keys', function (): void {
    $config = config('events');
    expect($config)->toHaveKey('table_names');
    expect($config)->toHaveKey('queue');
    expect($config)->toHaveKey('retry');
    expect($config)->toHaveKey('retention');
    expect($config)->toHaveKey('subscriptions');
    expect($config)->toHaveKey('disabled');
    expect($config)->toHaveKey('wildcard_cache_ttl');
});

it('config table_names has all three tables', function (): void {
    $tables = config('events.table_names');
    expect($tables)->toHaveKey('triggers');
    expect($tables)->toHaveKey('event_logs');
    expect($tables)->toHaveKey('subscriptions');
});

it('config subscriptions has all required keys', function (): void {
    $subs = config('events.subscriptions');
    expect($subs)->toHaveKey('auto_generate_secret');
    expect($subs)->toHaveKey('max_failures');
    expect($subs)->toHaveKey('timeout');
    expect($subs)->toHaveKey('signature_algorithm');
    expect($subs)->toHaveKey('cleanup_cron');
});

it('config retention has all required keys', function (): void {
    $ret = config('events.retention');
    expect($ret)->toHaveKey('days');
    expect($ret)->toHaveKey('include_pending');
    expect($ret)->toHaveKey('schedule_cron');
});

it('ConditionEngine supports all 21 operators', function (): void {
    $engine = new ConditionEngine;

    // >, >=, <, <=
    expect($engine->matches(['v' => ['>', 5]], ['v' => 10]))->toBeTrue();
    expect($engine->matches(['v' => ['>=', 5]], ['v' => 5]))->toBeTrue();
    expect($engine->matches(['v' => ['<', 5]], ['v' => 3]))->toBeTrue();
    expect($engine->matches(['v' => ['<=', 5]], ['v' => 5]))->toBeTrue();

    // =, ===, !=, !==
    expect($engine->matches(['v' => ['=', 'ok']], ['v' => 'ok']))->toBeTrue();
    expect($engine->matches(['v' => ['===', true]], ['v' => true]))->toBeTrue();
    expect($engine->matches(['v' => ['!=', 'no']], ['v' => 'yes']))->toBeTrue();
    expect($engine->matches(['v' => ['!==', true]], ['v' => false]))->toBeTrue();

    // Simple equality
    expect($engine->matches(['v' => 'yes'], ['v' => 'yes']))->toBeTrue();

    // in, not_in
    expect($engine->matches(['v' => ['in', ['a', 'b']]], ['v' => 'a']))->toBeTrue();
    expect($engine->matches(['v' => ['not_in', ['x']]], ['v' => 'a']))->toBeTrue();

    // contains, not_contains
    expect($engine->matches(['v' => ['contains', 'ell']], ['v' => 'hello']))->toBeTrue();
    expect($engine->matches(['v' => ['not_contains', 'xyz']], ['v' => 'hello']))->toBeTrue();

    // between
    expect($engine->matches(['v' => ['between', [1, 10]]], ['v' => 5]))->toBeTrue();

    // null, not_null
    expect($engine->matches(['v' => ['null']], ['v' => null]))->toBeTrue();
    expect($engine->matches(['v' => ['not_null']], ['v' => 'x']))->toBeTrue();

    // empty, not_empty
    expect($engine->matches(['v' => ['empty']], ['v' => '']))->toBeTrue();
    expect($engine->matches(['v' => ['empty']], ['v' => null]))->toBeTrue();
    expect($engine->matches(['v' => ['not_empty']], ['v' => 'x']))->toBeTrue();

    // starts_with, ends_with
    expect($engine->matches(['v' => ['starts_with', 'he']], ['v' => 'hello']))->toBeTrue();
    expect($engine->matches(['v' => ['ends_with', 'lo']], ['v' => 'hello']))->toBeTrue();

    // matches (regex)
    expect($engine->matches(['v' => ['matches', '/^[A-Z]+$/']], ['v' => 'ABC']))->toBeTrue();
});

it('ConditionEngine AND logic requires all conditions to match', function (): void {
    $engine = new ConditionEngine;
    $conditions = [
        'a' => 1,
        'b' => 2,
    ];
    expect($engine->matches($conditions, ['a' => 1, 'b' => 2]))->toBeTrue();
    expect($engine->matches($conditions, ['a' => 1, 'b' => 99]))->toBeFalse();
    expect($engine->matches($conditions, ['a' => 99, 'b' => 2]))->toBeFalse();
});

it('WildcardMatcher handles single segment, cross segment, and catch-all', function (): void {
    expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
    expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
    expect(WildcardMatcher::matches('*', 'anything'))->toBeTrue();
    expect(WildcardMatcher::matches('*', ''))->toBeFalse();
});

it('DomainEvent is immutable — all properties are readonly', function (): void {
    $ref = new ReflectionClass(DomainEvent::class);
    $props = $ref->getProperties();

    foreach ($props as $prop) {
        expect($prop->isReadOnly())->toBeTrue("Property {$prop->getName()} on DomainEvent is not readonly");
    }
});

it('DomainEvent fromArray preserves eventId and occurredAt', function (): void {
    $original = DomainEvent::occur('test.event', ['key' => 'val']);
    $data = $original->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($original->eventId->toString());
    expect($restored->occurredAt->getTimestamp())->toBe($original->occurredAt->getTimestamp());
    expect($restored->eventType)->toBe('test.event');
});

it('DomainEvent fromArray throws on missing eventType', function (): void {
    DomainEvent::fromArray(['payload' => []]);
})->throws(InvalidArgumentException::class);

it('Triggerable interface has handle method with void return', function (): void {
    $ref = new ReflectionClass(Triggerable::class);
    $method = $ref->getMethod('handle');
    expect($method->getReturnType()?->getName())->toBe('void');
});

it('DispatchTriggerJob reads config at construction time', function (): void {
    config(['events.retry.tries' => 5]);
    $job = new DispatchTriggerJob('test-id', 'test.event', []);
    expect($job->tries)->toBe(5);
});

it('EventManager facade accessor returns EventManager class', function (): void {
    // The getFacadeAccessor method is protected; we verify it by checking
    // the static proxy resolves to the correct class through the container.
    $resolved = EventManagerFacade::getFacadeRoot();
    expect($resolved)->toBeInstanceOf(EventManager::class);
});

it('phpstan.neon.dist uses max level', function (): void {
    $config = file_get_contents(base_path('../phpstan.neon.dist'));
    expect($config)->toContain('level: 9');
});

it('phpstan.neon.dist reports unmatched ignored errors', function (): void {
    $config = file_get_contents(base_path('../phpstan.neon.dist'));
    expect($config)->toContain('reportUnmatchedIgnoredErrors: true');
});

it('README changelog has v4.75.0 entry', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('### v4.75.0');
});

it('Pest.php registers this test file', function (): void {
    $pestContent = file_get_contents(base_path('Pest.php'));
    expect($pestContent)->toContain('EventsPhase146ProductionAuditTest');
});

it('README has Table of Contents section', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('## Table of Contents');
});

it('README has API Reference section', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('## API Reference');
});

it('README has Production Deployment Checklist', function (): void {
    $readme = file_get_contents(base_path('../README.md'));
    expect($readme)->toContain('## Production Deployment Checklist');
});

it('EventManager constructor has readonly promoted properties', function (): void {
    $ref = new ReflectionClass(EventManager::class);
    $ctor = $ref->getConstructor();
    $params = $ctor->getParameters();

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue(
            "Parameter \${$param->getName()} is not promoted in EventManager constructor",
        );
        $prop = $ref->getProperty($param->getName());
        expect($prop->isReadOnly())->toBeTrue(
            "Property \${$param->getName()} is not readonly in EventManager",
        );
    }
});

it('config events.disabled defaults to false', function (): void {
    expect(config('events.disabled'))->toBeFalse();
});

it('config wildcard_cache_ttl defaults to 300', function (): void {
    expect(config('events.wildcard_cache_ttl'))->toBe(300);
});

/**
 * Recursively glob for files.
 */
function glob_recursive(string $pattern, int $flags = 0): array
{
    $files = glob($pattern, $flags);
    $dirs = glob(dirname($pattern).'/*', GLOB_ONLYDIR | GLOB_NOSORT);

    foreach ($dirs as $dir) {
        $files = array_merge(
            $files,
            glob_recursive($dir.'/'.basename($pattern), $flags),
        );
    }

    return $files;
}
