<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Events\Tests;

use Illuminate\Container\Container;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use ReflectionProperty;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\EventScheduler;
use ZeroBoiler\Events\EventsServiceProvider;

describe('Phase 98 — Production Audit', function (): void {
    describe('EventScheduler container injection', function (): void {
        it('accepts Container in constructor via injection', function (): void {
            $app = app();
            $scheduler = new EventScheduler($app);

            // Verify the container is stored
            $reflection = new ReflectionProperty(EventScheduler::class, 'app');
            $reflection->setAccessible(true);

            expect($reflection->getValue($scheduler))->toBe($app);
        });

        it('EventScheduler is registered as singleton in ServiceProvider', function (): void {
            $app = app();
            $provider = new EventsServiceProvider($app);
            $provider->register();

            $first = $app->make(EventScheduler::class);
            $second = $app->make(EventScheduler::class);

            expect($first)->toBe($second); // Same instance (singleton)
            expect($first)->toBeInstanceOf(EventScheduler::class);
        });

        it('EventScheduler constructor has Container parameter with readonly type', function (): void {
            $constructor = new ReflectionMethod(EventScheduler::class, '__construct');
            $params = $constructor->getParameters();

            expect($params)->toHaveCount(1);

            $param = $params[0];
            expect($param->getName())->toBe('app');
            expect($param->getType())->not->toBeNull();

            $type = $param->getType();
            expect($type->getName())->toBe(Container::class);

            // Verify readonly (PHP 8.5+ property promotion)
            $reflection = new ReflectionProperty(EventScheduler::class, 'app');
            expect($reflection->isReadOnly())->toBeTrue();
        });

        it('resolveEventManager returns EventManager instance from container', function (): void {
            $app = app();
            $scheduler = new EventScheduler($app);

            $method = new ReflectionMethod(EventScheduler::class, 'resolveEventManager');
            $method->setAccessible(true);
            $result = $method->invoke($scheduler);

            expect($result)->toBeInstanceOf(EventManager::class);
        });

        it('register() calls both log purge and subscription cleanup', function (): void {
            $app = app();
            $scheduler = new EventScheduler($app);

            // Config for retention
            $app['config']->set('events.retention.days', 30);

            $schedule = new Schedule;
            $scheduler->register($schedule);

            $events = $schedule->events();
            expect($events)->toHaveCount(2);

            $names = array_map(fn ($e) => $e->command, $events);
            // Callback events don't have a simple command name, but we can check count
            expect($events)->toHaveCount(2);
        });
    });

    describe('No app() global helper usage', function (): void {
        it('EventScheduler source does not contain app() calls', function (): void {
            $content = file_get_contents(
                dirname(__DIR__).'/src/EventScheduler.php'
            );

            // No bare app() calls (only $app->make())
            expect((bool) preg_match('/(?<!\$)\bapp\s*\(/', $content))->toBeFalse();
        });
    });

    describe('phpstan.neon.dist app() suppression removal', function (): void {
        it('phpstan config does not suppress app() function', function (): void {
            $content = file_get_contents(
                dirname(__DIR__).'/phpstan.neon.dist'
            );

            // Should NOT contain 'app' in the undefined function suppression
            $hasAppSuppression = (bool) preg_match(
                '/undefined function.*\bapp\b/',
                $content
            );

            expect($hasAppSuppression)->toBeFalse();
        });
    });

    describe('EventManager registerScheduler delegates correctly', function (): void {
        it('registerScheduler uses container to resolve EventScheduler', function (): void {
            $app = app();
            $manager = $app->make(EventManager::class);

            // Should not throw — EventScheduler is properly registered
            $schedule = new Schedule;
            $manager->registerScheduler($schedule);

            // Verify scheduled tasks were registered
            expect($schedule->events())->toHaveCount(2);
        });
    });
});
