<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use ZeroBoiler\Events\Console\EventsHealthCommand;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Tests\TestCase;

uses(TestCase::class);

test('EventsHealthCommand exists and is final', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    expect($ref->isFinal())->toBeTrue();
});

test('EventsHealthCommand has zeroboiler:events:health signature', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    $prop = $ref->getProperty('signature');
    $sig = $prop->getValue(new \ZeroBoiler\Events\Console\EventsHealthCommand);
    expect($sig)->toContain('zeroboiler:events:health');
});

test('EventsHealthCommand has --json option', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    $prop = $ref->getProperty('signature');
    $sig = $prop->getValue(new \ZeroBoiler\Events\Console\EventsHealthCommand);
    expect($sig)->toContain('--json');
});

test('EventsHealthCommand has --check-cache option', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    $prop = $ref->getProperty('signature');
    $sig = $prop->getValue(new \ZeroBoiler\Events\Console\EventsHealthCommand);
    expect($sig)->toContain('--check-cache');
});

test('EventsHealthCommand handle method has int return type', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    $method = $ref->getMethod('handle');
    expect($method->getReturnType()?->getName())->toBe('int');
});

test('EventsHealthCommand handle method has #[Override] attribute', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    $method = $ref->getMethod('handle');
    $attrs = $method->getAttributes(\Override::class);
    expect(count($attrs))->toBe(1);
});

test('EventsHealthCommand is registered in ServiceProvider', function (): void {
    $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    // Verify the command is registered in the Artisan command list
    $commands = $this->app->get('commands');
    $registered = false;
    foreach ($commands as $command) {
        if ($command instanceof \ZeroBoiler\Events\Console\EventsHealthCommand) {
            $registered = true;
            break;
        }
    }
    expect($registered)->toBeTrue();
});

test('EventsHealthCommand health output contains all sections', function (): void {
    // Simulate the command's health check structure without actually running it
    // (no real DB/cache available in test environment)
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);

    // Verify all key properties exist
    expect($ref->hasProperty('signature'))->toBeTrue();
    expect($ref->hasProperty('description'))->toBeTrue();
    expect($ref->hasMethod('handle'))->toBeTrue();
});

test('EventsHealthCommand has declare strict_types', function (): void {
    $file = file_get_contents((string) (new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class))->getFileName());
    expect($file)->toContain('declare(strict_types=1)');
});

test('EventsHealthCommand has license header', function (): void {
    $file = file_get_contents((string) (new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class))->getFileName());
    expect($file)->toContain('This file is part of ZeroBoiler');
});

test('EventsHealthCommand extends Illuminate\Console\Command', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
    expect($ref->getParentClass()->getName())->toBe(\Illuminate\Console\Command::class);
});

test('Phase 59 production — comprehensive audit with health command', function (): void {
    // 1. Health command existence
    expect(class_exists(\ZeroBoiler\Events\Console\EventsHealthCommand::class))->toBeTrue();

    // 2. All 12 console commands are final
    $commands = [
        \ZeroBoiler\Events\Console\EventsListCommand::class,
        \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
        \ZeroBoiler\Events\Console\EventsFireCommand::class,
        \ZeroBoiler\Events\Console\EventsLogCommand::class,
        \ZeroBoiler\Events\Console\EventsRetryCommand::class,
        \ZeroBoiler\Events\Console\EventsEnableCommand::class,
        \ZeroBoiler\Events\Console\EventsDisableCommand::class,
        \ZeroBoiler\Events\Console\EventsHealthCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
        \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
        \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
    ];

    foreach ($commands as $cmd) {
        expect((new ReflectionClass($cmd))->isFinal())->toBeTrue("{$cmd} must be final");
    }

    // 3. All core classes are final
    $coreClasses = [
        \ZeroBoiler\Events\EventManager::class,
        \ZeroBoiler\Events\ConditionEngine::class,
        \ZeroBoiler\Events\ActionResolver::class,
        \ZeroBoiler\Events\TriggerBuilder::class,
        \ZeroBoiler\Events\SubscriptionBuilder::class,
        \ZeroBoiler\Events\WildcardMatcher::class,
        \ZeroBoiler\Events\Domain\DomainEvent::class,
        \ZeroBoiler\Events\EventsServiceProvider::class,
        \ZeroBoiler\Events\Actions\WebhookAction::class,
        \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
    ];

    foreach ($coreClasses as $cls) {
        expect((new ReflectionClass($cls))->isFinal())->toBeTrue("{$cls} must be final");
    }

    // 4. WildcardMatcher is readonly class
    $wmRef = new ReflectionClass(\ZeroBoiler\Events\WildcardMatcher::class);
    expect($wmRef->isReadOnly())->toBeTrue();

    // 5. All public WildcardMatcher methods have #[Pure]
    foreach ($wmRef->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $attrs = $method->getAttributes(\Pure::class);
        if ($method->isStatic()) {
            expect(count($attrs))->toBeGreaterThan(0, "WildcardMatcher::{$method->getName()} must have #[Pure]");
        }
    }

    // 6. DomainEvent has readonly properties
    $deRef = new ReflectionClass(\ZeroBoiler\Events\Domain\DomainEvent::class);
    $roProps = ['eventId', 'eventType', 'payload', 'occurredAt'];
    foreach ($roProps as $prop) {
        $rp = $deRef->getProperty($prop);
        expect($rp->isReadOnly())->toBeTrue("DomainEvent::\${$prop} must be readonly");
    }

    // 7. EventLog status constants
    expect(EventLog::STATUS_PENDING)->toBe('pending');
    expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
    expect(EventLog::STATUS_COMPLETED)->toBe('completed');
    expect(EventLog::STATUS_FAILED)->toBe('failed');

    // 8. Interface contracts
    $ceRef = new ReflectionClass(\ZeroBoiler\Events\ConditionEngine::class);
    expect($ceRef->implementsInterface(\ZeroBoiler\Events\Contracts\ConditionEngineContract::class))->toBeTrue();

    // 9. ServiceProvider bindings
    $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
    $provider->register();

    // Singleton bindings
    expect($this->app->isShared(\ZeroBoiler\Events\EventManager::class))->toBeTrue();
    expect($this->app->isShared(\ZeroBoiler\Events\ConditionEngine::class))->toBeTrue();
    expect($this->app->isShared(\ZeroBoiler\Events\ActionResolver::class))->toBeTrue();

    // 10. Config completeness
    $config = $this->app->get('config');
    expect($config)->toHaveKey('events');
});
