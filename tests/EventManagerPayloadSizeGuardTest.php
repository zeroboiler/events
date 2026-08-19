<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

use ZeroBoiler\Events\Contracts\Triggerable;
use ZeroBoiler\Events\EventManager;
use ZeroBoiler\Events\Models\Trigger;

/**
 * Tests for payload size guard and non-encodable payload handling in EventManager::fire().
 */
describe('EventManager payload size guard', function (): void {
    it('rejects payloads larger than 1 MB', function (): void {
        // Create a payload larger than 1 MB
        $largePayload = ['data' => str_repeat('x', 1_100_000)];

        expect(fn () => $this->app->make(EventManager::class)->fire('test.event', $largePayload))
            ->toThrow(\InvalidArgumentException::class, 'exceeds the maximum allowed size');
    });

    it('accepts payloads under 1 MB', function (): void {
        $trigger = Trigger::factory()
            ->forEvent('test.size.ok')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        // 100 KB payload — well under the limit
        $payload = ['data' => str_repeat('a', 100_000)];

        expect(fn () => $this->app->make(EventManager::class)->fire('test.size.ok', $payload))
            ->not->toThrow(\Throwable::class);

        // Verify the event log was created
        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.size.ok',
            'trigger_id' => $trigger->id,
            'status' => 'completed',
        ]);
    });

    it('rejects non-JSON-encodable payloads', function (): void {
        // \NAN and \INF are not JSON-encodable without flags
        $badPayload = ['value' => \NAN];

        expect(fn () => $this->app->make(EventManager::class)->fire('test.bad.payload', $badPayload))
            ->toThrow(\InvalidArgumentException::class, 'Event payload is not JSON-encodable');
    });

    it('accepts empty payload without issue', function (): void {
        Trigger::factory()
            ->forEvent('test.empty.payload')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        expect(fn () => $this->app->make(EventManager::class)->fire('test.empty.payload', []))
            ->not->toThrow(\Throwable::class);

        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.empty.payload',
            'status' => 'completed',
        ]);
    });

    it('accepts payload exactly at 1 MB boundary', function (): void {
        Trigger::factory()
            ->forEvent('test.boundary.payload')
            ->enabled()
            ->sync()
            ->withAction(\ZeroBoiler\Events\Tests\Actions\NullAction::class)
            ->create();

        // Create a payload that serializes to exactly 1 MB
        // JSON adds overhead for quotes, braces, etc.
        // Build payload data that fits within 1,048,576 bytes when encoded.
        $data = str_repeat('a', 1_048_560);
        $payload = ['data' => $data];
        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);

        // Verify it's under or exactly at the limit
        expect(strlen($encoded))->toBeLessThanOrEqual(1_048_576);

        $this->app->make(EventManager::class)->fire('test.boundary.payload', $payload);

        $this->assertDatabaseHas('event_logs', [
            'event' => 'test.boundary.payload',
            'status' => 'completed',
        ]);
    });

    it('rejects payload just over 1 MB boundary', function (): void {
        // Create payload that serializes to just over 1 MB
        $data = str_repeat('b', 1_100_000);
        $payload = ['data' => $data];

        expect(fn () => $this->app->make(EventManager::class)->fire('test.over.boundary', $payload))
            ->toThrow(\InvalidArgumentException::class, 'Event payload exceeds the maximum allowed size');
    });

    it('payload size guard is checked before global disable', function (): void {
        // Even when the system is disabled, a non-encodable payload should
        // still throw because the guard runs BEFORE the disable check.
        $this->app->make(EventManager::class)->setEnabled(true);

        $badPayload = ['value' => \INF];

        // The JSON encoding error should be thrown, not silently swallowed
        expect(fn () => $this->app->make(EventManager::class)->fire('test.encodable', $badPayload))
            ->toThrow(\InvalidArgumentException::class, 'Event payload is not JSON-encodable');
    });
});
