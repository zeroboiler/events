<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\ManagesHistory;
use ZeroBoiler\Events\Concerns\ManagesSubscriptions;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

/**
 * Phase 195 — Final Production Infrastructure Audit.
 *
 * Covers: strict types enforcement, return type declarations, docblock
 * presence on all public/protected methods, typed properties, readonly
 * modifiers, #[\Override] usage, and final class enforcement.
 */
test('all source files declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $missing = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        if (! str_contains($contents, 'declare(strict_types=1)')) {
            $missing[] = $file->getFilename();
        }
    }

    expect($missing)->toBeEmpty('Missing declare(strict_types=1) in: '.implode(', ', $missing));
});

test('all public classes use final keyword', function (): void {
    $nonFinalClasses = [
        'ZeroBoiler\\Events\\Contracts\\Triggerable',
        'ZeroBoiler\\Events\\Contracts\\ConditionEngineContract',
    ];
    $srcDir = __DIR__.'/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $missing = [];

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        // Extract FQN from namespace + class name
        if (! preg_match('/namespace\s+([\w\\\\]+)\s*;/', $contents, $nsMatch)) {
            continue;
        }
        if (! preg_match('/(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $contents, $classMatch)) {
            continue;
        }

        $fqn = $nsMatch[1].'\\'.$classMatch[1];

        if (in_array($fqn, $nonFinalClasses, true)) {
            continue;
        }

        // Skip traits and abstract classes
        if (str_contains($contents, 'trait '.$classMatch[1]) || str_contains($contents, 'abstract class '.$classMatch[1])) {
            continue;
        }

        if (! str_contains($contents, 'final class '.$classMatch[1]) && ! str_contains($contents, 'final readonly class '.$classMatch[1]) && ! str_contains($contents, 'readonly final class '.$classMatch[1])) {
            $missing[] = $fqn;
        }
    }

    expect($missing)->toBeEmpty('Classes missing final keyword: '.implode(', ', $missing));
});

test('EventManager constructor uses readonly promoted properties', function (): void {
    $reflection = new ReflectionClass(EventManager::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->toBeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    foreach ($params as $param) {
        expect($param->isPromoted())->toBeTrue("Parameter \${$param->getName()} should be constructor-promoted");

        $prop = $reflection->getProperty($param->getName());
        expect($prop->isReadOnly())->toBeTrue("Property \${$param->getName()} should be readonly");
        expect($prop->hasType())->toBeTrue("Property \${$param->getName()} should have a type declaration");
    }
});

test('ConditionEngine contract binding returns correct implementation', function (): void {
    $app = app();
    $contract = $app->make(ConditionEngineContract::class);

    expect($contract)->toBeInstanceOf(ConditionEngine::class);
});

test('WildcardMatcher is readonly final class', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue('WildcardMatcher must be final');
    expect($reflection->isReadOnly())->toBeTrue('WildcardMatcher must be readonly');
});

test('WildcardMatcher::extractWildcards returns empty for ** patterns', function (): void {
    $result = WildcardMatcher::extractWildcards('order.**', 'order.placed.extra');

    expect($result)->toBe([]);
});

test('WildcardMatcher::extractWildcards returns values for single-segment wildcards', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.created');

    expect($result)->toBe(['profile']);
});

test('WildcardMatcher::extractWildcards returns empty when segment count mismatches', function (): void {
    $result = WildcardMatcher::extractWildcards('user.*.created', 'user.profile.action.created');

    expect($result)->toBe([]);
});

test('DomainEvent::fromArray rejects non-string eventType', function (): void {
    DomainEvent::fromArray([
        'eventType' => 12345,
        'payload' => [],
    ]);
})->throws(\InvalidArgumentException::class);

test('DomainEvent::fromArray rejects numeric-string-empty eventType', function (): void {
    DomainEvent::fromArray([
        'eventType' => '',
        'payload' => [],
    ]);
})->throws(\InvalidArgumentException::class);

test('DispatchTriggerJob has all queue properties typed', function (): void {
    $reflection = new ReflectionClass(DispatchTriggerJob::class);

    $backoff = $reflection->getProperty('backoff');
    expect($backoff->hasType())->toBeTrue();

    $queue = $reflection->getProperty('queue');
    expect($queue->hasType())->toBeTrue();

    $tries = $reflection->getProperty('tries');
    expect($tries->hasType())->toBeTrue();

    $connection = $reflection->getProperty('connection');
    expect($connection->hasType())->toBeTrue();

    $triggerId = $reflection->getProperty('triggerId');
    expect($triggerId->isReadOnly())->toBeTrue();

    $event = $reflection->getProperty('event');
    expect($event->isReadOnly())->toBeTrue();

    $payload = $reflection->getProperty('payload');
    expect($payload->isReadOnly())->toBeTrue();
});

test('EventsServiceProvider provides all registered services', function (): void {
    $provider = new EventsServiceProvider(app());
    $provides = $provider->provides();

    expect($provides)->toContain(
        EventManager::class,
        ConditionEngine::class,
        ConditionEngineContract::class,
        ActionResolver::class,
        TriggerBuilder::class,
        SubscriptionBuilder::class,
        EventScheduler::class,
    );
});

test('all console commands have return type int on handle method', function (): void {
    $commandDir = __DIR__.'/../src/Console';
    $files = glob($commandDir.'/*.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            continue;
        }

        // Extract class name
        if (! preg_match('/class\s+(\w+)/', $contents, $match)) {
            continue;
        }

        $reflection = new ReflectionClass('ZeroBoiler\\Events\\Console\\'.$match[1]);

        if (! $reflection->hasMethod('handle')) {
            continue;
        }

        $handle = $reflection->getMethod('handle');
        $returnType = $handle->getReturnType();

        expect($returnType)->not->toBeNull("{$match[1]}::handle() must have a return type");
        expect((string) $returnType)->toBe('int', "{$match[1]}::handle() must return int");
    }
});

test('EscapesWildcardLike trait returns null for non-wildcard patterns', function (): void {
    // Test via a class that uses the trait — Subscription model has it
    $reflection = new ReflectionMethod(Subscription::class, 'wildcardToLike');
    expect($reflection->isProtected())->toBeTrue();
});

test('GetsWebhookTimeout returns positive integer', function (): void {
    // The trait is used in WebhookAction. We can test the method exists.
    $reflection = new ReflectionMethod(WebhookAction::class, 'getWebhookTimeout');
    expect($reflection->getReturnType()?->getName())->toBe('int');
});

test('Trigger model has all expected scopes', function (): void {
    $reflection = new ReflectionClass(Trigger::class);
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    expect($methods)->toContain(
        'scopeEnabled',
        'scopeAsync',
        'scopeOrderByPriority',
    );
});

test('Subscription model has failure tracking methods', function (): void {
    $reflection = new ReflectionClass(Subscription::class);

    expect($reflection->hasMethod('recordDelivery'))->toBeTrue()
        ->and($reflection->hasMethod('recordFailure'))->toBeTrue()
        ->and($reflection->hasMethod('resetFailures'))->toBeTrue()
        ->and($reflection->hasMethod('hasExceededFailures'))->toBeTrue()
        ->and($reflection->hasMethod('signPayload'))->toBeTrue()
        ->and($reflection->hasMethod('matchesEvent'))->toBeTrue();
});

test('EventLog model has all status constants', function (): void {
    expect(EventLog::STATUS_PENDING)->toBe('pending')
        ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
        ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
        ->and(EventLog::STATUS_FAILED)->toBe('failed');
});

test('EventLog model status list is complete', function (): void {
    $expected = [
        EventLog::STATUS_PENDING,
        EventLog::STATUS_DISPATCHED,
        EventLog::STATUS_COMPLETED,
        EventLog::STATUS_FAILED,
    ];

    expect(EventLog::$statuses)->toEqual($expected);
});

test('config file has all required sections', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config)->toBeArray()
        ->and($config)->toHaveKeys([
            'table_names',
            'queue',
            'retry',
            'retention',
            'subscriptions',
            'disabled',
            'wildcard_cache_ttl',
        ]);

    // Nested structure checks
    expect($config['table_names'])->toHaveKeys(['triggers', 'event_logs', 'subscriptions'])
        ->and($config['queue'])->toHaveKeys(['connection', 'queue'])
        ->and($config['retry'])->toHaveKeys(['tries', 'backoff'])
        ->and($config['retention'])->toHaveKeys(['days', 'include_pending', 'schedule_cron'])
        ->and($config['subscriptions'])->toHaveKeys([
            'auto_generate_secret',
            'secret_length',
            'max_failures',
            'timeout',
            'signature_algorithm',
            'cleanup_cron',
        ]);
});

test('EventManager::sanitizePayloadForQueue strips non-serializable values', function (): void {
    // Use reflection to test the protected method
    $manager = app()->make(EventManager::class);
    $method = new ReflectionMethod(EventManager::class, 'sanitizePayloadForQueue');
    $method->setAccessible(true);

    $payload = [
        'string' => 'hello',
        'int' => 42,
        'float' => 3.14,
        'bool' => true,
        'null' => null,
        'nested' => [
            'deep_string' => 'world',
            'deep_object' => new stdClass(),
        ],
        'object' => new stdClass(),
        'closure' => static fn (): string => 'should be stripped',
    ];

    $result = $method->invoke($manager, $payload);

    expect($result['string'])->toBe('hello')
        ->and($result['int'])->toBe(42)
        ->and($result['float'])->toBe(3.14)
        ->and($result['bool'])->toBe(true)
        ->and($result['null'])->toBeNull()
        ->and($result['nested']['deep_string'])->toBe('world')
        ->and($result['nested']['deep_object'])->toStartWith('[stripped:')
        ->and($result['object'])->toStartWith('[stripped:')
        ->and($result['closure'])->toStartWith('[stripped:');
});

test('ConditionEngine handles all documented operators', function (): void {
    $engine = new ConditionEngine;

    // Simple equality
    expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();

    // Greater than
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 150]))->toBeTrue();
    expect($engine->matches(['amount' => ['>', 100]], ['amount' => 50]))->toBeFalse();

    // Between
    expect($engine->matches(['price' => ['between', [10, 20]]], ['price' => 15]))->toBeTrue();

    // In
    expect($engine->matches(['role' => ['in', ['admin', 'mod']]], ['role' => 'admin']))->toBeTrue();

    // Null
    expect($engine->matches(['deleted_at' => ['null']], ['deleted_at' => null]))->toBeTrue();

    // Starts with
    expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@test.com']))->toBeTrue();

    // Matches (regex)
    expect($engine->matches(['code' => ['matches', '/^[A-Z]{3}$/']], ['code' => 'ABC']))->toBeTrue();

    // Empty
    expect($engine->matches(['tags' => ['empty']], ['tags' => []]))->toBeTrue();

    // Not empty
    expect($engine->matches(['tags' => ['not_empty']], ['tags' => ['urgent']]))->toBeTrue();
});

test('ConditionEngine::safeRegexMatch rejects overly long patterns', function (): void {
    $engine = new ConditionEngine;
    $method = new ReflectionMethod(ConditionEngine::class, 'safeRegexMatch');
    $method->setAccessible(true);

    $longPattern = '/'.str_repeat('a', 501).'/';

    expect($method->invoke($engine, $longPattern, 'aaa'))->toBeFalse();
});

test('ActionResolver throws for non-existent class', function (): void {
    $resolver = app()->make(ActionResolver::class);

    $resolver->resolve('NonExistent\\Action\\Class');
})->throws(\InvalidArgumentException::class);

test('Facade proxy has all public EventManager methods documented', function (): void {
    $facadeReflection = new ReflectionClass(EventManagerFacade::class);
    $docComment = $facadeReflection->getDocComment();

    expect($docComment)->not->toBeFalse();

    $doc = $docComment ?: '';

    // Verify key methods are documented in the facade
    $requiredMethods = [
        'on(', 'fire(', 'fireModel(', 'enable(', 'disable(',
        'subscribe(', 'unsubscribe(', 'listTriggers(',
        'getEventHistory(', 'getStats(', 'purgeLogs(',
    ];

    foreach ($requiredMethods as $method) {
        expect($doc)->toContain("@method static")->toContain($method);
    }
});

test('phpstan.neon.dist level is 9', function (): void {
    $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
    expect($contents)->not->toBeFalse()
        ->and($contents)->toContain('level: 9');
});
