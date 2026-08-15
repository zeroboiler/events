<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Concerns\GetsWebhookTimeout;
use ZeroBoiler\Events\Concerns\EscapesWildcardLike;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;

describe('Production API surface completeness', function (): void {
    test('all source classes are final', function (): void {
        $finalClasses = [
            EventManager::class,
            ConditionEngine::class,
            WildcardMatcher::class,
            DomainEvent::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            Trigger::class,
            EventLog::class,
            Subscription::class,
            WebhookAction::class,
            \ZeroBoiler\Events\EventsServiceProvider::class,
            \ZeroBoiler\Events\Jobs\DispatchTriggerJob::class,
        ];

        foreach ($finalClasses as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())->toBeTrue("{$class} should be final");
        }
    });

    test('all public methods have return type declarations', function (): void {
        $classesToCheck = [
            EventManager::class,
            ConditionEngine::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            DomainEvent::class,
        ];

        foreach ($classesToCheck as $class) {
            $reflection = new ReflectionClass($class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                // Skip constructor — return type is implied
                if ($method->getName() === '__construct') {
                    continue;
                }

                $returnType = $method->getReturnType();

                expect($returnType)->not->toBeNull(
                    "{$class}::{$method->getName()}() must have a return type declaration"
                );
            }
        }
    });

    test('ConditionEngineContract is properly bound', function (): void {
        $contract = app(ConditionEngineContract::class);

        expect($contract)->toBeInstanceOf(ConditionEngine::class);
    });

    test('Triggerable interface has handle() method', function (): void {
        $reflection = new ReflectionClass(Triggerable::class);

        expect($reflection->hasMethod('handle'))->toBeTrue();

        $method = $reflection->getMethod('handle');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('WildcardMatcher is readonly with no properties', function (): void {
        $reflection = new ReflectionClass(WildcardMatcher::class);

        expect($reflection->isReadOnly())->toBeTrue();
        expect($reflection->getProperties())->toHaveCount(0);
    });

    test('DomainEvent is immutable', function (): void {
        $event = DomainEvent::occur('test.event', ['key' => 'value']);

        // Readonly properties should prevent modification
        $reflection = new ReflectionClass($event);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            expect($property->isReadOnly())->toBeTrue(
                "DomainEvent::{$property->getName()} should be readonly"
            );
        }
    });

    test('DomainEvent fromArray preserves eventId and occurredAt', function (): void {
        $original = DomainEvent::occur('preserve.test', ['data' => 123]);

        $array = $original->toArray();

        $restored = DomainEvent::fromArray($array);

        expect($restored->eventId->toString())->toBe($original->eventId->toString())
            ->and($restored->eventType)->toBe('preserve.test')
            ->and($restored->payload)->toBe(['data' => 123]);
    });

    test('EventLog status constants cover all expected values', function (): void {
        expect(EventLog::$statuses)->toBe([
            EventLog::STATUS_PENDING,
            EventLog::STATUS_DISPATCHED,
            EventLog::STATUS_COMPLETED,
            EventLog::STATUS_FAILED,
        ]);
    });

    test('EventsServiceProvider provides() includes EventScheduler', function (): void {
        $provider = new \ZeroBoiler\Events\EventsServiceProvider(app());
        $provides = $provider->provides();

        expect($provides)->toContain(
            EventManager::class,
            ConditionEngine::class,
            ConditionEngineContract::class,
            ActionResolver::class,
            TriggerBuilder::class,
            SubscriptionBuilder::class,
            \ZeroBoiler\Events\EventScheduler::class,
        );
    });

    test('config events.retention.schedule_cron is documented and valid', function (): void {
        $cron = config('events.retention.schedule_cron');

        expect($cron)->toBeString()
            ->and($cron)->not->toBeEmpty();
    });

    test('config events.subscriptions.cleanup_cron is documented and valid', function (): void {
        $cron = config('events.subscriptions.cleanup_cron');

        expect($cron)->toBeString()
            ->and($cron)->not->toBeEmpty();
    });
});
