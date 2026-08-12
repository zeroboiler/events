<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;

describe('Phase 92 — Edge Cases and Validation', function () {
    describe('EventManager::fire() Validation', function () {
        test('fire() rejects empty string event name', function () {
            app(EventManager::class)->fire('');
        })->throws(InvalidArgumentException::class, 'Event name cannot be empty');

        test('fire() rejects zero-string event name', function () {
            app(EventManager::class)->fire('0');
        })->throws(InvalidArgumentException::class, 'Event name cannot be empty');

        test('fire() accepts single-character event name', function () {
            $manager = app(EventManager::class);
            $manager->on('x')->name('Single Char Trigger')->action(NoopValidationAction::class)->save();

            $manager->fire('x');

            expect(EventLog::where('event', 'x')->count())->toBe(1);
        });

        test('fire() with no matching triggers creates no logs', function () {
            $manager = app(EventManager::class);
            $manager->fire('nonexistent.event.that.has.no.triggers');

            expect(EventLog::count())->toBe(0);
        });
    });

    describe('EventManager::fireModel() Validation', function () {
        test('fireModel() rejects empty model class', function () {
            app(EventManager::class)->fireModel('', 'created', new \stdClass);
        })->throws(InvalidArgumentException::class, 'Model class name cannot be empty');

        test('fireModel() rejects empty action', function () {
            app(EventManager::class)->fireModel('App\\Models\\Order', '', new \stdClass);
        })->throws(InvalidArgumentException::class, 'Model action cannot be empty');

        test('fireModel() works with object having only toArray()', function () {
            $manager = app(EventManager::class);
            $manager->on('TestModel.updated')
                ->name('toArray Model Trigger')
                ->action(NoopValidationAction::class)
                ->save();

            $model = new class {
                public function toArray(): array
                {
                    return ['id' => 42, 'status' => 'updated'];
                }
            };

            $manager->fireModel('TestModel', 'updated', $model);

            $logs = EventLog::where('event', 'TestModel.updated')->get();
            expect($logs->count())->toBe(1);
        });
    });

    describe('TriggerBuilder Validation', function () {
        test('save() throws when event name is empty', function () {
            $builder = app(TriggerBuilder::class);
            $builder->action(NoopValidationAction::class)->save();
        })->throws(InvalidArgumentException::class, 'Event name is required');

        test('save() throws when no action is provided', function () {
            $builder = app(TriggerBuilder::class);
            $builder->on('test.no.action')->name('No Action Trigger')->save();
        })->throws(InvalidArgumentException::class, 'At least one action is required');

        test('actions() validates each element is a non-empty string', function () {
            $manager = app(EventManager::class);
            $manager->on('test.bad.actions')
                ->actions(['Valid\Class', '', 'Another\Class'])
                ->save();
        })->throws(InvalidArgumentException::class, 'Each action class must be a non-empty string');
    });

    describe('SubscriptionBuilder Validation', function () {
        test('save() throws when URL scheme is ftp', function () {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', 'ftp://malicious-server.com/hooks')
                ->withSecret('whsec_test')
                ->save();
        })->throws(InvalidArgumentException::class, 'HTTP or HTTPS');

        test('save() throws when URL scheme is file', function () {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', 'file:///etc/passwd')
                ->save();
        })->throws(InvalidArgumentException::class, 'HTTP or HTTPS');

        test('save() throws when URL is empty string', function () {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', '')->save();
        })->throws(InvalidArgumentException::class, 'Webhook URL is required');

        test('save() throws when event is empty string', function () {
            $manager = app(EventManager::class);
            $manager->subscribe('', 'https://example.com/hooks')->save();
        })->throws(InvalidArgumentException::class, 'Event name is required');

        test('save() throws when URL is not valid', function () {
            $manager = app(EventManager::class);
            $manager->subscribe('test.event', 'not-a-valid-url')
                ->save();
        })->throws(InvalidArgumentException::class, 'valid URL');
    });

    describe('Wildcard Cache TTL Edge Cases', function () {
        test('TTL=0 disables caching (each fire queries DB)', function () {
            config(['events.wildcard_cache_ttl' => 0]);

            $manager = app(EventManager::class);

            $trigger = $manager->on('test.ttl0.*')
                ->name('TTL Zero Trigger')
                ->action(NoopValidationAction::class)
                ->save();

            // Fire and verify it works
            $manager->fire('test.ttl0.event');

            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(1);

            // Verify cache is not storing wildcard triggers
            expect(Cache::get('zeroboiler:events:enabled_wildcard_triggers'))->toBeNull();

            // Reset
            config(['events.wildcard_cache_ttl' => 300]);
        });

        test('negative TTL falls back to default 300', function () {
            config(['events.wildcard_cache_ttl' => -5]);

            // The getTriggerCacheTtl() should return default for negative values
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.negttl.*')
                ->name('Negative TTL Trigger')
                ->action(NoopValidationAction::class)
                ->save();

            $manager->fire('test.negttl.event');
            $logs = EventLog::where('trigger_id', $trigger->id)->get();
            expect($logs->count())->toBe(1);

            // Cache should be populated (negative TTL -> fallback to 300)
            expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

            config(['events.wildcard_cache_ttl' => 300]);
        });
    });

    describe('ConditionEngine Boundary Tests', function () {
        test('between operator normalizes inverted range', function () {
            $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

            // [100, 50] should be normalized to [50, 100]
            $result = $engine->matches(['value' => ['between', [100, 50]]], ['value' => 75]);
            expect($result)->toBeTrue();
        });

        test('between rejects non-numeric actual', function () {
            $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

            $result = $engine->matches(['value' => ['between', [1, 100]]], ['value' => 'not-a-number']);
            expect($result)->toBeFalse();
        });

        test('between rejects non-array value', function () {
            $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

            $result = $engine->matches(['value' => ['between', 'not-array']], ['value' => 50]);
            expect($result)->toBeFalse();
        });

        test('regex matches operator rejects patterns over 500 chars', function () {
            $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

            $longPattern = '/^' . str_repeat('a', 500) . '$/';
            $result = $engine->matches(
                ['code' => ['matches', $longPattern]],
                ['code' => str_repeat('a', 500)],
            );
            expect($result)->toBeFalse();
        });

        test('empty conditions array matches any payload', function () {
            $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

            $result = $engine->matches([], ['anything' => 'goes']);
            expect($result)->toBeTrue();
        });

        test('empty operator array ([]) returns false', function () {
            $engine = app(\ZeroBoiler\Events\ConditionEngine::class);

            $result = $engine->matches(['field' => []], ['field' => 'value']);
            expect($result)->toBeFalse();
        });
    });

    describe('EventLog Status Transitions', function () {
        test('markAsCompleted sets correct status and duration', function () {
            $log = EventLog::factory()->pending()->create();
            $log->markAsCompleted(123);

            $log->refresh();
            expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->duration_ms)->toBe(123);
        });

        test('markAsFailed sets correct status and error message', function () {
            $log = EventLog::factory()->pending()->create();
            $log->markAsFailed('Something went wrong');

            $log->refresh();
            expect($log->status)->toBe(EventLog::STATUS_FAILED);
            expect($log->error)->toBe('Something went wrong');
        });
    });

    describe('Trigger CRUD Operations', function () {
        test('getTrigger returns null for non-existent ID', function () {
            $manager = app(EventManager::class);
            $trigger = $manager->getTrigger('00000000-0000-0000-0000-000000000000');
            expect($trigger)->toBeNull();
        });

        test('deleteTrigger returns false for non-existent ID', function () {
            $manager = app(EventManager::class);
            $result = $manager->deleteTrigger('00000000-0000-0000-0000-000000000000');
            expect($result)->toBeFalse();
        });

        test('enable/disable on non-existent trigger returns false', function () {
            $manager = app(EventManager::class);
            $id = '00000000-0000-0000-0000-000000000000';

            expect($manager->enable($id))->toBeFalse();
            expect($manager->disable($id))->toBeFalse();
        });

        test('deleteTrigger removes trigger and invalidates cache', function () {
            $manager = app(EventManager::class);

            $trigger = $manager->on('test.delete.me')
                ->name('Delete Me Trigger')
                ->action(NoopValidationAction::class)
                ->save();

            $id = $trigger->id;
            expect($manager->deleteTrigger($id))->toBeTrue();
            expect($manager->getTrigger($id))->toBeNull();
            expect($manager->deleteTrigger($id))->toBeFalse();
        });
    });
});

/**
 * Noop action for validation tests.
 */
class NoopValidationAction implements \ZeroBoiler\Events\Contracts\Triggerable
{
    #[\Override]
    public function handle(array $payload): void
    {
        // No-op
    }
}
