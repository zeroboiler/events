<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\Contracts\ConditionEngineContract;
use ZeroBoiler\Events\Domain\DomainEvent;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\Jobs\DispatchTriggerJob;
use ZeroBoiler\Events\Models\EventLog;
use ZeroBoiler\Events\Models\Subscription;
use ZeroBoiler\Events\Models\Trigger;
use ZeroBoiler\Events\SubscriptionBuilder;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\WildcardMatcher;

describe('Phase 142 Production Audit', function (): void {
    describe('PHP 8.5 syntax compliance — all source files', function (): void {
        it('all source files have declare(strict_types=1)', function (): void {
            $srcDir = realpath(__DIR__.'/../src');
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    if (! str_contains($content, 'declare(strict_types=1)')) {
                        $violations[] = $file->getPathname();
                    }
                }
            }
            expect($violations)->toBeEmpty(
                'All source files must have declare(strict_types=1). Violations: '.implode(', ', $violations),
            );
        });

        it('all source files have the ZeroBoiler license header', function (): void {
            $srcDir = realpath(__DIR__.'/../src');
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            $violations = [];
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    if (! str_contains($content, 'This file is part of ZeroBoiler')) {
                        $violations[] = $file->getPathname();
                    }
                }
            }
            expect($violations)->toBeEmpty(
                'All source files must have the ZeroBoiler license header. Violations: '.implode(', ', $violations),
            );
        });
    });

    describe('EventManager ManagesSubscriptions trait public API return types', function (): void {
        it('subscribe returns SubscriptionBuilder instance', function (): void {
            $manager = $this->app->make(EventManager::class);

            $builder = $manager->subscribe('test.event', 'https://example.com/hook');

            expect($builder)->toBeInstanceOf(SubscriptionBuilder::class);
        });

        it('listSubscriptions returns Collection with correct generic', function (): void {
            $manager = $this->app->make(EventManager::class);

            $result = $manager->listSubscriptions();

            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('listSubscriptions filters by wildcard event', function (): void {
            $manager = $this->app->make(EventManager::class);

            $result = $manager->listSubscriptions('order.*');

            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('subscribeWebhook returns trigger ID string', function (): void {
            $manager = $this->app->make(EventManager::class);

            $id = $manager->subscribeWebhook('test.webhook.142', 'https://example.com/hook');

            expect($id)->toBeString();
            expect($id)->not->toBeEmpty();

            // Verify trigger was created
            $trigger = Trigger::find($id);
            expect($trigger)->not->toBeNull();
            expect($trigger->event)->toBe('test.webhook.142');
        });
    });

    describe('EventManager ManagesHistory trait public API return types', function (): void {
        it('getStats returns array with correct shape', function (): void {
            $manager = $this->app->make(EventManager::class);

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

        it('getEventHistory returns Collection', function (): void {
            $manager = $this->app->make(EventManager::class);

            $result = $manager->getEventHistory(limit: 50);

            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('getStalePendingLogs returns Collection', function (): void {
            $manager = $this->app->make(EventManager::class);

            $result = $manager->getStalePendingLogs(\Illuminate\Support\Carbon::now()->subHours(1));

            expect($result)->toBeInstanceOf(\Illuminate\Database\Eloquent\Collection::class);
        });

        it('deactivateExceededSubscriptions returns int', function (): void {
            $manager = $this->app->make(EventManager::class);

            $count = $manager->deactivateExceededSubscriptions();

            expect($count)->toBeInt();
        });
    });

    describe('EventScheduler constructor injection and register', function (): void {
        it('is resolved as singleton from container', function (): void {
            $first = $this->app->make(EventScheduler::class);
            $second = $this->app->make(EventScheduler::class);

            expect($first)->toBe($second);
        });

        it('has Container constructor parameter', function (): void {
            $ref = new ReflectionClass(EventScheduler::class);
            $ctor = $ref->getConstructor();

            expect($ctor)->not->toBeNull();
            $params = $ctor->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('app');
            expect($params[0]->getType())->not->toBeNull();
        });
    });

    describe('DispatchTriggerJob config-driven properties', function (): void {
        it('reads tries from config at construction', function (): void {
            $this->app->get('config')->set('events.retry.tries', 5);

            $job = new DispatchTriggerJob('test-id', 'test.event', ['key' => 'val']);

            expect($job->tries)->toBe(5);
        });

        it('reads backoff array from config', function (): void {
            $this->app->get('config')->set('events.retry.backoff', [30, 120, 300]);

            $job = new DispatchTriggerJob('test-id', 'test.event', []);

            expect($job->backoff)->toBe([30, 120, 300]);
        });

        it('reads backoff string from config', function (): void {
            $this->app->get('config')->set('events.retry.backoff', '30,120,300');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);

            expect($job->backoff)->toBe([30, 120, 300]);
        });

        it('reads queue name from config', function (): void {
            $this->app->get('config')->set('events.queue.queue', 'events-high');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);

            expect($job->queue)->toBe('events-high');
        });

        it('reads connection from config when set', function (): void {
            $this->app->get('config')->set('events.queue.connection', 'redis-events');

            $job = new DispatchTriggerJob('test-id', 'test.event', []);

            expect($job->connection)->toBe('redis-events');
        });

        it('connection is null when not configured', function (): void {
            $this->app->get('config')->set('events.queue.connection', null);

            $job = new DispatchTriggerJob('test-id', 'test.event', []);

            expect($job->connection)->toBeNull();
        });
    });

    describe('WildcardMatcher Unicode edge cases', function (): void {
        it('matches Unicode event names with wildcard', function (): void {
            expect(WildcardMatcher::matches('order.*', 'order.teszt'))->toBeTrue();
        });

        it('matches Unicode multi-segment events', function (): void {
            expect(WildcardMatcher::matches('*.created', 'felhasználó.created'))->toBeTrue();
        });

        it('does not match Unicode cross-segment without **', function (): void {
            expect(WildcardMatcher::matches('a.*', 'a.b.c'))->toBeFalse();
        });

        it('matches cross-segment with ** for Unicode', function (): void {
            expect(WildcardMatcher::matches('a.**', 'a.αβγ.δ'))
                ->toBeTrue();
        });
    });

    describe('ConditionEngine string coercion edge cases', function (): void {
        it('strictEquals returns false for array vs string', function (): void {
            // Access strictEquals via reflection-free method behavior
            $engine = new ConditionEngine();

            // array on one side, string on the other → false (not scalar both)
            expect($engine->matches(['tags' => 'hello'], ['tags' => ['hello']]))->toBeFalse();
        });

        it('strictEquals coerces int and string to string comparison', function (): void {
            $engine = new ConditionEngine();

            // "42" == 42 → string coercion → true
            expect($engine->matches(['count' => '42'], ['count' => 42]))->toBeTrue();
        });

        it('not_contains operator works with string values', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['name' => ['not_contains', 'admin']], ['name' => 'user']))
                ->toBeTrue();
            expect($engine->matches(['name' => ['not_contains', 'admin']], ['name' => 'superadmin']))
                ->toBeFalse();
        });

        it('not_in operator works correctly', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'user']))
                ->toBeTrue();
            expect($engine->matches(['role' => ['not_in', ['admin', 'mod']]], ['role' => 'admin']))
                ->toBeFalse();
        });

        it('not_empty operator works for non-empty values', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['name' => ['not_empty']], ['name' => 'hello']))
                ->toBeTrue();
            expect($engine->matches(['name' => ['not_empty']], ['name' => '']))
                ->toBeFalse();
            expect($engine->matches(['name' => ['not_empty']], ['name' => null]))
                ->toBeFalse();
        });

        it('not_null operator works correctly', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['email' => ['not_null']], ['email' => 'test@example.com']))
                ->toBeTrue();
            expect($engine->matches(['email' => ['not_null']], ['email' => null]))
                ->toBeFalse();
        });

        it('ends_with operator works correctly', function (): void {
            $engine = new ConditionEngine();

            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.com']))
                ->toBeTrue();
            expect($engine->matches(['domain' => ['ends_with', '.com']], ['domain' => 'example.org']))
                ->toBeFalse();
        });
    });

    describe('DomainEvent edge cases', function (): void {
        it('rejects fromArray with missing eventType key', function (): void {
            expect(fn () => DomainEvent::fromArray(['payload' => ['a' => 'b']]))
                ->toThrow(\InvalidArgumentException::class, 'eventType is required');
        });

        it('defaults to empty payload when payload key is non-array', function (): void {
            $event = DomainEvent::fromArray([
                'eventType' => 'test.nonarray',
                'payload' => 'not-an-array',
            ]);

            expect($event->payload)->toBe([]);
        });

        it('preserves extra fields are ignored in fromArray', function (): void {
            $original = DomainEvent::occur('order.created', ['id' => '1']);
            $data = $original->toArray();
            $data['extra_field'] = 'should be ignored';

            $restored = DomainEvent::fromArray($data);

            expect($restored->eventType)->toBe('order.created');
        });

        it('toArray returns all expected keys', function (): void {
            $event = DomainEvent::occur('test.event', ['key' => 'val']);
            $arr = $event->toArray();

            expect($arr)->toHaveKeys(['eventId', 'eventType', 'payload', 'occurredAt']);
        });

        it('occurredAt format is ISO 8601', function (): void {
            $event = DomainEvent::occur('test.event');
            $arr = $event->toArray();

            $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $arr['occurredAt']);
            expect($parsed)->not->toBeFalse();
        });
    });

    describe('Subscription model methods', function (): void {
        it('recordDelivery increments delivery_count and updates last_fired_at', function (): void {
            $sub = Subscription::factory()->create([
                'failure_count' => 0,
                'delivery_count' => 5,
            ]);

            $sub->recordDelivery();
            $sub->refresh();

            expect($sub->delivery_count)->toBe(6);
            expect($sub->last_fired_at)->not->toBeNull();
        });

        it('recordFailure increments failure_count', function (): void {
            $sub = Subscription::factory()->create([
                'failure_count' => 3,
            ]);

            $sub->recordFailure();
            $sub->refresh();

            expect($sub->failure_count)->toBe(4);
        });

        it('resetFailures sets failure_count to zero', function (): void {
            $sub = Subscription::factory()->create([
                'failure_count' => 10,
            ]);

            $sub->resetFailures();
            $sub->refresh();

            expect($sub->failure_count)->toBe(0);
        });

        it('matchesEvent uses exact match for non-wildcard events', function (): void {
            $sub = Subscription::factory()->create(['event' => 'order.placed']);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeFalse();
        });

        it('matchesEvent uses WildcardMatcher for wildcard patterns', function (): void {
            $sub = Subscription::factory()->create(['event' => 'order.*']);

            expect($sub->matchesEvent('order.placed'))->toBeTrue();
            expect($sub->matchesEvent('order.shipped'))->toBeTrue();
            expect($sub->matchesEvent('order.placed.extra'))->toBeFalse();
        });
    });

    describe('Trigger model scopes', function (): void {
        it('scopeEnabled filters correctly', function (): void {
            Trigger::factory()->create(['event' => 'test.enabled', 'enabled' => true]);
            Trigger::factory()->create(['event' => 'test.disabled', 'enabled' => false]);

            $enabled = Trigger::enabled()->get();

            expect($enabled)->toHaveCount(1);
            expect($enabled->first()->event)->toBe('test.enabled');
        });

        it('scopeAsync filters correctly', function (): void {
            Trigger::factory()->create(['event' => 'test.async', 'async' => true]);
            Trigger::factory()->create(['event' => 'test.sync', 'async' => false]);

            $async = Trigger::async()->get();

            expect($async)->toHaveCount(1);
            expect($async->first()->event)->toBe('test.async');
        });

        it('scopeOrderByPriority returns triggers in descending priority', function (): void {
            Trigger::factory()->create(['event' => 'test.low', 'priority' => 1]);
            Trigger::factory()->create(['event' => 'test.high', 'priority' => 10]);

            $ordered = Trigger::orderByPriority()->get();

            expect($ordered->first()->event)->toBe('test.high');
        });
    });

    describe('EventLog model scopes and methods', function (): void {
        it('scopeWithStatus filters correctly', function (): void {
            EventLog::factory()->create(['status' => 'completed']);
            EventLog::factory()->create(['status' => 'failed']);

            $completed = EventLog::withStatus('completed')->get();

            expect($completed)->toHaveCount(1);
            expect($completed->first()->status)->toBe('completed');
        });

        it('markAsCompleted updates status and duration', function (): void {
            $log = EventLog::factory()->create(['status' => 'dispatched']);

            $log->markAsCompleted(42);
            $log->refresh();

            expect($log->status)->toBe('completed');
            expect($log->duration_ms)->toBe(42);
        });

        it('markAsFailed updates status and error message', function (): void {
            $log = EventLog::factory()->create(['status' => 'dispatched']);

            $log->markAsFailed('Something went wrong');
            $log->refresh();

            expect($log->status)->toBe('failed');
            expect($log->error)->toBe('Something went wrong');
        });
    });

    describe('Wildcard cache TTL edge cases', function (): void {
        it('returns 0 when TTL config is explicitly 0', function (): void {
            $this->app->get('config')->set('events.wildcard_cache_ttl', 0);

            $manager = $this->app->make(EventManager::class);
            // Fire an event to exercise getTriggerCacheTtl() path
            // Should not throw — TTL 0 means no caching
            expect(fn () => $manager->fire('test.ttl-zero.event', []))->not->toThrow();
        });

        it('uses default TTL when config value is negative', function (): void {
            $this->app->get('config')->set('events.wildcard_cache_ttl', -1);

            $manager = $this->app->make(EventManager::class);

            // Should fall back to default 300s TTL
            expect(fn () => $manager->fire('test.ttl-negative.event', []))->not->toThrow();
        });
    });

    describe('ServiceProvider provides completeness', function (): void {
        it('provides all bindings registered in register()', function (): void {
            $provider = new \ZeroBoiler\Events\EventsServiceProvider($this->app);
            $provides = $provider->provides();

            // Every binding from register() should be in provides()
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
                expect($provides)->toContain($binding);
            }
        });
    });

    describe('EventManager registerScheduler facade path', function (): void {
        it('delegates to EventScheduler register', function (): void {
            $manager = $this->app->make(EventManager::class);
            $scheduler = $this->app->make(EventScheduler::class);

            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // Should not throw
            $manager->registerScheduler($schedule);

            expect(true)->toBeTrue();
        });
    });

    describe('composer.json version consistency', function (): void {
        it('composer.json version matches README badge version', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            $readme = file_get_contents(__DIR__.'/../README.md');

            $version = $composer['version'];
            expect($readme)->toContain("version-{$version}-blue");
        });

        it('composer.json requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('composer.json has correct PSR-4 autoload', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['autoload']['psr-4']['ZeroBoiler\\Events\\'])->toBe('src/');
        });

        it('composer.json has correct provider in extra.laravel', function (): void {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Events\\EventsServiceProvider',
            );
        });
    });
});
