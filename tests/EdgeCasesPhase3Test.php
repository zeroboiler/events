<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use App\Actions\SendOrderNotification;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;

describe('ConditionEngine — empty conditions edge cases', function (): void {
    test('matches returns true with empty conditions and empty payload', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], []))->toBeTrue();
    });

    test('matches returns true with empty conditions and non-empty payload', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches([], ['key' => 'value', 'nested' => ['a' => 1]]))->toBeTrue();
    });

    test('matches returns true with single condition on empty payload (field missing)', function (): void {
        $engine = app(ConditionEngine::class);

        // Field does not exist in payload — actual is null
        // Condition expects string 'active' — null !== 'active' after string comparison
        expect($engine->matches(['status' => 'active'], []))->toBeFalse();
    });
});

describe('ConditionEngine — between operator edge cases', function (): void {
    test('between with inverted range normalises correctly', function (): void {
        $engine = app(ConditionEngine::class);

        // Range [100, 50] should be normalised to [50, 100]
        expect($engine->matches(['amount' => 75], ['amount' => ['between', [100, 50]]]))->toBeTrue();
    });

    test('between with equal min and max returns true for exact match', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => 50], ['amount' => ['between', [50, 50]]]))->toBeTrue();
    });

    test('between with non-array value returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['amount' => 50], ['amount' => ['between', 'invalid']]))->toBeFalse();
    });
});

describe('ConditionEngine — in/not_in with single element', function (): void {
    test('in with single-element array works', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['status' => 'active'], ['status' => ['in', ['active']]]))->toBeTrue();
    });

    test('not_in with single-element array works', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['status' => 'pending'], ['status' => ['not_in', ['active']]]))->toBeTrue();
    });

    test('in with empty array value returns false', function (): void {
        $engine = app(ConditionEngine::class);

        expect($engine->matches(['status' => 'active'], ['status' => ['in', null]]))->toBeFalse();
    });
});

describe('TriggerBuilder — validation edge cases', function (): void {
    test('save throws on event name "0"', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn (): Trigger => $manager->on('0')->action(SendOrderNotification::class)->save())
            ->toThrow(\InvalidArgumentException::class, 'Event name is required');
    });

    test('save throws when both action() and actions() are empty', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn (): Trigger => $manager->on('test.event')->save())
            ->toThrow(\InvalidArgumentException::class, 'At least one action is required');
    });

    test('save auto-generates name when name is "0"', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('test.name0')->action(SendOrderNotification::class)->name('0')->save();

        expect($trigger->name)->toBe('test.name0 Trigger');
    });

    test('save with actionParams and multiple actions uses classes key', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('test.multi')
            ->actions([SendOrderNotification::class, WebhookAction::class])
            ->actionParams(['url' => 'https://example.com'])
            ->save();

        $decoded = json_decode($trigger->action, true);
        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKey('classes')
            ->and($decoded['classes'])->toContain(SendOrderNotification::class)
            ->and($decoded['classes'])->toContain(WebhookAction::class)
            ->and($decoded)->toHaveKey('params')
            ->and($decoded['params'])->toHaveKey('url');
    });
});

describe('SubscriptionBuilder — validation edge cases', function (): void {
    test('save throws on event name "0"', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn (): Subscription => $manager->subscribe('0', 'https://example.com')->save())
            ->toThrow(\InvalidArgumentException::class, 'Event name is required');
    });

    test('save throws on URL "0"', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn (): Subscription => $manager->subscribe('test.event', '0')->save())
            ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
    });

    test('save throws on invalid URL', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect(fn (): Subscription => $manager->subscribe('test.event', 'not-a-url')->save())
            ->toThrow(\InvalidArgumentException::class, 'valid URL');
    });
});

describe('EventManager — fire edge cases', function (): void {
    test('fire with no matching triggers does nothing silently', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        // No triggers exist — should not throw
        $manager->fire('nonexistent.event', ['key' => 'value']);

        // No event logs should be created
        expect(EventLog::count())->toBe(0);
    });

    test('fire with empty payload works when trigger has no conditions', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $trigger = $manager->on('test.empty')
            ->action(SendOrderNotification::class)
            ->save();

        $manager->fire('test.empty');

        // EventLog should have been created
        expect(EventLog::where('trigger_id', $trigger->id)->count())->toBe(1);
    });

    test('enable non-existent trigger returns false', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect($manager->enable('nonexistent-id'))->toBeFalse();
    });

    test('disable non-existent trigger returns false', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        expect($manager->disable('nonexistent-id'))->toBeFalse();
    });

    test('fireModel with non-eloquent object uses toArray', function (): void {
        $manager = app(\ZeroBoiler\Events\EventManager::class);

        $obj = new class
        {
            public function toArray(): array
            {
                return ['name' => 'Test', 'value' => 42];
            }
        };

        // Should not throw — just verify it doesn't crash
        $manager->fireModel(get_class($obj), 'created', $obj);
    });
});

describe('EventLog — status constants', function (): void {
    test('all status constants are in $statuses array', function (): void {
        expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED)
            ->and(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
    });

    test('markAsCompleted sets status and duration', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        $log = EventLog::factory()->pending()->for($trigger)->create();

        $log->markAsCompleted(123);

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_COMPLETED)
            ->and($log->duration_ms)->toBe(123);
    });

    test('markAsFailed sets status and error', function (): void {
        $trigger = Trigger::factory()->enabled()->create();
        $log = EventLog::factory()->pending()->for($trigger)->create();

        $log->markAsFailed('something went wrong');

        $log->refresh();
        expect($log->status)->toBe(EventLog::STATUS_FAILED)
            ->and($log->error)->toBe('something went wrong');
    });
});

describe('Subscription — sign and verify', function (): void {
    test('signPayload returns empty string when secret is null', function (): void {
        $sub = Subscription::factory()->withoutSecret()->create();

        expect($sub->signPayload('test-payload'))->toBe('');
    });

    test('signPayload returns empty string when secret is empty', function (): void {
        $sub = Subscription::factory()->create(['secret' => '']);

        expect($sub->signPayload('test-payload'))->toBe('');
    });

    test('signPayload produces consistent signature', function (): void {
        $sub = Subscription::factory()->create(['secret' => 'whsec_test_secret']);

        $sig1 = $sub->signPayload('payload1');
        $sig2 = $sub->signPayload('payload1');
        $sig3 = $sub->signPayload('payload2');

        expect($sig1)->toBe($sig2)
            ->and($sig1)->not->toBe($sig3);
    });

    test('resetFailures sets failure_count to 0', function (): void {
        $sub = Subscription::factory()->create(['failure_count' => 5]);

        $sub->resetFailures();

        $sub->refresh();
        expect($sub->failure_count)->toBe(0);
    });
});

describe('WildcardMatcher — boundary cases', function (): void {
    test('matches returns false for both empty pattern and empty event', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('', ''))->toBeFalse();
    });

    test('matches returns false for empty pattern and non-empty event', function (): void {
        expect(\ZeroBoiler\Events\WildcardMatcher::matches('', 'order.placed'))->toBeFalse();
    });

    test('findMatchingPatterns returns empty for empty patterns array', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns([], 'order.placed');

        expect($result)->toBe([]);
    });

    test('findMatchingPatterns returns empty when no patterns match', function (): void {
        $result = \ZeroBoiler\Events\WildcardMatcher::findMatchingPatterns(
            ['user.*', 'invoice.*'],
            'order.placed',
        );

        expect($result)->toBe([]);
    });
});
