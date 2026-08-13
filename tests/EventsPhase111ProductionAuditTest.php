<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Command;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Console\EventsFireCommand;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\EventManager as EventManagerFacadeDirect;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Contracts\ConditionEngineContract as Contract;

/**
 * Phase 111 — Final production readiness audit for events package.
 *
 * Validates: setAccessible removal, EventsFireCommand --async coverage,
 * README badge version, strict types, final classes, config completeness,
 * ServiceProvider bindings, and PHP 8.5 compatibility.
 */
test('Phase 111: zero setAccessible(true) calls in all test files', function (): void {
    $violations = [];
    $testDir = __DIR__;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        $lines = explode("\n", $contents);

        foreach ($lines as $num => $line) {
            // Skip comments that merely mention setAccessible
            if (str_contains($line, '//') || str_contains($line, '*')) {
                // Check if the actual method call is on this line (not just a comment about it)
                $stripped = str_replace(['//', '*'], '', $line);
                if (! preg_match('/->setAccessible\s*\(/', $stripped)) {
                    continue;
                }
            }

            if (preg_match('/->setAccessible\s*\(/', $line)) {
                $violations[] = basename($file->getPathname()).':'.($num + 1);
            }
        }
    }

    expect($violations)->toBeEmpty(
        'Found setAccessible() calls (removed in PHP 8.5): '.implode(', ', $violations)
    );
});

test('Phase 111: EventsFireCommand has --async option in signature', function (): void {
    $command = new EventsFireCommand;
    $definition = $command->getDefinition();

    expect($definition->hasOption('async'))->toBeTrue();
    expect($command->getSignature())->toContain('--async');
});

test('Phase 111: EventsFireCommand handle method has int return type', function (): void {
    $method = new ReflectionMethod(EventsFireCommand::class, 'handle');
    $returnType = $method->getReturnType();

    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('int');
});

test('Phase 111: EventsFireCommand parseJsonOption has nullable array return type', function (): void {
    $method = new ReflectionMethod(EventsFireCommand::class, 'parseJsonOption');
    $returnType = $method->getReturnType();

    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('array');
    expect($returnType->allowsNull())->toBeTrue();
});

test('Phase 111: EventsFireCommand is final class', function (): void {
    $reflection = new ReflectionClass(EventsFireCommand::class);

    expect($reflection->isFinal())->toBeTrue('EventsFireCommand must be final');
});

test('Phase 111: README version badge matches composer.json version', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    $version = $composer['version'] ?? 'unknown';

    expect($readme)->toContain("version-{$version}");
});

test('Phase 111: all 12 console commands are registered in ServiceProvider', function (): void {
    $provider = new EventsServiceProvider($this->app);

    // Boot the provider to register commands
    $this->app->register($provider);

    $kernel = $this->app->make('Illuminate\Contracts\Console\Kernel');
    $kernel->bootstrap();

    $artisan = $this->app->make('Illuminate\Contracts\Console\Application');
    $commands = array_keys($artisan->all());

    $expected = [
        'zeroboiler:events:list',
        'zeroboiler:events:fire',
        'zeroboiler:events:register',
        'zeroboiler:events:enable',
        'zeroboiler:events:disable',
        'zeroboiler:events:log',
        'zeroboiler:events:retry',
        'zeroboiler:events:health',
        'zeroboiler:events:subscribe',
        'zeroboiler:events:unsubscribe',
        'zeroboiler:events:subscriptions',
        'zeroboiler:events:redeliver',
    ];

    foreach ($expected as $cmd) {
        expect(in_array($cmd, $commands, true))->toBeTrue("Missing command: {$cmd}");
    }
});

test('Phase 111: ConditionEngine supports all 19 documented operators', function (): void {
    $engine = new ConditionEngine;

    $operators = [
        '>' => ['age', ['>', 18], ['age' => 25]],
        '>=' => ['age', ['>=', 18], ['age' => 18]],
        '<' => ['age', ['<', 65], ['age' => 30]],
        '<=' => ['age', ['<=', 65], ['age' => 65]],
        '=' => ['status', 'paid', ['status' => 'paid']],
        '===' => ['flag', ['===', true], ['flag' => true]],
        '!=' => ['status', ['!=', 'draft'], ['status' => 'paid']],
        '!==' => ['flag', ['!==', true], ['flag' => false]],
        'in' => ['role', ['in', ['admin', 'mod']], ['role' => 'admin']],
        'not_in' => ['role', ['not_in', ['guest']], ['role' => 'admin']],
        'contains' => ['tags', ['contains', 'urgent'], ['tags' => ['urgent', 'high']]],
        'not_contains' => ['tags', ['not_contains', 'spam'], ['tags' => ['urgent']]],
        'between' => ['age', ['between', [18, 65]], ['age' => 30]],
        'null' => ['deleted_at', ['null'], ['deleted_at' => null]],
        'not_null' => ['email', ['not_null'], ['email' => 'test@example.com']],
        'empty' => ['notes', ['empty'], ['notes' => '']],
        'not_empty' => ['notes', ['not_empty'], ['notes' => 'hello']],
        'starts_with' => ['email', ['starts_with', 'admin@'], ['email' => 'admin@test.com']],
        'ends_with' => ['domain', ['ends_with', '.com'], ['domain' => 'example.com']],
        'matches' => ['code', ['matches', '/^[A-Z]{3}$/'], ['code' => 'ABC']],
    ];

    foreach ($operators as $name => $args) {
        [$field, $expected, $payload] = $args;
        $result = $engine->matches([$field => $expected], $payload);
        expect($result)->toBeTrue("Operator '{$name}' failed");
    }
});

test('Phase 111: DomainEvent roundtrip preserves identity', function (): void {
    $event = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);

    $data = $event->toArray();
    $restored = DomainEvent::fromArray($data);

    expect($restored->eventId->toString())->toBe($event->eventId->toString());
    expect($restored->eventType)->toBe($event->eventType);
    expect($restored->payload)->toBe($event->payload);
    expect($restored->occurredAt->format(DateTimeImmutable::ATOM))->toBe(
        $event->occurredAt->format(DateTimeImmutable::ATOM)
    );
});

test('Phase 111: Trigger model has correct casts', function (): void {
    $trigger = new Trigger;
    $casts = $trigger->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('async');
    expect($casts)->toHaveKey('enabled');
    expect($casts)->toHaveKey('priority');
});

test('Phase 111: EventLog model has correct casts', function (): void {
    $log = new EventLog;
    $casts = $log->casts();

    expect($casts)->toHaveKey('payload');
    expect($casts)->toHaveKey('duration_ms');
    expect($casts)->toHaveKey('error');
});

test('Phase 111: Subscription model has correct casts', function (): void {
    $subscription = new Subscription;
    $casts = $subscription->casts();

    expect($casts)->toHaveKey('conditions');
    expect($casts)->toHaveKey('priority');
    expect($casts)->toHaveKey('active');
    expect($casts)->toHaveKey('failure_count');
    expect($casts)->toHaveKey('delivery_count');
    expect($casts)->toHaveKey('last_fired_at');
});

test('Phase 111: config events.php has all required keys', function (): void {
    $config = include __DIR__.'/../config/events.php';

    $requiredKeys = [
        'table_names', 'queue', 'retry', 'retention', 'subscriptions',
        'disabled', 'wildcard_cache_ttl',
    ];

    foreach ($requiredKeys as $key) {
        expect(array_key_exists($key, $config))->toBeTrue("Missing config key: {$key}");
    }
});

test('Phase 111: config table_names has triggers, event_logs, subscriptions', function (): void {
    $config = include __DIR__.'/../config/events.php';

    expect($config['table_names'])->toHaveKey('triggers');
    expect($config['table_names'])->toHaveKey('event_logs');
    expect($config['table_names'])->toHaveKey('subscriptions');
});

test('Phase 111: EventManager facade proxy covers all public methods', function (): void {
    $facadeReflection = new ReflectionClass(EventManagerFacade::class);
    $doc = $facadeReflection->getDocComment();
    expect($doc)->not()->toBeFalse();

    $requiredMethods = [
        'on(', 'register(', 'fire(', 'fireModel(',
        'enable(', 'disable(', 'deleteTrigger(',
        'invalidateTriggerCache(', 'isDisabled(', 'setEnabled(',
        'listTriggers(', 'getTrigger(',
        'subscribe(', 'unsubscribe(', 'listSubscriptions(', 'getSubscription(',
        'subscribeWebhook(', 'getEventHistory(', 'getStats(',
        'purgeLogs(', 'getStalePendingLogs(', 'deactivateExceededSubscriptions(',
        'executeTrigger(', 'registerScheduler(',
    ];

    foreach ($requiredMethods as $method) {
        expect($doc)->toContain($method, "Facade docblock missing @method for {$method}");
    }
});

test('Phase 111: WildcardMatcher is readonly final class', function (): void {
    $reflection = new ReflectionClass(WildcardMatcher::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});
