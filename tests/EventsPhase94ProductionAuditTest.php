<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Contracts\Triggerable as TriggerableContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\WildcardMatcher;


describe('Phase 94 — Production Readiness Audit', function () {
    describe('EventManager fireModel Edge Cases', function () {
        test('fireModel with empty model class throws exception', function () {
            $manager = app(EventManager::class);

            expect(fn () => $manager->fireModel('', 'created', new \stdClass()))
                ->toThrow(\InvalidArgumentException::class, 'Model class name cannot be empty');
        });

        test('fireModel with empty action throws exception', function () {
            $manager = app(EventManager::class);

            expect(fn () => $manager->fireModel('App\\Models\\Order', '', new \stdClass()))
                ->toThrow(\InvalidArgumentException::class, 'Model action cannot be empty');
        });

        test('fireModel constructs correct event name format', function () {
            Trigger::factory()->create([
                'event' => 'App\\Models\\Order.created',
                'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
                'enabled' => true,
                'async' => false,
            ]);

            $model = new class {
                public function attributesToArray(): array
                {
                    return ['id' => 1, 'status' => 'pending'];
                }
            };

            EventManagerFacade::fireModel('App\\Models\\Order', 'created', $model);

            expect(EventLog::count())->toBe(1);
            $log = EventLog::first();
            expect($log->event)->toBe('App\\Models\\Order.created');
        });

        test('fireModel flattens model attributes into payload root', function () {
            Trigger::factory()->create([
                'event' => 'App\\Models\\Item.updated',
                'action' => \ZeroBoiler\Events\Tests\Actions\LogOrderEvent::class,
                'enabled' => true,
                'async' => false,
                'conditions' => ['status' => 'active'],
            ]);

            $model = new class {
                public function attributesToArray(): array
                {
                    return ['id' => 42, 'status' => 'active', 'name' => 'Test Item'];
                }
            };

            EventManagerFacade::fireModel('App\\Models\\Item', 'updated', $model);

            expect(EventLog::count())->toBe(1);
            $log = EventLog::first();
            expect($log->status)->toBe(EventLog::STATUS_COMPLETED);
        });

        test('fireModel with object that only has toArray method', function () {
            Trigger::factory()->create([
                'event' => 'App\\Models\\Basic.created',
                'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
                'enabled' => true,
                'async' => false,
            ]);

            $model = new class {
                public function toArray(): array
                {
                    return ['data' => 'value'];
                }
            };

            EventManagerFacade::fireModel('App\\Models\\Basic', 'created', $model);

            expect(EventLog::count())->toBe(1);
        });
    });

    describe('ConditionEngine Type Safety', function () {
        test('comparison operators return false when actual is null', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['amount' => ['>', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['>=', 0]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<', 100]], ['amount' => null]))->toBeFalse();
            expect($engine->matches(['amount' => ['<=', 100]], ['amount' => null]))->toBeFalse();
        });

        test('comparison operators return false when value is null', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['amount' => ['>', null]], ['amount' => 100]))->toBeFalse();
        });

        test('strictEquals with same type returns true', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['count' => 42], ['count' => 42]))->toBeTrue();
            expect($engine->matches(['name' => 'test'], ['name' => 'test']))->toBeTrue();
        });

        test('strictEquals with different types compares as strings when both scalar', function () {
            $engine = app(ConditionEngineContract::class);

            // int 42 vs string "42"
            expect($engine->matches(['count' => 42], ['count' => '42']))->toBeTrue();
        });

        test('strictEquals with array vs string returns false', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['tags' => 'test'], ['tags' => ['test']]))->toBeFalse();
        });

        test('empty array conditions returns true (vacuous truth)', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches([], ['any' => 'data']))->toBeTrue();
        });

        test('empty operator array returns false', function () {
            $engine = app(ConditionEngineContract::class);

            expect($engine->matches(['field' => []], ['field' => 'value']))->toBeFalse();
        });
    });

    describe('WildcardMatcher Borderline Cases', function () {
        test('catch-all pattern * matches any non-empty event', function () {
            expect(WildcardMatcher::matches('*', 'a'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('*', 'a.b.c.d.e'))->toBeTrue();
        });

        test('catch-all pattern * does not match empty string', function () {
            expect(WildcardMatcher::matches('*', ''))->toBeFalse();
        });

        test('catch-all pattern ** matches any non-empty event', function () {
            expect(WildcardMatcher::matches('**', 'a'))->toBeTrue();
            expect(WildcardMatcher::matches('**', 'a.b.c'))->toBeTrue();
        });

        test('single-segment wildcard does not match across dots', function () {
            expect(WildcardMatcher::matches('order.*', 'order.placed.extra'))->toBeFalse();
        });

        test('cross-segment wildcard matches across dots', function () {
            expect(WildcardMatcher::matches('order.**', 'order.placed.extra'))->toBeTrue();
        });

        test('pattern with no wildcards matches exactly', function () {
            expect(WildcardMatcher::matches('order.placed', 'order.placed'))->toBeTrue();
            expect(WildcardMatcher::matches('order.placed', 'order.shipped'))->toBeFalse();
        });

        test('escaped special regex chars in event name', function () {
            expect(WildcardMatcher::matches('test.*', 'test.hello+world'))->toBeTrue();
            expect(WildcardMatcher::matches('test.*', 'test.hello(world)'))->toBeTrue();
        });
    });

    describe('DomainEvent Immutability and Identity', function () {
        test('eventId is readonly and unique across instances', function () {
            $event1 = DomainEvent::occur('test.event');
            $event2 = DomainEvent::occur('test.event');

            expect($event1->eventId->toString())->not->toBe($event2->eventId->toString());
        });

        test('toArray and fromArray roundtrip preserves all fields', function () {
            $event = DomainEvent::occur('user.updated', [
                'user_id' => 123,
                'changes' => ['email' => 'new@example.com'],
            ]);

            $restored = DomainEvent::fromArray($event->toArray());

            expect($restored->eventId->toString())->toBe($event->eventId->toString());
            expect($restored->eventType)->toBe($event->eventType);
            expect($restored->payload)->toBe($event->payload);
            expect($restored->occurredAt)->toEqual($event->occurredAt);
        });

        test('fromArray handles extra fields gracefully', function () {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.extra',
                'payload' => ['key' => 'val'],
                'extraField' => 'ignored',
            ]);

            expect($event->eventType)->toBe('test.extra');
            expect($event->payload)->toBe(['key' => 'val']);
        });

        test('payload property is public readonly', function () {
            $ref = new ReflectionProperty(DomainEvent::class, 'payload');

            expect($ref->isPublic())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });
    });

    describe('SubscriptionBuilder Validation', function () {
        test('save rejects empty event name', function () {
            expect(fn () => EventManagerFacade::subscribe('', 'https://example.com/hook')->save())
                ->toThrow(\InvalidArgumentException::class, 'Event name is required');
        });

        test('save rejects empty URL', function () {
            expect(fn () => EventManagerFacade::subscribe('test.event', '')->save())
                ->toThrow(\InvalidArgumentException::class, 'Webhook URL is required');
        });

        test('save rejects non-HTTP scheme URLs', function () {
            expect(fn () => EventManagerFacade::subscribe('test.event', 'ftp://evil.com/upload')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');

            expect(fn () => EventManagerFacade::subscribe('test.event', 'file:///etc/passwd')->save())
                ->toThrow(\InvalidArgumentException::class, 'HTTP or HTTPS');

            expect(fn () => EventManagerFacade::subscribe('test.event', 'javascript:alert(1)')->save())
                ->toThrow(\InvalidArgumentException::class, 'valid URL');
        });

        test('save accepts HTTP and HTTPS URLs', function () {
            $sub = EventManagerFacade::subscribe('test.event', 'http://example.com/hook')
                ->withSecret('whsec_test')
                ->save();

            expect($sub->url)->toBe('http://example.com/hook');
        });
    });

    describe('DispatchTriggerJob Config Handling', function () {
        test('constructor reads array backoff format from config', function () {
            Config::set('events.retry.backoff', [10, 20, 30]);

            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->backoff)->toBe([10, 20, 30]);
        });

        test('constructor handles empty backoff string', function () {
            Config::set('events.retry.backoff', '');

            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->backoff)->toBe([]);
        });

        test('constructor handles single-value backoff', function () {
            Config::set('events.retry.backoff', '60');

            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->backoff)->toBe([60]);
        });

        test('constructor defaults connection to null when empty string', function () {
            Config::set('events.queue.connection', '');

            $job = new DispatchTriggerJob('id', 'event', []);

            expect($job->connection)->toBeNull();
        });

        test('failed() handles null eventLogId gracefully', function () {
            $job = new DispatchTriggerJob('id', 'event', []);

            // eventLogId is null by default — failed() should not throw
            expect(fn () => $job->failed(new \RuntimeException('test')))
                ->not->toThrow(\Throwable::class);
        });
    });

    describe('EventLog Status Constants Consistency', function () {
        test('all statuses are distinct strings', function () {
            $statuses = [
                EventLog::STATUS_PENDING,
                EventLog::STATUS_DISPATCHED,
                EventLog::STATUS_COMPLETED,
                EventLog::STATUS_FAILED,
            ];

            expect($statuses)->toHaveCount(4);
            expect(array_unique($statuses))->toHaveCount(4);
        });

        test('static $statuses array contains all constants', function () {
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_PENDING);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_DISPATCHED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_COMPLETED);
            expect(EventLog::$statuses)->toContain(EventLog::STATUS_FAILED);
        });
    });

    describe('EventManager Cache Invalidation', function () {
        test('invalidateTriggerCache clears the wildcard cache', function () {
            $manager = app(EventManager::class);

            // Fire an event to populate the cache
            Trigger::factory()->create([
                'event' => 'cache.invalidate.test',
                'action' => \ZeroBoiler\Events\Tests\Actions\SendOrderNotification::class,
                'enabled' => true,
                'async' => false,
            ]);

            EventManagerFacade::fire('cache.invalidate.test', ['data' => 'value']);
            expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeTrue();

            // Invalidate
            $manager->invalidateTriggerCache();
            expect(Cache::has('zeroboiler:events:enabled_wildcard_triggers'))->toBeFalse();
        });
    });

    describe('ServiceProvider Completeness', function () {
        test('composer.json extra.laravel.providers references EventsServiceProvider', function () {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider'
            );
        });

        test('composer.json extra.laravel.aliases references EventManager facade', function () {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['aliases']['EventManager'])->toBe(
                'ZeroBoiler\\Events\\Facades\\EventManager'
            );
        });

        test('composer.json autoload maps ZeroBoiler\\Events to src/', function () {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });
    });

    describe('Facade Delegation', function () {
        test('Facade accessor resolves to EventManager instance', function () {
            $resolved = app(\ZeroBoiler\Events\Facades\EventManager::getFacadeAccessor());

            expect($resolved)->toBeInstanceOf(EventManager::class);
        });

        test('Facade registerScheduler delegates to EventManager', function () {
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $scheduler = app(EventScheduler::class);

            // Clear previous events
            $schedule->events(); // Access to ensure schedule is ready

            EventManagerFacade::registerScheduler($schedule);

            $events = $schedule->events();
            $names = array_map(fn ($e) => $e->command ?? $e->description ?? '', $events);

            expect($names)->toContain('zeroboiler:events:purge-logs');
            expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
        });
    });

    describe('Config Key Consistency', function () {
        test('all config keys referenced in source exist in config file', function () {
            $configContents = file_get_contents(__DIR__.'/../config/events.php');

            // Top-level keys
            expect($configContents)->toContain("'table_names'");
            expect($configContents)->toContain("'queue'");
            expect($configContents)->toContain("'retry'");
            expect($configContents)->toContain("'retention'");
            expect($configContents)->toContain("'subscriptions'");
            expect($configContents)->toContain("'disabled'");
            expect($configContents)->toContain("'wildcard_cache_ttl'");

            // Nested subscription keys
            expect($configContents)->toContain("'auto_generate_secret'");
            expect($configContents)->toContain("'max_failures'");
            expect($configContents)->toContain("'timeout'");
            expect($configContents)->toContain("'signature_algorithm'");
            expect($configContents)->toContain("'cleanup_cron'");

            // Retention nested keys
            expect($configContents)->toContain("'days'");
            expect($configContents)->toContain("'include_pending'");
            expect($configContents)->toContain("'schedule_cron'");
        });

        test('phpstan.neon.dist targets src/ directory', function () {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

            expect($contents)->toContain("paths:");
            expect($contents)->toContain("- src");
        });

        test('phpstan.neon.dist has treatPhpDocTypesAsCertain false', function () {
            $contents = file_get_contents(__DIR__.'/../phpstan.neon.dist');

            expect($contents)->toContain('treatPhpDocTypesAsCertain: false');
        });
    });

    describe('ActionResolver Error Handling', function () {
        test('resolve throws for non-existent class', function () {
            $resolver = app(ActionResolver::class);

            expect(fn () => $resolver->resolve('NonExistent\\Class\\Here'))
                ->toThrow(\InvalidArgumentException::class, 'does not exist');
        });

        test('resolve throws for class that does not implement Triggerable', function () {
            $resolver = app(ActionResolver::class);

            // Use a built-in class that doesn't implement Triggerable
            expect(fn () => $resolver->resolve(\stdClass::class))
                ->toThrow(\InvalidArgumentException::class, 'must implement');
        });
    });

    describe('Source Files Strict Types Verification', function () {
        test('all source files have declare(strict_types=1)', function () {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $firstLine = strtok($contents, "\n");

                // Skip the opening <?php tag and find declare()
                $lines = explode("\n", $contents);
                $hasStrictTypes = false;
                foreach ($lines as $line) {
                    if (str_contains($line, 'declare(strict_types=1)')) {
                        $hasStrictTypes = true;
                        break;
                    }
                    // Stop searching after namespace or use statements
                    if (str_contains($line, 'namespace ')) {
                        break;
                    }
                }

                expect($hasStrictTypes)->toBeTrue("{$file->getPathname()} missing declare(strict_types=1)");
            }
        });
    });
});
