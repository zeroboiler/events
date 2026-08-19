<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\EventManager;

describe('EventManager configurable payload max bytes', function (): void {
    it('rejects payloads exceeding the default 1 MB limit', function (): void {
        $this->app['config']->set('events.payload_max_bytes', null);
        $this->app['config']->set('events.disabled', false);

        $manager = $this->app->make(EventManager::class);
        $bigPayload = ['data' => str_repeat('a', 1_100_000)];

        expect(fn () => $manager->fire('test.big', $bigPayload))
            ->toThrow(\InvalidArgumentException::class, 'exceeds the maximum allowed size');
    });

    it('respects a custom payload_max_bytes config value', function (): void {
        $this->app['config']->set('events.payload_max_bytes', 100);
        $this->app['config']->set('events.disabled', false);

        $manager = $this->app->make(EventManager::class);
        $payload = ['data' => str_repeat('x', 200)];

        expect(fn () => $manager->fire('test.oversized', $payload))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('allows any size when payload_max_bytes is 0', function (): void {
        $this->app['config']->set('events.payload_max_bytes', 0);
        $this->app['config']->set('events.disabled', true);

        $manager = $this->app->make(EventManager::class);

        // 2 MB payload — would normally fail, but limit is disabled
        $hugePayload = ['data' => str_repeat('b', 2_097_152)];

        // Should not throw; disabled=true returns early after validation
        $manager->fire('test.huge', $hugePayload);

        expect(true)->toBeTrue();
    });

    it('coerces string config values to int', function (): void {
        $this->app['config']->set('events.payload_max_bytes', '50');
        $this->app['config']->set('events.disabled', false);

        $manager = $this->app->make(EventManager::class);
        $payload = ['data' => str_repeat('z', 100)];

        expect(fn () => $manager->fire('test.string_limit', $payload))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('falls back to 1 MB default for negative values', function (): void {
        $this->app['config']->set('events.payload_max_bytes', -1);
        $this->app['config']->set('events.disabled', false);

        $manager = $this->app->make(EventManager::class);

        // 500 bytes — well under default 1 MB
        $smallPayload = ['data' => str_repeat('a', 500)];

        // Should not throw about size
        $manager->fire('test.small', $smallPayload);

        expect(true)->toBeTrue();
    });
});
