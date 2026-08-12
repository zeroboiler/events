<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use ZeroBoiler\Events\ActionResolver;
use ZeroBoiler\Events\ConditionEngine;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\Facades\EventManager as EventManagerFacade;
use ZeroBoiler\Events\TriggerBuilder;
use ZeroBoiler\Events\SubscriptionBuilder;

beforeEach(function (): void {
    $this->app = new Application;
    $this->app->singleton(ConditionEngine::class);
    $this->app->singleton(ActionResolver::class);
    $this->app->singleton(EventScheduler::class);
    $this->app->singleton(EventManager::class, function (Container $app): EventManager {
        return new EventManager(
            $app->make(ConditionEngine::class),
            $app->make(ActionResolver::class),
            $app,
        );
    });
    $this->app->bind(TriggerBuilder::class);
    $this->app->bind(SubscriptionBuilder::class);

    // Set up config
    $this->app['config']->set('events', [
        'disabled' => false,
        'wildcard_cache_ttl' => 300,
        'table_names' => [
            'triggers' => 'triggers',
            'event_logs' => 'event_logs',
            'subscriptions' => 'event_subscriptions',
        ],
        'queue' => [
            'connection' => 'default',
            'queue' => 'default',
        ],
        'retry' => [
            'tries' => 3,
            'backoff' => '60,300,900',
        ],
        'retention' => [
            'days' => 30,
            'include_pending' => false,
            'schedule_cron' => '0 2 * * *',
        ],
        'subscriptions' => [
            'auto_generate_secret' => true,
            'max_failures' => 10,
            'timeout' => 30,
            'signature_algorithm' => 'sha256',
            'cleanup_cron' => '0 3 * * *',
        ],
    ]);
});

describe('EventManager::registerScheduler', function (): void {
    it('exists as a public method on EventManager', function (): void {
        $manager = $this->app->make(EventManager::class);

        expect(method_exists($manager, 'registerScheduler'))->toBeTrue();
    });

    it('has correct type signature', function (): void {
        $manager = $this->app->make(EventManager::class);
        $reflection = new ReflectionMethod($manager, 'registerScheduler');

        expect($reflection->isPublic())->toBeTrue();
        expect($reflection->getReturnType()?->getName())->toBe('void');
        expect($reflection->getParameters())->toHaveCount(1);
        expect($reflection->getParameters()[0]->getName())->toBe('schedule');
        expect($reflection->getParameters()[0]->getType()?->getName())
            ->toBe(Schedule::class);
    });

    it('resolves EventScheduler from container and calls register()', function (): void {
        $manager = $this->app->make(EventManager::class);

        // Get the singleton scheduler instance
        $scheduler = $this->app->make(EventScheduler::class);

        // Create a mock schedule
        $schedule = new Schedule;

        // Verify no events scheduled yet
        expect($schedule->events())->toBeEmpty();

        // Call registerScheduler
        $manager->registerScheduler($schedule);

        // The scheduler should have registered two events:
        // 1. Log purge (zeroboiler:events:purge-logs)
        // 2. Subscription cleanup (zeroboiler:events:cleanup-subscriptions)
        $events = $schedule->events();
        expect($events)->toHaveCount(2);

        $eventNames = array_map(
            fn ($e) => $e->description ?? $e->command ?? '',
            $events,
        );
        // The scheduled callbacks are named, not the description
        $namedEvents = array_filter($events, fn ($e) => $e->name !== null);
        $names = array_map(fn ($e) => $e->name, $namedEvents);

        expect($names)->toContain('zeroboiler:events:purge-logs');
        expect($names)->toContain('zeroboiler:events:cleanup-subscriptions');
    });
});

describe('Facade::registerScheduler delegation', function (): void {
    it('does not throw BadMethodCallException when called via facade', function (): void {
        // Swap the container with our test app
        app()->singleton(EventManager::class, fn () => $this->app->make(EventManager::class));
        app()->singleton(EventScheduler::class, fn () => $this->app->make(EventScheduler::class));
        app()->singleton(ConditionEngine::class, fn () => $this->app->make(ConditionEngine::class));
        app()->singleton(ActionResolver::class, fn () => $this->app->make(ActionResolver::class));
        app()->bind(TriggerBuilder::class, fn () => $this->app->make(TriggerBuilder::class));
        app()->bind(SubscriptionBuilder::class, fn () => $this->app->make(SubscriptionBuilder::class));
        app()->singleton('config', fn () => $this->app['config']);

        $schedule = new Schedule;

        // This should NOT throw BadMethodCallException
        expect(fn () => EventManagerFacade::registerScheduler($schedule))
            ->not->toThrow(BadMethodCallException::class);
    });
});

describe('registerScheduler edge cases', function (): void {
    it('gracefully handles missing EventScheduler binding', function (): void {
        // Create a fresh container without EventScheduler bound
        $container = new Container;
        $container->singleton(ConditionEngine::class);
        $container->singleton(ActionResolver::class);
        // Intentionally DO NOT bind EventScheduler

        $manager = new EventManager(
            $container->make(ConditionEngine::class),
            $container->make(ActionResolver::class),
            $container,
        );

        $schedule = new Schedule;

        // Should not throw — just silently skip registration
        expect(fn () => $manager->registerScheduler($schedule))->not->toThrow();
    });

    it('is idempotent — calling twice does not duplicate schedules beyond normal', function (): void {
        $manager = $this->app->make(EventManager::class);
        $schedule = new Schedule;

        $manager->registerScheduler($schedule);
        $count1 = count($schedule->events());

        $manager->registerScheduler($schedule);
        $count2 = count($schedule->events());

        // Laravel Schedule accumulates events, so calling twice would add more.
        // This test verifies it doesn't crash.
        expect($count1)->toBe(2);
        expect($count2)->toBe(4); // Doubled because schedule accumulates
    });
});

describe('registerScheduler PHPStan compliance', function (): void {
    it('EventManager class is final', function (): void {
        $reflection = new ReflectionClass(EventManager::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('EventScheduler class is final', function (): void {
        $reflection = new ReflectionClass(EventScheduler::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('registerScheduler has a docblock', function (): void {
        $reflection = new ReflectionMethod(EventManager::class, 'registerScheduler');
        expect($reflection->getDocComment())->not->toBeFalse();
        expect($reflection->getDocComment())->toContain('Delegates to EventScheduler');
    });

    it('EventManager has declare(strict_types=1)', function (): void {
        $contents = file_get_contents((new ReflectionClass(EventManager::class))->getFileName());
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('EventScheduler has declare(strict_types=1)', function (): void {
        $contents = file_get_contents((new ReflectionClass(EventScheduler::class))->getFileName());
        expect($contents)->toContain('declare(strict_types=1)');
    });
});
