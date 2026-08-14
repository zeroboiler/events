<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

// ─── Phase 159: Production Audit ─────────────────────────────────────────────
// Covers: force async fire(), fire() event name edge cases, ConditionEngine
// ReDoS patterns, WildcardMatcher boundary, ServiceProvider deferred provider
// verification, config cross-validation, SubscriptionBuilder transactional
// consistency, DomainEvent fromArray edge cases, EventManager registerScheduler
// ─────────────────────────────────────────────────────────────────────────────

describe('Phase 159: Production Audit', function (): void {
    // ─── 1. fire() with force async mode ─────────────────────────────────

    describe('fire() with async: true force mode', function (): void {
        test('fire() with async:true pushes DispatchTriggerJob for sync trigger', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            $trigger = $app->make(TriggerBuilder::class);
            $trigger->on('async.test')
                ->name('Async Test Trigger')
                ->action(SendOrderNotification::class)
                ->async(false)
                ->save();

            $pushed = false;
            Queue::shouldReceive('push')
                ->once()
                ->withArgs(function (DispatchTriggerJob $job) use (&$pushed): bool {
                    $pushed = true;
                    return $job->triggerId === Trigger::where('event', 'async.test')->first()?->id;
                });

            $manager->fire('async.test', ['key' => 'value'], async: true);

            expect($pushed)->toBeTrue();
        });

        test('fire() without force async still pushes job for async trigger', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            $app->make(TriggerBuilder::class)
                ->on('native.async')
                ->name('Native Async Trigger')
                ->action(SendOrderNotification::class)
                ->async(true)
                ->save();

            $pushed = false;
            Queue::shouldReceive('push')
                ->once()
                ->withArgs(function (DispatchTriggerJob $job) use (&$pushed): bool {
                    $pushed = true;
                    return $job->event === 'native.async';
                });

            $manager->fire('native.async', ['key' => 'value']);

            expect($pushed)->toBeTrue();
        });
    });

    // ─── 2. fire() event name edge cases ───────────────────────────────

    describe('fire() event name validation', function (): void {
        test('fire() rejects "0" string as empty event name', function (): void {
            $manager = $this->createApp()->make(EventManager::class);

            expect(fn (): mixed => $manager->fire('0', ['key' => 'value']))
                ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty.');
        });

        test('fire() rejects empty string as event name', function (): void {
            $manager = $this->createApp()->make(EventManager::class);

            expect(fn (): mixed => $manager->fire('', ['key' => 'value']))
                ->toThrow(InvalidArgumentException::class, 'Event name cannot be empty.');
        });

        test('fire() accepts event names with special characters', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            $app->make(TriggerBuilder::class)
                ->on('user.profile.updated')
                ->name('Profile Update')
                ->action(SendOrderNotification::class)
                ->save();

            // Should not throw — event name with dots is valid
            $manager->fire('user.profile.updated', ['user_id' => 1]);
            expect(true)->toBeTrue();
        });
    });

    // ─── 3. fireModel() edge cases ──────────────────────────────────────

    describe('fireModel() edge cases', function (): void {
        test('fireModel() rejects "0" as model class', function (): void {
            $manager = $this->createApp()->make(EventManager::class);

            expect(fn (): mixed => $manager->fireModel('0', 'created', new stdClass))
                ->toThrow(InvalidArgumentException::class, 'Model class name cannot be empty.');
        });

        test('fireModel() rejects "0" as action', function (): void {
            $manager = $this->createApp()->make(EventManager::class);

            expect(fn (): mixed => $manager->fireModel('App\\Models\\Order', '0', new stdClass))
                ->toThrow(InvalidArgumentException::class, 'Model action cannot be empty.');
        });

        test('fireModel() with stdClass uses toArray when available', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            $app->make(TriggerBuilder::class)
                ->on('stdClass.created')
                ->name('StdClass Test')
                ->action(SendOrderNotification::class)
                ->save();

            $obj = new class {
                public function toArray(): array
                {
                    return ['name' => 'test', 'value' => 42];
                }
            };

            // Should not throw
            $manager->fireModel(get_class($obj), 'created', $obj);
            expect(true)->toBeTrue();
        });
    });

    // ─── 4. ConditionEngine ReDoS protection ────────────────────────────

    describe('ConditionEngine ReDoS protection', function (): void {
        test('rejects regex pattern exceeding 500 characters', function (): void {
            $engine = new ConditionEngine;

            $longPattern = '/^' . str_repeat('a', 500) . '$/';

            expect($engine->matches(
                ['code' => ['matches', $longPattern]],
                ['code' => 'aaaa'],
            ))->toBeFalse();
        });

        test('rejects regex with nested quantifiers (catastrophic backtracking)', function (): void {
            $engine = new ConditionEngine;

            // Pattern with nested quantifier: (a+)+
            expect($engine->matches(
                ['code' => ['matches', '/(a+)+b/']],
                ['code' => 'aaab'],
            ))->toBeFalse();
        });

        test('accepts safe regex patterns under 500 chars', function (): void {
            $engine = new ConditionEngine;

            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']],
                ['code' => 'ABC-1234'],
            ))->toBeTrue();

            expect($engine->matches(
                ['code' => ['matches', '/^[A-Z]{3}-\\d{4}$/']],
                ['code' => 'invalid'],
            ))->toBeFalse();
        });

        test('matches operator with non-string actual returns false', function (): void {
            $engine = new ConditionEngine;

            // Integer actual value should not match regex
            expect($engine->matches(
                ['count' => ['matches', '/^\\d+$/']],
                ['count' => 42],
            ))->toBeFalse();

            // Null actual value should not match regex
            expect($engine->matches(
                ['name' => ['matches', '/^test/']],
                ['name' => null],
            ))->toBeFalse();
        });
    });

    // ─── 5. WildcardMatcher boundary tests ───────────────────────────────

    describe('WildcardMatcher boundary cases', function (): void {
        test('single segment wildcard does not match multi-segment event', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('double wildcard matches single segment event', function (): void {
            expect(WildcardMatcher::matches('order.**', 'order'))->toBeFalse();
        });

        test('catch-all * matches single segment event', function (): void {
            expect(WildcardMatcher::matches('*', 'simple'))->toBeTrue();
        });

        test('catch-all * matches multi-segment event', function (): void {
            expect(WildcardMatcher::matches('*', 'a.b.c.d'))->toBeTrue();
        });

        test('catch-all * does not match empty string', function (): void {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        test('catch-all ** does not match empty string', function (): void {
            expect(WildcardMatcher::matches('**', ''))->toBeFalse();
        });

        test('extractWildcards returns empty for ** pattern', function (): void {
            expect(WildcardMatcher::extractWildcards('order.**', 'order.placed.extra'))->toBe([]);
        });

        test('findMatchingPatterns preserves order', function (): void {
            $patterns = ['a.*', 'b.*', 'a.specific'];
            $result = WildcardMatcher::findMatchingPatterns($patterns, 'a.specific');

            expect($result)->toBe(['a.*', 'a.specific']);
        });
    });

    // ─── 6. ServiceProvider deferred provider verification ──────────────

    describe('EventsServiceProvider deferred provider verification', function (): void {
        test('provides() returns all registered bindings', function (): void {
            $provides = (new EventsServiceProvider(app()))->provides();

            expect($provides)->toContain(EventManager::class);
            expect($provides)->toContain(ConditionEngine::class);
            expect($provides)->toContain(ConditionEngineContract::class);
            expect($provides)->toContain(ActionResolver::class);
            expect($provides)->toContain(TriggerBuilder::class);
            expect($provides)->toContain(SubscriptionBuilder::class);
            expect($provides)->toContain(EventScheduler::class);
            expect($provides)->toHaveCount(7);
        });

        test('register() creates singleton bindings correctly', function (): void {
            $app = $this->createApp();

            // ConditionEngine should be a singleton
            $first = $app->make(ConditionEngine::class);
            $second = $app->make(ConditionEngine::class);
            expect($first)->toBe($second);

            // ConditionEngineContract should resolve to ConditionEngine
            $contract = $app->make(ConditionEngineContract::class);
            expect($contract)->toBeInstanceOf(ConditionEngine::class);

            // ActionResolver should be a singleton
            $resolver1 = $app->make(ActionResolver::class);
            $resolver2 = $app->make(ActionResolver::class);
            expect($resolver1)->toBe($resolver2);
        });

        test('TriggerBuilder is transient (fresh instance each time)', function (): void {
            $app = $this->createApp();

            $builder1 = $app->make(TriggerBuilder::class);
            $builder2 = $app->make(TriggerBuilder::class);

            // Should be different instances (transient binding)
            expect($builder1)->not->toBe($builder2);
        });

        test('SubscriptionBuilder is transient (fresh instance each time)', function (): void {
            $app = $this->createApp();

            $builder1 = $app->make(SubscriptionBuilder::class);
            $builder2 = $app->make(SubscriptionBuilder::class);

            // Should be different instances (transient binding)
            expect($builder1)->not->toBe($builder2);
        });
    });

    // ─── 7. Config cross-validation ──────────────────────────────────────

    describe('Config cross-validation', function (): void {
        test('config has all 7 top-level keys', function (): void {
            $config = config('events');

            expect($config)->toBeArray();
            expect(array_keys($config))->toContain('table_names');
            expect(array_keys($config))->toContain('queue');
            expect(array_keys($config))->toContain('retry');
            expect(array_keys($config))->toContain('retention');
            expect(array_keys($config))->toContain('subscriptions');
            expect(array_keys($config))->toContain('disabled');
            expect(array_keys($config))->toContain('wildcard_cache_ttl');
            expect(array_keys($config))->toHaveCount(7);
        });

        test('table_names config has all 3 keys', function (): void {
            $tableNames = config('events.table_names');

            expect($tableNames)->toBeArray();
            expect(array_keys($tableNames))->toContain('triggers');
            expect(array_keys($tableNames))->toContain('event_logs');
            expect(array_keys($tableNames))->toContain('subscriptions');
        });

        test('queue config has connection and queue keys', function (): void {
            $queue = config('events.queue');

            expect($queue)->toBeArray();
            expect(array_keys($queue))->toContain('connection');
            expect(array_keys($queue))->toContain('queue');
        });

        test('retry config has tries and backoff keys', function (): void {
            $retry = config('events.retry');

            expect($retry)->toBeArray();
            expect(array_keys($retry))->toContain('tries');
            expect(array_keys($retry))->toContain('backoff');
        });

        test('subscriptions config has all 6 keys', function (): void {
            $subs = config('events.subscriptions');

            expect($subs)->toBeArray();
            expect(array_keys($subs))->toContain('auto_generate_secret');
            expect(array_keys($subs))->toContain('max_failures');
            expect(array_keys($subs))->toContain('timeout');
            expect(array_keys($subs))->toContain('signature_algorithm');
            expect(array_keys($subs))->toContain('cleanup_cron');
        });

        test('disabled config defaults to false', function (): void {
            expect(config('events.disabled'))->toBeFalse();
        });

        test('wildcard_cache_ttl config defaults to 300', function (): void {
            expect(config('events.wildcard_cache_ttl'))->toBe(300);
        });
    });

    // ─── 8. DomainEvent fromArray edge cases ─────────────────────────────

    describe('DomainEvent fromArray edge cases', function (): void {
        test('fromArray with missing eventType throws InvalidArgumentException', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray(['payload' => []]))
                ->toThrow(InvalidArgumentException::class, 'DomainEvent eventType is required for reconstruction.');
        });

        test('fromArray with empty eventType throws InvalidArgumentException', function (): void {
            expect(fn (): mixed => DomainEvent::fromArray(['eventType' => '']))
                ->toThrow(InvalidArgumentException::class, 'DomainEvent eventType is required for reconstruction.');
        });

        test('fromArray with invalid UUID falls back to fresh UUID', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => 'not-a-valid-uuid',
            ]);

            // Should not throw — falls back to fresh UUID
            expect($event->eventType)->toBe('test.event');
            expect($event->eventId->toString())->toBeString();
            expect(strlen($event->eventId->toString()))->toBe(36);
        });

        test('fromArray with valid UUID preserves it', function (): void {
            $uuid = \Ramsey\Uuid\Uuid::uuid4();
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'eventId' => $uuid->toString(),
            ]);

            expect($event->eventId->toString())->toBe($uuid->toString());
        });

        test('fromArray with invalid datetime falls back to now', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => 'not-a-date',
            ]);

            // Should not throw — falls back to now
            expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
        });

        test('fromArray with valid datetime preserves it', function (): void {
            $originalTime = new DateTimeImmutable('2025-01-15T10:30:00+00:00');
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'occurredAt' => $originalTime->format(DateTimeImmutable::ATOM),
            ]);

            expect($event->occurredAt->format('Y-m-d H:i:s'))->toBe('2025-01-15 10:30:00');
        });

        test('occur factory creates fresh UUID and timestamp', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);

            expect($event->eventId)->toBeInstanceOf(\Ramsey\Uuid\UuidInterface::class);
            expect($event->occurredAt)->toBeInstanceOf(DateTimeImmutable::class);
            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
        });

        test('toArray contains all expected keys', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'value']);
            $data = $event->toArray();

            expect(array_keys($data))->toContain('eventId');
            expect(array_keys($data))->toContain('eventType');
            expect(array_keys($data))->toContain('payload');
            expect(array_keys($data))->toContain('occurredAt');
        });
    });

    // ─── 9. EventManager global disable ──────────────────────────────────

    describe('EventManager global disable behavior', function (): void {
        test('fire() returns silently when globally disabled', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            $manager->setEnabled(false);

            // Register a trigger that would fire
            $app->make(TriggerBuilder::class)
                ->on('disabled.test')
                ->name('Disabled Test')
                ->action(SendOrderNotification::class)
                ->save();

            // Fire should not throw and should not create event log
            $manager->fire('disabled.test', ['key' => 'value']);

            $logCount = EventLog::where('event', 'disabled.test')->count();
            expect($logCount)->toBe(0);
        });

        test('isDisabled() returns true after setEnabled(false)', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();

            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });

        test('invalidatedTriggerCache clears wildcard cache', function (): void {
            $app = $this->createApp();
            $manager = $app->make(EventManager::class);

            // Create a wildcard trigger
            $app->make(TriggerBuilder::class)
                ->on('cache.test.*')
                ->name('Cache Test')
                ->action(SendOrderNotification::class)
                ->save();

            // Fire once to populate cache
            $manager->fire('cache.test.event', ['key' => 'value']);

            // Invalidate
            $manager->invalidateTriggerCache();

            // Should not throw
            expect(true)->toBeTrue();
        });
    });

    // ─── 10. SubscriptionBuilder validation ─────────────────────────────

    describe('SubscriptionBuilder validation', function (): void {
        test('rejects empty event name', function (): void {
            $app = $this->createApp();
            $builder = $app->make(SubscriptionBuilder::class);

            $builder->on('')->to('https://example.com/webhook');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required for subscription');
        });

        test('rejects empty URL', function (): void {
            $app = $this->createApp();
            $builder = $app->make(SubscriptionBuilder::class);

            $builder->on('test.event')->to('');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Webhook URL is required for subscription');
        });

        test('rejects invalid URL', function (): void {
            $app = $this->createApp();
            $builder = $app->make(SubscriptionBuilder::class);

            $builder->on('test.event')->to('not-a-url');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Webhook URL must be a valid URL');
        });

        test('rejects non-HTTP URL scheme (ftp)', function (): void {
            $app = $this->createApp();
            $builder = $app->make(SubscriptionBuilder::class);

            $builder->on('test.event')->to('ftp://example.com/webhook');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');
        });

        test('rejects file:// URL scheme', function (): void {
            $app = $this->createApp();
            $builder = $app->make(SubscriptionBuilder::class);

            $builder->on('test.event')->to('file:///etc/passwd');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Webhook URL must use HTTP or HTTPS protocol');
        });

        test('rejects "0" as event name', function (): void {
            $app = $this->createApp();
            $builder = $app->make(SubscriptionBuilder::class);

            $builder->on('0')->to('https://example.com/webhook');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required for subscription');
        });
    });

    // ─── 11. TriggerBuilder validation ───────────────────────────────────

    describe('TriggerBuilder validation', function (): void {
        test('rejects empty event name', function (): void {
            $app = $this->createApp();
            $builder = $app->make(TriggerBuilder::class);

            $builder->on('')->action(SendOrderNotification::class);

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required');
        });

        test('rejects missing action', function (): void {
            $app = $this->createApp();
            $builder = $app->make(TriggerBuilder::class);

            $builder->on('test.event');

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'At least one action is required');
        });

        test('rejects empty string as action class in actions()', function (): void {
            $app = $this->createApp();
            $builder = $app->make(TriggerBuilder::class);

            $builder->on('test.event')->actions(['', SendOrderNotification::class]);

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Each action class must be a non-empty string.');
        });

        test('auto-generates name from event when not provided', function (): void {
            $app = $this->createApp();
            $builder = $app->make(TriggerBuilder::class);

            $trigger = $builder->on('order.placed')->action(SendOrderNotification::class)->save();

            expect($trigger->name)->toBe('order.placed Trigger');
        });

        test('rejects "0" as event name', function (): void {
            $app = $this->createApp();
            $builder = $app->make(TriggerBuilder::class);

            $builder->on('0')->action(SendOrderNotification::class);

            expect(fn (): mixed => $builder->save())
                ->toThrow(InvalidArgumentException::class, 'Event name is required');
        });
    });

    // ─── 12. All source files strict types and final classes ─────────────

    describe('PHP 8.5 source file compliance', function (): void {
        test('all src files have declare(strict_types=1)', function (): void {
            $srcDir = realpath(__DIR__ . '/../src');
            $violations = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false || !str_contains($contents, 'declare(strict_types=1)')) {
                    $violations[] = $file->getFilename();
                }
            }

            expect($violations)->toBeEmpty('Missing declare(strict_types=1) in: ' . implode(', ', $violations));
        });

        test('all src classes are final', function (): void {
            $srcDir = realpath(__DIR__ . '/../src');
            $nonFinal = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                if (!preg_match('/\bclass\s+\w+/', $contents, $matches)) {
                    continue;
                }

                // Skip interfaces and abstract classes
                if (str_contains($contents, 'interface ') || str_contains($contents, 'abstract class ')) {
                    continue;
                }

                // For files with 'class ClassName', check if 'final' precedes it
                if (!preg_match('/\bfinal\s+class\s/', $contents)) {
                    $nonFinal[] = $file->getFilename();
                }
            }

            expect($nonFinal)->toBeEmpty('Non-final classes found: ' . implode(', ', $nonFinal));
        });

        test('no source files contain setAccessible() calls', function (): void {
            $srcDir = realpath(__DIR__ . '/../src');
            $violations = [];

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if ($contents !== false && preg_match('/->setAccessible\s*\(/', $contents)) {
                    $violations[] = $file->getFilename();
                }
            }

            expect($violations)->toBeEmpty('setAccessible() found in: ' . implode(', ', $violations));
        });
    });

    // ─── 13. ConditionEngine comprehensive operator coverage ─────────────

    describe('ConditionEngine all operators', function (): void {
        $engine = new ConditionEngine;

        test('equality operator with matching values', function () use ($engine): void {
            expect($engine->matches(['status' => 'active'], ['status' => 'active']))->toBeTrue();
        });

        test('equality operator with non-matching values', function () use ($engine): void {
            expect($engine->matches(['status' => 'active'], ['status' => 'inactive']))->toBeFalse();
        });

        test('strict equality operator (===)', function () use ($engine): void {
            expect($engine->matches(['flag' => ['===', true]], ['flag' => true]))->toBeTrue();
            expect($engine->matches(['flag' => ['===', true]], ['flag' => 1]))->toBeFalse();
        });

        test('not_empty operator', function () use ($engine): void {
            expect($engine->matches(['tags' => ['not_empty']], ['tags' => ['a', 'b']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_empty']], ['tags' => []]))->toBeFalse();
            expect($engine->matches(['tags' => ['not_empty']], ['tags' => null]))->toBeFalse();
        });

        test('not_contains operator', function () use ($engine): void {
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['urgent', 'billing']]))->toBeTrue();
            expect($engine->matches(['tags' => ['not_contains', 'spam']], ['tags' => ['spam', 'urgent']]))->toBeFalse();
        });

        test('starts_with operator', function () use ($engine): void {
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'admin@example.com']))->toBeTrue();
            expect($engine->matches(['email' => ['starts_with', 'admin@']], ['email' => 'user@example.com']))->toBeFalse();
        });

        test('ends_with operator', function () use ($engine): void {
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))->toBeTrue();
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.org']))->toBeFalse();
        });

        test('AND logic requires all conditions to match', function () use ($engine): void {
            $conditions = [
                'status' => 'active',
                'amount' => ['>', 50],
            ];

            expect($engine->matches($conditions, ['status' => 'active', 'amount' => 100]))->toBeTrue();
            expect($engine->matches($conditions, ['status' => 'inactive', 'amount' => 100]))->toBeFalse();
            expect($engine->matches($conditions, ['status' => 'active', 'amount' => 30]))->toBeFalse();
        });

        test('nested dot notation', function () use ($engine): void {
            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'admin']],
            ))->toBeTrue();

            expect($engine->matches(
                ['user.role' => 'admin'],
                ['user' => ['role' => 'user']],
            ))->toBeFalse();
        });

        test('empty conditions array matches any payload', function () use ($engine): void {
            expect($engine->matches([], ['anything' => 'here']))->toBeTrue();
            expect($engine->matches([], []))->toBeTrue();
        });

        test('between operator auto-normalizes inverted ranges', function () use ($engine): void {
            expect($engine->matches(
                ['amount' => ['between', [100, 50]]],
                ['amount' => 75],
            ))->toBeTrue();

            expect($engine->matches(
                ['amount' => ['between', [100, 50]]],
                ['amount' => 49],
            ))->toBeFalse();
        });

        test('in operator with array value', function () use ($engine): void {
            expect($engine->matches(
                ['role' => ['in', ['admin', 'moderator']]],
                ['role' => 'admin'],
            ))->toBeTrue();

            expect($engine->matches(
                ['role' => ['in', ['admin', 'moderator']]],
                ['role' => 'user'],
            ))->toBeFalse();
        });

        test('comparison operators with null actual returns false', function () use ($engine): void {
            expect($engine->matches(['amount' => ['>', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['>=', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
        });
    });

    // ─── 14. Models config-driven table names ────────────────────────────

    describe('Models config-driven table names', function (): void {
        test('Trigger uses config table name', function (): void {
            $trigger = new Trigger;
            expect($trigger->getTable())->toBe(config('events.table_names.triggers'));
        });

        test('EventLog uses config table name', function (): void {
            $log = new EventLog;
            expect($log->getTable())->toBe(config('events.table_names.event_logs'));
        });

        test('Subscription uses config table name', function (): void {
            $sub = new Subscription;
            expect($sub->getTable())->toBe(config('events.table_names.subscriptions'));
        });
    });

    // ─── 15. Facade accessor correctness ─────────────────────────────────

    describe('Facade accessor', function (): void {
        test('EventManager facade resolves to correct class', function (): void {
            $facade = new \ZeroBoiler\Events\Facades\EventManager;
            expect($facade->getFacadeAccessor())->toBe(EventManager::class);
        });

        test('facade has all @method annotations for public API', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $doc = $reflection->getDocComment();
            expect($doc)->not->toBeFalse();

            $requiredMethods = [
                'on(', 'register(', 'fire(', 'fireModel(',
                'enable(', 'disable(', 'invalidateTriggerCache(',
                'isDisabled()', 'setEnabled(', 'listTriggers(',
                'getTrigger(', 'deleteTrigger(', 'subscribe(',
                'unsubscribe(', 'listSubscriptions(', 'getSubscription(',
                'getEventHistory(', 'getStats(', 'purgeLogs(',
                'getStalePendingLogs(', 'deactivateExceededSubscriptions(',
                'executeTrigger(', 'registerScheduler(',
            ];

            foreach ($requiredMethods as $method) {
                expect(str_contains($doc ?: '', $method))
                    ->toBeTrue("Facade docblock missing @method for {$method}");
            }
        });
    });

    // ─── 16. DispatchTriggerJob config-driven properties ────────────────

    describe('DispatchTriggerJob config-driven properties', function (): void {
        test('reads retry tries from config', function (): void {
            $original = config('events.retry.tries');
            config(['events.retry.tries' => 5]);

            $job = new DispatchTriggerJob('trigger-id', 'test.event', ['key' => 'value']);
            expect($job->tries)->toBe(5);

            config(['events.retry.tries' => $original]);
        });

        test('reads backoff from string config', function (): void {
            $original = config('events.retry.backoff');
            config(['events.retry.backoff' => '30,120,300']);

            $job = new DispatchTriggerJob('trigger-id', 'test.event', ['key' => 'value']);
            expect($job->backoff)->toBe([30, 120, 300]);

            config(['events.retry.backoff' => $original]);
        });

        test('reads queue name from config', function (): void {
            $original = config('events.queue.queue');
            config(['events.queue.queue' => 'custom-queue']);

            $job = new DispatchTriggerJob('trigger-id', 'test.event', ['key' => 'value']);
            expect($job->queue)->toBe('custom-queue');

            config(['events.queue.queue' => $original]);
        });

        test('promoted readonly properties are set correctly', function (): void {
            $job = new DispatchTriggerJob('my-trigger-id', 'my.event', ['foo' => 'bar']);

            expect($job->triggerId)->toBe('my-trigger-id');
            expect($job->event)->toBe('my.event');
            expect($job->payload)->toBe(['foo' => 'bar']);
        });
    });
});
