<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;

describe('Events v1.31.0 Phase 1 — connection property and quality', function (): void {
    test('DispatchTriggerJob connection property defaults to null without config', function (): void {
        config()->set('events.queue.connection', null);

        $job = new DispatchTriggerJob('test', 'test.event', []);

        expect($job->connection)->toBeNull();
    });

    test('DispatchTriggerJob connection property is set from config as string', function (): void {
        config()->set('events.queue.connection', 'database');

        $job = new DispatchTriggerJob('test', 'test.event', []);

        expect($job->connection)->toBe('database');
    });

    test('DispatchTriggerJob connection property ignores empty string config', function (): void {
        config()->set('events.queue.connection', '');

        $job = new DispatchTriggerJob('test', 'test.event', []);

        expect($job->connection)->toBeNull();
    });

    test('DispatchTriggerJob connection property handles numeric config gracefully', function (): void {
        config()->set('events.queue.connection', 12345);

        $job = new DispatchTriggerJob('test', 'test.event', []);

        expect($job->connection)->toBeNull();
    });

    test('DispatchTriggerJob all declared properties have native types', function (): void {
        $reflection = new ReflectionClass(DispatchTriggerJob::class);
        $props = $reflection->getProperties();

        foreach ($props as $prop) {
            // Skip properties inherited from traits (Queueable, etc.)
            if ($prop->getDeclaringClass()->getName() !== DispatchTriggerJob::class) {
                continue;
            }

            expect($prop->getType())->not->toBeNull(
                "Property {$prop->getName()} must have a native type declaration"
            );
        }
    });

    test('ConditionEngine handles all operators with null actual values safely', function (): void {
        $engine = new ConditionEngine;

        // All comparison operators should return false when actual is null
        expect($engine->matches(['amount' => ['>', 100]], []))->toBeFalse()
            ->and($engine->matches(['amount' => ['>=', 100]], []))->toBeFalse()
            ->and($engine->matches(['amount' => ['<', 100]], []))->toBeFalse()
            ->and($engine->matches(['amount' => ['<=', 100]], []))->toBeFalse()
            ->and($engine->matches(['amount' => ['between', [50, 200]]], []))->toBeFalse()
            ->and($engine->matches(['amount' => ['between', [200, 50]]], []))->toBeFalse()
            ->and($engine->matches(['tags' => ['in', ['a', 'b']]], []))->toBeFalse()
            ->and($engine->matches(['tags' => ['not_in', ['a', 'b']]], []))->toBeFalse();
    });

    test('ConditionEngine null and not_null operators work correctly', function (): void {
        $engine = new ConditionEngine;

        expect($engine->matches(['field' => ['null']], []))->toBeTrue()
            ->and($engine->matches(['field' => ['null']], ['field' => 'value']))->toBeFalse()
            ->and($engine->matches(['field' => ['not_null']], ['field' => 'value']))->toBeTrue()
            ->and($engine->matches(['field' => ['not_null']], []))->toBeFalse();
    });

    test('WildcardMatcher exact match does not match different event', function (): void {
        expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
    });

    test('WildcardMatcher single segment wildcard matches single segment', function (): void {
        expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.*', 'order.shipped'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
    });

    test('WildcardMatcher cross-segment wildcard matches multiple segments', function (): void {
        expect(WildcardMatcher::matches('order.**', 'order.placed'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue()
            ->and(WildcardMatcher::matches('order.**', 'order.placed.extra.detail'))->toBeTrue();
    });

    test('WildcardMatcher findMatchingPatterns returns only matching patterns', function (): void {
        $patterns = ['order.*', 'user.*', 'order.placed', '*.created'];
        $result = WildcardMatcher::findMatchingPatterns($patterns, 'order.placed');

        expect($result)->toBe(['order.*', 'order.placed']);
    });

    test('WildcardMatcher extractWildcards returns empty for cross-segment pattern', function (): void {
        expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
    });

    test('WildcardMatcher extractWildcards extracts single-segment values', function (): void {
        $result = WildcardMatcher::extractWildcards('user.*.created', 'user.admin.created');

        expect($result)->toBe(['admin']);
    });

    test('EventManager invalidateTriggerCache can be called multiple times', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // Should not throw
        $manager->invalidateTriggerCache();
        $manager->invalidateTriggerCache();
        $manager->invalidateTriggerCache();

        expect(true)->toBeTrue();
    });

    test('EventManager enable returns false for non-existent trigger', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect($manager->enable('non-existent-id'))->toBeFalse();
    });

    test('EventManager disable returns false for non-existent trigger', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect($manager->disable('non-existent-id'))->toBeFalse();
    });

    test('EventLog status constants match expected values', function (): void {
        expect(EventLog::STATUS_PENDING)->toBe('pending')
            ->and(EventLog::STATUS_DISPATCHED)->toBe('dispatched')
            ->and(EventLog::STATUS_COMPLETED)->toBe('completed')
            ->and(EventLog::STATUS_FAILED)->toBe('failed');
    });

    test('EventLog statuses array contains all status constants', function (): void {
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED)
            ->and(EventLog::$statuses)->toHaveCount(4);
    });

    test('Trigger scopes return correct builder instances', function (): void {
        $enabledQuery = Trigger::enabled();
        $asyncQuery = Trigger::async();
        $priorityQuery = Trigger::orderByPriority();

        // All should be Builder instances
        expect($enabledQuery)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class)
            ->and($asyncQuery)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class)
            ->and($priorityQuery)->toBeInstanceOf(\Illuminate\Database\Eloquent\Builder::class);
    });

    test('EventLog factory creates valid model with default state', function (): void {
        $log = EventLog::factory()->create();

        expect($log)->toBeInstanceOf(EventLog::class)
            ->and($log->id)->toBeString()->not->toBeEmpty()
            ->and($log->status)->toBeIn(EventLog::$statuses);
    });

    test('Trigger factory creates valid model with default state', function (): void {
        $trigger = Trigger::factory()->create();

        expect($trigger)->toBeInstanceOf(Trigger::class)
            ->and($trigger->id)->toBeString()->not->toBeEmpty()
            ->and($trigger->event)->toBeString()->not->toBeEmpty()
            ->and($trigger->action)->toBeString()->not->toBeEmpty();
    });

    test('Subscription factory creates valid model with default state', function (): void {
        $subscription = \ZeroBoiler\Events\Models\Subscription::factory()->create();

        expect($subscription)->toBeInstanceOf(\ZeroBoiler\Events\Models\Subscription::class)
            ->and($subscription->id)->toBeString()->not->toBeEmpty()
            ->and($subscription->url)->toBeString()->not->toBeEmpty()
            ->and($subscription->secret)->toBeString()->not->toBeEmpty();
    });
});
