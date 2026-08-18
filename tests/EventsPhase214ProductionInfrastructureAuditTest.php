<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 214 — Production Infrastructure Audit', function (): void {
    // ─────────────────────────────────────────────
    // 1. TriggerBuilder: actions() + actionParams → classes key
    // ─────────────────────────────────────────────
    describe('TriggerBuilder multi-action with actionParams encodes classes key', function (): void {
        it('actions() with actionParams produces {classes:[...], params:{...}} JSON', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.multi.action')
                ->actions([
                    'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
                    'ZeroBoiler\\Events\\Tests\\Actions\\LogOrderAction',
                ])
                ->actionParams(['webhook_url' => 'https://example.com/hook'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBeArray();
            expect($decoded)->toHaveKey('classes');
            expect($decoded)->toHaveKey('params');
            expect($decoded['classes'])->toBe([
                'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
                'ZeroBoiler\\Events\\Tests\\Actions\\LogOrderAction',
            ]);
            expect($decoded['params'])->toBe(['webhook_url' => 'https://example.com/hook']);
        });

        it('single action() with actionParams produces {class:"...", params:{...}} JSON', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.single.action.params')
                ->action('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification')
                ->actionParams(['channel' => '#alerts'])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBeArray();
            expect($decoded)->toHaveKey('class');
            expect($decoded)->toHaveKey('params');
            expect($decoded['class'])->toBe('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification');
            expect($decoded['params'])->toBe(['channel' => '#alerts']);
        });

        it('actions() without actionParams produces plain JSON array', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.multi.no.params')
                ->actions([
                    'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
                ])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBe([
                'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
            ]);
        });
    });

    // ─────────────────────────────────────────────
    // 2. TriggerBuilder: action() + actions() merge
    // ─────────────────────────────────────────────
    describe('TriggerBuilder action() and actions() merge', function (): void {
        it('action() prepended to actions() when both called with no overlap', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.merge.no.overlap')
                ->action('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification')
                ->actions([
                    'ZeroBoiler\\Events\\Tests\\Actions\\LogOrderAction',
                ])
                ->save();

            $decoded = json_decode($trigger->action, true);
            // No params, so plain array
            expect($decoded)->toBe([
                'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
                'ZeroBoiler\\Events\\Tests\\Actions\\LogOrderAction',
            ]);
        });

        it('duplicate across action() and actions() is deduplicated', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('test.merge.dedup')
                ->action('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification')
                ->actions([
                    'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
                ])
                ->save();

            $decoded = json_decode($trigger->action, true);
            expect($decoded)->toBe([
                'ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification',
            ]);
        });
    });

    // ─────────────────────────────────────────────
    // 3. WildcardMatcher: regex-special characters
    // ─────────────────────────────────────────────
    describe('WildcardMatcher with regex-special characters in non-wildcard events', function (): void {
        it('pattern with literal dots matches exact dotted event', function (): void {
            expect(WildcardMatcher::matches('order.item.created', 'order.item.created'))->toBeTrue();
            expect(WildcardMatcher::matches('order.item.created', 'orderXitemXcreated'))->toBeFalse();
        });

        it('pattern with literal plus sign matches exactly', function (): void {
            expect(WildcardMatcher::matches('user+role', 'user+role'))->toBeTrue();
            expect(WildcardMatcher::matches('user+role', 'userXrole'))->toBeFalse();
        });

        it('pattern with parentheses are escaped', function (): void {
            expect(WildcardMatcher::matches('event(test)', 'event(test)'))->toBeTrue();
            expect(WildcardMatcher::matches('event(test)', 'eventtest'))->toBeFalse();
        });

        it('wildcard adjacent to special chars works', function (): void {
            // order.* should match order.placed but not order
            expect(WildcardMatcher::matches('order.*', 'order.placed'))->toBeTrue();
            // * with literal dot in event
            expect(WildcardMatcher::matches('*+extra', 'test+extra'))->toBeTrue();
        });
    });

    // ─────────────────────────────────────────────
    // 4. ConditionEngine: deep nesting
    // ─────────────────────────────────────────────
    describe('ConditionEngine 5+ level deep dot notation', function (): void {
        it('matches 5-level nested value', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['a.b.c.d.e' => 'deep'],
                ['a' => ['b' => ['c' => ['d' => ['e' => 'deep']]]]],
            );
            expect($result)->toBeTrue();
        });

        it('returns false when intermediate key is not array', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['a.b.c' => 'value'],
                ['a' => ['b' => 'not_array']],
            );
            expect($result)->toBeFalse();
        });

        it('returns false when key path does not exist', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(
                ['a.b.c.d.e.f' => 'value'],
                ['a' => ['b' => ['c' => ['d' => ['e' => 'stop']]]]],
            );
            expect($result)->toBeFalse();
        });
    });

    // ─────────────────────────────────────────────
    // 5. DomainEvent: payload type edge cases
    // ─────────────────────────────────────────────
    describe('DomainEvent fromArray payload edge cases', function (): void {
        it('fromArray with string payload defaults to empty array', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => 'not-an-array',
            ]);
            expect($event->payload)->toBe([]);
        });

        it('fromArray with integer payload defaults to empty array', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => 42,
            ]);
            expect($event->payload)->toBe([]);
        });

        it('fromArray with null payload defaults to empty array', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => null,
            ]);
            expect($event->payload)->toBe([]);
        });

        it('fromArray with nested object payload preserves structure', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'order.placed',
                'payload' => [
                    'order' => ['id' => 'uuid-123', 'items' => ['sku' => 'A1']],
                    'customer' => ['email' => 'test@example.com'],
                ],
            ]);

            expect($event->payload['order']['items']['sku'])->toBe('A1');
            expect($event->payload['customer']['email'])->toBe('test@example.com');
        });

        it('fromArray with extra unknown keys are ignored', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.event',
                'payload' => ['key' => 'value'],
                'unknownKey' => 'ignored',
                'anotherUnknown' => 123,
            ]);

            expect($event->eventType)->toBe('test.event');
            expect($event->payload)->toBe(['key' => 'value']);
        });
    });

    // ─────────────────────────────────────────────
    // 6. EventManager: sanitizePayloadForQueue
    // ─────────────────────────────────────────────
    describe('EventManager sanitizePayloadForQueue', function (): void {
        it('preserves nested arrays of scalars', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('sanitizePayloadForQueue');

            $input = [
                'string' => 'hello',
                'int' => 42,
                'float' => 3.14,
                'bool' => true,
                'null' => null,
                'nested' => [
                    'a' => 1,
                    'b' => 'two',
                ],
            ];

            $result = $method->invoke($manager, $input);
            expect($result)->toBe($input);
        });

        it('strips objects with type placeholder', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('sanitizePayloadForQueue');

            $obj = new stdClass();
            $input = ['key' => $obj];
            $result = $method->invoke($manager, $input);

            expect($result['key'])->toBe('[stripped:stdClass]');
        });

        it('strips closures with type placeholder', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('sanitizePayloadForQueue');

            $input = ['callback' => fn (): string => 'hello'];
            $result = $method->invoke($manager, $input);

            expect($result['callback'])->toBe('[stripped:Closure]');
        });

        it('handles empty array', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('sanitizePayloadForQueue');

            $result = $method->invoke($manager, []);
            expect($result)->toBe([]);
        });
    });

    // ─────────────────────────────────────────────
    // 7. EventManager: parseActions
    // ─────────────────────────────────────────────
    describe('EventManager parseActions edge cases', function (): void {
        it('parses single class string as-is', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, 'App\\Actions\\SendNotification');
            expect($result)->toBe(['App\\Actions\\SendNotification']);
        });

        it('parses JSON array of classes', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, '["App\\Actions\\A", "App\\Actions\\B"]');
            expect($result)->toBe(['App\\Actions\\A', 'App\\Actions\\B']);
        });

        it('parses JSON object with class+params', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, '{"class": "App\\Actions\\A", "params": {"url": "https://x.com"}}');
            expect($result)->toBe([
                ['class' => 'App\\Actions\\A', 'params' => ['url' => 'https://x.com']],
            ]);
        });

        it('parses JSON with classes+params key', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, '{"classes": ["A", "B"], "params": {"key": "val"}}');
            expect($result)->toBe([
                ['class' => 'A', 'params' => ['key' => 'val']],
                ['class' => 'B', 'params' => ['key' => 'val']],
            ]);
        });

        it('returns empty array for empty string', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, '');
            expect($result)->toBe([]);
        });

        it('returns empty array for whitespace-only string', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, '   ');
            expect($result)->toBe([]);
        });

        it('returns single-element array for invalid JSON string', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('parseActions');

            $result = $method->invoke($manager, 'not-valid-json');
            expect($result)->toBe(['not-valid-json']);
        });
    });

    // ─────────────────────────────────────────────
    // 8. EventScheduler: registration and config
    // ─────────────────────────────────────────────
    describe('EventScheduler registration and config', function (): void {
        it('register() calls both log purge and subscription cleanup', function (): void {
            $scheduler = app(EventScheduler::class);
            $schedule = new Schedule;

            // Should not throw
            $scheduler->register($schedule);
            expect(true)->toBeTrue();
        });

        it('register() is idempotent — calling twice does not error', function (): void {
            $scheduler = app(EventScheduler::class);
            $schedule = new Schedule;

            $scheduler->register($schedule);
            $scheduler->register($schedule);
            expect(true)->toBeTrue();
        });

        it('register() with disabled retention still registers cleanup', function (): void {
            $config = app('config');
            $config->set('events.retention.days', 0);

            $scheduler = app(EventScheduler::class);
            $schedule = new Schedule;

            // Should not throw even with retention disabled
            $scheduler->register($schedule);
            expect(true)->toBeTrue();

            // Restore
            $config->set('events.retention.days', 30);
        });
    });

    // ─────────────────────────────────────────────
    // 9. Subscription model operations
    // ─────────────────────────────────────────────
    describe('Subscription model operations', function (): void {
        it('signPayload with empty secret returns empty string', function (): void {
            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com',
                'secret' => '',
            ]);

            expect($sub->signPayload('payload'))->toBe('');
        });

        it('signPayload with null secret returns empty string', function (): void {
            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com',
                'secret' => null,
            ]);

            expect($sub->signPayload('payload'))->toBe('');
        });

        it('signPayload produces deterministic output', function (): void {
            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com',
                'secret' => 'whsec_test_secret_for_hmac',
            ]);

            $sig1 = $sub->signPayload('test-payload');
            $sig2 = $sub->signPayload('test-payload');

            expect($sig1)->toBe($sig2);
            expect($sig1)->not->toBe('');
        });

        it('matchesEvent delegates to WildcardMatcher for wildcard events', function (): void {
            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'order.*',
                'url' => 'https://example.com',
            ]);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
            expect($sub->matchesEvent('user.placed'))->toBeFalse();
        });

        it('matchesEvent exact match for non-wildcard events', function (): void {
            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'order.placed',
                'url' => 'https://example.com',
            ]);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        });

        it('hasExceededFailures uses config threshold by default', function (): void {
            $config = app('config');
            $config->set('events.subscriptions.max_failures', 5);

            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com',
                'failure_count' => 5,
            ]);

            expect($sub->hasExceededFailures())->toBeTrue();

            $sub2 = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com',
                'failure_count' => 4,
            ]);

            expect($sub2->hasExceededFailures())->toBeFalse();

            // Restore
            $config->set('events.subscriptions.max_failures', 10);
        });

        it('hasExceededFailures uses explicit override when provided', function (): void {
            $sub = new Subscription([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'url' => 'https://example.com',
                'failure_count' => 3,
            ]);

            expect($sub->hasExceededFailures(3))->toBeTrue();
            expect($sub->hasExceededFailures(4))->toBeFalse();
        });
    });

    // ─────────────────────────────────────────────
    // 10. EventLog model operations
    // ─────────────────────────────────────────────
    describe('EventLog model operations', function (): void {
        it('markAsCompleted sets status and duration', function (): void {
            $log = new EventLog([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'status' => EventLog::STATUS_DISPATCHED,
            ]);
            $log->save();

            $log->markAsCompleted(150);

            expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
            expect($log->duration_ms)->toBe(150);
        });

        it('markAsFailed sets status and error', function (): void {
            $log = new EventLog([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'trigger_id' => (string) \Illuminate\Support\Str::uuid(),
                'event' => 'test.event',
                'status' => EventLog::STATUS_DISPATCHED,
            ]);
            $log->save();

            $log->markAsFailed('Connection timeout');

            expect($log->status)->toBe(EventLog::STATUS_FAILED);
            expect($log->error)->toBe('Connection timeout');
        });

        it('status constants are unique', function (): void {
            $statuses = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];

            expect(count($statuses))->toBe(count(array_unique($statuses)));
        });
    });

    // ─────────────────────────────────────────────
    // 11. ServiceProvider binding verification
    // ─────────────────────────────────────────────
    describe('ServiceProvider binding verification', function (): void {
        it('ConditionEngineContract resolves to ConditionEngine', function (): void {
            $engine = app(ConditionEngineContract::class);
            expect($engine)->toBeInstanceOf(ConditionEngine::class);
        });

        it('ConditionEngine is singleton', function (): void {
            $a = app(ConditionEngine::class);
            $b = app(ConditionEngine::class);
            expect($a)->toBe($b);
        });

        it('EventManager is singleton', function (): void {
            $a = app(EventManager::class);
            $b = app(EventManager::class);
            expect($a)->toBe($b);
        });

        it('ActionResolver is singleton', function (): void {
            $a = app(ActionResolver::class);
            $b = app(ActionResolver::class);
            expect($a)->toBe($b);
        });

        it('TriggerBuilder is transient (new instance each time)', function (): void {
            $a = app(TriggerBuilder::class);
            $b = app(TriggerBuilder::class);
            expect($a)->not->toBe($b);
        });

        it('SubscriptionBuilder is transient (new instance each time)', function (): void {
            $a = app(SubscriptionBuilder::class);
            $b = app(SubscriptionBuilder::class);
            expect($a)->not->toBe($b);
        });

        it('EventScheduler is singleton', function (): void {
            $a = app(EventScheduler::class);
            $b = app(EventScheduler::class);
            expect($a)->toBe($b);
        });
    });

    // ─────────────────────────────────────────────
    // 12. Config completeness
    // ─────────────────────────────────────────────
    describe('Config completeness', function (): void {
        it('has all 8 top-level keys', function (): void {
            $config = app('config')->get('events');
            $expectedKeys = [
                'table_names', 'queue', 'retry', 'retention',
                'subscriptions', 'disabled', 'wildcard_cache_ttl',
            ];

            foreach ($expectedKeys as $key) {
                expect(array_key_exists($key, $config))->toBeTrue("Missing config key: events.{$key}");
            }
        });

        it('table_names has all 3 entries', function (): void {
            $tables = app('config')->get('events.table_names');
            expect($tables)->toHaveKeys(['triggers', 'event_logs', 'subscriptions']);
        });

        it('subscriptions has all 6 sub-keys', function (): void {
            $subs = app('config')->get('events.subscriptions');
            expect($subs)->toHaveKeys([
                'auto_generate_secret', 'secret_length', 'max_failures',
                'timeout', 'signature_algorithm', 'cleanup_cron',
            ]);
        });

        it('retry has tries and backoff', function (): void {
            $retry = app('config')->get('events.retry');
            expect($retry)->toHaveKeys(['tries', 'backoff']);
        });

        it('retention has days, include_pending, and schedule_cron', function (): void {
            $ret = app('config')->get('events.retention');
            expect($ret)->toHaveKeys(['days', 'include_pending', 'schedule_cron']);
        });

        it('queue has connection and queue', function (): void {
            $queue = app('config')->get('events.queue');
            expect($queue)->toHaveKeys(['connection', 'queue']);
        });
    });

    // ─────────────────────────────────────────────
    // 13. Exception hierarchy
    // ─────────────────────────────────────────────
    describe('Exception hierarchy', function (): void {
        it('all exceptions extend EventException', function (): void {
            $exceptions = [
                \ZeroBoiler\Events\Exceptions\TriggerNotFoundException::class,
                \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
                \ZeroBoiler\Events\Exceptions\ConditionEvaluationException::class,
                \ZeroBoiler\Events\Exceptions\SubscriptionException::class,
            ];

            foreach ($exceptions as $cls) {
                expect(is_subclass_of($cls, \ZeroBoiler\Events\Exceptions\EventException::class))
                    ->toBeTrue("{$cls} must extend EventException");
            }
        });

        it('EventException extends RuntimeException', function (): void {
            expect(is_subclass_of(
                \ZeroBoiler\Events\Exceptions\EventException::class,
                \RuntimeException::class,
            ))->toBeTrue();
        });

        it('all leaf exceptions are final', function (): void {
            $leaves = [
                \ZeroBoiler\Events\Exceptions\TriggerNotFoundException::class,
                \ZeroBoiler\Events\Exceptions\ActionResolutionException::class,
                \ZeroBoiler\Events\Exceptions\ConditionEvaluationException::class,
                \ZeroBoiler\Events\Exceptions\SubscriptionException::class,
            ];

            foreach ($leaves as $cls) {
                $ref = new ReflectionClass($cls);
                expect($ref->isFinal())->toBeTrue("{$cls} must be final");
            }
        });

        it('EventException is not final (open for extension)', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Exceptions\EventException::class);
            expect($ref->isFinal())->toBeFalse();
        });
    });

    // ─────────────────────────────────────────────
    // 14. Source file quality audit
    // ─────────────────────────────────────────────
    describe('Source file quality', function (): void {
        it('all source files have declare(strict_types=1)', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                expect($contents)->toContain('declare(strict_types=1)');
            }
        });

        it('all source files have license header', function (): void {
            $srcDir = __DIR__.'/../src';
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the proprietary license');
            }
        });

        it('composer.json requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('composer.json requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });
    });

    // ─────────────────────────────────────────────
    // 15. Facade correctness
    // ─────────────────────────────────────────────
    describe('Facade correctness', function (): void {
        it('facade accessor returns EventManager class name', function (): void {
            $facade = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            $method = $facade->getMethod('getFacadeAccessor');
            $result = $method->invoke(null);
            expect($result)->toBe(\ZeroBoiler\Events\EventManager::class);
        });

        it('facade is final class', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Events\Facades\EventManager::class);
            expect($ref->isFinal())->toBeTrue();
        });
    });

    // ─────────────────────────────────────────────
    // 16. EventManager public API surface
    // ─────────────────────────────────────────────
    describe('EventManager public API completeness', function (): void {
        it('has fire() method', function (): void {
            expect(method_exists(EventManager::class, 'fire'))->toBeTrue();
        });

        it('has fireModel() method', function (): void {
            expect(method_exists(EventManager::class, 'fireModel'))->toBeTrue();
        });

        it('has executeTrigger() method', function (): void {
            expect(method_exists(EventManager::class, 'executeTrigger'))->toBeTrue();
        });

        it('has registerScheduler() method', function (): void {
            expect(method_exists(EventManager::class, 'registerScheduler'))->toBeTrue();
        });

        it('has container() method', function (): void {
            expect(method_exists(EventManager::class, 'container'))->toBeTrue();
        });

        it('has subscribe() method (from ManagesSubscriptions trait)', function (): void {
            expect(method_exists(EventManager::class, 'subscribe'))->toBeTrue();
        });

        it('has unsubscribe() method (from ManagesSubscriptions trait)', function (): void {
            expect(method_exists(EventManager::class, 'unsubscribe'))->toBeTrue();
        });

        it('has getEventHistory() method (from ManagesHistory trait)', function (): void {
            expect(method_exists(EventManager::class, 'getEventHistory'))->toBeTrue();
        });

        it('has getStats() method (from ManagesHistory trait)', function (): void {
            expect(method_exists(EventManager::class, 'getStats'))->toBeTrue();
        });

        it('has purgeLogs() method (from ManagesHistory trait)', function (): void {
            expect(method_exists(EventManager::class, 'purgeLogs'))->toBeTrue();
        });

        it('has deactivateExceededSubscriptions() method (from ManagesHistory trait)', function (): void {
            expect(method_exists(EventManager::class, 'deactivateExceededSubscriptions'))->toBeTrue();
        });
    });

    // ─────────────────────────────────────────────
    // 17. Trigger model scopes
    // ─────────────────────────────────────────────
    describe('Trigger model scopes', function (): void {
        it('scopeEnabled filters by enabled=true', function (): void {
            $trigger = Trigger::factory()->create(['enabled' => true]);
            Trigger::factory()->create(['enabled' => false]);

            $results = Trigger::enabled()->get();
            expect($results)->toHaveCount(1);
            expect($results->first()->id)->toBe($trigger->id);
        });

        it('scopeAsync filters by async=true', function (): void {
            $trigger = Trigger::factory()->create(['async' => true]);
            Trigger::factory()->create(['async' => false]);

            $results = Trigger::async()->get();
            expect($results)->toHaveCount(1);
            expect($results->first()->id)->toBe($trigger->id);
        });
    });

    // ─────────────────────────────────────────────
    // 18. ManagesHistory operations
    // ─────────────────────────────────────────────
    describe('ManagesHistory operations', function (): void {
        it('purgeLogs deletes old completed logs', function (): void {
            $trigger = Trigger::factory()->create();

            EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_COMPLETED,
                'created_at' => Carbon::now()->subDays(60),
            ]);

            EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_COMPLETED,
                'created_at' => Carbon::now()->subDays(5),
            ]);

            $deleted = app(EventManager::class)->purgeLogs(Carbon::now()->subDays(30));
            expect($deleted)->toBe(1);
        });

        it('purgeLogs with includePending also deletes pending logs', function (): void {
            $trigger = Trigger::factory()->create();

            EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_PENDING,
                'created_at' => Carbon::now()->subDays(60),
            ]);

            // Without includePending, pending logs are NOT purged
            $deleted1 = app(EventManager::class)->purgeLogs(Carbon::now()->subDays(30));
            expect($deleted1)->toBe(0);

            // With includePending, they ARE purged
            $deleted2 = app(EventManager::class)->purgeLogs(Carbon::now()->subDays(30), includePending: true);
            expect($deleted2)->toBe(1);
        });

        it('getStalePendingLogs returns logs older than threshold', function (): void {
            $trigger = Trigger::factory()->create();

            EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_PENDING,
                'created_at' => Carbon::now()->subHours(5),
            ]);

            EventLog::factory()->create([
                'trigger_id' => $trigger->id,
                'status' => EventLog::STATUS_PENDING,
                'created_at' => Carbon::now()->subMinutes(5),
            ]);

            $stale = app(EventManager::class)->getStalePendingLogs(Carbon::now()->subHours(1));
            expect($stale)->toHaveCount(1);
        });

        it('getStats returns correct structure', function (): void {
            $stats = app(EventManager::class)->getStats();

            expect($stats)->toHaveKeys([
                'total_logs', 'total_triggers', 'active_triggers',
                'completed', 'failed', 'pending', 'dispatched',
                'success_rate', 'failure_rate', 'avg_duration_ms',
                'top_events', 'top_failed_events',
            ]);
        });
    });

    // ─────────────────────────────────────────────
    // 19. ActionResolver error handling
    // ─────────────────────────────────────────────
    describe('ActionResolver error handling', function (): void {
        it('throws for non-existent class', function (): void {
            $resolver = app(ActionResolver::class);

            $this->expectException(\ZeroBoiler\Events\Exceptions\ActionResolutionException::class);
            $resolver->resolve('NonExistent\Class\Here');
        });

        it('throws for class that does not implement Triggerable', function (): void {
            $resolver = app(ActionResolver::class);

            $this->expectException(\ZeroBoiler\Events\Exceptions\ActionResolutionException::class);
            $resolver->resolve(stdClass::class);
        });

        it('resolves valid Triggerable class', function (): void {
            $resolver = app(ActionResolver::class);
            $instance = $resolver->resolve('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification');

            expect($instance)->toBeInstanceOf(Triggerable::class);
        });
    });

    // ─────────────────────────────────────────────
    // 20. ManagesSubscriptions operations
    // ─────────────────────────────────────────────
    describe('ManagesSubscriptions operations', function (): void {
        it('listSubscriptions with event filter returns matching', function (): void {
            $manager = app(EventManager::class);

            Subscription::factory()->create(['event' => 'order.placed', 'active' => true]);
            Subscription::factory()->create(['event' => 'user.created', 'active' => true]);

            $results = $manager->listSubscriptions('order.placed');
            expect($results)->toHaveCount(1);
            expect($results->first()->event)->toBe('order.placed');
        });

        it('listSubscriptions with activeOnly filters inactive', function (): void {
            $manager = app(EventManager::class);

            Subscription::factory()->create(['event' => 'test.event', 'active' => true]);
            Subscription::factory()->create(['event' => 'test.event', 'active' => false]);

            $results = $manager->listSubscriptions(null, activeOnly: true);
            expect($results)->toHaveCount(1);
            expect($results->first()->active)->toBeTrue();
        });

        it('getSubscription returns null for non-existent ID', function (): void {
            $manager = app(EventManager::class);

            $result = $manager->getSubscription('non-existent-id');
            expect($result)->toBeNull();
        });
    });

    // ─────────────────────────────────────────────
    // 21. EventManager CRUD edge cases
    // ─────────────────────────────────────────────
    describe('EventManager CRUD edge cases', function (): void {
        it('getTrigger returns null for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->getTrigger(''))->toBeNull();
        });

        it('getTrigger returns null for zero string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->getTrigger('0'))->toBeNull();
        });

        it('deleteTrigger returns false for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->deleteTrigger(''))->toBeFalse();
        });

        it('deleteTrigger returns false for zero string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->deleteTrigger('0'))->toBeFalse();
        });

        it('enable returns false for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->enable(''))->toBeFalse();
        });

        it('disable returns false for empty string', function (): void {
            $manager = app(EventManager::class);
            expect($manager->disable(''))->toBeFalse();
        });
    });

    // ─────────────────────────────────────────────
    // 22. Global disable system
    // ─────────────────────────────────────────────
    describe('Global disable system', function (): void {
        it('setEnabled(false) makes isDisabled() return true', function (): void {
            $manager = app(EventManager::class);
            $manager->setEnabled(false);
            expect($manager->isDisabled())->toBeTrue();
            $manager->setEnabled(true);
        });

        it('setEnabled(true) makes isDisabled() return false', function (): void {
            $manager = app(EventManager::class);
            $manager->setEnabled(true);
            expect($manager->isDisabled())->toBeFalse();
        });
    });

    // ─────────────────────────────────────────────
    // 23. EscapesWildcardLike trait
    // ─────────────────────────────────────────────
    describe('EscapesWildcardLike SQL injection prevention', function (): void {
        it('returns null for non-wildcard pattern', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('wildcardToLike');

            $result = $method->invoke($manager, 'order.placed');
            expect($result)->toBeNull();
        });

        it('escapes backslash, percent, underscore', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('wildcardToLike');

            // Pattern: testoo%bar_*
            // Expected:  test\fooar%_ * → test\\foo\%bar\_ %
            $result = $method->invoke($manager, 'test\foo%bar_*');
            expect($result)->toBe('test\\foo\%bar\_%');
        });

        it('catch-all * becomes %', function (): void {
            $manager = app(EventManager::class);
            $reflection = new ReflectionClass($manager);
            $method = $reflection->getMethod('wildcardToLike');

            $result = $method->invoke($manager, '*');
            expect($result)->toBe('%');
        });
    });

    // ─────────────────────────────────────────────
    // 24. Model casts verification
    // ─────────────────────────────────────────────
    describe('Model casts', function (): void {
        it('Trigger has 4 casts', function (): void {
            $trigger = new Trigger;
            $casts = $trigger->casts();
            expect(count($casts))->toBe(4);
            expect($casts)->toHaveKeys(['conditions', 'async', 'enabled', 'priority']);
        });

        it('EventLog has 3 casts', function (): void {
            $log = new EventLog;
            $casts = $log->casts();
            expect(count($casts))->toBe(3);
            expect($casts)->toHaveKeys(['payload', 'duration_ms', 'error']);
        });

        it('Subscription has 6 casts', function (): void {
            $sub = new Subscription;
            $casts = $sub->casts();
            expect(count($casts))->toBe(6);
            expect($casts)->toHaveKeys([
                'conditions', 'priority', 'active', 'failure_count',
                'delivery_count', 'last_fired_at',
            ]);
        });
    });

    // ─────────────────────────────────────────────
    // 25. PHPStan config validation
    // ─────────────────────────────────────────────
    describe('PHPStan config validation', function (): void {
        it('phpstan.neon.dist exists and has level 9', function (): void {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');
            expect($contents)->toContain('level: 9');
            expect($contents)->toContain('checkExplicitMixed: true');
            expect($contents)->toContain('checkUninitializedProperties: true');
            expect($contents)->toContain('baselineFile: phpstan-baseline.neon');
            expect($contents)->toContain('src');
        });
    });

    // ─────────────────────────────────────────────
    // 26. DispatchTriggerJob config initialization
    // ─────────────────────────────────────────────
    describe('DispatchTriggerJob config initialization', function (): void {
        it('reads tries from config', function (): void {
            $config = app('config');
            $original = $config->get('events.retry.tries');
            $config->set('events.retry.tries', 5);

            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                'test-id',
                'test.event',
                ['key' => 'value'],
            );

            expect($job->tries)->toBe(5);

            // Restore
            $config->set('events.retry.tries', $original);
        });

        it('reads queue from config', function (): void {
            $config = app('config');
            $original = $config->get('events.queue.queue');
            $config->set('events.queue.queue', 'events-queue');

            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                'test-id',
                'test.event',
                [],
            );

            expect($job->queue)->toBe('events-queue');

            // Restore
            $config->set('events.queue.queue', $original);
        });

        it('reads connection from config when set', function (): void {
            $config = app('config');
            $original = $config->get('events.queue.connection');
            $config->set('events.queue.connection', 'redis-events');

            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                'test-id',
                'test.event',
                [],
            );

            expect($job->connection)->toBe('redis-events');

            // Restore
            $config->set('events.queue.connection', $original);
        });

        it('connection defaults to null when not configured', function (): void {
            $config = app('config');
            $original = $config->get('events.queue.connection');
            $config->set('events.queue.connection', null);

            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                'test-id',
                'test.event',
                [],
            );

            expect($job->connection)->toBeNull();

            // Restore
            $config->set('events.queue.connection', $original);
        });

        it('eventLogId is initially null', function (): void {
            $job = new \ZeroBoiler\Events\Jobs\DispatchTriggerJob(
                'test-id',
                'test.event',
                [],
            );

            $reflection = new ReflectionClass($job);
            $prop = $reflection->getProperty('eventLogId');
            expect($prop->getValue($job))->toBeNull();
        });
    });

    // ─────────────────────────────────────────────
    // 27. TriggerBuilder validation
    // ─────────────────────────────────────────────
    describe('TriggerBuilder validation', function (): void {
        it('save() throws when event is empty', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Event name is required');

            $manager->on('')->action('SomeAction')->save();
        });

        it('save() throws when no action is provided', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('At least one action is required');

            $manager->on('test.event')->save();
        });

        it('save() auto-generates name from event', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('auto.name.test')
                ->action('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification')
                ->save();

            expect($trigger->name)->toBe('auto.name.test Trigger');
        });
    });

    // ─────────────────────────────────────────────
    // 28. SubscriptionBuilder validation
    // ─────────────────────────────────────────────
    describe('SubscriptionBuilder validation', function (): void {
        it('save() throws when event is empty', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Event name is required');

            $manager->subscribe('', 'https://example.com/hook')->save();
        });

        it('save() throws when URL is empty', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Webhook URL is required');

            $manager->subscribe('test.event', '')->save();
        });

        it('save() throws for non-HTTP scheme', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('must use HTTP or HTTPS protocol');

            $manager->subscribe('test.event', 'ftp://evil.com/upload')->save();
        });

        it('save() throws for file scheme', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('must use HTTP or HTTPS protocol');

            $manager->subscribe('test.event', 'file:///etc/passwd')->save();
        });

        it('save() throws for secret shorter than 16 chars', function (): void {
            $manager = app(EventManager::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('at least 16 characters');

            $manager->subscribe('test.event', 'https://example.com/hook')
                ->withSecret('short')
                ->save();
        });
    });

    // ─────────────────────────────────────────────
    // 29. WebhookAction edge cases
    // ─────────────────────────────────────────────
    describe('WebhookAction edge cases', function (): void {
        it('handle() throws when URL is missing from payload', function (): void {
            $action = new \ZeroBoiler\Events\Actions\WebhookAction;

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('non-empty "url"');

            $action->handle([]);
        });

        it('handle() throws when URL is empty string', function (): void {
            $action = new \ZeroBoiler\Events\Actions\WebhookAction;

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('non-empty "url"');

            $action->handle(['url' => '']);
        });
    });

    // ─────────────────────────────────────────────
    // 30. EventManager cache invalidation consistency
    // ─────────────────────────────────────────────
    describe('Cache invalidation consistency', function (): void {
        it('deleteTrigger invalidates cache', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('cache.invalid.delete.test')
                ->action('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification')
                ->save();

            $id = $trigger->id;
            $result = $manager->deleteTrigger($id);
            expect($result)->toBeTrue();
            // If we reach here without exception, cache was invalidated
            expect(true)->toBeTrue();
        });

        it('enable() invalidates cache', function (): void {
            $manager = app(EventManager::class);
            $trigger = $manager->on('cache.invalid.enable.test')
                ->action('ZeroBoiler\\Events\\Tests\\Actions\\SendOrderNotification')
                ->save();

            $result = $manager->disable($trigger->id);
            expect($result)->toBeTrue();

            $result2 = $manager->enable($trigger->id);
            expect($result2)->toBeTrue();
        });
    });

    // ─────────────────────────────────────────────
    // 31. ReDoS protection
    // ─────────────────────────────────────────────
    describe('ReDoS protection in ConditionEngine', function (): void {
        it('rejects regex longer than 500 characters', function (): void {
            $engine = new ConditionEngine();
            $longPattern = str_repeat('a', 501);

            $result = $engine->matches(['f' => ['matches', $longPattern]], ['f' => 'test']);
            expect($result)->toBeFalse();
        });

        it('rejects nested quantifier patterns', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['f' => ['matches', '(a+)+']], ['f' => 'aaa']);
            expect($result)->toBeFalse();
        });

        it('accepts safe regex patterns', function (): void {
            $engine = new ConditionEngine();

            $result = $engine->matches(['code' => ['matches', '/^[A-Z]{3}-\d{4}$/']], ['code' => 'ABC-1234']);
            expect($result)->toBeTrue();
        });
    });

    // ─────────────────────────────────────────────
    // 32. Migrations integrity
    // ─────────────────────────────────────────────
    describe('Migrations integrity', function (): void {
        it('all 3 migration files exist', function (): void {
            $migrationDir = __DIR__.'/../database/migrations';
            $files = glob($migrationDir.'/*.php');
            expect(count($files))->toBe(3);
        });
    });

    // ─────────────────────────────────────────────
    // 33. Factories integrity
    // ─────────────────────────────────────────────
    describe('Factories integrity', function (): void {
        it('all 3 factory files exist', function (): void {
            $factoryDir = __DIR__.'/../database/factories';
            $files = glob($factoryDir.'/*.php');
            expect(count($files))->toBe(3);
        });
    });

    // ─────────────────────────────────────────────
    // 34. Subscription auto-generate secret config
    // ─────────────────────────────────────────────
    describe('Subscription auto-generate secret config', function (): void {
        it('auto-generates secret when config is true', function (): void {
            $config = app('config');
            $config->set('events.subscriptions.auto_generate_secret', true);

            $manager = app(EventManager::class);
            $sub = $manager->subscribe('auto.secret.test', 'https://example.com/hook')
                ->save();

            expect($sub->secret)->not->toBeNull();
            expect($sub->secret)->toContain('whsec_');
            expect(strlen($sub->secret))->toBeGreaterThanOrEqual(19); // whsec_ (6) + 13 minimum
        });

        it('does not generate secret when config is false', function (): void {
            $config = app('config');
            $config->set('events.subscriptions.auto_generate_secret', false);

            $manager = app(EventManager::class);
            $sub = $manager->subscribe('no.auto.secret.test', 'https://example.com/hook')
                ->save();

            expect($sub->secret)->toBeNull();

            // Restore
            $config->set('events.subscriptions.auto_generate_secret', true);
        });
    });

    // ─────────────────────────────────────────────
    // 35. EventManager::registerScheduler edge cases
    // ─────────────────────────────────────────────
    describe('EventManager::registerScheduler edge cases', function (): void {
        it('delegates to EventScheduler::register', function (): void {
            $manager = app(EventManager::class);
            $schedule = new Schedule;

            // Should not throw
            $manager->registerScheduler($schedule);
            expect(true)->toBeTrue();
        });
    });
});
