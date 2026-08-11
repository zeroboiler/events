<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

// ─── Phase 81: SerializesModels removal ────────────────────────────────────

test('DispatchTriggerJob does NOT use SerializesModels trait', function (): void {
    $file = file_get_contents(__DIR__.'/../src/Jobs/DispatchTriggerJob.php');
    expect($file)->toBeString();
    expect($file)->not->toContain('SerializesModels');
    expect($file)->not->toContain('use Illuminate\\Queue\\SerializesModels');
});

test('DispatchTriggerJob only stores primitive types in properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
    $props = $ref->getProperties();

    $allowedTypes = ['string', 'array', 'int', 'bool', 'float', 'null', 'mixed'];

    foreach ($props as $prop) {
        if ($prop->getName() === 'app') {
            continue; // Skip Container property from InteractsWithQueue
        }

        $type = $prop->getType();
        if ($type === null) {
            continue;
        }

        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        // Skip built-in property types from traits (connection, queue from Queueable)
        if (in_array($prop->getName(), ['connection', 'queue'], true)) {
            continue;
        }

        // All custom properties should be primitive types
        if (! in_array($typeName, $allowedTypes, true)) {
            expect($typeName)->toBeIn($allowedTypes, "Property {$prop->getName()} has type {$typeName} — expected primitive type only");
        }
    }

    // Verify specific properties
    expect($ref->hasProperty('triggerId'))->toBeTrue();
    expect($ref->hasProperty('event'))->toBeTrue();
    expect($ref->hasProperty('payload'))->toBeTrue();
    expect($ref->hasProperty('eventLogId'))->toBeTrue();
});

// ─── Phase 81: #[Override] attributes on DispatchTriggerJob ─────────────────

test('DispatchTriggerJob::handle has Override attribute', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class, 'handle');
    $attrs = $ref->getAttributes();

    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }

    expect($hasOverride)->toBeTrue('DispatchTriggerJob::handle() should have #[Override] attribute');
});

test('DispatchTriggerJob::failed has Override attribute', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class, 'failed');
    $attrs = $ref->getAttributes();

    $hasOverride = false;
    foreach ($attrs as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }

    expect($hasOverride)->toBeTrue('DispatchTriggerJob::failed() should have #[Override] attribute');
});

// ─── Phase 81: DispatchTriggerJob constructor reads config ─────────────────

test('DispatchTriggerJob constructor sets tries from config', function (): void {
    config(['events.retry.tries' => 5]);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->tries)->toBe(5);
});

test('DispatchTriggerJob constructor falls back to default tries', function (): void {
    config(['events.retry.tries' => null]);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->tries)->toBe(3);
});

test('DispatchTriggerJob constructor sets queue name from config', function (): void {
    config(['events.queue.queue' => 'events-high']);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->queue)->toBe('events-high');
});

test('DispatchTriggerJob constructor sets connection from config', function (): void {
    config(['events.queue.connection' => 'redis']);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->connection)->toBe('redis');
});

test('DispatchTriggerJob constructor ignores empty connection config', function (): void {
    config(['events.queue.connection' => '']);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->connection)->toBeNull();
});

test('DispatchTriggerJob constructor parses string backoff', function (): void {
    config(['events.retry.backoff' => '30,120,300']);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([30, 120, 300]);
});

test('DispatchTriggerJob constructor handles array backoff from config', function (): void {
    config(['events.retry.backoff' => [10, 60, 180]]);

    $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
        'test-trigger-id',
        'test.event',
        ['key' => 'value'],
    );

    expect($job->backoff)->toBe([10, 60, 180]);
});

// ─── Phase 81: All source files use strict_types ──────────────────────────

test('all source files declare strict_types', function (): void {
    $dir = new RecursiveDirectoryIterator(__DIR__.'/../src');
    $iterator = new RecursiveIteratorIterator($dir);
    $phpFiles = new RegexIterator($iterator, '/\.php$/');

    $violations = [];
    foreach ($phpFiles as $file) {
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }
        if (! str_contains($content, 'declare(strict_types=1)')) {
            $violations[] = str_replace(__DIR__.'/../', '', $file->getPathname());
        }
    }

    expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
});

// ─── Phase 81: All final classes are actually final ─────────────────────────

test('all designated final classes have final keyword', function (): void {
    $finalClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
        \ZeroBoiler\Events\Facades\EventManager::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
    ];

    // Console commands are also final
    $consoleDir = __DIR__.'/../src/Console';
    foreach (glob($consoleDir.'/*Command.php') as $file) {
        $className = 'ZeroBoiler\\Events\\Console\\'.basename($file, '.php');
        if (class_exists($className)) {
            $finalClasses[] = $className;
        }
    }

    foreach ($finalClasses as $class) {
        $ref = new ReflectionClass($class);
        expect($ref->isFinal())->toBeTrue("{$class} should be final");
    }
});

// ─── Phase 81: Model property type annotations are complete ───────────────

test('Trigger model has typed properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\Trigger::class);

    expect($ref->getProperty('keyType')->getType()->getName())->toBe('string');
    expect($ref->getProperty('incrementing')->getType()->getName())->toBe('bool');
    expect($ref->getProperty('fillable')->getType())->toBeNull(); // Old-style, acceptable
});

test('EventLog model has typed properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\EventLog::class);

    expect($ref->getProperty('keyType')->getType()->getName())->toBe('string');
    expect($ref->getProperty('incrementing')->getType()->getName())->toBe('bool');
});

test('Subscription model has typed properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Models\Subscription::class);

    expect($ref->getProperty('keyType')->getType()->getName())->toBe('string');
    expect($ref->getProperty('incrementing')->getType()->getName())->toBe('bool');
});

// ─── Phase 81: Composer.json consistency ──────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($composer['version'])->toBeString();
    expect($readme)->toContain("badge/version-{$composer['version']}-blue");
});

test('composer.json requires PHP ^8.5', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['php'])->toBe('^8.5');
});

test('composer.json requires Laravel ^13.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
});

test('composer.json has correct autoload PSR-4 mapping', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
});

test('composer.json has correct service provider registration', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['providers'])->toContain(
        'ZeroBoiler\\Events\\EventsServiceProvider',
    );
});

test('composer.json has correct facade alias', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
        'ZeroBoiler\\Events\\Facades\\EventManager',
    );
});

// ─── Phase 81: Contract interfaces are implemented ────────────────────────

test('ConditionEngine implements ConditionEngineContract', function (): void {
    $engine = new ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
    expect($engine->implementsInterface(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();
});

test('WebhookAction implements Triggerable', function (): void {
    $action = new ReflectionClass(\ZeroBoiler\Events\Actions\WebhookAction::class);
    expect($action->implementsInterface(\ZeroBoiler\Events\Contracts\Triggerable::class))->toBeTrue();
});

// ─── Phase 81: Domain event immutability ─────────────────────────────────

test('DomainEvent has readonly properties', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);

    $props = ['eventId', 'eventType', 'payload', 'occurredAt'];
    foreach ($props as $propName) {
        expect($ref->hasProperty($propName))->toBeTrue("DomainEvent should have {$propName} property");

        $prop = $ref->getProperty($propName);
        expect($prop->isReadOnly())->toBeTrue("DomainEvent::\${$propName} should be readonly");
    }
});

test('DomainEvent::occur factory creates immutable instance', function (): void {
    $event = \ZeroBoiler\Events\Domain\DomainEvent::occur('test.event', ['key' => 'value']);

    expect($event->eventType)->toBe('test.event');
    expect($event->payload)->toBe(['key' => 'value']);
    expect($event->eventId->toString())->toBeString();
    expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('DomainEvent::fromArray reconstructs preserving UUID and timestamp', function (): void {
    $original = \ZeroBoiler\Events\Domain\DomainEvent::occur('user.created', ['name' => 'Alice']);
    $data = $original->toArray();

    $reconstructed = \ZeroBoiler\Events\Domain\DomainEvent::fromArray($data);

    expect($reconstructed->eventId->toString())->toBe($original->eventId->toString());
    expect($reconstructed->eventType)->toBe($original->eventType);
    expect($reconstructed->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
        $original->occurredAt->format(DateTimeImmutable::ATOM),
    );
});
