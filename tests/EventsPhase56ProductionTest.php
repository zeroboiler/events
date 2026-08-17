<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\WildcardMatcher;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Actions\WebhookAction;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\Tests\TestActions;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;

/**
 * Comprehensive edge-case and integration tests for production hardening.
 * Phase 56: Additional coverage for boundary conditions, type safety,
 * config edge-cases, and concurrency-safe patterns.
 */
describe('Phase 56 — Production Hardening Edge Cases', function (): void {
    // ─── ConditionEngine: deep nesting and type coercion ───────────────────
    describe('ConditionEngine deep nesting', function (): void {
        test('matches nested field with array value', function (): void {
            $engine = app(ConditionEngine::class);
            $conditions = ['user.settings.notifications.email' => true];
            $payload = [
                'user' => [
                    'settings' => [
                        'notifications' => [
                            'email' => true,
                        ],
                    ],
                ],
            ];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        test('returns false when nested path partially matches', function (): void {
            $engine = app(ConditionEngine::class);
            $conditions = ['user.email.verified' => true];
            $payload = ['user' => ['email' => 'test@example.com']];

            expect($engine->matches($conditions, $payload))->toBeFalse();
        });

        test('matches with zero as a valid comparison value', function (): void {
            $engine = app(ConditionEngine::class);
            $conditions = ['count' => ['=', 0]];
            $payload = ['count' => 0];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        test('not_in operator with single-element array', function (): void {
            $engine = app(ConditionEngine::class);
            $conditions = ['status' => ['not_in', ['active']]];
            $payload = ['status' => 'pending'];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        test('between with equal min and max values', function (): void {
            $engine = app(ConditionEngine::class);
            $conditions = ['score' => ['between', [50, 50]]];
            $payload = ['score' => 50];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });

        test('matches with float comparison (>=)', function (): void {
            $engine = app(ConditionEngine::class);
            $conditions = ['amount' => ['>=', 99.99]];
            $payload = ['amount' => 100.0];

            expect($engine->matches($conditions, $payload))->toBeTrue();
        });
    });

    // ─── WildcardMatcher: edge cases ──────────────────────────────────────
    describe('WildcardMatcher advanced patterns', function (): void {
        test('pattern with trailing dot does not match single segment', function (): void {
            expect(WildcardMatcher::matches('order.', 'order'))->toBeFalse();
        });

        test('pattern with leading dot does not match', function (): void {
            expect(WildcardMatcher::matches('.order', 'order'))->toBeFalse();
        });

        test('empty event with empty pattern', function (): void {
            expect(WildcardMatcher::matches('', ''))->toBeFalse();
        });

        test('pattern with special regex chars is properly escaped', function (): void {
            expect(WildcardMatcher::matches('user.login', 'user.login'))->toBeTrue();
            expect(WildcardMatcher::matches('user.login', 'userXlogin'))->toBeFalse();
        });

        test('findMatchingPatterns returns empty for no matches', function (): void {
            $result = WildcardMatcher::findMatchingPatterns(
                ['order.placed', 'user.created'],
                'payment.received',
            );

            expect($result)->toBe([]);
        });

        test('extractWildcards returns empty for catch-all pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('**', 'order.placed.extra'))->toBe([]);
        });
    });

    // ─── TriggerBuilder: edge cases ──────────────────────────────────────
    describe('TriggerBuilder validation edge cases', function (): void {
        test('save throws for event name consisting only of spaces', function (): void {
            $builder = app(TriggerBuilder::class);
            $builder->on('   ')->action('ZeroBoiler\Events\Tests\Actions\Foo');

            expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class);
        });

        test('save auto-generates name from event with multiple segments', function (): void {
            $builder = app(TriggerBuilder::class);
            $trigger = $builder->on('order.item.created')->action('ZeroBoiler\Events\Tests\Actions\Foo')->save();

            expect($trigger->name)->toBe('order.item.created Trigger');
        });
    });

    // ─── SubscriptionBuilder: URL validation ──────────────────────────────
    describe('SubscriptionBuilder URL validation', function (): void {
        test('rejects ftp:// URL scheme', function (): void {
            $builder = app(SubscriptionBuilder::class);
            $builder->on('test.event')->to('ftp://evil.com/hooks');

            expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        test('rejects file:// URL scheme', function (): void {
            $builder = app(SubscriptionBuilder::class);
            $builder->on('test.event')->to('file:///etc/passwd');

            expect(fn () => $builder->save())->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');
        });

        test('rejects javascript:// URL scheme', function (): void {
            $builder = app(SubscriptionBuilder::class);
            $builder->on('test.event')->to('javascript:alert(1)');

            // filter_var may or may not accept this, but if it does,
            // the scheme check should catch it
            try {
                $builder->save();
                // If it passed filter_var, scheme check must catch it
                expect(true)->toBeFalse();
            } catch (\InvalidArgumentException $e) {
                expect(true)->toBeTrue();
            }
        });
    });

    // ─── DomainEvent: serialization edge cases ───────────────────────────
    describe('DomainEvent edge cases', function (): void {
        test('fromArray with numeric-string eventType', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => '123',
                'payload' => [],
            ]);

            expect($event->eventType)->toBe('123');
        });

        test('fromArray preserves payload with mixed types', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test',
                'payload' => [
                    'int' => 42,
                    'float' => 3.14,
                    'bool' => true,
                    'null' => null,
                    'nested' => ['key' => 'value'],
                ],
            ]);

            expect($event->payload['int'])->toBe(42);
            expect($event->payload['float'])->toBe(3.14);
            expect($event->payload['bool'])->toBeTrue();
            expect($event->payload['null'])->toBeNull();
            expect($event->payload['nested']['key'])->toBe('value');
        });

        test('toArray produces consistent round-trip data', function (): void {
            $original = DomainEvent::occur('user.registered', ['email' => 'test@example.com']);
            $data = $original->toArray();
            $restored = DomainEvent::fromArray($data);

            expect($restored->eventId->toString())->toBe($original->eventId->toString());
            expect($restored->eventType)->toBe($original->eventType);
            expect($restored->payload)->toBe($original->payload);
        });
    });

    // ─── EventManager: fireModel edge cases ───────────────────────────────
    describe('EventManager fireModel edge cases', function (): void {
        test('fireModel throws for empty model class', function (): void {
            $em = app(\ZeroBoiler\Events\EventManager::class);

            expect(fn () => $em->fireModel('', 'created', new \stdClass()))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
        });

        test('fireModel throws for empty action', function (): void {
            $em = app(\ZeroBoiler\Events\EventManager::class);

            expect(fn () => $em->fireModel('App\\Models\\Order', '', new \stdClass()))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
        });
    });

    // ─── EventLog: status transitions ─────────────────────────────────────
    describe('EventLog status transitions', function (): void {
        test('markAsCompleted sets status and duration', function (): void {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->forTrigger($trigger->id)->pending()->create();

            $log->markAsCompleted(150);

            expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->duration_ms)->toBe(150);
        });

        test('markAsFailed sets status and error message', function (): void {
            $trigger = Trigger::factory()->create();
            $log = EventLog::factory()->forTrigger($trigger->id)->dispatched()->create();

            $log->markAsFailed('Connection timeout');

            expect($log->status)->toBe(EventLog::STATUS_FAILED);
            expect($log->error)->toBe('Connection timeout');
        });
    });

    // ─── Subscription: signPayload edge cases ───────────────────────────
    describe('Subscription signPayload', function (): void {
        test('signPayload produces deterministic output', function (): void {
            $sub = Subscription::factory()->withSecret('test_secret_key')->create();
            $signature1 = $sub->signPayload('{"event":"test"}');
            $signature2 = $sub->signPayload('{"event":"test"}');

            expect($signature1)->toBe($signature2);
            expect($signature1)->not->toBe('');
        });

        test('signPayload returns empty for null secret', function (): void {
            $sub = Subscription::factory()->withoutSecret()->create();

            expect($sub->signPayload('{"event":"test"}'))->toBe('');
        });

        test('signPayload returns empty for empty string secret', function (): void {
            $sub = Subscription::factory()->create(['secret' => '']);

            expect($sub->signPayload('{"event":"test"}'))->toBe('');
        });
    });

    // ─── DispatchTriggerJob: config-driven values ────────────────────────
    describe('DispatchTriggerJob config behavior', function (): void {
        test('reads tries from config', function (): void {
            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->tries)->toBeGreaterThanOrEqual(1);
        });

        test('reads queue name from config', function (): void {
            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->queue)->toBe('default');
        });

        test('backoff array is not empty', function (): void {
            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->backoff)->not->toBeEmpty();
        });
    });

    // ─── Cache invalidation: full lifecycle ────────────────────────────
    describe('Cache invalidation lifecycle', function (): void {
        test('invalidateTriggerCache clears wildcard cache', function (): void {
            $key = 'zeroboiler:events:enabled_wildcard_triggers';
            Cache::put($key, 'stale_data', 300);

            app(\ZeroBoiler\Events\EventManager::class)->invalidateTriggerCache();

            expect(Cache::get($key))->toBeNull();
        });
    });

    // ─── ServiceProvider: binding integrity ────────────────────────────
    describe('ServiceProvider binding integrity', function (): void {
        test('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $engine = app(ConditionEngineContract::class);

            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        test('EventManager is singleton', function (): void {
            $first = app(\ZeroBoiler\Events\EventManager::class);
            $second = app(\ZeroBoiler\Events\EventManager::class);

            expect($first)->toBe($second);
        });

        test('TriggerBuilder is transient (new instance each time)', function (): void {
            $first = app(TriggerBuilder::class);
            $second = app(TriggerBuilder::class);

            expect($first)->not->toBe($second);
        });

        test('SubscriptionBuilder is transient', function (): void {
            $first = app(SubscriptionBuilder::class);
            $second = app(SubscriptionBuilder::class);

            expect($first)->not->toBe($second);
        });

        test('ActionResolver is singleton', function (): void {
            $first = app(ActionResolver::class);
            $second = app(ActionResolver::class);

            expect($first)->toBe($second);
        });
    });

    // ─── All src files have strict types ────────────────────────────────
    describe('Source code strict types compliance', function (): void {
        test('all src files declare strict_types=1', function (): void {
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
                if ($contents === false) {
                    continue;
                }
                if (! str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = $file->getFilename();
                }
            }

            expect($violations)->toBeEmpty();
        });
    });
});
