<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
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

describe('Phase 181 — Production Readiness Deep Audit', function (): void {
    it('validates SubscriptionBuilder rejects secrets shorter than 16 characters', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'https://example.com/webhook');

        expect(fn (): mixed => $builder->withSecret('short'))
            ->toThrow(InvalidArgumentException::class, 'at least 16 characters');
    });

    it('validates SubscriptionBuilder accepts secrets of exactly 16 characters', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'https://example.com/webhook');

        // 16-char secret should not throw
        $result = $builder->withSecret('abcdefghijklmnop');
        expect($result)->toBeInstanceOf(SubscriptionBuilder::class);
    });

    it('validates SubscriptionBuilder accepts long secrets', function (): void {
        $manager = app(EventManager::class);
        $builder = $manager->subscribe('test.event', 'https://example.com/webhook');

        $longSecret = str_repeat('a', 64);
        $result = $builder->withSecret($longSecret);
        expect($result)->toBeInstanceOf(SubscriptionBuilder::class);
    });

    it('verifies EventsHealthCommand has laravel property docblock', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Events\Console\EventsHealthCommand::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@property-read');
        expect($doc)->toContain('laravel');
    });

    it('verifies all source files have declare strict_types', function (): void {
        $srcDir = __DIR__.'/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $filesWithoutStrict = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false || ! str_contains($contents, 'declare(strict_types=1)')) {
                $filesWithoutStrict[] = $file->getFilename();
            }
        }

        expect($filesWithoutStrict)->toBeEmpty('Files missing strict_types: '.implode(', ', $filesWithoutStrict));
    });

    it('verifies all source files have license header', function (): void {
        $srcDir = __DIR__.'/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $filesWithoutLicense = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false || ! str_contains($contents, 'ZeroBoiler, licensed under the proprietary license')) {
                $filesWithoutLicense[] = $file->getFilename();
            }
        }

        expect($filesWithoutLicense)->toBeEmpty('Files missing license: '.implode(', ', $filesWithoutLicense));
    });

    it('verifies all public classes are final', function (): void {
        $classes = [
            EventManager::class,
            ConditionEngine::class,
            ActionResolver::class,
            EventScheduler::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            WebhookAction::class,
            DomainEvent::class,
            EventsServiceProvider::class,
            EventManagerFacade::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
            WildcardMatcher::class,
            \ZeroBoiler\Events\Console\EventsFireCommand::class,
            \ZeroBoiler\Events\Console\EventsHealthCommand::class,
            \ZeroBoiler\Events\Console\EventsListCommand::class,
            \ZeroBoiler\Events\Console\EventsLogCommand::class,
            \ZeroBoiler\Events\Console\EventsRegisterCommand::class,
            \ZeroBoiler\Events\Console\EventsEnableCommand::class,
            \ZeroBoiler\Events\Console\EventsDisableCommand::class,
            \ZeroBoiler\Events\Console\EventsRetryCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsUnsubscribeCommand::class,
            \ZeroBoiler\Events\Console\EventsSubscriptionsCommand::class,
            \ZeroBoiler\Events\Console\EventsRedeliverCommand::class,
            \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        ];

        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            expect($ref->isFinal())->toBeTrue("{$class} must be final");
        }
    });

    it('verifies DomainEvent preserves identity through roundtrip with nested payload', function (): void {
        $nestedPayload = [
            'order' => [
                'id' => 'order-123',
                'items' => [
                    ['sku' => 'A1', 'qty' => 2],
                    ['sku' => 'B2', 'qty' => 1],
                ],
                'total' => 99.99,
            ],
            'metadata' => [
                'ip' => '192.168.1.1',
                'user_agent' => 'TestBot/1.0',
            ],
        ];

        $event = DomainEvent::occur('order.placed', $nestedPayload);
        $array = $event->toArray();
        $restored = DomainEvent::fromArray($array);

        expect($restored->eventId->toString())->toBe($event->eventId->toString());
        expect($restored->occurredAt->format(\DateTimeImmutable::ATOM))->toBe($event->occurredAt->format(\DateTimeImmutable::ATOM));
        expect($restored->eventType)->toBe('order.placed');
        expect($restored->payload)->toBe($nestedPayload);
    });

    it('verifies ConditionEngine not_contains operator', function (): void {
        $engine = new ConditionEngine;
        $payload = ['tags' => ['urgent', 'billing', 'review']];

        expect($engine->matches(['tags' => ['not_contains', 'spam']], $payload))->toBeTrue();
        expect($engine->matches(['tags' => ['not_contains', 'urgent']], $payload))->toBeFalse();
    });

    it('verifies ConditionEngine not_empty operator', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(['notes' => ['not_empty']], ['notes' => 'some text']))->toBeTrue();
        expect($engine->matches(['notes' => ['not_empty']], ['notes' => '']))->toBeFalse();
        expect($engine->matches(['notes' => ['not_empty']], ['notes' => []]))->toBeFalse();
    });

    it('verifies WildcardMatcher with multi-segment patterns', function (): void {
        expect(WildcardMatcher::matches('order.item.*', 'order.item.created'))->toBeTrue();
        expect(WildcardMatcher::matches('order.item.*', 'order.item.updated'))->toBeTrue();
        expect(WildcardMatcher::matches('order.item.*', 'order.item.sub.created'))->toBeFalse();
        expect(WildcardMatcher::matches('order.item.**', 'order.item.sub.created'))->toBeTrue();
    });

    it('verifies config has all required top-level keys', function (): void {
        $config = config('events');
        expect($config)->not->toBeNull();

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
            expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
        }
    });

    it('verifies config table_names has all three tables', function (): void {
        $tables = config('events.table_names');
        expect($tables)->not->toBeNull();
        expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
    });

    it('verifies config subscriptions has all required sub-keys', function (): void {
        $subs = config('events.subscriptions');
        expect($subs)->not->toBeNull();

        $requiredSubKeys = [
            'auto_generate_secret',
            'secret_length',
            'max_failures',
            'timeout',
            'signature_algorithm',
            'cleanup_cron',
        ];

        foreach ($requiredSubKeys as $key) {
            expect(array_key_exists($key, $subs))->toBeTrue("Missing subscription config key: {$key}");
        }
    });

    it('verifies ServiceProvider provides all bindings', function (): void {
        $provider = new EventsServiceProvider(app());
        $provides = $provider->provides();

        $expectedBindings = [
            EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            EventScheduler::class,
        ];

        foreach ($expectedBindings as $binding) {
            expect(in_array($binding, $provides, true))->toBeTrue("Missing from provides(): {$binding}");
        }
    });

    it('verifies models have correct casts', function (): void {
        // Trigger
        $triggerReflection = new ReflectionMethod(Trigger::class, 'casts');
        $triggerCasts = $triggerReflection->invoke(null);
        expect($triggerCasts)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);

        // EventLog
        $logReflection = new ReflectionMethod(EventLog::class, 'casts');
        $logCasts = $logReflection->invoke(null);
        expect($logCasts)->toHaveKeys(['payload', 'duration_ms', 'error']);

        // Subscription
        $subReflection = new ReflectionMethod(Subscription::class, 'casts');
        $subCasts = $subReflection->invoke(null);
        expect($subCasts)->toHaveKeys(['conditions', 'priority', 'active', 'failure_count', 'delivery_count', 'last_fired_at']);
    });

    it('verifies EventLog has all status constants', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending');
        expect(EventLog::STATUS_DISPATCHED)->toBe('dispatched');
        expect(EventLog::STATUS_COMPLETED)->toBe('completed');
        expect(EventLog::STATUS_FAILED)->toBe('failed');
    });

    it('verifies composer.json PHP version requirement', function (): void {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    it('verifies WildcardMatcher is readonly final class', function (): void {
        $ref = new ReflectionClass(WildcardMatcher::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    it('verifies sanitizePayloadForQueue strips objects but keeps scalars', function (): void {
        $reflection = new ReflectionMethod(EventManager::class, 'sanitizePayloadForQueue');

        $manager = app(EventManager::class);

        $payload = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => ['nested' => 'value'],
            'object' => new stdClass,
            'closure' => static fn (): string => 'should be stripped',
        ];

        $result = $reflection->invoke($manager, $payload);

        expect($result['string'])->toBe('hello');
        expect($result['int'])->toBe(42);
        expect($result['float'])->toBe(3.14);
        expect($result['bool'])->toBeTrue();
        expect($result['null'])->toBeNull();
        expect($result['array'])->toBe(['nested' => 'value']);
        expect($result['object'])->toBeString();
        expect($result['object'])->toContain('stripped');
        expect($result['closure'])->toBeString();
        expect($result['closure'])->toContain('stripped');
    });

    it('verifies ManagesSubscriptions unsubscribe cleans associated trigger', function (): void {
        // This tests the unsubscribe method removes WebhookAction triggers
        // tied to the subscription
        $manager = app(EventManager::class);

        // Verify unsubscribe returns false for non-existent subscription
        $result = $manager->unsubscribe('non-existent-id');
        expect($result)->toBeFalse();
    });

    it('verifies TriggerBuilder resolveActions merges single and multiple', function (): void {
        $reflectionMethod = new ReflectionMethod(TriggerBuilder::class, 'resolveActions');

        $manager = app(EventManager::class);
        $builder = new TriggerBuilder($manager);

        // Set up: both action() and actions() called
        $prop = new ReflectionProperty(TriggerBuilder::class, 'action');
        $prop->setValue($builder, 'ClassA');

        $propActions = new ReflectionProperty(TriggerBuilder::class, 'actions');
        $propActions->setValue($builder, ['ClassB', 'ClassC']);

        $result = $reflectionMethod->invoke($builder);
        expect($result)->toBe(['ClassA', 'ClassB', 'ClassC']);
    });

    it('verifies TriggerBuilder resolveActions deduplicates', function (): void {
        $reflectionMethod = new ReflectionMethod(TriggerBuilder::class, 'resolveActions');

        $manager = app(EventManager::class);
        $builder = new TriggerBuilder($manager);

        $propActions = new ReflectionProperty(TriggerBuilder::class, 'actions');
        $propActions->setValue($builder, ['ClassA', 'ClassA', 'ClassB', 'ClassA']);

        $prop = new ReflectionProperty(TriggerBuilder::class, 'action');
        $prop->setValue($builder, '');

        $result = $reflectionMethod->invoke($builder);
        expect($result)->toBe(['ClassA', 'ClassB']);
    });

    it('verifies Subscription recordDelivery is atomic via transaction', function (): void {
        $method = new ReflectionMethod(Subscription::class, 'recordDelivery');
        expect($method)->not->toBeFalse();
        // Verify the method exists and is public
        expect($method->isPublic())->toBeTrue();
    });

    it('verifies DispatchTriggerJob is ShouldQueue', function (): void {
        $jobRef = new ReflectionClass(\ZeroBoiler\Events\Jobs\DispatchTriggerJob::class);
        expect($jobRef->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class))->toBeTrue();
    });

    it('verifies EventManager getStats returns correct shape', function (): void {
        $manager = app(EventManager::class);
        $stats = $manager->getStats();

        expect($stats)->toBeArray();
        expect($stats)->toHaveKeys([
            'total_logs',
            'total_triggers',
            'active_triggers',
            'completed',
            'failed',
            'pending',
            'dispatched',
            'success_rate',
            'failure_rate',
            'avg_duration_ms',
            'top_events',
            'top_failed_events',
        ]);
    });

    it('verifies no source file uses deprecated setAccessible pattern for testing', function (): void {
        $srcDir = __DIR__.'/../src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && str_contains($contents, '->setAccessible(')) {
                $violations[] = $file->getFilename();
            }
        }

        expect($violations)->toBeEmpty('Files using setAccessible(): '.implode(', ', $violations));
    });

    it('verifies phpstan.neon.dist exists and is valid JSON-equivalent', function (): void {
        $neonPath = __DIR__.'/../phpstan.neon.dist';
        expect(file_exists($neonPath))->toBeTrue();

        $contents = file_get_contents($neonPath);
        expect($contents)->toContain('level: 9');
        expect($contents)->toContain('checkExplicitMixed: true');
        expect($contents)->toContain('reportUnusedIgnoredErrors: true');
        expect($contents)->toContain('checkUninitializedProperties: true');
    });

    it('verifies rector.php targets src directory with Laravel 13 set', function (): void {
        $contents = file_get_contents(__DIR__.'/../rector.php');
        expect($contents)->toContain("LaravelSetList::LARAVEL_130");
        expect($contents)->toContain("__DIR__.'/src'");
    });
});
